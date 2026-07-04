<?php
/**
 * AURALIS — Gera um pagamento Pix avulso pra assinar um plano.
 *
 * Diferente da assinatura via cartão (Checkout Pro + preapproval, que renova
 * sozinha), esse fluxo usa a API comum de pagamentos do Mercado Pago
 * (POST /v1/payments com payment_method_id=pix) — bem documentada, mas SEM
 * recorrência automática. A renovação é manual; o cron
 * cron/verificar_assinaturas_pix_vencendo.php avisa a pessoa antes de vencer.
 *
 * O plano só é ativado de fato quando o pagamento é confirmado — isso
 * acontece via verificar_pix_assinatura.php (consulta ativa, polling do
 * front-end) e, como camada de segurança, via webhook_mercadopago.php.
 */
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['usuario_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'erro' => 'nao_autenticado']);
    exit;
}

require_once 'config/conexao.php';
require_once 'config/funcoes.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'erro' => 'metodo_invalido']);
    exit;
}

$usuarioId = $_SESSION['usuario_id'];
$planId    = trim($_POST['plan_id'] ?? '');
$docRaw    = trim($_POST['documento'] ?? '');
$doc       = preg_replace('/\D/', '', $docRaw);

$planos = MP_PLANOS;
if (!isset($planos[$planId])) {
    echo json_encode(['ok' => false, 'erro' => 'plano_invalido']);
    exit;
}
$config = $planos[$planId];

// Validação leve de CPF/CNPJ — formato + rejeita sequências óbvias (000000..., 111111...).
// A checagem definitiva de validade fica por conta do próprio Mercado Pago na hora de processar.
$docValido = in_array(strlen($doc), [11, 14], true) && !preg_match('/^(\d)\1*$/', $doc);
if (!$docValido) {
    echo json_encode(['ok' => false, 'erro' => 'documento_invalido']);
    exit;
}
$tipoDoc = strlen($doc) === 11 ? 'CPF' : 'CNPJ';

try {
    $stmtU = $pdo->prepare("SELECT Nome, Email FROM Usuario WHERE IDUsuario = :uid LIMIT 1");
    $stmtU->execute([':uid' => $usuarioId]);
    $usuario = $stmtU->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $usuario = null;
}

if (!$usuario || empty($usuario['Email'])) {
    echo json_encode(['ok' => false, 'erro' => 'usuario_invalido']);
    exit;
}

$nomeLabel = [
    'pro' => 'Auralis PRO',
    'vip' => 'Auralis VIP',
][$config['plano']] ?? 'Auralis';
$cicloLabel = $config['ciclo'] === 'anual' ? 'Anual' : 'Mensal';

// O MP rejeita offset com dois pontos (-03:00) — o formato aceito, conforme o
// próprio exemplo oficial deles, é UTC simples terminado em "Z" (sem milissegundos).
$expiraEm = new DateTime('+60 minutes', new DateTimeZone('UTC'));

$primeiroNome = trim(explode(' ', trim($usuario['Nome']))[0] ?? 'Cliente');

$payload = [
    'transaction_amount' => (float) $config['valor'],
    'description'        => "{$nomeLabel} - {$cicloLabel}",
    'payment_method_id'  => 'pix',
    'external_reference' => $planId,
    'date_of_expiration' => $expiraEm->format('Y-m-d\TH:i:s\\Z'),
    'payer' => [
        'email'      => $usuario['Email'],
        'first_name' => $primeiroNome,
        'identification' => [
            'type'   => $tipoDoc,
            'number' => $doc,
        ],
    ],
];

$ch = curl_init('https://api.mercadopago.com/v1/payments');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode($payload),
    CURLOPT_HTTPHEADER     => [
        'Authorization: Bearer ' . MP_ACCESS_TOKEN,
        'Content-Type: application/json',
        'X-Idempotency-Key: ' . gerarUuid(),
    ],
    CURLOPT_TIMEOUT => 20,
]);
$resposta = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$dados = json_decode($resposta, true);

if ($httpCode !== 201 || empty($dados['id'])) {
    @file_put_contents(
        __DIR__ . '/logs/webhook_mercadopago.log',
        date('[Y-m-d H:i:s] ') . "PIX AVULSO: falha ao criar pagamento (http={$httpCode}): " . substr((string)$resposta, 0, 500) . PHP_EOL,
        FILE_APPEND | LOCK_EX
    );
    echo json_encode(['ok' => false, 'erro' => 'mp_falhou']);
    exit;
}

$qrCode       = $dados['point_of_interaction']['transaction_data']['qr_code']        ?? null;
$qrCodeBase64 = $dados['point_of_interaction']['transaction_data']['qr_code_base64'] ?? null;

if (!$qrCode || !$qrCodeBase64) {
    echo json_encode(['ok' => false, 'erro' => 'sem_qrcode']);
    exit;
}

echo json_encode([
    'ok'             => true,
    'payment_id'     => (string) $dados['id'],
    'qr_code'        => $qrCode,
    'qr_code_base64' => $qrCodeBase64,
    'valor'          => (float) $config['valor'],
    'expira_em'      => $expiraEm->format('Y-m-d\TH:i:s'),
]);
