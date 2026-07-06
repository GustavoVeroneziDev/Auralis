<?php
session_start();
if (!isset($_SESSION['usuario_id'])) { header("Location: ../usuario/login.php"); exit; }

require_once '../config/conexao.php';
require_once '../config/funcoes.php';
require_once '../config/funcoes_cartao.php';

$uid       = $_SESSION['usuario_id'];
$cartaoId  = trim($_GET['cartao'] ?? '');
$erro      = null;
$sucesso   = null;
$pageTitle = 'Fatura — Auralis';

if (!$cartaoId) { header("Location: index.php"); exit; }

// Carrega o cartão
$stmtC = $pdo->prepare("SELECT * FROM CartaoCredito WHERE IDCartao = :id AND FKUsuario = :uid AND Ativo = 1");
$stmtC->execute([':id' => $cartaoId, ':uid' => $uid]);
$cartao = $stmtC->fetch(PDO::FETCH_ASSOC);
if (!$cartao) { header("Location: index.php"); exit; }

$pageTitle = 'Fatura ' . $cartao['Nome'] . ' — Auralis';

cartao_verificarFechamentos($pdo, $uid);

// ── POST HANDLER ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'fechar_fatura') {
        $faturaId    = trim($_POST['fatura_id'] ?? '');
        $valorRaw    = preg_replace('/[^\d,]/', '', trim($_POST['valor_manual'] ?? ''));
        $valorManual = $valorRaw !== '' ? (float) str_replace(',', '.', $valorRaw) : null;
        $stmt = $pdo->prepare("SELECT f.*, c.DiaVencimento, c.FKCarteiraDebito, c.Nome AS NomeCartao
                                FROM FaturaCartao f JOIN CartaoCredito c ON f.FKCartao = c.IDCartao
                                WHERE f.IDFatura = :id AND f.FKUsuario = :uid AND f.Status = 'aberta'");
        $stmt->execute([':id' => $faturaId, ':uid' => $uid]);
        $fat = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($fat) {
            cartao_fecharFatura($pdo, $fat, $uid, $valorManual);
            $sucesso = 'Fatura fechada com sucesso. O lembrete de pagamento foi criado na agenda.';
        }
    }

    if ($action === 'excluir_lancamento') {
        $lancId       = trim($_POST['lancamento_id'] ?? '');
        $scope        = trim($_POST['scope'] ?? 'so_esta');
        $grupo        = trim($_POST['grupo'] ?? '');
        $parcelaAtual = (int)($_POST['parcela_atual'] ?? 0);

        $stmtFid = $pdo->prepare("SELECT FKFatura FROM LancamentoCartao WHERE IDLancamento = :lid AND FKUsuario = :uid");
        $stmtFid->execute([':lid' => $lancId, ':uid' => $uid]);
        $faturaIdDel = $stmtFid->fetchColumn();

        if ($scope === 'futuras' && $grupo && $parcelaAtual > 0) {
            $pdo->prepare(
                "DELETE l FROM LancamentoCartao l
                 JOIN FaturaCartao f ON l.FKFatura = f.IDFatura
                 WHERE l.GrupoParcelamento = :grupo AND l.FKUsuario = :uid AND l.FKCartao = :cid
                   AND l.ParcelaAtual >= :parc AND f.Status = 'aberta'"
            )->execute([':grupo' => $grupo, ':uid' => $uid, ':cid' => $cartaoId, ':parc' => $parcelaAtual]);
            $stmtFats = $pdo->prepare("SELECT IDFatura FROM FaturaCartao WHERE FKCartao = :cid AND FKUsuario = :uid AND Status = 'aberta'");
            $stmtFats->execute([':cid' => $cartaoId, ':uid' => $uid]);
            foreach ($stmtFats->fetchAll(PDO::FETCH_COLUMN) as $fid) {
                cartao_sincronizarPreview($pdo, $fid, $uid, $cartao);
            }
            $sucesso = 'Parcelas removidas com sucesso.';
        } else {
            $pdo->prepare(
                "DELETE l FROM LancamentoCartao l
                 JOIN FaturaCartao f ON l.FKFatura = f.IDFatura
                 WHERE l.IDLancamento = :lid AND l.FKUsuario = :uid AND f.Status = 'aberta'"
            )->execute([':lid' => $lancId, ':uid' => $uid]);
            if ($faturaIdDel) {
                cartao_sincronizarPreview($pdo, $faturaIdDel, $uid, $cartao);
            }
            $sucesso = 'Lançamento removido.';
        }
    }

    if ($action === 'editar_lancamento') {
        $lancId       = trim($_POST['lancamento_id'] ?? '');
        $scope        = trim($_POST['scope'] ?? 'so_esta');
        $grupo        = trim($_POST['grupo'] ?? '');
        $parcelaAtual = (int)($_POST['parcela_atual'] ?? 0);
        $desc         = trim($_POST['descricao'] ?? '');
        $valorRaw     = preg_replace('/[^\d,]/', '', trim($_POST['valor'] ?? '0'));
        $valor        = (float) str_replace(',', '.', $valorRaw);
        $data         = trim($_POST['data_compra'] ?? '');
        $catId        = trim($_POST['categoria_id'] ?? '') ?: null;

        if ($lancId && $desc && $valor > 0 && $data) {
            $stmtFid = $pdo->prepare("SELECT FKFatura FROM LancamentoCartao WHERE IDLancamento = :lid AND FKUsuario = :uid");
            $stmtFid->execute([':lid' => $lancId, ':uid' => $uid]);
            $faturaIdEdit = $stmtFid->fetchColumn();

            if ($scope === 'futuras' && $grupo && $parcelaAtual > 0) {
                $pdo->prepare(
                    "UPDATE LancamentoCartao l
                     JOIN FaturaCartao f ON l.FKFatura = f.IDFatura
                     SET l.Descricao = :desc, l.Valor = :val, l.FKCategoria = :cat
                     WHERE l.GrupoParcelamento = :grupo AND l.FKUsuario = :uid AND l.FKCartao = :cid
                       AND l.ParcelaAtual >= :parc AND f.Status = 'aberta'"
                )->execute([':desc' => $desc, ':val' => $valor, ':cat' => $catId,
                            ':grupo' => $grupo, ':uid' => $uid, ':cid' => $cartaoId, ':parc' => $parcelaAtual]);
                $stmtFats = $pdo->prepare("SELECT IDFatura FROM FaturaCartao WHERE FKCartao = :cid AND FKUsuario = :uid AND Status = 'aberta'");
                $stmtFats->execute([':cid' => $cartaoId, ':uid' => $uid]);
                foreach ($stmtFats->fetchAll(PDO::FETCH_COLUMN) as $fid) {
                    cartao_sincronizarPreview($pdo, $fid, $uid, $cartao);
                }
                $sucesso = 'Parcelas futuras atualizadas.';
            } else {
                $pdo->prepare(
                    "UPDATE LancamentoCartao l
                     JOIN FaturaCartao f ON l.FKFatura = f.IDFatura
                     SET l.Descricao = :desc, l.Valor = :val, l.DataCompra = :data, l.FKCategoria = :cat
                     WHERE l.IDLancamento = :lid AND l.FKUsuario = :uid AND f.Status = 'aberta'"
                )->execute([':desc' => $desc, ':val' => $valor, ':data' => $data, ':cat' => $catId,
                            ':lid' => $lancId, ':uid' => $uid]);
                if ($faturaIdEdit) {
                    cartao_sincronizarPreview($pdo, $faturaIdEdit, $uid, $cartao);
                }
                $sucesso = 'Lançamento atualizado.';
            }
        }
    }

    if ($action === 'ajustar_valor') {
        $faturaId  = trim($_POST['fatura_id'] ?? '');
        $novoTotal = (float)str_replace(['.', ','], ['', '.'], trim($_POST['novo_total'] ?? ''));
        if ($faturaId && $novoTotal >= 0) {
            $pdo->prepare("UPDATE FaturaCartao SET ValorTotal = :v WHERE IDFatura = :id AND FKUsuario = :uid AND Status != 'aberta'")
                ->execute([':v' => $novoTotal, ':id' => $faturaId, ':uid' => $uid]);

            // Mantém sincronizado o lançamento que representa essa fatura na agenda —
            // sem isso, o valor mudava aqui mas continuava o antigo lá.
            $stmtReg = $pdo->prepare("SELECT FKRegistroPagamento FROM FaturaCartao WHERE IDFatura = :id AND FKUsuario = :uid");
            $stmtReg->execute([':id' => $faturaId, ':uid' => $uid]);
            $registroId = $stmtReg->fetchColumn();
            if ($registroId) {
                $pdo->prepare("UPDATE Registro SET Valor = :v WHERE IDRegistro = :rid AND FKUsuario = :uid")
                    ->execute([':v' => $novoTotal, ':rid' => $registroId, ':uid' => $uid]);
            }

            $sucesso = 'Valor da fatura ajustado.';
        }
    }

    if ($action === 'marcar_paga') {
        $faturaId = trim($_POST['fatura_id'] ?? '');
        // Repara antes de marcar como paga: se essa fatura fechou numa época em que o
        // cartão ainda não tinha carteira de pagamento definida, nunca existiu um lançamento
        // de cobrança vinculado — sem isso, marcar como paga não debitava nada do saldo.
        cartao_repararRegistroPagamento($pdo, $faturaId, $uid);
        $pdo->prepare("UPDATE FaturaCartao SET Status='paga' WHERE IDFatura=:id AND FKUsuario=:uid AND Status='fechada'")
            ->execute([':id' => $faturaId, ':uid' => $uid]);
        // Efetiva o Registro de pagamento vinculado
        try {
            $pdo->prepare(
                "UPDATE Registro r
                 JOIN FaturaCartao f ON f.FKRegistroPagamento = r.IDRegistro
                 SET r.StatusRegistro = 'efetivado'
                 WHERE f.IDFatura = :fid AND f.FKUsuario = :uid"
            )->execute([':fid' => $faturaId, ':uid' => $uid]);
        } catch (PDOException $e) {}
        $sucesso = 'Fatura marcada como paga.';
    }

    // Desfaz "marcar como paga" — mesmo padrão do toggle Efetivado/Pendente das transações
    // normais. Só volta pra "fechada" (não pra "aberta": lançamentos continuam travados,
    // quem quiser editá-los de novo usa "Reabrir" a partir de fechada).
    if ($action === 'desfazer_pagamento') {
        $faturaId = trim($_POST['fatura_id'] ?? '');
        $pdo->prepare("UPDATE FaturaCartao SET Status='fechada' WHERE IDFatura=:id AND FKUsuario=:uid AND Status='paga'")
            ->execute([':id' => $faturaId, ':uid' => $uid]);
        try {
            $pdo->prepare(
                "UPDATE Registro r
                 JOIN FaturaCartao f ON f.FKRegistroPagamento = r.IDRegistro
                 SET r.StatusRegistro = 'pendente'
                 WHERE f.IDFatura = :fid AND f.FKUsuario = :uid"
            )->execute([':fid' => $faturaId, ':uid' => $uid]);
        } catch (PDOException $e) {}
        $sucesso = 'Pagamento desfeito — a fatura voltou pra "fechada".';
    }

    // Corrige uma fatura já fechada/paga que ficou órfã (sem lançamento de cobrança
    // vinculado) — único jeito de resolver isso depois que a fatura já virou "paga", já
    // que os botões de marcar paga/reabrir somem nesse status.
    if ($action === 'reparar_pagamento') {
        $faturaId = trim($_POST['fatura_id'] ?? '');
        $reparou  = cartao_repararRegistroPagamento($pdo, $faturaId, $uid);
        $sucesso  = $reparou
            ? 'Lançamento da fatura recriado e vinculado — já deve aparecer na agenda e no saldo.'
            : null;
        $erro     = $reparou ? null : 'Não deu pra reparar. Confira se o cartão já tem uma carteira de pagamento definida em "Editar Cartão".';
    }

    if ($action === 'reabrir_fatura') {
        $faturaId = trim($_POST['fatura_id'] ?? '');
        $stmt = $pdo->prepare(
            "SELECT f.*, c.DiaVencimento, c.FKCarteiraDebito, c.Nome AS NomeCartao
             FROM FaturaCartao f JOIN CartaoCredito c ON f.FKCartao = c.IDCartao
             WHERE f.IDFatura = :id AND f.FKUsuario = :uid AND f.Status = 'fechada'"
        );
        $stmt->execute([':id' => $faturaId, ':uid' => $uid]);
        $fat = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($fat) {
            cartao_reabrirFatura($pdo, $fat, $uid, $cartao);
            $sucesso = 'Fatura reaberta. Você pode adicionar ou editar lançamentos normalmente.';
        }
    }
}

