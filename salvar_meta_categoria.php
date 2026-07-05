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

garantirTabelaMetaCategoria($pdo);
garantirEstruturaCarteirasCompartilhadas($pdo);

$categoriaId = trim($_POST['categoria_id'] ?? '');
$acao        = trim($_POST['acao'] ?? 'salvar');

if (empty($categoriaId)) {
    header("Location: gerenciar_categorias.php?erro_meta=categoria_invalida");
    exit;
}

// Confirma que a categoria pertence mesmo ao usuário logado (e pega o tipo, pra saber pra qual
// lista voltar). Numa categoria de carteira compartilhada, FKUsuario é sempre o dono da carteira
// (definido na criação) — então essa mesma checagem já barra convidado de mexer aqui, de graça.
$stmtCat = $pdo->prepare("SELECT IDCategoria, TipoCategoria, FKCarteira FROM Categoria WHERE IDCategoria = :id AND FKUsuario = :uid");
$stmtCat->execute([':id' => $categoriaId, ':uid' => $usuario_id]);
$categoria = $stmtCat->fetch(PDO::FETCH_ASSOC);
if (!$categoria) {
    header("Location: gerenciar_categorias.php?erro_meta=categoria_invalida");
    exit;
}

$ancora = $categoria['TipoCategoria'] === 'receita' ? 'lista-receitas' : 'lista-despesas';
$qsCarteira = !empty($categoria['FKCarteira']) ? '&carteira=' . urlencode($categoria['FKCarteira']) : '';

try {
    if ($acao === 'remover') {
        $pdo->prepare("DELETE FROM MetaCategoria WHERE FKUsuario = :uid AND FKCategoria = :cat")
            ->execute([':uid' => $usuario_id, ':cat' => $categoriaId]);
        header("Location: gerenciar_categorias.php?sucesso_meta=meta_removida{$qsCarteira}#{$ancora}");
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
        header("Location: gerenciar_categorias.php?erro_meta=valor_invalido{$qsCarteira}#{$ancora}");
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

    header("Location: gerenciar_categorias.php?sucesso_meta=meta_salva{$qsCarteira}#{$ancora}");
    exit;
} catch (PDOException $e) {
    header("Location: gerenciar_categorias.php?erro_meta=banco{$qsCarteira}#{$ancora}");
    exit;
}
