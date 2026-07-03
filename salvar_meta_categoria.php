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
    header("Location: analises.php");
    exit;
}

garantirTabelaMetaCategoria($pdo);

$categoriaId = trim($_POST['categoria_id'] ?? '');
$mes         = intval($_POST['mes'] ?? date('m'));
$ano         = intval($_POST['ano'] ?? date('Y'));
$carteira    = trim($_POST['carteira'] ?? '');
$acao        = trim($_POST['acao'] ?? 'salvar');

$voltar = "analises.php?mes={$mes}&ano={$ano}" . ($carteira !== '' ? "&carteira=" . urlencode($carteira) : "") . "#metas-categoria";

if (empty($categoriaId)) {
    header("Location: {$voltar}&erro_meta=categoria_invalida");
    exit;
}

// Confirma que a categoria pertence mesmo ao usuário logado
$stmtCat = $pdo->prepare("SELECT IDCategoria FROM Categoria WHERE IDCategoria = :id AND FKUsuario = :uid");
$stmtCat->execute([':id' => $categoriaId, ':uid' => $usuario_id]);
if (!$stmtCat->fetch()) {
    header("Location: {$voltar}&erro_meta=categoria_invalida");
    exit;
}

try {
    if ($acao === 'remover') {
        $pdo->prepare("DELETE FROM MetaCategoria WHERE FKUsuario = :uid AND FKCategoria = :cat")
            ->execute([':uid' => $usuario_id, ':cat' => $categoriaId]);
        header("Location: {$voltar}&sucesso_meta=meta_removida");
        exit;
    }

    $valorPost  = trim($_POST['valor_meta'] ?? '');
    $valorLimpo = preg_replace('/[^\d.,]/', '', $valorPost);
    if (strpos($valorLimpo, ',') !== false) {
        $valorLimpo = str_replace('.', '', $valorLimpo);
        $valorRaw   = str_replace(',', '.', $valorLimpo);
    } else {
        $valorRaw = $valorLimpo;
    }

    if (empty($valorRaw) || !is_numeric($valorRaw) || (float)$valorRaw <= 0) {
        header("Location: {$voltar}&erro_meta=valor_invalido");
        exit;
    }

    $pdo->prepare("
        INSERT INTO MetaCategoria (IDMeta, FKUsuario, FKCategoria, ValorMeta)
        VALUES (:id, :uid, :cat, :valor)
        ON DUPLICATE KEY UPDATE ValorMeta = :valor2
    ")->execute([
        ':id'     => gerarUuid(),
        ':uid'    => $usuario_id,
        ':cat'    => $categoriaId,
        ':valor'  => (float)$valorRaw,
        ':valor2' => (float)$valorRaw,
    ]);

    header("Location: {$voltar}&sucesso_meta=meta_salva");
    exit;
} catch (PDOException $e) {
    header("Location: {$voltar}&erro_meta=banco");
    exit;
}
