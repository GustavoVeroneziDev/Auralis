<?php
// ==============================================================================
// ADMIN/SIMULADOR_FATURA.PHP — Testa N meses de fechamento de fatura de uma vez
// ==============================================================================
// Sem isso, testar o motor de fechamento de cartão só dava pra fazer esperando
// o calendário real passar (ou editando data no banco na mão, mês a mês) — um
// jeito lento de achar bug que só aparece "uma vez por mês". Aqui roda 8 meses
// de fechamento numa tarde, num cartão isolado que nunca mistura com dado real.
require_once '../config/sessao.php';
if (!isset($_SESSION['usuario_id'])) { header("Location: /usuario/login.php"); exit; }
require_once '../config/conexao.php';
require_once '../config/funcoes.php';
require_once '../config/funcoes_cartao.php';
require_once '../config/permissoes.php';

exigirSupremo();

$uid = $_SESSION['usuario_id'];

// ── Provisiona (ou recupera) a carteira + cartão de simulação, isolados de dado real ──
function _simFatObterOuCriarCarteira(PDO $pdo, string $uid): string
{
    $stmt = $pdo->prepare("SELECT IDCarteira FROM Carteira WHERE FKUsuarioDono = :uid AND TipoCarteira = :nome LIMIT 1");
    $stmt->execute([':uid' => $uid, ':nome' => '🧪 Simulação de Fatura']);
    $id = $stmt->fetchColumn();
    if ($id) return $id;

    $id = gerarUuid();
    $pdo->prepare("INSERT INTO Carteira (IDCarteira, TipoCarteira, FKUsuarioDono, Compartilhada) VALUES (:id, :nome, :uid, 0)")
        ->execute([':id' => $id, ':nome' => '🧪 Simulação de Fatura', ':uid' => $uid]);
    return $id;
}

