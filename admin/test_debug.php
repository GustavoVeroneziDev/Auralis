<?php
// DIAGNÓSTICO v2 — simula exatamente admin/usuarios.php — DELETAR APÓS USO
session_start();

$out = [];
$out[] = "=== SESSÃO ===";
$out[] = "usuario_id: " . ($_SESSION['usuario_id'] ?? '❌ não definido');
$out[] = "nivel_acesso: " . ($_SESSION['nivel_acesso'] ?? '❌ não definido');
$out[] = "plano: " . ($_SESSION['plano'] ?? '❌ não definido');

$nivelSessao = strtolower($_SESSION['nivel_acesso'] ?? '');
$isAdmin = in_array($nivelSessao, ['admin', 'supremo']);
$out[] = "É admin? " . ($isAdmin ? '✅ sim' : '❌ não — usuarios.php REDIRECIONA para dashboard');

$out[] = "";
$out[] = "=== INCLUDES ===";

require_once '../config/conexao.php';
$out[] = "conexao.php: ✅ carregado";
$out[] = "pdo: " . (isset($pdo) ? '✅ conectado' : '❌ ausente');

$out[] = "";
$out[] = "=== HEADER.PHP (ob_start para não quebrar layout) ===";
ob_start();
try {
    $pageTitle = 'Teste';
    require_once '../geral/header.php';
    $headerContent = ob_get_clean();
    $out[] = "header.php: ✅ incluído sem exceção";
    $out[] = "Bytes gerados pelo header: " . strlen($headerContent);
    $out[] = "Começa com DOCTYPE? " . (strpos($headerContent, '<!DOCTYPE') !== false ? '✅ sim' : '❌ NÃO — possível causa do blank');
    $out[] = "Contém <body>? " . (strpos($headerContent, '<body') !== false ? '✅ sim' : '❌ não');
} catch (Throwable $e) {
    ob_end_clean();
    $out[] = "❌ EXCEÇÃO em header.php: " . $e->getMessage();
    $out[] = "Arquivo: " . $e->getFile() . " linha " . $e->getLine();
}

$out[] = "";
$out[] = "=== auto_prepend_file ===";
$out[] = "auto_prepend_file: '" . ini_get('auto_prepend_file') . "'";
$out[] = "auto_append_file: '" . ini_get('auto_append_file') . "'";

$out[] = "";
$out[] = "=== QUERY USUARIOS ===";
try {
    $total = $pdo->query("SELECT COUNT(*) FROM Usuario")->fetchColumn();
    $out[] = "Total de usuários: $total ✅";
} catch (Throwable $e) {
    $out[] = "❌ ERRO query: " . $e->getMessage();
}

// Agora renderiza tudo
error_reporting(E_ALL);
ini_set('display_errors', 1);
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Debug v2</title>
    <style>body{font-family:monospace;padding:20px;background:#111;color:#eee;white-space:pre-wrap;font-size:13px;}</style>
</head>
<body>
<h2>Debug v2 — Simulação de admin/usuarios.php</h2>
<?= implode("\n", array_map('htmlspecialchars', $out)) ?>

<h3>Erros PHP capturados (error_get_last)</h3>
<?php
$last = error_get_last();
if ($last) {
    echo htmlspecialchars(print_r($last, true));
} else {
    echo "Nenhum erro PHP registrado ✅";
}
?>

<?php if ($isAdmin): ?>
<h3>✅ Sessão tem acesso admin — o problema está em outro lugar</h3>
<?php else: ?>
<h3>❌ Sessão NÃO tem acesso admin — usuarios.php está redirecionando para dashboard</h3>
<p>Para testar acesse esta página logado com sua conta admin em beta.meuauralis.com</p>
<?php endif; ?>

<p style="color:#444;margin-top:40px">Deletar após diagnóstico.</p>
</body>
</html>
