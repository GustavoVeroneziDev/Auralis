<?php
session_start();
if (!isset($_SESSION['usuario_id'])) { http_response_code(401); exit(json_encode(['ok' => false])); }
require_once '../config/conexao.php';
require_once '../config/funcoes.php';
header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$uid      = $_SESSION['usuario_id'];
$endpoint = trim($input['endpoint'] ?? '');

try {
    if ($endpoint !== '') {
        $pdo->prepare("DELETE FROM PushSubscription WHERE FKUsuario = :uid AND EndpointHash = :hash")
            ->execute([':uid' => $uid, ':hash' => hash('sha256', $endpoint)]);
    } else {
        // Sem endpoint específico — remove todas as subscriptions do usuário neste caso
        $pdo->prepare("DELETE FROM PushSubscription WHERE FKUsuario = :uid")
            ->execute([':uid' => $uid]);
    }
    echo json_encode(['ok' => true]);
} catch (PDOException $e) {
    echo json_encode(['ok' => false, 'erro' => 'db_erro']);
}
