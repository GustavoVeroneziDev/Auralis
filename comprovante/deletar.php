<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['usuario_id'])) {
    echo json_encode(['ok' => false, 'msg' => 'Não autorizado']);
    exit;
}

require_once '../config/conexao.php';

$id  = trim($_POST['id'] ?? '');
$uid = $_SESSION['usuario_id'];

if (empty($id)) {
    echo json_encode(['ok' => false, 'msg' => 'ID inválido']);
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM Comprovante WHERE IDComprovante = :id AND FKUsuario = :uid");
$stmt->execute([':id' => $id, ':uid' => $uid]);
$comp = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$comp) {
    echo json_encode(['ok' => false, 'msg' => 'Arquivo não encontrado']);
    exit;
}

$path = __DIR__ . '/../uploads/comprovantes/' . $comp['FKUsuario'] . '/' . $comp['NomeArquivo'];
if (file_exists($path)) {
    unlink($path);
}

$pdo->prepare("DELETE FROM Comprovante WHERE IDComprovante = :id")->execute([':id' => $id]);

echo json_encode(['ok' => true]);
exit;
