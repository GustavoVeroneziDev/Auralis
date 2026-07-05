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

garantirEstruturaCarteirasCompartilhadas($pdo);
garantirTabelaConfiguracaoFinanceira($pdo);

// Numa carteira compartilhada, a poupança é da CARTEIRA (representada pelo FKUsuario do
// dono) — só o dono pode mexer. Sem carteira informada, é a poupança pessoal de sempre.
$carteiraId = trim($_POST['carteira'] ?? '');
$qsCarteira = '';
$fkUsuarioAlvo = $usuario_id;
if ($carteiraId !== '') {
    if (carteiraPapelDoUsuario($pdo, $carteiraId, $usuario_id) !== 'dono') {
        header("Location: gerenciar_categorias.php?erro_poupanca=sem_permissao#relacao-entrada-saida");
        exit;
    }
    $qsCarteira = '&carteira=' . urlencode($carteiraId);
    $fkUsuarioAlvo = $usuario_id; // o próprio dono, já validado acima
}

$percentual = str_replace(',', '.', trim($_POST['percentual'] ?? ''));

if (!is_numeric($percentual) || (float)$percentual < 0 || (float)$percentual > 100) {
    header("Location: gerenciar_categorias.php?erro_poupanca=valor_invalido{$qsCarteira}#relacao-entrada-saida");
    exit;
}

try {
    $pdo->prepare("
        INSERT INTO ConfiguracaoFinanceira (FKUsuario, PercentualPoupanca)
        VALUES (:uid, :p)
        ON DUPLICATE KEY UPDATE PercentualPoupanca = :p2
    ")->execute([
        ':uid' => $fkUsuarioAlvo,
        ':p'   => (float)$percentual,
        ':p2'  => (float)$percentual,
    ]);
    header("Location: gerenciar_categorias.php?sucesso_poupanca=1{$qsCarteira}#relacao-entrada-saida");
    exit;
} catch (PDOException $e) {
    header("Location: gerenciar_categorias.php?erro_poupanca=banco{$qsCarteira}#relacao-entrada-saida");
    exit;
}
