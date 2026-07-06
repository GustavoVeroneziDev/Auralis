<?php
// ==============================================================================
// USUARIO/WEBAUTHN_CRIAR_VERIFICAR.PHP — 2º passo do cadastro de biometria: recebe
// a resposta do navigator.credentials.create(), valida a assinatura e salva a
// credencial (chave pública) pra usar no login depois.
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
$challenge = $_SESSION['webauthn_challenge'] ?? null;

if (!$challenge) {
    echo json_encode(['success' => false, 'msg' => 'O cadastro expirou, tente novamente.']);
    exit;
}

$post = json_decode(file_get_contents('php://input')) ?: new stdClass();
$apelido = trim($post->apelido ?? '') ?: null;
if ($apelido !== null) $apelido = mb_substr($apelido, 0, 60);

try {
    $webauthn = obterWebAuthn();
    $data = $webauthn->processCreate(
        base64_decode($post->clientDataJSON ?? ''),
        base64_decode($post->attestationObject ?? ''),
        $challenge,
        true,
        true,
        false,
        false // não exige que o Android seja um dispositivo "Google-certificado" (SafetyNet CTS)
    );

    $pdo->prepare("
        INSERT INTO CredencialWebAuthn (IDCredencial, FKUsuario, CredentialId, PublicKey, SignCounter, Apelido)
        VALUES (:id, :uid, :cid, :pk, :cnt, :apelido)
    ")->execute([
        ':id'      => gerarUuid(),
        ':uid'     => $uid,
        ':cid'     => base64_encode($data->credentialId),
        ':pk'      => $data->credentialPublicKey,
        ':cnt'     => $data->signatureCounter ?? 0,
        ':apelido' => $apelido,
    ]);

    unset($_SESSION['webauthn_challenge']);
    echo json_encode(['success' => true]);
} catch (\lbuchs\WebAuthn\WebAuthnException $e) {
    // DEBUG TEMPORÁRIO — mensagem detalhada pra achar a causa raiz, tirar depois.
    echo json_encode(['success' => false, 'msg' => 'WebAuthnException: ' . $e->getMessage() . ' (código ' . $e->getCode() . ')']);
} catch (Throwable $e) {
    // DEBUG TEMPORÁRIO — mensagem detalhada pra achar a causa raiz, tirar depois.
    echo json_encode(['success' => false, 'msg' => get_class($e) . ': ' . $e->getMessage() . ' em ' . basename($e->getFile()) . ':' . $e->getLine()]);
}
