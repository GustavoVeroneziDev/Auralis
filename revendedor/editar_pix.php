<?php
session_start();
if (!isset($_SESSION['usuario_id'])) { header("Location: /usuario/login.php"); exit; }
require_once '../config/conexao.php';
require_once '../config/funcoes.php';

$uid = $_SESSION['usuario_id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: dashboard.php");
    exit;
}

$chavePix = trim($_POST['chave_pix'] ?? '');

if (empty($chavePix)) {
    header("Location: dashboard.php?erro=pix_vazio");
    exit;
}

try {
    // Só atualiza se o usuário logado for de fato um revendedor ativo (mesma checagem do dashboard)
    $stmt = $pdo->prepare("UPDATE Revendedor SET ChavePix = :pix WHERE FKUsuario = :uid AND Ativo = 1");
    $stmt->execute([':pix' => $chavePix, ':uid' => $uid]);

    if ($stmt->rowCount() === 0) {
        header("Location: /dashboard.php?erro=sem_permissao");
        exit;
    }

    header("Location: dashboard.php?sucesso=pix_atualizado");
    exit;
} catch (PDOException $e) {
    header("Location: dashboard.php?erro=banco");
    exit;
}
