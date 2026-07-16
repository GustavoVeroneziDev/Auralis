<?php
// usuario/amizade_cancelar.php
// Cancela um pedido de amizade que EU enviei e ainda está pendente
// (desfaz — não é a mesma coisa que recusar, que é resposta de quem recebeu).

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

if (empty($idAmizade)) {
    echo json_encode(['ok' => false, 'erro' => 'ID inválido']);
    exit;
}

try {
    $pdo->prepare("
        DELETE FROM Amizade
        WHERE IDAmizade = :id AND FKUsuarioSolicitante = :uid AND Status = 'pendente'
    ")->execute([':id' => $idAmizade, ':uid' => $uid]);

    echo json_encode(['ok' => true, 'status' => 'nenhuma']);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'erro' => 'Erro ao processar']);
}
