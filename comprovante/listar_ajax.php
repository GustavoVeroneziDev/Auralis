<?php
session_start();
if (!isset($_SESSION['usuario_id'])) { http_response_code(403); echo json_encode(['erro' => 'Sem permissão']); exit; }
require_once '../config/conexao.php';
require_once '../config/funcoes.php';

header('Content-Type: application/json; charset=utf-8');

if (!recursoDisponivelParaPlano('comprovantes')) {
    echo json_encode(['erro' => 'Recurso não disponível no plano atual']);
    exit;
}

$registroId = trim($_GET['registro'] ?? '');
$uid        = $_SESSION['usuario_id'];

if (empty($registroId)) { echo json_encode(['erro' => 'ID inválido']); exit; }

$stmt = $pdo->prepare("SELECT IDComprovante, NomeOriginal, TipoMime, Tamanho FROM Comprovante WHERE FKRegistro = :reg AND FKUsuario = :uid ORDER BY MomentoUpload ASC");
$stmt->execute([':reg' => $registroId, ':uid' => $uid]);
$arquivos = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode(['arquivos' => $arquivos]);