// Fatura aberta atual
$faturaAberta = null;
try {
    $faturaAberta = cartao_obterFaturaAberta($pdo, $cartaoId, $uid, $cartao);
} catch (Exception $e) {}

// Lançamentos da fatura aberta
$lancamentosAberta = [];
if ($faturaAberta) {
    $s = $pdo->prepare(
        "SELECT l.*, cat.NomeCategoria, cat.IconeCategoria
         FROM LancamentoCartao l
         LEFT JOIN Categoria cat ON l.FKCategoria = cat.IDCategoria
         WHERE l.FKFatura = :fid ORDER BY l.DataCompra DESC, l.CriadoEm DESC"
    );
    $s->execute([':fid' => $faturaAberta['IDFatura']]);
    $lancamentosAberta = $s->fetchAll(PDO::FETCH_ASSOC);
}

// Histórico de faturas fechadas/pagas (últimas 6)
$historico = [];
$sH = $pdo->prepare(
    "SELECT * FROM FaturaCartao WHERE FKCartao=:cid AND FKUsuario=:uid AND Status != 'aberta'
     ORDER BY DataVencimento DESC LIMIT 6"
);
$sH->execute([':cid' => $cartaoId, ':uid' => $uid]);
$historico = $sH->fetchAll(PDO::FETCH_ASSOC);

