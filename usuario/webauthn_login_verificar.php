<?php
// ==============================================================================
// USUARIO/WEBAUTHN_LOGIN_VERIFICAR.PHP — 2º passo do login por biometria: recebe a
// resposta do navigator.credentials.get(), confere a assinatura contra a chave
// pública salva e, se bater, abre a sessão exatamente como o login por senha.
// ==============================================================================
// conexao.php (que carrega funcoes.php/o autoloader do Composer) precisa vir ANTES do
// session_start() — a sessão guarda um objeto ByteBuffer (challenge), e se a classe
// ainda não estiver registrada no autoloader nesse momento, o PHP desserializa como
// __PHP_Incomplete_class em vez do objeto real.
require_once '../config/conexao.php';
require_once '../config/funcoes.php';
session_start();
header('Content-Type: application/json');

$ip  = $_SERVER['REMOTE_ADDR'] ?? 'desconhecido';
$uid = $_SESSION['webauthn_login_uid'] ?? null;
$challenge = $_SESSION['webauthn_login_challenge'] ?? null;

if (!$uid || !$challenge) {
    echo json_encode(['success' => false, 'msg' => 'A verificação expirou, tente novamente.']);
    exit;
}

// Mesmo limite anti-força-bruta do login por senha — conta pro mesmo balde.
if (contarTentativasSeguranca($pdo, 'login', $uid, 15) >= 6 || contarTentativasSeguranca($pdo, 'login_ip', $ip, 15) >= 20) {
    echo json_encode(['success' => false, 'msg' => 'Muitas tentativas. Aguarde alguns minutos.']);
    exit;
}

$post = json_decode(file_get_contents('php://input')) ?: new stdClass();

try {
    $credentialId = base64_decode($post->id ?? '');
    if ($credentialId === '') throw new \Exception('id ausente');

    $stmtCred = $pdo->prepare("SELECT * FROM CredencialWebAuthn WHERE FKUsuario = :uid AND CredentialId = :cid");
    $stmtCred->execute([':uid' => $uid, ':cid' => base64_encode($credentialId)]);
    $cred = $stmtCred->fetch(PDO::FETCH_ASSOC);
    if (!$cred) throw new \Exception('credencial não encontrada');

    $webauthn = obterWebAuthn();
    $webauthn->processGet(
        base64_decode($post->clientDataJSON ?? ''),
        base64_decode($post->authenticatorData ?? ''),
        base64_decode($post->signature ?? ''),
        $cred['PublicKey'],
        $challenge,
        (int)$cred['SignCounter'],
        true,
        true
    );

    $novoContador = $webauthn->getSignatureCounter() ?? (int)$cred['SignCounter'];
    $pdo->prepare("UPDATE CredencialWebAuthn SET SignCounter = :cnt, UltimoUso = NOW() WHERE IDCredencial = :id")
        ->execute([':cnt' => $novoContador, ':id' => $cred['IDCredencial']]);

    $stmtU = $pdo->prepare("SELECT IDUsuario, Nome, NivelAcesso, StatusConta, Plano, Tema, NavTipo FROM Usuario WHERE IDUsuario = :uid");
    $stmtU->execute([':uid' => $uid]);
    $usuario = $stmtU->fetch(PDO::FETCH_ASSOC);
    if (!$usuario || $usuario['StatusConta'] === 'pendente') throw new \Exception('conta indisponível');

    session_regenerate_id(true);
    $_SESSION['usuario_id']   = $usuario['IDUsuario'];
    $_SESSION['usuario_nome'] = $usuario['Nome'];
    $_SESSION['nivel_acesso'] = strtolower($usuario['NivelAcesso']);
    $_SESSION['plano']        = strtolower($usuario['Plano'] ?? 'free');
    $_SESSION['tema']         = strtolower($usuario['Tema'] ?? 'dark');
    $_SESSION['nav_tipo']     = strtolower($usuario['NavTipo'] ?? 'sidebar');
    unset($_SESSION['webauthn_login_challenge'], $_SESSION['webauthn_login_uid']);

    echo json_encode(['success' => true, 'redirect' => '../dashboard.php']);
} catch (Throwable $e) {
    registrarTentativaSeguranca($pdo, 'login', $uid);
    registrarTentativaSeguranca($pdo, 'login_ip', $ip);
    // DEBUG TEMPORÁRIO — mensagem detalhada pra achar a causa raiz, tirar depois.
    echo json_encode(['success' => false, 'msg' => get_class($e) . ': ' . $e->getMessage() . ' em ' . basename($e->getFile()) . ':' . $e->getLine()]);
}
