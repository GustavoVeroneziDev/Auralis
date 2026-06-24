<?php
session_start();
if (!isset($_SESSION['usuario_id'])) { http_response_code(401); exit(json_encode(['ok' => false])); }
require_once '../config/conexao.php';
require_once '../config/funcoes.php';
header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$uid   = $_SESSION['usuario_id'];
$plano = strtolower($_SESSION['plano'] ?? 'free');

try {
    if (!empty($input['todas'])) {
        $stmt = $pdo->prepare("
            SELECT n.IDNotificacao FROM Notificacao n
            WHERE n.Ativo = 1
              AND (n.DataExpiracao IS NULL OR n.DataExpiracao >= CURDATE())
              AND (
                n.DestinatarioTipo = 'todos'
                OR n.DestinatarioTipo = :plano
                OR (n.DestinatarioTipo = 'selecionado' AND EXISTS (
                    SELECT 1 FROM NotificacaoDestinatario nd
                    WHERE nd.FKNotificacao = n.IDNotificacao AND nd.FKUsuario = :uid2
                ))
              )
              AND NOT EXISTS (
                  SELECT 1 FROM NotificacaoLeitura nl
                  WHERE nl.FKNotificacao = n.IDNotificacao AND nl.FKUsuario = :uid3
              )
        ");
        $stmt->execute([':plano' => $plano, ':uid2' => $uid, ':uid3' => $uid]);
        $ins = $pdo->prepare("INSERT IGNORE INTO NotificacaoLeitura (FKNotificacao, FKUsuario) VALUES (:nid, :uid)");
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $nid) {
            $ins->execute([':nid' => $nid, ':uid' => $uid]);
        }
        echo json_encode(['ok' => true]);

    } elseif (!empty($input['id'])) {
        $pdo->prepare("INSERT IGNORE INTO NotificacaoLeitura (FKNotificacao, FKUsuario) VALUES (:nid, :uid)")
            ->execute([':nid' => $input['id'], ':uid' => $uid]);
        echo json_encode(['ok' => true]);

    } else {
        echo json_encode(['ok' => false, 'erro' => 'Parâmetro inválido.']);
    }
} catch (PDOException $e) {
    echo json_encode(['ok' => false]);
}