// Lançamentos por fatura histórica (carregados sob demanda via JS — aqui pré-carregamos todos)
$lancHistorico = [];
foreach ($historico as $fh) {
    $sL = $pdo->prepare(
        "SELECT l.*, cat.NomeCategoria FROM LancamentoCartao l
         LEFT JOIN Categoria cat ON l.FKCategoria = cat.IDCategoria
         WHERE l.FKFatura = :fid ORDER BY l.DataCompra DESC"
    );
    $sL->execute([':fid' => $fh['IDFatura']]);
    $lancHistorico[$fh['IDFatura']] = $sL->fetchAll(PDO::FETCH_ASSOC);
}

$totalAberta = array_sum(array_column($lancamentosAberta, 'Valor'));

$stmtCat = $pdo->prepare("SELECT IDCategoria, NomeCategoria, IconeCategoria FROM Categoria WHERE FKUsuario = :uid ORDER BY NomeCategoria ASC");
$stmtCat->execute([':uid' => $uid]);
$categorias = $stmtCat->fetchAll(PDO::FETCH_ASSOC);

$bandeiras = ['visa'=>'Visa','mastercard'=>'Mastercard','elo'=>'Elo','amex'=>'Amex','hipercard'=>'Hipercard','outro'=>'Outro'];
$cor = $cartao['Cor'];

require_once '../geral/header.php';
?>

