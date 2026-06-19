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
        $faturaId = trim($_POST['fatura_id'] ?? '');
        $stmt = $pdo->prepare("SELECT f.*, c.DiaVencimento, c.FKCarteiraDebito, c.Nome AS NomeCartao
                                FROM FaturaCartao f JOIN CartaoCredito c ON f.FKCartao = c.IDCartao
                                WHERE f.IDFatura = :id AND f.FKUsuario = :uid AND f.Status = 'aberta'");
        $stmt->execute([':id' => $faturaId, ':uid' => $uid]);
        $fat = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($fat) {
            cartao_fecharFatura($pdo, $fat, $uid);
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
        $valorRaw     = str_replace(['.', ','], ['', '.'], trim($_POST['valor'] ?? '0'));
        $valor        = (float)$valorRaw;
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

    if ($action === 'marcar_paga') {
        $faturaId = trim($_POST['fatura_id'] ?? '');
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
        <a href="../nova_transacao.php?tipo=cartao&cartao_id=<?= urlencode($cartaoId) ?>&voltar=<?= urlencode('cartao_credito/fatura.php?cartao='.$cartaoId) ?>"
           class="btn fw-bold rounded-pill px-3"
           style="background:<?= $cor ?>22;color:<?= $cor ?>;border:1px solid <?= $cor ?>55;">
            <i class="bi bi-plus-lg me-1"></i> Lançar no cartão
        </a>
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
                <div class="table-responsive mb-3">
                    <table class="table table-dark table-hover rounded-3 overflow-hidden mb-0" style="font-size:0.875rem;">
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
            $isPaga    = $fh['Status'] === 'paga';
            $lancsFh   = $lancHistorico[$fh['IDFatura']] ?? [];
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
                                Venceu em <?= date('d/m/Y', strtotime($fh['DataVencimento'])) ?>
                            </p>
                        </div>
                    </div>
                    <div class="d-flex align-items-center gap-3">
                        <span class="fw-bold" style="color:#f87171;">R$ <?= number_format($fh['ValorTotal'], 2, ',', '.') ?></span>
                        <?php if (!$isPaga): ?>
                            <form method="POST" onclick="event.stopPropagation()">
                                <input type="hidden" name="action" value="marcar_paga">
                                <input type="hidden" name="fatura_id" value="<?= $fh['IDFatura'] ?>">
                                <button class="btn btn-sm rounded-pill fw-semibold px-3"
                                    style="background:rgba(22,163,74,.15);color:#86efac;border:1px solid rgba(22,163,74,.3);font-size:0.75rem;">
                                    Marcar como paga
                                </button>
                            </form>
                        <?php endif; ?>
                        <i class="bi bi-chevron-down text-secondary" id="ico-fh-<?= $fh['IDFatura'] ?>"></i>
                    </div>
                </div>

                <!-- Lançamentos (colapsável) -->
                <div id="fh-<?= $fh['IDFatura'] ?>" style="display:none;">
                    <div class="border-top border-secondary-subtle px-4 py-3">
                        <?php if (empty($lancsFh)): ?>
                            <p class="text-secondary small mb-0 text-center py-2">Nenhum lançamento registrado.</p>
                        <?php else: ?>
                            <div class="table-responsive">
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
                            required inputmode="decimal">
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
            <div class="modal-header border-secondary-subtle px-4 py-3">
                <h6 class="modal-title fw-bold text-light mb-0">Fechar fatura</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body px-4 py-3">
                <p class="text-secondary mb-1">Deseja fechar a fatura de <strong class="text-light" id="fecharFaturaTotal"></strong>?</p>
                <p class="text-secondary small mb-0">O valor será congelado e um lembrete de pagamento será criado na agenda.</p>
            </div>
            <div class="modal-footer border-secondary-subtle d-flex justify-content-between p-3">
                <button type="button" class="btn btn-sm btn-link text-secondary text-decoration-none" data-bs-dismiss="modal">Cancelar</button>
                <form method="POST">
                    <input type="hidden" name="action" value="fechar_fatura">
                    <input type="hidden" name="fatura_id" id="fecharFaturaId">
                    <button type="submit" class="btn btn-sm fw-bold rounded-pill px-4"
                        style="background:rgba(239,68,68,.15);color:#f87171;border:1px solid rgba(239,68,68,.4);">
                        <i class="bi bi-lock-fill me-1"></i> Fechar fatura
                    </button>
                </form>
            </div>
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
        document.getElementById('editarLancValor').value       = d.valor;
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

function abrirModalFecharFatura(faturaId, total) {
    document.getElementById('fecharFaturaId').value          = faturaId;
    document.getElementById('fecharFaturaTotal').textContent = 'R$ ' + total;
    bsModal('modalFecharFatura').show();
}
</script>

<?php require_once '../geral/footer.php'; ?>
