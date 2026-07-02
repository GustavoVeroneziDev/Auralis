<?php
session_start();
if (!isset($_SESSION['usuario_id'])) { http_response_code(401); exit(json_encode(['ok' => false])); }
require_once '../config/conexao.php';
require_once '../config/funcoes.php';
header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$uid   = $_SESSION['usuario_id'];

$endpoint = trim($input['endpoint'] ?? '');
$p256dh   = trim($input['keys']['p256dh'] ?? '');
$auth     = trim($input['keys']['auth'] ?? '');
$ua       = mb_substr(trim($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);

if (empty($endpoint) || empty($p256dh) || empty($auth)) {
    echo json_encode(['ok' => false, 'erro' => 'subscription_invalida']);
    exit;
}

try {
    $pdo->prepare("
        INSERT INTO PushSubscription (IDSubscription, FKUsuario, Endpoint, EndpointHash, P256dhKey, AuthToken, UserAgent)
        VALUES (:id, :uid, :ep, :hash, :p256dh, :auth, :ua)
        ON DUPLICATE KEY UPDATE Endpoint = VALUES(Endpoint), P256dhKey = VALUES(P256dhKey), AuthToken = VALUES(AuthToken), UserAgent = VALUES(UserAgent)
    ")->execute([
        ':id'     => gerarUuid(),
        ':uid'    => $uid,
        ':ep'     => $endpoint,
        ':hash'   => hash('sha256', $endpoint),
        ':p256dh' => $p256dh,
        ':auth'   => $auth,
        ':ua'     => $ua,
    ]);
    echo json_encode(['ok' => true]);
} catch (PDOException $e) {
    echo json_encode(['ok' => false, 'erro' => 'db_erro']);
}