<main class="container py-4 mt-2 flex-grow-1" style="padding-inline:var(--space-page-x);max-width:900px;">

    <!-- Cabeçalho -->
    <div class="d-flex align-items-center gap-3 mb-4 pb-3 border-bottom border-secondary-subtle flex-wrap">
        <a href="index.php" class="btn btn-outline-secondary btn-sm rounded-pill">
            <i class="bi bi-arrow-left me-1"></i> Cartões
        </a>
        <div class="d-flex align-items-center gap-3 flex-grow-1">
            <div class="rounded-3 d-flex align-items-center justify-content-center flex-shrink-0"
                style="width:44px;height:44px;background:<?= $cor ?>22;border:1.5px solid <?= $cor ?>55;">
                <i class="bi bi-credit-card-2-front" style="color:<?= $cor ?>;font-size:1.3rem;"></i>
            </div>
            <div>
                <h2 class="fw-bold text-light mb-0"><?= htmlspecialchars($cartao['Nome']) ?></h2>
                <p class="text-secondary small mb-0"><?= $bandeiras[$cartao['Bandeira']] ?> · fecha dia <?= $cartao['DiaFechamento'] ?> · vence dia <?= $cartao['DiaVencimento'] ?></p>
            </div>
        </div>
        <div class="d-flex align-items-center gap-2 flex-wrap no-print">
            <?php if ($faturaAberta ?? null): ?>
                <a href="/exportar.php?tipo=fatura&fatura=<?= urlencode($faturaAberta['IDFatura']) ?>"
                   class="btn btn-sm d-flex align-items-center gap-1 rounded-3"
                   style="background:var(--bg-card);border:1px solid var(--card-border-color);color:var(--text-main);font-size:0.78rem;"
                   title="Exportar fatura em CSV">
                    <i class="bi bi-filetype-csv" style="color:var(--accent);font-size:0.9rem;"></i>
                    <span class="d-none d-sm-inline">CSV</span>
                </a>
                <button onclick="window.print()"
                   class="btn btn-sm d-flex align-items-center gap-1 rounded-3"
                   style="background:var(--bg-card);border:1px solid var(--card-border-color);color:var(--text-main);font-size:0.78rem;"
                   title="Salvar fatura como PDF">
                    <i class="bi bi-printer" style="color:var(--accent);font-size:0.9rem;"></i>
                    <span class="d-none d-sm-inline">PDF</span>
                </button>
            <?php endif; ?>
            <a href="../nova_transacao.php?tipo=cartao&cartao_id=<?= urlencode($cartaoId) ?>&voltar=<?= urlencode('cartao_credito/fatura.php?cartao='.$cartaoId) ?>"
               class="btn fw-bold rounded-pill px-3"
               style="background:<?= $cor ?>22;color:<?= $cor ?>;border:1px solid <?= $cor ?>55;">
                <i class="bi bi-plus-lg me-1"></i> Lançar no cartão
            </a>
        </div>
    </div>

    <!-- Cabeçalho de impressão (só aparece no print) -->
    <div class="print-header" style="display:none;">
        <div class="print-header-logo">Auralis</div>
        <div class="print-header-meta">
            <?php if ($faturaAberta ?? null): ?>
                Fatura: <?= htmlspecialchars($cartao['Nome']) ?><br>
                Vencimento: <?= date('d/m/Y', strtotime($faturaAberta['DataVencimento'])) ?><br>
            <?php endif; ?>
            Gerado em <?= date('d/m/Y H:i') ?>
        </div>
    </div>

    <?php if ($erro): ?>
        <div class="alert rounded-3 mb-4" style="background:rgba(220,38,38,.15);border:1px solid rgba(220,38,38,.4);color:#fca5a5;"><?= htmlspecialchars($erro) ?></div>
    <?php endif; ?>
    <?php if ($sucesso): ?>
        <div class="alert rounded-3 mb-4" style="background:rgba(22,163,74,.15);border:1px solid rgba(22,163,74,.4);color:#86efac;"><?= htmlspecialchars($sucesso) ?></div>
    <?php endif; ?>

    <!-- FATURA ABERTA -->
    <?php if ($faturaAberta): ?>
    <div class="card rounded-4 shadow-sm mb-4" style="background:var(--bg-card);border:1.5px solid <?= $cor ?>44;">
        <div class="card-body p-4">
            <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
                <div>
                    <span class="badge rounded-pill px-3 py-1 fw-semibold mb-2" style="background:rgba(22,163,74,.15);color:#86efac;border:1px solid rgba(22,163,74,.3);font-size:0.7rem;">
                        <i class="bi bi-circle-fill me-1" style="font-size:0.4rem;vertical-align:middle;"></i> FATURA ABERTA
                    </span>
                    <p class="text-secondary small mb-0">
                        Fecha em <?= date('d/m/Y', strtotime($faturaAberta['DataFechamento'])) ?>
                        · Vence em <?= date('d/m/Y', strtotime($faturaAberta['DataVencimento'])) ?>
                    </p>
                </div>
                <div class="text-end">
                    <p class="text-secondary small mb-0">Total acumulado</p>
                    <p class="fw-bold text-light mb-0" style="font-size:1.8rem;">R$ <?= number_format($totalAberta, 2, ',', '.') ?></p>
                </div>
            </div>

            <!-- Lista de lançamentos -->
            <?php if (empty($lancamentosAberta)): ?>
                <p class="text-secondary text-center py-3 mb-0 small">Nenhum lançamento nesta fatura ainda.</p>
            <?php else: ?>
                <div class="table-responsive mb-3 rounded-3 overflow-hidden" style="border:1px solid rgba(255,255,255,.08);">
                    <table class="table table-dark table-hover mb-0" style="font-size:0.875rem;">
                        <thead>
                            <tr style="background:rgba(255,255,255,.04);">
                                <th class="fw-semibold text-secondary border-0 py-2">Descrição</th>
                                <th class="fw-semibold text-secondary border-0 py-2">Categoria</th>
                                <th class="fw-semibold text-secondary border-0 py-2">Data</th>
                                <th class="fw-semibold text-secondary border-0 py-2 text-end">Valor</th>
                                <th class="border-0 py-2"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($lancamentosAberta as $l): ?>
                            <tr style="border-color:rgba(255,255,255,.06);">
                                <td class="text-light py-2 border-0">
                                    <?= htmlspecialchars($l['Descricao']) ?>
                                    <?php if ($l['TotalParcelas']): ?>
                                        <span class="badge ms-1 rounded-pill" style="background:rgba(124,58,237,.2);color:#a78bfa;font-size:0.65rem;">
                                            <?= $l['ParcelaAtual'] ?>/<?= $l['TotalParcelas'] ?>x
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-secondary py-2 border-0">
                                    <?php if ($l['IconeCategoria']): ?>
                                        <i class="bi <?= htmlspecialchars($l['IconeCategoria']) ?> me-1"></i>
                                    <?php endif; ?>
                                    <?= htmlspecialchars($l['NomeCategoria'] ?? '—') ?>
                                </td>
                                <td class="text-secondary py-2 border-0"><?= date('d/m', strtotime($l['DataCompra'])) ?></td>
                                <td class="text-end fw-semibold py-2 border-0" style="color:#f87171;">
                                    R$ <?= number_format($l['Valor'], 2, ',', '.') ?>
                                </td>
                                <td class="py-2 border-0 text-end" style="white-space:nowrap;">
                                    <button class="btn btn-sm btn-link p-0 me-2 btn-editar-lanc" style="color:#a78bfa;" title="Editar"
                                        data-id="<?= $l['IDLancamento'] ?>"
                                        data-desc="<?= htmlspecialchars($l['Descricao']) ?>"
                                        data-valor="<?= number_format($l['Valor'], 2, ',', '.') ?>"
                                        data-data="<?= substr($l['DataCompra'], 0, 10) ?>"
                                        data-cat="<?= htmlspecialchars($l['FKCategoria'] ?? '') ?>"
                                        data-grupo="<?= htmlspecialchars($l['GrupoParcelamento'] ?? '') ?>"
                                        data-parcela-atual="<?= (int)($l['ParcelaAtual'] ?? 0) ?>"
                                        data-total-parcelas="<?= (int)($l['TotalParcelas'] ?? 0) ?>"
                                        data-is-parcelado="<?= ($l['TotalParcelas'] > 1) ? '1' : '0' ?>">
                                        <i class="bi bi-pencil" style="font-size:0.8rem;"></i>
                                    </button>
                                    <button class="btn btn-sm btn-link text-danger p-0 btn-excluir-lanc" title="Remover"
                                        data-id="<?= $l['IDLancamento'] ?>"
                                        data-desc="<?= htmlspecialchars($l['Descricao']) ?>"
                                        data-grupo="<?= htmlspecialchars($l['GrupoParcelamento'] ?? '') ?>"
                                        data-parcela-atual="<?= (int)($l['ParcelaAtual'] ?? 0) ?>"
                                        data-total-parcelas="<?= (int)($l['TotalParcelas'] ?? 0) ?>"
                                        data-is-parcelado="<?= ($l['TotalParcelas'] > 1) ? '1' : '0' ?>">
                                        <i class="bi bi-trash3" style="font-size:0.8rem;"></i>
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr style="background:rgba(255,255,255,.03);border-top:1px solid rgba(255,255,255,.1);">
                                <td colspan="3" class="fw-bold text-secondary py-2 border-0">Total</td>
                                <td class="fw-bold text-end py-2 border-0" style="color:#f87171;">
                                    R$ <?= number_format($totalAberta, 2, ',', '.') ?>
                                </td>
                                <td class="border-0"></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            <?php endif; ?>

            <!-- Botão de fechar fatura -->
            <?php if ($totalAberta > 0): ?>
            <button class="btn btn-sm rounded-pill fw-semibold px-4"
                style="background:rgba(239,68,68,.15);color:#f87171;border:1px solid rgba(239,68,68,.4);"
                onclick="abrirModalFecharFatura('<?= $faturaAberta['IDFatura'] ?>','<?= number_format($totalAberta, 2, ',', '.') ?>')">
                <i class="bi bi-lock-fill me-1"></i> Fechar fatura manualmente
            </button>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- HISTÓRICO DE FATURAS -->
    <?php if (!empty($historico)): ?>
    <h5 class="fw-bold text-light mb-3">Histórico de faturas</h5>
    <div class="d-flex flex-column gap-3">
        <?php foreach ($historico as $fh):
            $isPaga       = $fh['Status'] === 'paga';
            $lancsFh      = $lancHistorico[$fh['IDFatura']] ?? [];
            $vencTxt      = strtotime($fh['DataVencimento']) < strtotime('today') ? 'Venceu' : 'Vence';
            $fechouLabel  = !empty($fh['DataFechamento']) ? 'Fechou em ' . date('d/m/Y', strtotime($fh['DataFechamento'])) . ' · ' : '';
        ?>
        <div class="card rounded-4" style="background:var(--bg-card);border:1px solid rgba(255,255,255,.08);">
            <div class="card-body p-0">
                <!-- Header clicável -->
                <div class="d-flex align-items-center justify-content-between px-4 py-3 gap-2 flex-wrap"
                    style="cursor:pointer;" onclick="toggleFatura('fh-<?= $fh['IDFatura'] ?>')">
                    <div class="d-flex align-items-center gap-3">
                        <?php if ($isPaga): ?>
                            <span class="badge rounded-pill" style="background:rgba(22,163,74,.15);color:#86efac;border:1px solid rgba(22,163,74,.3);font-size:0.7rem;">Paga</span>
                        <?php else: ?>
                            <span class="badge rounded-pill" style="background:rgba(245,158,11,.15);color:#fbbf24;border:1px solid rgba(245,158,11,.3);font-size:0.7rem;">Fechada</span>
                        <?php endif; ?>
                        <div>
                            <p class="text-light fw-semibold mb-0 small">
                                Fatura <?= date('M/Y', strtotime($fh['MesReferencia'] . '-01')) ?>
                            </p>
                            <p class="text-secondary mb-0" style="font-size:0.72rem;">
                                <?= $fechouLabel ?><?= $vencTxt ?> em <?= date('d/m/Y', strtotime($fh['DataVencimento'])) ?>
                            </p>
                        </div>
                    </div>
                    <div class="d-flex align-items-center gap-3">
                        <div class="d-flex align-items-center gap-1" onclick="event.stopPropagation()">
                            <span class="fw-bold" style="color:#f87171;">R$ <?= number_format($fh['ValorTotal'], 2, ',', '.') ?></span>
                            <button type="button" title="Ajustar valor total"
                                class="btn btn-sm p-1 border-0 text-secondary"
                                style="line-height:1;background:transparent;"
                                onclick="abrirAjuste('<?= $fh['IDFatura'] ?>', <?= (float)$fh['ValorTotal'] ?>)">
                                <i class="bi bi-pencil-square" style="font-size:0.75rem;"></i>
                            </button>
                        </div>
                        <?php if (!$isPaga): ?>
                            <form method="POST" onclick="event.stopPropagation()">
                                <input type="hidden" name="action" value="marcar_paga">
                                <input type="hidden" name="fatura_id" value="<?= $fh['IDFatura'] ?>">
                                <button class="btn btn-sm rounded-pill fw-semibold px-3"
                                    style="background:rgba(22,163,74,.15);color:#86efac;border:1px solid rgba(22,163,74,.3);font-size:0.75rem;">
                                    Marcar como paga
                                </button>
                            </form>
                            <button type="button" title="Reabrir fatura"
                                onclick="event.stopPropagation(); abrirModalReabrir('<?= $fh['IDFatura'] ?>')"
                                class="btn btn-sm rounded-pill px-3"
                                style="background:rgba(148,163,184,.08);color:#94a3b8;border:1px solid rgba(148,163,184,.2);font-size:0.75rem;">
                                <i class="bi bi-arrow-counterclockwise me-1"></i> Reabrir
                            </button>
                        <?php else: ?>
                            <!-- Mesmo padrão de "Desfazer" que já existe pra transação normal (Efetivado ⇄
                                 Pendente no dashboard) — marcar como paga não pode ter menos fricção de
                                 reverter do que excluir um lançamento avulso. -->
                            <form method="POST" onclick="event.stopPropagation()">
                                <input type="hidden" name="action" value="desfazer_pagamento">
                                <input type="hidden" name="fatura_id" value="<?= $fh['IDFatura'] ?>">
                                <button class="btn btn-sm rounded-pill fw-semibold px-3" title="Volta a fatura pra 'fechada' e o lançamento pra pendente"
                                    style="background:rgba(148,163,184,.08);color:#94a3b8;border:1px solid rgba(148,163,184,.2);font-size:0.75rem;">
                                    <i class="bi bi-arrow-counterclockwise me-1"></i> Desfazer
                                </button>
                            </form>
                        <?php endif; ?>
                        <i class="bi bi-chevron-down text-secondary" id="ico-fh-<?= $fh['IDFatura'] ?>"></i>
                    </div>
                </div>

                <?php if (empty($fh['FKRegistroPagamento']) && (float)$fh['ValorTotal'] > 0): ?>
                    <div class="d-flex align-items-center justify-content-between gap-2 flex-wrap px-4 py-2"
                        style="background:rgba(245,158,11,.08);border-top:1px solid rgba(245,158,11,.2);">
                        <span class="text-secondary" style="font-size:0.75rem;">
                            <i class="bi bi-exclamation-triangle-fill me-1" style="color:#f59e0b;"></i>
                            Essa fatura não tem um lançamento de cobrança vinculado — por isso não aparece na agenda nem debita do saldo.
                            <?php if (empty($cartao['FKCarteiraDebito'])): ?>
                                Defina uma carteira de pagamento em <strong class="text-light">Editar Cartão</strong> primeiro.
                            <?php endif; ?>
                        </span>
                        <?php if (!empty($cartao['FKCarteiraDebito'])): ?>
                            <form method="POST" onclick="event.stopPropagation()">
                                <input type="hidden" name="action" value="reparar_pagamento">
                                <input type="hidden" name="fatura_id" value="<?= $fh['IDFatura'] ?>">
                                <button class="btn btn-sm rounded-pill fw-semibold px-3 flex-shrink-0"
                                    style="background:rgba(245,158,11,.15);color:#fbbf24;border:1px solid rgba(245,158,11,.35);font-size:0.75rem;">
                                    <i class="bi bi-tools me-1"></i> Corrigir agora
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <!-- Lançamentos (colapsável) -->
                <div id="fh-<?= $fh['IDFatura'] ?>" style="display:none;">
                    <div class="border-top border-secondary-subtle px-4 py-3">
                        <?php if (empty($lancsFh)): ?>
                            <p class="text-secondary small mb-0 text-center py-2">Nenhum lançamento registrado.</p>
                        <?php else: ?>
                            <div class="table-responsive rounded-3 overflow-hidden" style="border:1px solid rgba(255,255,255,.07);">
                                <table class="table table-dark mb-0" style="font-size:0.82rem;">
                                    <tbody>
                                        <?php foreach ($lancsFh as $l): ?>
                                        <tr style="border-color:rgba(255,255,255,.06);">
                                            <td class="text-light py-2 border-0">
                                                <?= htmlspecialchars($l['Descricao']) ?>
                                                <?php if ($l['TotalParcelas']): ?>
                                                    <span class="badge ms-1 rounded-pill" style="background:rgba(124,58,237,.2);color:#a78bfa;font-size:0.6rem;">
                                                        <?= $l['ParcelaAtual'] ?>/<?= $l['TotalParcelas'] ?>x
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-secondary py-2 border-0 small"><?= htmlspecialchars($l['NomeCategoria'] ?? '—') ?></td>
                                            <td class="text-secondary py-2 border-0 small"><?= date('d/m', strtotime($l['DataCompra'])) ?></td>
                                            <td class="text-end fw-semibold py-2 border-0" style="color:#f87171;">
                                                R$ <?= number_format($l['Valor'], 2, ',', '.') ?>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</main>

