<?php
// usuario/amizade_remover.php
// Desfaz uma amizade já aceita. Funciona não importa quem enviou o pedido
// originalmente — qualquer um dos dois lados pode remover.

session_start();
if (!isset($_SESSION['usuario_id'])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'erro' => 'Sem permissão']);
    exit;
}

require_once '../config/conexao.php';
require_once '../config/funcoes.php';

header('Content-Type: application/json; charset=utf-8');

$uid    = $_SESSION['usuario_id'];
$amigoId = trim($_POST['amigo_id'] ?? '');

if (empty($amigoId)) {
    echo json_encode(['ok' => false, 'erro' => 'ID inválido']);
    exit;
}

try {
    $pdo->prepare("
        DELETE FROM Amizade
        WHERE Status = 'aceito'
          AND ((FKUsuarioSolicitante = :uid AND FKUsuarioDestinatario = :amigo)
            OR (FKUsuarioSolicitante = :amigo2 AND FKUsuarioDestinatario = :uid2))
    ")->execute([
        ':uid'    => $uid,
        ':amigo'  => $amigoId,
        ':amigo2' => $amigoId,
        ':uid2'   => $uid,
    ]);

    echo json_encode(['ok' => true, 'status' => 'nenhuma']);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'erro' => 'Erro ao processar']);
}
