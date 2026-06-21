<?php
session_start();
if (!isset($_SESSION['usuario_id'])) {
    header("Location: ../usuario/login.php");
    exit;
}
require_once '../config/conexao.php';
require_once '../config/funcoes.php';

$uid = $_SESSION['usuario_id'];
$erros = [];
$infos = [];

// ── 1. Verifica coluna FKCofrinho ───────────────────────────────────────────
try {
    $chk = $pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='Registro' AND COLUMN_NAME='FKCofrinho'");
    $existe = (int)$chk->fetchColumn();
    $infos[] = $existe
        ? '✅ Coluna FKCofrinho existe em Registro'
        : '❌ Coluna FKCofrinho NÃO existe em Registro';

    if (!$existe) {
        try {
            $pdo->exec("ALTER TABLE Registro ADD COLUMN FKCofrinho VARCHAR(36) NULL DEFAULT NULL");
            $infos[] = '✅ Coluna FKCofrinho criada agora';
        } catch (PDOException $e2) {
            $erros[] = 'Falha ao criar coluna: ' . $e2->getMessage();
        }
    }
} catch (PDOException $e) {
    $erros[] = 'Erro ao verificar coluna: ' . $e->getMessage();
}

// ── 2. Lista colunas da tabela Registro ─────────────────────────────────────
$colunasRegistro = [];
try {
    $stmt = $pdo->query("SHOW COLUMNS FROM Registro");
    $colunasRegistro = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $erros[] = 'Erro ao listar colunas de Registro: ' . $e->getMessage();
}

// ── 3. Lista cofrinhos do usuário ────────────────────────────────────────────
$cofrinhos = [];
try {
    $stmt = $pdo->prepare("SELECT co.*, ca.TipoCarteira as NomeCarteira FROM Cofrinho co LEFT JOIN Carteira ca ON ca.IDCarteira = co.FKCarteira WHERE co.FKUsuario = :uid");
    $stmt->execute([':uid' => $uid]);
    $cofrinhos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $infos[] = count($cofrinhos) . ' cofrinho(s) encontrado(s) para este usuário';
} catch (PDOException $e) {
    $erros[] = 'Erro ao buscar cofrinhos: ' . $e->getMessage();
}

// ── 4. Lista registros cofrinho no Registro ──────────────────────────────────
$registros = [];
try {
    $stmt = $pdo->prepare("SELECT IDRegistro, TipoRegistro, Valor, Descricao, MomentoRegistro, FKCarteira, FKCofrinho FROM Registro WHERE FKUsuario = :uid AND TipoRegistro IN ('cofrinho','cofrinho_retirada') ORDER BY MomentoRegistro DESC LIMIT 30");
    $stmt->execute([':uid' => $uid]);
    $registros = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $infos[] = count($registros) . ' registro(s) cofrinho encontrado(s) em Registro';
} catch (PDOException $e) {
    $erros[] = 'Erro ao buscar registros cofrinho: ' . $e->getMessage();
}

