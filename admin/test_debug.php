<?php
// PÁGINA DE DIAGNÓSTICO — DELETAR APÓS USO
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>Debug</title></head><body style='font-family:monospace;padding:20px;background:#111;color:#eee;'>";
echo "<h2>Debug — admin/usuarios.php</h2>";

// ── 1. Sessão ─────────────────────────────────────────────────────────────────
session_start();
echo "<h3>1. Sessão</h3>";
echo "<b>usuario_id:</b> " . ($_SESSION['usuario_id'] ?? '❌ não definido') . "<br>";
echo "<b>nivel_acesso:</b> " . ($_SESSION['nivel_acesso'] ?? '❌ não definido') . "<br>";
echo "<b>plano:</b> " . ($_SESSION['plano'] ?? '❌ não definido') . "<br>";

// ── 2. conexao.php ────────────────────────────────────────────────────────────
echo "<h3>2. conexao.php</h3>";
try {
    require_once '../config/conexao.php';
    echo "✅ conexao.php carregado com sucesso<br>";
    echo "<b>PDO:</b> " . (isset($pdo) ? "✅ conectado" : "❌ não criado") . "<br>";
} catch (Throwable $e) {
    echo "❌ ERRO em conexao.php: " . htmlspecialchars($e->getMessage()) . "<br>";
}

// ── 3. funcoes.php (separado, para ver se já foi incluído junto) ───────────────
echo "<h3>3. funcoes.php</h3>";
echo "function_exists('concederConquistaParaUsuario'): " . (function_exists('concederConquistaParaUsuario') ? '✅ sim' : '❌ não') . "<br>";
echo "function_exists('criarNotificacaoSistema'): " . (function_exists('criarNotificacaoSistema') ? '✅ sim' : '❌ não') . "<br>";
echo "function_exists('verificarAvisosAutomaticos'): " . (function_exists('verificarAvisosAutomaticos') ? '✅ sim' : '❌ não') . "<br>";

// ── 4. PHP info ───────────────────────────────────────────────────────────────
echo "<h3>4. Ambiente PHP</h3>";
echo "<b>Versão PHP:</b> " . phpversion() . "<br>";
echo "<b>display_errors (original):</b> " . ini_get('display_errors') . "<br>";
echo "<b>output_buffering:</b> " . ini_get('output_buffering') . "<br>";

// ── 5. Banco de dados — query de usuários ─────────────────────────────────────
echo "<h3>5. Query de usuários</h3>";
if (isset($pdo)) {
    try {
        $usuarios = $pdo->query("
            SELECT u.IDUsuario, u.Nome, u.Email, u.Plano, u.StatusConta, u.NivelAcesso
            FROM Usuario u
            ORDER BY u.MomentoCriacao DESC
            LIMIT 5
        ")->fetchAll(PDO::FETCH_ASSOC);
        echo "✅ " . count($usuarios) . " usuários retornados<br>";
        foreach ($usuarios as $u) {
            echo "&nbsp;&nbsp;→ " . htmlspecialchars($u['Nome']) . " (" . htmlspecialchars($u['Email']) . ")<br>";
        }
    } catch (Throwable $e) {
        echo "❌ ERRO na query: " . htmlspecialchars($e->getMessage()) . "<br>";
    }
} else {
    echo "⚠️ PDO não disponível<br>";
}

// ── 6. Conquista table ────────────────────────────────────────────────────────
echo "<h3>6. Tabela conquista (colunas)</h3>";
if (isset($pdo)) {
    try {
        $cols = $pdo->query("DESCRIBE conquista")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($cols as $c) {
            echo "&nbsp;&nbsp;→ " . htmlspecialchars($c['Field']) . " (" . htmlspecialchars($c['Type']) . ")<br>";
        }
    } catch (Throwable $e) {
        echo "❌ ERRO: " . htmlspecialchars($e->getMessage()) . "<br>";
    }
}

// ── 7. header.php parse test ──────────────────────────────────────────────────
echo "<h3>7. Teste de header.php</h3>";
echo "Verificando se header.php pode ser incluído...<br>";
// Não vamos incluir de verdade para não quebrar o layout, mas checamos o arquivo
$headerPath = realpath(__DIR__ . '/../geral/header.php');
echo "<b>Caminho:</b> " . ($headerPath ?: '❌ não encontrado') . "<br>";
echo "<b>Existe:</b> " . (file_exists($headerPath) ? '✅ sim' : '❌ não') . "<br>";

$footerPath = realpath(__DIR__ . '/../geral/footer.php');
echo "<b>footer.php existe:</b> " . (file_exists($footerPath) ? '✅ sim' : '❌ não') . "<br>";

echo "<br><hr><p style='color:#666'>Remova este arquivo após diagnosticar.</p>";
echo "</body></html>";
