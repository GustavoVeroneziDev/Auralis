<?php
// usuario/amizade_responder.php
// Aceita ou recusa um pedido de amizade recebido.

session_start();
if (!isset($_SESSION['usuario_id'])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'erro' => 'Sem permissão']);
    exit;
}

require_once '../config/conexao.php';
require_once '../config/funcoes.php';

header('Content-Type: application/json; charset=utf-8');

$uid       = $_SESSION['usuario_id'];
$idAmizade = trim($_POST['amizade_id'] ?? '');
$acao      = $_POST['acao'] ?? '';

if (empty($idAmizade) || !in_array($acao, ['aceitar', 'recusar'], true)) {
    echo json_encode(['ok' => false, 'erro' => 'Requisição inválida']);
    exit;
}

garantirTabelaAmizade($pdo);

try {
    // Só quem recebeu o pedido pode responder — nunca quem mandou
    $stmt = $pdo->prepare("SELECT FKUsuarioDestinatario, Status FROM Amizade WHERE IDAmizade = :id LIMIT 1");
    $stmt->execute([':id' => $idAmizade]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row || $row['FKUsuarioDestinatario'] !== $uid || $row['Status'] !== 'pendente') {
        echo json_encode(['ok' => false, 'erro' => 'Pedido não encontrado']);
        exit;
    }

    $novoStatus = $acao === 'aceitar' ? 'aceito' : 'recusado';
    $pdo->prepare("UPDATE Amizade SET Status = :status, RespondidoEm = NOW() WHERE IDAmizade = :id")
        ->execute([':status' => $novoStatus, ':id' => $idAmizade]);

    echo json_encode(['ok' => true, 'status' => $novoStatus === 'aceito' ? 'amigos' : 'nenhuma']);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'erro' => 'Erro ao processar']);
}