<!-- ── Modal: Reabrir fatura ─────────────────────────────────────────────── -->
<div class="modal fade" id="modalReabrirFatura" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content border-secondary-subtle shadow-lg" style="background:var(--bg-card);">
            <form method="POST">
                <input type="hidden" name="action" value="reabrir_fatura">
                <input type="hidden" name="fatura_id" id="reabrirFaturaId">
                <div class="modal-header border-secondary-subtle px-4 py-3">
                    <h6 class="modal-title fw-bold text-light mb-0">Reabrir fatura</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body px-4 py-3">
                    <p class="text-secondary mb-2" style="font-size:0.83rem;">
                        A fatura voltará para <strong class="text-light">aberta</strong> e o lembrete de pagamento pendente será removido da agenda.
                    </p>
                    <p class="text-secondary mb-0" style="font-size:0.78rem;">
                        Lançamentos e faturas posteriores não são afetados.
                    </p>
                </div>
                <div class="modal-footer border-secondary-subtle d-flex justify-content-between p-3">
                    <button type="button" class="btn btn-sm btn-link text-secondary text-decoration-none" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-sm fw-bold rounded-pill px-4"
                        style="background:rgba(148,163,184,.12);color:#94a3b8;border:1px solid rgba(148,163,184,.3);">
                        <i class="bi bi-arrow-counterclockwise me-1"></i> Reabrir
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ── Modal: Ajustar valor de fatura ──────────────────────────────────── -->
<div class="modal fade" id="modalAjustarValor" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content border-secondary-subtle shadow-lg" style="background:var(--bg-card);">
            <form method="POST">
                <input type="hidden" name="action" value="ajustar_valor">
                <input type="hidden" name="fatura_id" id="ajusteFaturaId">
                <div class="modal-header border-secondary-subtle px-4 py-3">
                    <h6 class="modal-title fw-bold text-light mb-0">Ajustar valor da fatura</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body px-4 py-3">
                    <p class="text-secondary mb-3" style="font-size:0.83rem;">
                        Informe o valor real cobrado pelo cartão. Útil para incluir juros, multas ou discrepâncias.
                    </p>
                    <label class="form-label text-secondary small fw-semibold mb-1">Novo valor total</label>
                    <div class="input-group">
                        <span class="input-group-text border-secondary-subtle text-secondary" style="background:var(--bg-hover);">R$</span>
                        <input type="text" name="novo_total" id="ajusteValorInput"
                            class="form-control border-secondary-subtle shadow-none"
                            style="background:var(--bg-card);color:var(--text-main);"
                            placeholder="0,00" required>
                    </div>
                </div>
                <div class="modal-footer border-secondary-subtle px-4 py-3 gap-2">
                    <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill px-3" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-sm rounded-pill px-3 fw-semibold"
                        style="background:rgba(212,175,55,.15);color:#d4af37;border:1px solid rgba(212,175,55,.35);">
                        Salvar ajuste
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<script>
function abrirAjuste(faturaId, valorAtual) {
    document.getElementById('ajusteFaturaId').value = faturaId;
    setCurrencyInputValue(document.getElementById('ajusteValorInput'), valorAtual);
    new bootstrap.Modal(document.getElementById('modalAjustarValor')).show();
}
</script>

