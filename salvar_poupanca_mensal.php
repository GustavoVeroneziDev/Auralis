<?php
session_start();
if (!isset($_SESSION['usuario_id'])) {
    header("Location: usuario/login.php");
    exit;
}
require_once 'config/conexao.php';
require_once 'config/funcoes.php';

$usuario_id = $_SESSION['usuario_id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: gerenciar_categorias.php");
    exit;
}

garantirTabelaConfiguracaoFinanceira($pdo);

$percentual = str_replace(',', '.', trim($_POST['percentual'] ?? ''));

if (!is_numeric($percentual) || (float)$percentual < 0 || (float)$percentual > 100) {
    header("Location: gerenciar_categorias.php?erro_poupanca=valor_invalido#relacao-entrada-saida");
    exit;
}

try {
    $pdo->prepare("
        INSERT INTO ConfiguracaoFinanceira (FKUsuario, PercentualPoupanca)
        VALUES (:uid, :p)
        ON DUPLICATE KEY UPDATE PercentualPoupanca = :p2
    ")->execute([
        ':uid' => $usuario_id,
        ':p'   => (float)$percentual,
        ':p2'  => (float)$percentual,
    ]);
    header("Location: gerenciar_categorias.php?sucesso_poupanca=1#relacao-entrada-saida");
    exit;
} catch (PDOException $e) {
    header("Location: gerenciar_categorias.php?erro_poupanca=banco#relacao-entrada-saida");
    exit;
}
