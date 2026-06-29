<?php
// DIAGNÓSTICO v3 — DELETAR APÓS USO
session_start();
require_once '../config/conexao.php';

$erros = [];

// ── Query exata do admin/usuarios.php ────────────────────────────────────────
try {
    $usuarios = $pdo->query("
        SELECT
            u.IDUsuario, u.Nome, u.Email, u.Plano, u.StatusConta,
            u.NivelAcesso, u.MomentoCriacao,
            a.DataExpiracao, a.DataInicio, a.ValorPago, a.Ciclo, a.FontePagamento
        FROM Usuario u
        LEFT JOIN Assinatura a ON a.IDAssinatura = (
            SELECT IDAssinatura FROM Assinatura
            WHERE FKUsuario = u.IDUsuario AND Status = 'ativa'
            ORDER BY DataExpiracao DESC LIMIT 1
        )
        ORDER BY
            CASE u.Plano WHEN 'vip' THEN 1 WHEN 'pro' THEN 2 ELSE 3 END,
            u.MomentoCriacao DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $erros[] = "❌ QUERY falhou: " . $e->getMessage();
    $usuarios = [];
}

// ── Simula o foreach do usuarios.php ─────────────────────────────────────────
$rowProblemas = [];
foreach ($usuarios as $i => $u) {
    $linha = [];

    // DateTime MomentoCriacao (linha 320 do usuarios.php)
    try {
        $criacao = new DateTime($u['MomentoCriacao']);
    } catch (Throwable $e) {
        $linha[] = "MomentoCriacao='" . $u['MomentoCriacao'] . "' → ERRO: " . $e->getMessage();
    }

    // DateTime DataExpiracao (linha 317)
    try {
        $exp = !empty($u['DataExpiracao']) ? new DateTime($u['DataExpiracao']) : null;
    } catch (Throwable $e) {
        $linha[] = "DataExpiracao='" . $u['DataExpiracao'] . "' → ERRO: " . $e->getMessage();
    }

    // match FontePagamento (linha 385)
    try {
        $fonte = $u['FontePagamento'] ?? '';
        $fonteLabel = match ($fonte) {
            'mercadopago'  => 'MercadoPago',
            'manual_admin' => 'Manual',
            ''             => '—',
            default        => $fonte,
        };
    } catch (Throwable $e) {
        $linha[] = "FontePagamento='" . ($u['FontePagamento'] ?? 'NULL') . "' → ERRO: " . $e->getMessage();
    }

    // match NivelAcesso (linha 407)
    try {
        $nivel = strtolower($u['NivelAcesso'] ?? 'titular');
        $nivelCor = match ($nivel) {
            'supremo' => '#E63946',
            'admin'   => '#f87171',
            default   => '#666',
        };
    } catch (Throwable $e) {
        $linha[] = "NivelAcesso='" . ($u['NivelAcesso'] ?? 'NULL') . "' → ERRO: " . $e->getMessage();
    }

    if ($linha) {
        $rowProblemas[$i] = ['usuario' => $u['Nome'] . ' (' . $u['Email'] . ')', 'erros' => $linha];
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Debug v3</title>
    <style>body{font-family:monospace;padding:20px;background:#111;color:#eee;font-size:13px;}
    .ok{color:#22c55e;} .err{color:#f87171;background:#2a0a0a;padding:4px 8px;border-radius:4px;display:block;margin:4px 0;}
    table{border-collapse:collapse;width:100%;} td,th{border:1px solid #333;padding:6px 10px;text-align:left;font-size:12px;}
    th{background:#1c1f24;color:#9ca3af;}</style>
</head>
<body>
<h2>Debug v3 — Foreach de usuarios</h2>

<?php if ($erros): ?>
    <h3 class="err">❌ Erros gerais</h3>
    <?php foreach ($erros as $e) echo "<div class='err'>" . htmlspecialchars($e) . "</div>"; ?>
<?php endif; ?>

<h3>Usuários retornados: <?= count($usuarios) ?></h3>

<?php if ($rowProblemas): ?>
    <h3 style="color:#f87171">❌ Linhas problemáticas no foreach (causam blank page):</h3>
    <?php foreach ($rowProblemas as $i => $p): ?>
        <div style="border:1px solid #f87171;padding:10px;margin-bottom:8px;border-radius:6px;">
            <b>Usuário #<?= $i ?>:</b> <?= htmlspecialchars($p['usuario']) ?><br>
            <?php foreach ($p['erros'] as $err): ?>
                <span class="err"><?= htmlspecialchars($err) ?></span>
            <?php endforeach; ?>
        </div>
    <?php endforeach; ?>
<?php else: ?>
    <p class="ok">✅ Nenhum problema no foreach — o erro está em outra parte do arquivo</p>
<?php endif; ?>

<h3>Todos os valores brutos</h3>
<table>
    <tr>
        <th>#</th><th>Nome</th><th>Plano</th><th>StatusConta</th>
        <th>NivelAcesso</th><th>MomentoCriacao</th><th>DataExpiracao</th><th>FontePagamento</th>
    </tr>
    <?php foreach ($usuarios as $i => $u): ?>
    <tr>
        <td><?= $i ?></td>
        <td><?= htmlspecialchars($u['Nome'] ?? 'NULL') ?></td>
        <td><?= htmlspecialchars($u['Plano'] ?? 'NULL') ?></td>
        <td><?= htmlspecialchars($u['StatusConta'] ?? 'NULL') ?></td>
        <td><?= htmlspecialchars($u['NivelAcesso'] ?? 'NULL') ?></td>
        <td><?= htmlspecialchars($u['MomentoCriacao'] ?? 'NULL') ?></td>
        <td><?= htmlspecialchars($u['DataExpiracao'] ?? 'NULL') ?></td>
        <td><?= htmlspecialchars($u['FontePagamento'] ?? 'NULL') ?></td>
    </tr>
    <?php endforeach; ?>
</table>

<p style="color:#444;margin-top:40px">Deletar após diagnóstico.</p>
</body>
</html>