// ── 5. Testa INSERT de depósito (se cofrinho existir) ────────────────────────
$testeInsertResult = null;
if (!empty($cofrinhos) && isset($_GET['testar'])) {
    $cof = $cofrinhos[0];
    $testId = gerarUuid();
    $hoje = date('Y-m-d');
    try {
        $stmt = $pdo->prepare("
            INSERT INTO Registro
              (IDRegistro, TipoRegistro, Valor, Descricao, MomentoRegistro, DataVencimento,
               StatusRegistro, Recorrente, DiaVencimento, FKCarteira, FKUsuario, FKCofrinho)
            VALUES
              (:id, 'cofrinho', 0.01, 'TESTE-DEBUG', NOW(), :hoje,
               'efetivado', 0, NULL, :carteira, :uid, :cofrinho)
        ");
        $stmt->execute([
            ':id'       => $testId,
            ':hoje'     => $hoje,
            ':carteira' => $cof['FKCarteira'],
            ':uid'      => $uid,
            ':cofrinho' => $cof['IDCofrinho'],
        ]);
        $testeInsertResult = ['sucesso' => true, 'id' => $testId, 'cofrinho' => $cof['IDCofrinho'], 'carteira' => $cof['FKCarteira']];
        // Apaga o registro de teste
        $pdo->prepare("DELETE FROM Registro WHERE IDRegistro = :id")->execute([':id' => $testId]);
        $testeInsertResult['msg'] = 'INSERT funcionou e foi removido com sucesso';
    } catch (PDOException $e) {
        $testeInsertResult = ['sucesso' => false, 'erro' => $e->getMessage(), 'code' => $e->getCode()];
    }
}

// ── 6. Testa a query de ValorAtual (igual à analises.php) ───────────────────
$valoresAtual = [];
if (!empty($cofrinhos)) {
    foreach ($cofrinhos as $cof) {
        try {
            $stmt = $pdo->prepare("
                SELECT COALESCE(SUM(CASE WHEN r.TipoRegistro='cofrinho'          THEN  r.Valor
                                         WHEN r.TipoRegistro='cofrinho_retirada' THEN -r.Valor
                                         ELSE 0 END), 0) as ValorAtual,
                       COUNT(r.IDRegistro) as QtdRegistros
                FROM Registro r
                WHERE r.FKCofrinho = :id
                  AND r.TipoRegistro IN ('cofrinho','cofrinho_retirada')
            ");
            $stmt->execute([':id' => $cof['IDCofrinho']]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $valoresAtual[$cof['IDCofrinho']] = $row;
        } catch (PDOException $e) {
            $valoresAtual[$cof['IDCofrinho']] = ['erro' => $e->getMessage()];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<title>Debug Cofrinhos</title>
<style>
body { font-family: monospace; background: #111; color: #eee; padding: 24px; }
h2 { color: #f59e0b; border-bottom: 1px solid #333; padding-bottom: 8px; }
h3 { color: #0891b2; margin-top: 24px; }
.ok  { color: #16a34a; }
.err { color: #dc2626; }
.warn { color: #f59e0b; }
table { border-collapse: collapse; width: 100%; margin: 12px 0; font-size: 0.82rem; }
th, td { border: 1px solid #333; padding: 6px 10px; text-align: left; }
th { background: #1e1e1e; color: #f59e0b; }
tr:hover td { background: #1a1a1a; }
.null { color: #dc2626; font-style: italic; }
.btn { display:inline-block; padding:8px 16px; background:#f59e0b22; color:#f59e0b;
       border:1px solid #f59e0b44; border-radius:8px; text-decoration:none; margin:8px 0; cursor:pointer; }
.btn:hover { background:#f59e0b44; }
pre { background:#1a1a1a; padding:12px; border-radius:6px; overflow-x:auto; border:1px solid #333; }
</style>
</head>
<body>
<h2>🔍 Debug — Cofrinhos & Metas</h2>
<p style="color:#888;font-size:0.85rem;">Usuário: <?= htmlspecialchars($uid) ?> &nbsp;|&nbsp; <?= date('d/m/Y H:i:s') ?></p>

<?php if (!empty($erros)): ?>
<div style="background:#dc262622;border:1px solid #dc2626;border-radius:8px;padding:16px;margin-bottom:16px;">
<strong class="err">❌ Erros encontrados:</strong>
<?php foreach ($erros as $e): ?>
<div class="err" style="margin-top:6px;"><?= htmlspecialchars($e) ?></div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<h3>1. Verificações gerais</h3>
<?php foreach ($infos as $i): ?>
<div class="<?= str_starts_with($i,'✅') ? 'ok' : (str_starts_with($i,'❌') ? 'err' : '') ?>"><?= htmlspecialchars($i) ?></div>
<?php endforeach; ?>

<h3>2. Colunas de Registro (verifica FKCofrinho)</h3>
<table>
<tr><th>#</th><th>Field</th><th>Type</th><th>Null</th><th>Default</th></tr>
<?php foreach ($colunasRegistro as $i => $col): ?>
<tr style="<?= $col['Field']==='FKCofrinho' ? 'background:#f59e0b11;' : '' ?>">
<td><?= $i+1 ?></td>
<td><strong><?= htmlspecialchars($col['Field']) ?></strong><?= $col['Field']==='FKCofrinho' ? ' ⬅' : '' ?></td>
<td><?= htmlspecialchars($col['Type']) ?></td>
<td><?= htmlspecialchars($col['Null']) ?></td>
<td><?= htmlspecialchars($col['Default'] ?? 'NULL') ?></td>
</tr>
<?php endforeach; ?>
</table>

<h3>3. Cofrinhos do usuário</h3>
<?php if (empty($cofrinhos)): ?>
<p class="warn">Nenhum cofrinho encontrado para este usuário.</p>
<?php else: ?>
<table>
<tr><th>IDCofrinho</th><th>Nome</th><th>FKCarteira</th><th>NomeCarteira</th><th>ValorMeta</th><th>Ativo</th><th>ValorAtual (query direta)</th><th>QtdRegistros</th></tr>
<?php foreach ($cofrinhos as $cof): $va = $valoresAtual[$cof['IDCofrinho']] ?? []; ?>
<tr>
<td style="font-size:0.7rem;"><?= htmlspecialchars($cof['IDCofrinho']) ?></td>
<td><?= htmlspecialchars($cof['Nome']) ?></td>
<td style="font-size:0.7rem;"><?= htmlspecialchars($cof['FKCarteira']) ?></td>
<td><?= htmlspecialchars($cof['NomeCarteira'] ?? '—') ?></td>
<td><?= $cof['ValorMeta'] !== null ? 'R$ ' . number_format($cof['ValorMeta'],2,',','.') : '<span class="null">NULL</span>' ?></td>
<td><?= $cof['Ativo'] ? '<span class="ok">Sim</span>' : '<span class="err">Não</span>' ?></td>
<td><?= isset($va['erro']) ? '<span class="err">'.$va['erro'].'</span>' : 'R$ ' . number_format((float)($va['ValorAtual']??0),2,',','.') ?></td>
<td><?= htmlspecialchars($va['QtdRegistros'] ?? '?') ?></td>
</tr>
<?php endforeach; ?>
</table>
<?php endif; ?>

<h3>4. Registros cofrinho/cofrinho_retirada no banco (últimos 30)</h3>
<?php if (empty($registros)): ?>
<p class="warn">⚠️ Nenhum registro cofrinho encontrado. Isso explica o saldo zero — os depósitos não estão sendo gravados.</p>
<?php else: ?>
<table>
<tr><th>IDRegistro</th><th>Tipo</th><th>Valor</th><th>Descricao</th><th>Momento</th><th>FKCarteira</th><th>FKCofrinho</th></tr>
<?php foreach ($registros as $r): ?>
<tr>
<td style="font-size:0.7rem;"><?= htmlspecialchars(substr($r['IDRegistro'],0,8)) ?>...</td>
<td><?= htmlspecialchars($r['TipoRegistro']) ?></td>
<td>R$ <?= number_format((float)$r['Valor'],2,',','.') ?></td>
<td><?= htmlspecialchars($r['Descricao'] ?? '') ?></td>
<td><?= htmlspecialchars($r['MomentoRegistro']) ?></td>
<td style="font-size:0.7rem;"><?= htmlspecialchars(substr($r['FKCarteira']??'',0,8)) ?>...</td>
<td style="font-size:0.7rem;"><?= $r['FKCofrinho'] ? htmlspecialchars(substr($r['FKCofrinho'],0,8)).'...' : '<span class="null">NULL ❌</span>' ?></td>
</tr>
<?php endforeach; ?>
</table>
<?php endif; ?>

<h3>5. Teste de INSERT (depósito R$0,01)</h3>
<?php if (empty($cofrinhos)): ?>
<p class="warn">Nenhum cofrinho disponível para testar.</p>
<?php elseif ($testeInsertResult === null): ?>
<p><a class="btn" href="?testar=1">▶ Executar teste de INSERT (e apagar em seguida)</a></p>
<p style="color:#888;font-size:0.8rem;">Isso insere um registro de R$0,01 e imediatamente o remove. Serve para testar se o INSERT funciona.</p>
<?php elseif ($testeInsertResult['sucesso']): ?>
<div class="ok">✅ INSERT funcionou! Coluna FKCofrinho foi salva como: <strong><?= htmlspecialchars($testeInsertResult['cofrinho']) ?></strong></div>
<div class="ok" style="margin-top:4px;"><?= htmlspecialchars($testeInsertResult['msg']) ?></div>
<p style="color:#888;font-size:0.8rem;margin-top:8px;">Se o INSERT funciona mas o saldo ainda mostra 0, o problema está na query de leitura em analises.php.</p>
<?php else: ?>
<div class="err">❌ INSERT FALHOU: <?= htmlspecialchars($testeInsertResult['erro']) ?></div>
<div class="err" style="margin-top:4px;">Código do erro: <?= htmlspecialchars($testeInsertResult['code']) ?></div>
<p style="margin-top:8px;color:#888;font-size:0.8rem;">Este é o motivo pelo qual os depósitos não são salvos. Veja o erro acima para diagnóstico.</p>
<?php endif; ?>

<div style="margin-top:32px;padding-top:16px;border-top:1px solid #333;font-size:0.78rem;color:#666;">
<strong>Como interpretar:</strong><br>
— Seção 4 vazia → os depósitos nunca chegam ao banco (erro no processa_cofrinho.php)<br>
— Seção 4 com FKCofrinho=NULL → INSERT chega mas sem o ID do cofrinho (bug na passagem do ID)<br>
— Seção 4 com FKCofrinho preenchido mas seção 3 mostra R$0,00 → bug na query de leitura (JOIN errado)<br>
— Seção 5 com erro → revela o motivo exato do INSERT falhar
<br><br>
<a href="../analises.php#cofrinhos" style="color:#0891b2;">← Voltar para Análises</a>
</div>
</body>
</html>
