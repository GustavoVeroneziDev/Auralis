<?php
session_start();
if (!isset($_SESSION['usuario_id'])) {
    header("Location: ../usuario/login.php");
    exit;
}
require_once '../config/conexao.php';

$usuarioId  = $_SESSION['usuario_id'];
$idCarteira = trim($_POST['id_carteira'] ?? '');

if (empty($idCarteira) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: listar_carteiras.php");
    exit;
}

try {
    $stmtChk = $pdo->prepare("SELECT Principal FROM Carteira WHERE IDCarteira = :id AND FKUsuarioDono = :uid");
    $stmtChk->execute([':id' => $idCarteira, ':uid' => $usuarioId]);
    $atual = $stmtChk->fetchColumn();

    if ($atual === false) {
        header("Location: listar_carteiras.php?erro=carteira_invalida");
        exit;
    }

    $pdo->beginTransaction();
    // Sempre desmarca todas as outras primeiro — só pode existir 1 carteira principal por usuário
    $pdo->prepare("UPDATE Carteira SET Principal = 0 WHERE FKUsuarioDono = :uid")
        ->execute([':uid' => $usuarioId]);

    if ((int)$atual === 0) {
        // Estava desmarcada — marca como principal
        $pdo->prepare("UPDATE Carteira SET Principal = 1 WHERE IDCarteira = :id AND FKUsuarioDono = :uid")
            ->execute([':id' => $idCarteira, ':uid' => $usuarioId]);
        $pdo->commit();
        header("Location: listar_carteiras.php?sucesso=principal_definida");
    } else {
        // Já era a principal — só desmarca (fica sem nenhuma principal)
        $pdo->commit();
        header("Location: listar_carteiras.php?sucesso=principal_removida");
    }
    exit;
} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    header("Location: listar_carteiras.php?erro=banco");
    exit;
}
