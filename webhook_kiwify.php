<?php

// ── Configuração ──────────────────────────────────────────────────────────
define('KIWIFY_TOKEN', '5sm15z80ljq');

// Mapeamento: ID do produto Kiwify → plano no Auralis
// Substitua pelos IDs reais dos seus produtos depois de criá-los na Kiwify
define('PRODUTOS', [
    '85614e30-4d82-11f1-8f1e-03c7d68b7a1a'  => ['plano' => 'pro',  'ciclo' => 'mensal',  'dias' => 32],
    'ded60050-4d82-11f1-9355-f981dc8f326a'   => ['plano' => 'pro',  'ciclo' => 'anual',   'dias' => 370],
    '40ab03c0-4d83-11f1-990a-29bfa0d38fbb'  => ['plano' => 'vip',  'ciclo' => 'mensal',  'dias' => 32],
    '7e5a07c0-4d83-11f1-8430-c7a3defb5c42'   => ['plano' => 'vip',  'ciclo' => 'anual',   'dias' => 370],
]);

// ── Bootstrap ─────────────────────────────────────────────────────────────
require_once 'config/conexao.php';

// Log de segurança — NUNCA exponha erros PHP para o exterior
ini_set('display_errors', '0');
error_reporting(0);

// ── Leitura e validação do payload ────────────────────────────────────────
$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!$data) {
    http_response_code(400);
    exit('Payload inválido');
}

// Validação do token Kiwify (vem no header Authorization: Bearer TOKEN)
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['HTTP_X_KIWIFY_TOKEN'] ?? '');
$token      = str_replace('Bearer ', '', $authHeader);

if ($token !== KIWIFY_TOKEN) {
    // Tenta também via query string (alguns gateways preferem assim)
    if (($_GET['token'] ?? '') !== KIWIFY_TOKEN) {
        http_response_code(401);
        exit('Não autorizado');
    }
}

// ── Leitura dos campos da Kiwify ──────────────────────────────────────────
$evento        = $data['type']                   ?? '';          // order.approved, order.refunded, subscription.cancelled
$emailComprador = strtolower(trim($data['Customer']['email'] ?? $data['customer']['email'] ?? ''));
$idProduto     = $data['Product']['id']          ?? $data['product']['id'] ?? '';
$idAssinaturaGW = $data['subscription_id']       ?? $data['id'] ?? '';
$valorPago     = (float)($data['amount']         ?? 0) / 100;   // Kiwify envia centavos

if (empty($emailComprador) || empty($idProduto)) {
    http_response_code(422);
    exit('Dados insuficientes');
}

// Verifica se o produto é nosso
if (!array_key_exists($idProduto, PRODUTOS)) {
    http_response_code(200); // Retorna 200 para a Kiwify não ficar re-tentando
    exit('Produto não mapeado — ignorado');
}

$config = PRODUTOS[$idProduto];

// ── Encontra o usuário pelo e-mail ────────────────────────────────────────
try {
    $stmtUsuario = $pdo->prepare("SELECT IDUsuario, Plano FROM Usuario WHERE Email = :email LIMIT 1");
    $stmtUsuario->execute([':email' => $emailComprador]);
    $usuario = $stmtUsuario->fetch();
} catch (PDOException $e) {
    http_response_code(500);
    exit('Erro interno');
}

if (!$usuario) {
    // Usuário não encontrado — pode não ter se cadastrado ainda
    // Logamos e saímos (não é erro nosso)
    _log("USUARIO_NAO_ENCONTRADO: {$emailComprador} comprou {$idProduto}");
    http_response_code(200);
    exit('Usuário não encontrado no sistema');
}

$uid = $usuario['IDUsuario'];

// ── Processa por tipo de evento ───────────────────────────────────────────
$pdo->beginTransaction();

try {
    switch (true) {

        // ── Pagamento aprovado / assinatura nova ─────────────────────────
        case in_array($evento, ['order.approved', 'subscription.renewed', 'subscription.activated']):
            $dataInicio    = new DateTime();
            $dataExpiracao = (new DateTime())->modify("+{$config['dias']} days");

            // Cancela assinatura ativa anterior do mesmo plano (renovação)
            $pdo->prepare("
                UPDATE Assinatura SET Status = 'cancelada'
                WHERE FKUsuario = :uid AND Plano = :plano AND Status = 'ativa'
            ")->execute([':uid' => $uid, ':plano' => $config['plano']]);

            // Cria nova assinatura
            $novoId = function_exists('gerarUuid') ? gerarUuid() : bin2hex(random_bytes(16));
            $pdo->prepare("
                INSERT INTO Assinatura
                    (IDAssinatura, FKUsuario, Plano, Status, Ciclo, ValorPago,
                     DataInicio, DataExpiracao, IDAssinaturaGW, EmailGateway, FontePagamento)
                VALUES
                    (:id, :uid, :plano, 'ativa', :ciclo, :valor,
                     :inicio, :expiracao, :gwid, :email, 'kiwify')
            ")->execute([
                ':id'        => $novoId,
                ':uid'       => $uid,
                ':plano'     => $config['plano'],
                ':ciclo'     => $config['ciclo'],
                ':valor'     => $valorPago,
                ':inicio'    => $dataInicio->format('Y-m-d H:i:s'),
                ':expiracao' => $dataExpiracao->format('Y-m-d H:i:s'),
                ':gwid'      => $idAssinaturaGW,
                ':email'     => $emailComprador,
            ]);

            // Atualiza o plano do usuário (campo desnormalizado para queries rápidas)
            $pdo->prepare("UPDATE Usuario SET Plano = :plano WHERE IDUsuario = :uid")
                ->execute([':plano' => $config['plano'], ':uid' => $uid]);

            _log("PLANO_ATIVADO: {$emailComprador} → {$config['plano']} ({$config['ciclo']})");
            break;

        // ── Reembolso / cancelamento ──────────────────────────────────────
        case in_array($evento, ['order.refunded', 'subscription.cancelled', 'subscription.overdue']):
            $novoStatus = ($evento === 'subscription.overdue') ? 'inadimplente' : 'cancelada';

            $pdo->prepare("
                UPDATE Assinatura SET Status = :status
                WHERE FKUsuario = :uid AND Status IN ('ativa','trial')
            ")->execute([':status' => $novoStatus, ':uid' => $uid]);

            // Rebaixa para free
            $pdo->prepare("UPDATE Usuario SET Plano = 'free' WHERE IDUsuario = :uid")
                ->execute([':uid' => $uid]);

            _log("PLANO_CANCELADO: {$emailComprador} → free (evento: {$evento})");
            break;

        default:
            // Evento que não nos interessa (ex: order.pending) — ignora
            break;
    }

    $pdo->commit();
    http_response_code(200);
    echo 'OK';

} catch (PDOException $e) {
    $pdo->rollBack();
    _log("ERRO_DB: " . $e->getMessage());
    http_response_code(500);
    exit('Erro interno');
}

// ── Helper de log ─────────────────────────────────────────────────────────
function _log(string $msg): void {
    $linha = date('[Y-m-d H:i:s] ') . $msg . PHP_EOL;
    @file_put_contents(__DIR__ . '/logs/webhook_kiwify.log', $linha, FILE_APPEND | LOCK_EX);
}
