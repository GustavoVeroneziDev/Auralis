<?php
session_start();
if (!isset($_SESSION['usuario_id'])) {
    http_response_code(403);
    exit;
}

require_once '../config/conexao.php';

$id  = trim($_GET['id'] ?? '');
$uid = $_SESSION['usuario_id'];

if (empty($id)) { http_response_code(400); exit; }

$stmt = $pdo->prepare("SELECT * FROM Comprovante WHERE IDComprovante = :id AND FKUsuario = :uid");
$stmt->execute([':id' => $id, ':uid' => $uid]);
$comp = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$comp) { http_response_code(404); exit; }

$path = __DIR__ . '/../uploads/comprovantes/' . $comp['FKUsuario'] . '/' . $comp['NomeArquivo'];
if (!file_exists($path)) { http_response_code(404); exit; }

$mime = $comp['TipoMime'];
$nome = $comp['NomeOriginal'];

$download = isset($_GET['download']);

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
if ($download) {
    header('Content-Disposition: attachment; filename="' . rawurlencode($nome) . '"');
} else {
    header('Content-Disposition: inline; filename="' . rawurlencode($nome) . '"');
}
header('Cache-Control: private, max-age=3600');

readfile($path);
exit;