function _simFatObterOuCriarCartao(PDO $pdo, string $uid, string $carteiraId): array
{
    $stmt = $pdo->prepare("SELECT * FROM CartaoCredito WHERE FKUsuario = :uid AND Nome = :nome LIMIT 1");
    $stmt->execute([':uid' => $uid, ':nome' => '🧪 Cartão de Simulação']);
    $cartao = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($cartao) return $cartao;

    $id = gerarUuid();
    $pdo->prepare(
        "INSERT INTO CartaoCredito (IDCartao, FKUsuario, Nome, Bandeira, Cor, Limite, DiaFechamento, DiaVencimento, FKCarteiraDebito)
         VALUES (:id, :uid, :nome, 'outro', '#f97316', 5000, 5, 15, :cart)"
    )->execute([':id' => $id, ':uid' => $uid, ':nome' => '🧪 Cartão de Simulação', ':cart' => $carteiraId]);

    $stmt = $pdo->prepare("SELECT * FROM CartaoCredito WHERE IDCartao = :id");
    $stmt->execute([':id' => $id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

$carteiraTesteId = _simFatObterOuCriarCarteira($pdo, $uid);
$cartaoTeste     = _simFatObterOuCriarCartao($pdo, $uid, $carteiraTesteId);
$cartaoTesteId   = $cartaoTeste['IDCartao'];

$erro = $sucesso = null;
$relatorio = [];
$alertas   = [];

// ── Reseta: apaga fatura/lançamento/registro de preview e pagamento do cartão de teste ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'resetar' && csrfValido()) {
    try {
        $pdo->prepare("
            DELETE r FROM Registro r
            JOIN FaturaCartao f ON r.IDRegistro IN (f.FKRegistroPreview, f.FKRegistroPagamento)
            WHERE f.FKCartao = :cid AND f.FKUsuario = :uid
        ")->execute([':cid' => $cartaoTesteId, ':uid' => $uid]);
        $pdo->prepare("DELETE FROM LancamentoCartao WHERE FKCartao = :cid AND FKUsuario = :uid")
            ->execute([':cid' => $cartaoTesteId, ':uid' => $uid]);
        $pdo->prepare("DELETE FROM FaturaCartao WHERE FKCartao = :cid AND FKUsuario = :uid")
            ->execute([':cid' => $cartaoTesteId, ':uid' => $uid]);
        header("Location: simulador_fatura.php?ok_reset=1");
        exit;
    } catch (PDOException $e) {
        $erro = 'Erro ao resetar: ' . $e->getMessage();
    }
}

// ── Atualiza dia de fechamento/vencimento do cartão de teste (pra testar casos como dia 29/30/31) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'salvar_dias' && csrfValido()) {
    $diaFech = max(1, min(31, (int)($_POST['dia_fechamento'] ?? 5)));
    $diaVenc = max(1, min(31, (int)($_POST['dia_vencimento'] ?? 15)));
    $pdo->prepare("UPDATE CartaoCredito SET DiaFechamento = :df, DiaVencimento = :dv WHERE IDCartao = :id AND FKUsuario = :uid")
        ->execute([':df' => $diaFech, ':dv' => $diaVenc, ':id' => $cartaoTesteId, ':uid' => $uid]);
    header("Location: simulador_fatura.php?ok_dias=1");
    exit;
}

// ── Roda a simulação: N meses seguidos, uma "hoje" simulada por vez ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'simular' && csrfValido()) {
    $dataInicioPost = trim($_POST['data_inicio'] ?? '');
    $meses          = max(1, min(36, (int)($_POST['meses'] ?? 8)));
    $valorCompra    = parseValorBr($_POST['valor_compra'] ?? '0');

    try {
        $dataAtual = $dataInicioPost !== '' ? new DateTime($dataInicioPost) : new DateTime('today');
    } catch (Exception $e) {
        $erro = 'Data de início inválida.';
        $dataAtual = null;
    }

    if ($dataAtual) {
        for ($i = 0; $i < $meses; $i++) {
            $passo = ['mes' => $dataAtual->format('d/m/Y'), 'eventos' => []];

            // Snapshot ANTES — pra saber o que mudou depois de rodar o fechamento deste mês.
            $antes = [];
            $stmtSnap = $pdo->prepare("SELECT IDFatura, Status FROM FaturaCartao WHERE FKCartao = :cid AND FKUsuario = :uid");
            $stmtSnap->execute([':cid' => $cartaoTesteId, ':uid' => $uid]);
            foreach ($stmtSnap->fetchAll(PDO::FETCH_ASSOC) as $r) $antes[$r['IDFatura']] = $r['Status'];

            if ($valorCompra > 0) {
                $stmtCartaoAtual = $pdo->prepare("SELECT * FROM CartaoCredito WHERE IDCartao = :id");
                $stmtCartaoAtual->execute([':id' => $cartaoTesteId]);
                $cartaoAtual = $stmtCartaoAtual->fetch(PDO::FETCH_ASSOC);

                $fatura = cartao_obterFaturaAberta($pdo, $cartaoTesteId, $uid, $cartaoAtual, $dataAtual);
                $pdo->prepare(
                    "INSERT INTO LancamentoCartao (IDLancamento, FKFatura, FKCartao, FKUsuario, Descricao, Valor, DataCompra)
                     VALUES (:id, :fid, :cid, :uid, :desc, :val, :data)"
                )->execute([
                    ':id'   => gerarUuid(),
                    ':fid'  => $fatura['IDFatura'],
                    ':cid'  => $cartaoTesteId,
                    ':uid'  => $uid,
                    ':desc' => 'Compra simulada #' . ($i + 1),
                    ':val'  => $valorCompra,
                    ':data' => $dataAtual->format('Y-m-d'),
                ]);
                cartao_sincronizarPreview($pdo, $fatura['IDFatura'], $uid, $cartaoAtual);
                $passo['eventos'][] = "Lançou compra simulada de R$ " . number_format($valorCompra, 2, ',', '.') . " na fatura {$fatura['MesReferencia']}";
            }

            cartao_verificarFechamentos($pdo, $uid, $dataAtual);

            // Snapshot DEPOIS — qualquer fatura cujo status mudou vira um evento no relatório.
            $stmtSnap->execute([':cid' => $cartaoTesteId, ':uid' => $uid]);
            $stmtDepoisFull = $pdo->prepare("SELECT * FROM FaturaCartao WHERE FKCartao = :cid AND FKUsuario = :uid ORDER BY MesReferencia ASC");
            $stmtDepoisFull->execute([':cid' => $cartaoTesteId, ':uid' => $uid]);
            $todasFaturas = $stmtDepoisFull->fetchAll(PDO::FETCH_ASSOC);
            foreach ($todasFaturas as $f) {
                $statusAntes = $antes[$f['IDFatura']] ?? null;
                if ($statusAntes !== $f['Status']) {
                    $valorFmt = number_format((float)$f['ValorTotal'], 2, ',', '.');
                    $passo['eventos'][] = "Fatura {$f['MesReferencia']}: " . ($statusAntes ?? 'nova') . " → {$f['Status']}" . ($f['Status'] === 'fechada' ? " (R$ {$valorFmt})" : '');
                }
            }
            if (!$passo['eventos']) $passo['eventos'][] = '— nada mudou —';

            $relatorio[] = $passo;
            $dataAtual->modify('+1 month');
        }

        // ── Checagem de invariantes — é isso que expõe bug de verdade, não só "rodou sem erro" ──
        $stmtFinal = $pdo->prepare("SELECT * FROM FaturaCartao WHERE FKCartao = :cid AND FKUsuario = :uid ORDER BY MesReferencia ASC");
        $stmtFinal->execute([':cid' => $cartaoTesteId, ':uid' => $uid]);
        $faturasFinais = $stmtFinal->fetchAll(PDO::FETCH_ASSOC);

        $abertas = array_filter($faturasFinais, fn($f) => $f['Status'] === 'aberta');
        if (count($abertas) > 1) {
            $alertas[] = count($abertas) . ' faturas marcadas "aberta" ao mesmo tempo — só deveria existir 1.';
        }
        $hojeSimFinal = (clone $dataAtual)->modify('-1 month')->format('Y-m-d');
        foreach ($faturasFinais as $f) {
            if ($f['Status'] === 'aberta' && $f['DataFechamento'] < $hojeSimFinal) {
                $alertas[] = "Fatura {$f['MesReferencia']} ainda 'aberta' com fechamento no passado (" . date('d/m/Y', strtotime($f['DataFechamento'])) . ") — ficou presa.";
            }
        }

        $sucesso = "Simulação de {$meses} mês(es) concluída.";
    }
}

$stmtListaFaturas = $pdo->prepare("SELECT * FROM FaturaCartao WHERE FKCartao = :cid AND FKUsuario = :uid ORDER BY MesReferencia ASC");
$stmtListaFaturas->execute([':cid' => $cartaoTesteId, ':uid' => $uid]);
$faturasAtuais = $stmtListaFaturas->fetchAll(PDO::FETCH_ASSOC);

if (isset($_GET['ok_reset'])) $sucesso = 'Simulação resetada — cartão de teste voltou a ficar vazio.';
if (isset($_GET['ok_dias']))  $sucesso = 'Dia de fechamento/vencimento do cartão de teste atualizado.';

$pageTitle = 'Simulador de Fatura — Admin Auralis';
require_once '../geral/header.php';
?>
<style>
.card-admin { background:var(--bg-card,#1a1d27); border:1px solid rgba(255,255,255,.08); border-radius:12px; }
</style>

<main class="container-fluid py-4" style="max-width:960px;">
    <div class="d-flex align-items-center gap-2 mb-1">
        <i class="bi bi-clock-history" style="color:var(--accent, #d4af37);font-size:1.3rem;"></i>
        <h4 class="fw-bold text-light mb-0">Simulador de Fatura</h4>
    </div>
    <p class="text-secondary mb-4" style="font-size:.85rem;">
        Roda vários meses de fechamento de cartão de crédito de uma vez, num cartão isolado
        (<code style="color:var(--accent,#d4af37);">🧪 Cartão de Simulação</code>) que nunca mistura com dado real.
        Não precisa esperar o calendário passar pra achar bug de fechamento.
    </p>

    <?php if ($erro): ?>
        <div class="alert border-0 rounded-3 mb-4 py-2 px-3" style="background:rgba(239,68,68,.1);color:#fca5a5;">
            <i class="bi bi-exclamation-triangle me-2"></i><?= htmlspecialchars($erro) ?>
        </div>
    <?php endif; ?>
    <?php if ($sucesso): ?>
        <div class="alert border-0 rounded-3 mb-4 py-2 px-3" style="background:rgba(34,197,94,.1);color:#86efac;">
            <i class="bi bi-check-circle me-2"></i><?= htmlspecialchars($sucesso) ?>
        </div>
    <?php endif; ?>

    <div class="card-admin p-4 mb-4">
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-3">
            <div>
                <h6 class="fw-semibold text-light mb-1">Cartão de teste</h6>
                <p class="text-secondary mb-0" style="font-size:.8rem;">
                    Fecha dia <strong class="text-light"><?= (int)$cartaoTeste['DiaFechamento'] ?></strong>,
                    vence dia <strong class="text-light"><?= (int)$cartaoTeste['DiaVencimento'] ?></strong>.
                    Testa dias como 29/30/31 pra ver se o clamping de fim de mês se comporta certo.
                </p>
            </div>
            <form method="POST" class="d-flex align-items-center gap-2 flex-wrap">
                <input type="hidden" name="action" value="salvar_dias">
                <?= csrfCampo() ?>
                <input type="number" name="dia_fechamento" min="1" max="31" value="<?= (int)$cartaoTeste['DiaFechamento'] ?>"
                    class="form-control form-control-sm" style="width:80px;" title="Dia de fechamento">
                <input type="number" name="dia_vencimento" min="1" max="31" value="<?= (int)$cartaoTeste['DiaVencimento'] ?>"
                    class="form-control form-control-sm" style="width:80px;" title="Dia de vencimento">
                <button type="submit" class="btn btn-sm rounded-pill px-3"
                    style="background:rgba(212,175,55,.15);color:#d4af37;border:1px solid rgba(212,175,55,.3);">
                    Salvar dias
                </button>
            </form>
        </div>

        <form method="POST" class="row g-3 align-items-end">
            <input type="hidden" name="action" value="simular">
            <?= csrfCampo() ?>
            <div class="col-sm-4">
                <label class="form-label text-secondary small fw-semibold">Data de início</label>
                <input type="date" name="data_inicio" class="form-control" value="<?= date('Y-m-d') ?>" required>
            </div>
            <div class="col-sm-3">
                <label class="form-label text-secondary small fw-semibold">Meses a simular</label>
                <input type="number" name="meses" class="form-control" value="8" min="1" max="36" required>
            </div>
            <div class="col-sm-3">
                <label class="form-label text-secondary small fw-semibold">Compra por mês (R$)</label>
                <input type="text" name="valor_compra" class="form-control" value="150,00" placeholder="0 = sem compra">
            </div>
            <div class="col-sm-2">
                <button type="submit" class="btn w-100 fw-bold rounded-pill"
                    style="background:linear-gradient(135deg,#d4af37,#AA8C2C);color:#000;border:none;">
                    <i class="bi bi-play-fill me-1"></i> Rodar
                </button>
            </div>
        </form>

        <form method="POST" class="mt-3" onsubmit="return confirm('Apaga todas as faturas e lançamentos do cartão de simulação. Confirma?');">
            <input type="hidden" name="action" value="resetar">
            <?= csrfCampo() ?>
            <button type="submit" class="btn btn-sm rounded-pill px-3"
                style="background:rgba(239,68,68,.08);color:#fca5a5;border:1px solid rgba(239,68,68,.2);">
                <i class="bi bi-arrow-counterclockwise me-1"></i> Resetar simulação
            </button>
        </form>
    </div>

    <?php if ($alertas): ?>
    <div class="card-admin p-4 mb-4" style="border:1px solid rgba(239,68,68,.35);">
        <h6 class="fw-semibold mb-2" style="color:#fca5a5;"><i class="bi bi-bug-fill me-2"></i>Alertas — isso é bug de verdade</h6>
        <ul class="mb-0 text-light" style="font-size:.85rem;">
            <?php foreach ($alertas as $a): ?>
                <li><?= htmlspecialchars($a) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <?php if ($relatorio): ?>
    <div class="card-admin p-4 mb-4">
        <h6 class="fw-semibold text-light mb-3">Passo a passo da simulação</h6>
        <div class="table-responsive">
        <table class="table table-dark table-sm align-middle mb-0">
            <thead><tr style="background:rgba(255,255,255,.04);"><th class="border-0">"Hoje" simulado</th><th class="border-0">O que aconteceu</th></tr></thead>
            <tbody>
                <?php foreach ($relatorio as $p): ?>
                <tr style="border-color:rgba(255,255,255,.05);">
                    <td class="border-0 text-secondary" style="white-space:nowrap;"><?= htmlspecialchars($p['mes']) ?></td>
                    <td class="border-0 text-light"><?= htmlspecialchars(implode(' · ', $p['eventos'])) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
    <?php endif; ?>

    <div class="card-admin p-4">
        <h6 class="fw-semibold text-light mb-3">Estado atual das faturas do cartão de teste</h6>
        <?php if (!$faturasAtuais): ?>
            <p class="text-secondary mb-0" style="font-size:.85rem;">Nenhuma fatura ainda — rode uma simulação acima.</p>
        <?php else: ?>
        <div class="table-responsive">
        <table class="table table-dark table-sm align-middle mb-0">
            <thead>
                <tr style="background:rgba(255,255,255,.04);">
                    <th class="border-0">Mês ref.</th><th class="border-0">Fechamento</th><th class="border-0">Vencimento</th>
                    <th class="border-0">Status</th><th class="border-0">Valor</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($faturasAtuais as $f): ?>
                <tr style="border-color:rgba(255,255,255,.05);">
                    <td class="border-0 text-light"><?= htmlspecialchars($f['MesReferencia']) ?></td>
                    <td class="border-0 text-secondary"><?= date('d/m/Y', strtotime($f['DataFechamento'])) ?></td>
                    <td class="border-0 text-secondary"><?= date('d/m/Y', strtotime($f['DataVencimento'])) ?></td>
                    <td class="border-0">
                        <span class="badge rounded-pill" style="font-size:.7rem;background:rgba(255,255,255,.08);color:#e5e7eb;"><?= htmlspecialchars($f['Status']) ?></span>
                    </td>
                    <td class="border-0 text-light">R$ <?= number_format((float)$f['ValorTotal'], 2, ',', '.') ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>
</main>

<?php require_once '../geral/footer.php'; ?>
