<?php
session_start();
if (!isset($_SESSION['usuario_id'])) { http_response_code(401); exit(json_encode(['ok' => false])); }
require_once '../config/conexao.php';
require_once '../config/funcoes.php';
header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$uid   = $_SESSION['usuario_id'];
$nid   = $input['notificacao_id'] ?? '';
$resp  = $input['respostas'] ?? null;

if (empty($nid) || !is_array($resp)) {
    echo json_encode(['ok' => false, 'erro' => 'Dados inválidos.']); exit;
}

try {
    $stmt = $pdo->prepare("SELECT IDNotificacao FROM Notificacao WHERE IDNotificacao = :id AND Ativo = 1");
    $stmt->execute([':id' => $nid]);
    if (!$stmt->fetch()) { echo json_encode(['ok' => false, 'erro' => 'Notificação não encontrada.']); exit; }

    $pdo->prepare("
        INSERT INTO NotificacaoResposta (IDNotificacaoResposta, FKNotificacao, FKUsuario, Resposta)
        VALUES (:id, :nid, :uid, :resp)
        ON DUPLICATE KEY UPDATE Resposta = VALUES(Resposta), DataResposta = NOW()
    ")->execute([':id' => gerarUuid(), ':nid' => $nid, ':uid' => $uid, ':resp' => json_encode($resp)]);

    $pdo->prepare("INSERT IGNORE INTO NotificacaoLeitura (FKNotificacao, FKUsuario) VALUES (:nid, :uid)")
        ->execute([':nid' => $nid, ':uid' => $uid]);

    echo json_encode(['ok' => true]);
} catch (PDOException $e) {
    echo json_encode(['ok' => false]);
}
