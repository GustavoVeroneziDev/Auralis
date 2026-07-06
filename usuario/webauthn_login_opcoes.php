<?php
// ==============================================================================
// USUARIO/WEBAUTHN_LOGIN_OPCOES.PHP — 1º passo do login por biometria: recebe o
// e-mail digitado, confere se essa conta tem alguma credencial cadastrada e, se
// tiver, devolve os parâmetros pro navigator.credentials.get(). Sempre responde no
// mesmo formato (disponivel:false) quando o e-mail não existe, pra não dar pra
// descobrir por aqui se um e-mail está cadastrado no Auralis ou não.
// ==============================================================================
session_start();
header('Content-Type: application/json');

require_once '../config/conexao.php';
require_once '../config/funcoes.php';
garantirTabelaCredencialWebAuthn($pdo);

$ip = $_SERVER['REMOTE_ADDR'] ?? 'desconhecido';
if (contarTentativasSeguranca($pdo, 'webauthn_login_ip', $ip, 15) >= 30) {
    echo json_encode(['disponivel' => false]);
    exit;
}

$post = json_decode(file_get_contents('php://input')) ?: new stdClass();
$email = trim($post->email ?? '');

if (empty($email)) {
    echo json_encode(['disponivel' => false]);
    exit;
}

try {
    registrarTentativaSeguranca($pdo, 'webauthn_login_ip', $ip);

    $stmtU = $pdo->prepare("SELECT IDUsuario FROM Usuario WHERE Email = :email AND StatusConta != 'pendente' LIMIT 1");
    $stmtU->execute([':email' => $email]);
    $uid = $stmtU->fetchColumn();
    if (!$uid) {
        echo json_encode(['disponivel' => false]);
        exit;
    }

    $stmtIds = $pdo->prepare("SELECT CredentialId FROM CredencialWebAuthn WHERE FKUsuario = :uid");
    $stmtIds->execute([':uid' => $uid]);
    $ids = array_map('base64_decode', $stmtIds->fetchAll(PDO::FETCH_COLUMN));

    if (empty($ids)) {
        echo json_encode(['disponivel' => false]);
        exit;
    }

    $webauthn = obterWebAuthn();
    $args = $webauthn->getGetArgs($ids, 60, false, false, false, false, true, true);

    $_SESSION['webauthn_login_challenge'] = $webauthn->getChallenge();
    $_SESSION['webauthn_login_uid']       = $uid;

    echo json_encode(['disponivel' => true, 'options' => $args]);
} catch (Throwable $e) {
    echo json_encode(['disponivel' => false]);
}
