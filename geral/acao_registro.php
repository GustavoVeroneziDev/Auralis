<?php
session_start();
if (!isset($_SESSION['usuario_id'])) { http_response_code(403); exit; }
require_once __DIR__ . '/../config/conexao.php';

header('Content-Type: application/json');

$uid  = $_SESSION['usuario_id'];
$acao = $_POST['acao'] ?? '';
$id   = trim($_POST['id'] ?? '');

if (!$id || !$acao) { echo json_encode(['ok' => false, 'erro' => 'parametros invalidos']); exit; }

// Verifica que o registro pertence ao usuário antes de qualquer ação
$stmtCheck = $pdo->prepare("SELECT IDRegistro, StatusRegistro FROM Registro WHERE IDRegistro = :id AND FKUsuario = :uid LIMIT 1");
$stmtCheck->execute([':id' => $id, ':uid' => $uid]);
$reg = $stmtCheck->fetch();
if (!$reg) { http_response_code(404); echo json_encode(['ok' => false, 'erro' => 'nao encontrado']); exit; }

try {
    if ($acao === 'excluir') {
        $pdo->prepare("DELETE FROM Registro WHERE IDRegistro = :id AND FKUsuario = :uid")
            ->execute([':id' => $id, ':uid' => $uid]);
        echo json_encode(['ok' => true]);

    } elseif ($acao === 'nao_efetivar') {
        $pdo->prepare("UPDATE Registro SET StatusRegistro = 'pendente' WHERE IDRegistro = :id AND FKUsuario = :uid")
            ->execute([':id' => $id, ':uid' => $uid]);
        echo json_encode(['ok' => true]);

    } elseif ($acao === 'efetivar') {
        $pdo->prepare("UPDATE Registro SET StatusRegistro = 'efetivado' WHERE IDRegistro = :id AND FKUsuario = :uid")
            ->execute([':id' => $id, ':uid' => $uid]);
        echo json_encode(['ok' => true]);

    } else {
        echo json_encode(['ok' => false, 'erro' => 'acao desconhecida']);
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'erro' => 'erro interno']);
}