<!-- ── Modal: Excluir lançamento ───────────────────────────────────────── -->
<div class="modal fade" id="modalExcluirLanc" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-secondary-subtle shadow-lg" style="background:var(--bg-card);">
            <div class="modal-header border-secondary-subtle px-4 py-3">
                <h6 class="modal-title fw-bold text-light mb-0">Remover lançamento</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body px-4 py-3">
                <p class="text-secondary mb-0">Deseja remover <strong class="text-light" id="excluirLancNome"></strong>?</p>
                <div id="excluirEscopoWrap" class="d-none mt-3 p-3 rounded-3" style="background:rgba(129,140,248,.06);border:1px solid rgba(129,140,248,.2);">
                    <p class="text-secondary mb-2" style="font-size:0.82rem;">Esta é uma compra parcelada. O que deseja remover?</p>
                    <div class="d-flex flex-column gap-2">
                        <label class="d-flex align-items-center gap-2 text-light" style="cursor:pointer;font-size:0.875rem;">
                            <input type="radio" name="scope_excluir" value="so_esta" checked style="accent-color:#818cf8;"
                                onchange="document.getElementById('excluirLancScope').value=this.value">
                            Somente esta parcela (<span id="excluirParcelaLabel">—</span>)
                        </label>
                        <label class="d-flex align-items-center gap-2 text-light" style="cursor:pointer;font-size:0.875rem;">
                            <input type="radio" name="scope_excluir" value="futuras" style="accent-color:#818cf8;"
                                onchange="document.getElementById('excluirLancScope').value=this.value">
                            Esta e as parcelas futuras em aberto
                        </label>
                    </div>
                </div>
                <p class="text-secondary small mt-2 mb-0">Esta ação não pode ser desfeita.</p>
            </div>
            <div class="modal-footer border-secondary-subtle d-flex justify-content-between p-3">
                <button type="button" class="btn btn-sm btn-link text-secondary text-decoration-none" data-bs-dismiss="modal">Cancelar</button>
                <form method="POST">
                    <input type="hidden" name="action" value="excluir_lancamento">
                    <input type="hidden" name="lancamento_id" id="excluirLancId">
                    <input type="hidden" name="scope" id="excluirLancScope" value="so_esta">
                    <input type="hidden" name="grupo" id="excluirLancGrupo">
                    <input type="hidden" name="parcela_atual" id="excluirLancParcelaAtual">
                    <button type="submit" class="btn btn-sm fw-bold rounded-pill px-4"
                        style="background:rgba(239,68,68,.2);color:#f87171;border:1px solid rgba(239,68,68,.4);">
                        <i class="bi bi-trash3 me-1"></i> Remover
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- ── Modal: Editar lançamento ────────────────────────────────────────── -->
<div class="modal fade" id="modalEditarLanc" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-secondary-subtle shadow-lg" style="background:var(--bg-card);">
            <form method="POST">
                <input type="hidden" name="action" value="editar_lancamento">
                <input type="hidden" name="lancamento_id" id="editarLancId">
                <input type="hidden" name="scope" id="editarLancScope" value="so_esta">
                <input type="hidden" name="grupo" id="editarLancGrupo">
                <input type="hidden" name="parcela_atual" id="editarLancParcelaAtual">
                <div class="modal-header border-secondary-subtle px-4 py-3">
                    <h6 class="modal-title fw-bold text-light mb-0">Editar lançamento</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body px-4 py-3 d-flex flex-column gap-3">
                    <div>
                        <label class="form-label text-secondary small">Descrição</label>
                        <input type="text" name="descricao" id="editarLancDesc"
                            class="form-control bg-transparent text-light border-secondary"
                            required maxlength="120">
                    </div>
                    <div>
                        <label class="form-label text-secondary small">Valor (R$)</label>
                        <input type="text" name="valor" id="editarLancValor"
                            class="form-control bg-transparent text-light border-secondary"
                            required inputmode="numeric" oninput="mascaraMoeda(this)" placeholder="R$ 0,00" autocomplete="off">
                    </div>
                    <div id="editarDataWrap">
                        <label class="form-label text-secondary small">Data da compra</label>
                        <input type="date" name="data_compra" id="editarLancData"
                            class="form-control bg-transparent text-light border-secondary"
                            required>
                        <p id="editarDataNote" class="d-none text-secondary mb-0 mt-1" style="font-size:0.75rem;">
                            <i class="bi bi-info-circle me-1"></i>A data de cada parcela não será alterada.
                        </p>
                    </div>
                    <div>
                        <label class="form-label text-secondary small">Categoria</label>
                        <select name="categoria_id" id="editarLancCat"
                            class="form-select border-secondary">
                            <option value="">— Sem categoria —</option>
                            <?php foreach ($categorias as $cat): ?>
                            <option value="<?= $cat['IDCategoria'] ?>"><?= htmlspecialchars($cat['NomeCategoria']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div id="editarEscopoWrap" class="d-none p-3 rounded-3" style="background:rgba(129,140,248,.06);border:1px solid rgba(129,140,248,.2);">
                        <p class="text-secondary mb-2" style="font-size:0.82rem;">Esta é uma compra parcelada. O que deseja editar?</p>
                        <div class="d-flex flex-column gap-2">
                            <label class="d-flex align-items-center gap-2 text-light" style="cursor:pointer;font-size:0.875rem;">
                                <input type="radio" name="scope_editar" value="so_esta" checked style="accent-color:#818cf8;"
                                    onchange="onScopeEditarChange(this.value)">
                                Somente esta parcela (<span id="editarParcelaLabel">—</span>)
                            </label>
                            <label class="d-flex align-items-center gap-2 text-light" style="cursor:pointer;font-size:0.875rem;">
                                <input type="radio" name="scope_editar" value="futuras" style="accent-color:#818cf8;"
                                    onchange="onScopeEditarChange(this.value)">
                                Esta e as parcelas futuras em aberto
                            </label>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-secondary-subtle d-flex justify-content-between p-3">
                    <button type="button" class="btn btn-sm btn-link text-secondary text-decoration-none" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-sm fw-bold rounded-pill px-4"
                        style="background:linear-gradient(135deg,#7c3aed,#a78bfa);color:#fff;border:none;">
                        <i class="bi bi-check-lg me-1"></i> Salvar
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ── Modal: Fechar fatura ────────────────────────────────────────────── -->
<div class="modal fade" id="modalFecharFatura" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-secondary-subtle shadow-lg" style="background:var(--bg-card);">
            <form method="POST">
                <input type="hidden" name="action" value="fechar_fatura">
                <input type="hidden" name="fatura_id" id="fecharFaturaId">
                <div class="modal-header border-secondary-subtle px-4 py-3">
                    <h6 class="modal-title fw-bold text-light mb-0">Fechar fatura</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body px-4 py-3">
                    <p class="text-secondary mb-3" style="font-size:0.83rem;">
                        Lançamentos somam <strong class="text-light" id="fecharFaturaTotal"></strong>. Confirme ou ajuste o valor real cobrado pelo cartão.
                    </p>
                    <label class="form-label text-secondary small fw-semibold mb-1">Valor de fechamento</label>
                    <div class="input-group">
                        <span class="input-group-text border-secondary-subtle text-secondary" style="background:var(--bg-hover);">R$</span>
                        <input type="text" name="valor_manual" id="fecharFaturaValor"
                            class="form-control border-secondary-subtle shadow-none"
                            style="background:var(--bg-card);color:var(--text-main);font-variant-numeric:tabular-nums;letter-spacing:.02em;"
                            placeholder="R$ 0,00" required autocomplete="off"
                            inputmode="numeric" oninput="mascaraMoeda(this)">
                    </div>
                    <p class="text-secondary mt-2 mb-0" style="font-size:0.75rem;">O valor será congelado e um lembrete de pagamento será criado na agenda.</p>
                </div>
                <div class="modal-footer border-secondary-subtle d-flex justify-content-between p-3">
                    <button type="button" class="btn btn-sm btn-link text-secondary text-decoration-none" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-sm fw-bold rounded-pill px-4"
                        style="background:rgba(239,68,68,.15);color:#f87171;border:1px solid rgba(239,68,68,.4);">
                        <i class="bi bi-lock-fill me-1"></i> Fechar fatura
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function bsModal(id) {
    const el = document.getElementById(id);
    return bootstrap.Modal.getInstance(el) || new bootstrap.Modal(el);
}

function toggleFatura(id) {
    const el  = document.getElementById(id);
    const ico = document.getElementById('ico-' + id);
    const vis = el.style.display !== 'none';
    el.style.display = vis ? 'none' : 'block';
    ico.className    = vis ? 'bi bi-chevron-down text-secondary' : 'bi bi-chevron-up text-secondary';
}

function onScopeEditarChange(val) {
    document.getElementById('editarLancScope').value = val;
    const dataWrap = document.getElementById('editarDataWrap');
    const dataNote = document.getElementById('editarDataNote');
    if (val === 'futuras') {
        dataWrap.style.opacity = '0.45';
        dataNote.classList.remove('d-none');
    } else {
        dataWrap.style.opacity = '';
        dataNote.classList.add('d-none');
    }
}

document.addEventListener('click', function(e) {
    const btnEdit = e.target.closest('.btn-editar-lanc');
    if (btnEdit) {
        const d = btnEdit.dataset;
        document.getElementById('editarLancId').value          = d.id;
        document.getElementById('editarLancDesc').value        = d.desc;
        var lancValorInp = document.getElementById('editarLancValor');
        lancValorInp.value = d.valor;
        mascaraMoeda(lancValorInp);
        document.getElementById('editarLancData').value        = d.data;
        document.getElementById('editarLancCat').value         = d.cat || '';
        document.getElementById('editarLancGrupo').value       = d.grupo || '';
        document.getElementById('editarLancParcelaAtual').value = d.parcelaAtual || '';
        document.getElementById('editarLancScope').value       = 'so_esta';
        const temParcela = d.isParcelado === '1';
        const escopoWrap = document.getElementById('editarEscopoWrap');
        if (temParcela) {
            escopoWrap.classList.remove('d-none');
            document.getElementById('editarParcelaLabel').textContent = (d.parcelaAtual || '?') + '/' + (d.totalParcelas || '?');
            document.querySelector('input[name="scope_editar"][value="so_esta"]').checked = true;
        } else {
            escopoWrap.classList.add('d-none');
        }
        onScopeEditarChange('so_esta');
        bsModal('modalEditarLanc').show();
        return;
    }
    const btnDel = e.target.closest('.btn-excluir-lanc');
    if (btnDel) {
        const d = btnDel.dataset;
        document.getElementById('excluirLancId').value           = d.id;
        document.getElementById('excluirLancNome').textContent   = d.desc;
        document.getElementById('excluirLancGrupo').value        = d.grupo || '';
        document.getElementById('excluirLancParcelaAtual').value = d.parcelaAtual || '';
        document.getElementById('excluirLancScope').value        = 'so_esta';
        const temParcelaExcluir = d.isParcelado === '1';
        const excluirWrap = document.getElementById('excluirEscopoWrap');
        if (temParcelaExcluir) {
            excluirWrap.classList.remove('d-none');
            document.getElementById('excluirParcelaLabel').textContent = (d.parcelaAtual || '?') + '/' + (d.totalParcelas || '?');
            document.querySelector('input[name="scope_excluir"][value="so_esta"]').checked = true;
        } else {
            excluirWrap.classList.add('d-none');
        }
        bsModal('modalExcluirLanc').show();
        return;
    }
});

// ── ATM-style currency input ──────────────────────────────────────────────
function setupCurrencyInput(el) {
    if (!el || el._currencyReady) return;
    el._currencyReady = true;
    el._digits = '';
    el.value = '0,00';
    el.addEventListener('keydown', function(e) {
        if (e.key >= '0' && e.key <= '9') {
            e.preventDefault();
            if (el._digits.length >= 11) return;
            el._digits += e.key;
            _fmtCurrency(el);
        } else if (e.key === 'Backspace') {
            e.preventDefault();
            el._digits = el._digits.slice(0, -1);
            _fmtCurrency(el);
        } else if (e.key !== 'Tab' && e.key !== 'Enter') {
            e.preventDefault();
        }
    });
    el.addEventListener('click', function() { el.setSelectionRange(el.value.length, el.value.length); });
    el.addEventListener('focus', function() { el.setSelectionRange(el.value.length, el.value.length); });
}
function _fmtCurrency(el) {
    var d = el._digits || '';
    var padded = d.padStart(3, '0');
    var cents = padded.slice(-2);
    var reais = padded.slice(0, -2).replace(/^0+/, '') || '0';
    reais = reais.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    el.value = reais + ',' + cents;
}
function setCurrencyInputValue(el, floatVal) {
    if (!el) return;
    if (!el._currencyReady) setupCurrencyInput(el);
    var cents = Math.round(Math.abs(floatVal) * 100);
    el._digits = cents === 0 ? '' : String(cents);
    _fmtCurrency(el);
}

function abrirModalReabrir(faturaId) {
    document.getElementById('reabrirFaturaId').value = faturaId;
    bsModal('modalReabrirFatura').show();
}

function abrirModalFecharFatura(faturaId, totalStr) {
    document.getElementById('fecharFaturaId').value          = faturaId;
    document.getElementById('fecharFaturaTotal').textContent = 'R$ ' + totalStr;
    var floatVal = parseFloat(totalStr.replace(/\./g, '').replace(',', '.')) || 0;
    setCurrencyInputValue(document.getElementById('fecharFaturaValor'), floatVal);
    bsModal('modalFecharFatura').show();
}

document.addEventListener('DOMContentLoaded', function() {
    setupCurrencyInput(document.getElementById('fecharFaturaValor'));
    setupCurrencyInput(document.getElementById('ajusteValorInput'));
});
</script>

<?php require_once '../geral/footer.php'; ?>
