<?php
// ==============================================================================
// USUARIO/WEBAUTHN_CRIAR_OPCOES.PHP — 1º passo do cadastro de biometria: gera os
// parâmetros (challenge, RP ID etc.) que o navegador precisa pra abrir o prompt
// nativo de Face ID / Windows Hello / digital via navigator.credentials.create().
// ==============================================================================
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['usuario_id'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'msg' => 'Sessão expirada.']);
    exit;
}

require_once '../config/conexao.php';
require_once '../config/funcoes.php';
garantirTabelaCredencialWebAuthn($pdo);

$uid = $_SESSION['usuario_id'];

try {
    $stmtU = $pdo->prepare("SELECT Nome, Email FROM Usuario WHERE IDUsuario = :uid");
    $stmtU->execute([':uid' => $uid]);
    $usuario = $stmtU->fetch(PDO::FETCH_ASSOC);
    if (!$usuario) {
        http_response_code(403);
        echo json_encode(['success' => false, 'msg' => 'Sessão expirada.']);
        exit;
    }

    // Evita cadastrar o mesmo autenticador duas vezes.
    $stmtIds = $pdo->prepare("SELECT CredentialId FROM CredencialWebAuthn WHERE FKUsuario = :uid");
    $stmtIds->execute([':uid' => $uid]);
    $excluir = array_map('base64_decode', $stmtIds->fetchAll(PDO::FETCH_COLUMN));

    $webauthn = obterWebAuthn();
    $args = $webauthn->getCreateArgs($uid, $usuario['Email'], $usuario['Nome'], 60, false, true, false, $excluir);

    $_SESSION['webauthn_challenge'] = $webauthn->getChallenge();

    echo json_encode($args);
} catch (Throwable $e) {
    http_response_code(400);
    // DEBUG TEMPORÁRIO — mensagem detalhada pra achar a causa raiz, tirar depois.
    echo json_encode(['success' => false, 'msg' => get_class($e) . ': ' . $e->getMessage() . ' em ' . basename($e->getFile()) . ':' . $e->getLine()]);
}
