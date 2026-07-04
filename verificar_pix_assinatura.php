<?php
/**
 * AURALIS — Verifica o status de um pagamento Pix avulso (polling do front-end).
 *
 * Consulta ativa direto na API do MP (mesmo padrão do sucesso_pagamento.php
 * pra cartão) — ativa o plano na hora, sem depender do webhook. O webhook
 * continua existindo como camada de segurança caso o navegador feche antes
 * da confirmação.
 */
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['usuario_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'nao_autenticado']);
    exit;
}

require_once 'config/conexao.php';
require_once 'config/funcoes.php';

$paymentId = trim($_GET['payment_id'] ?? '');
if (empty($paymentId) || !ctype_digit($paymentId)) {
    echo json_encode(['status' => 'invalido']);
    exit;
}

list($httpCode, $pagamento) = mpConsultarApi("https://api.mercadopago.com/v1/payments/{$paymentId}");

if ($httpCode !== 200 || empty($pagamento)) {
    echo json_encode(['status' => 'erro_consulta']);
    exit;
}

$mpStatus = $pagamento['status'] ?? '';

if ($mpStatus === 'approved') {
    $planId = $pagamento['external_reference'] ?? '';
    $email  = $pagamento['payer']['email']     ?? '';
    $valor  = (float) ($pagamento['transaction_amount'] ?? 0);

    // Só ativa se o pagamento pertence mesmo ao usuário logado (protege contra
    // alguém tentar "adivinhar" o payment_id de outra pessoa nesse endpoint).
    $stmtU = $pdo->prepare("SELECT Email FROM Usuario WHERE IDUsuario = :uid LIMIT 1");
    $stmtU->execute([':uid' => $_SESSION['usuario_id']]);
    $emailSessao = $stmtU->fetchColumn();

    if ($emailSessao && strtolower(trim($emailSessao)) === strtolower(trim($email))) {
        $resultado = mpAtivarPlano($pdo, $email, $planId, "pix_{$paymentId}", $valor);
        if ($resultado) {
            $_SESSION['plano'] = $resultado;
            unset($_SESSION['expiracao_verificada']);
            processarIndicacaoConversao($pdo, $email, $valor, $resultado);
            echo json_encode(['status' => 'aprovado', 'plano' => $resultado]);
            exit;
        }
    }

    echo json_encode(['status' => 'aprovado_pendente_ativacao']);
    exit;
}

if ($mpStatus === 'pending') {
    $expiracao = $pagamento['date_of_expiration'] ?? null;
    if ($expiracao && strtotime($expiracao) < time()) {
        echo json_encode(['status' => 'expirado']);
        exit;
    }
    echo json_encode(['status' => 'pendente']);
    exit;
}

echo json_encode(['status' => $mpStatus ?: 'desconhecido']);
