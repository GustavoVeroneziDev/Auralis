<?php
session_start();
if (!isset($_SESSION['usuario_id'])) { header("Location: /usuario/login.php"); exit; }
require_once '../config/conexao.php';
require_once '../config/funcoes.php';

$uid = $_SESSION['usuario_id'];

// Verifica se este usuário é revendedor ativo
$stmtRev = $pdo->prepare(
    "SELECT r.*, u.Nome, u.Email, u.CodigoIndicacao
     FROM Revendedor r JOIN Usuario u ON u.IDUsuario = r.FKUsuario
     WHERE r.FKUsuario = :uid AND r.Ativo = 1"
);
$stmtRev->execute([':uid' => $uid]);
$revendedor = $stmtRev->fetch(PDO::FETCH_ASSOC);

if (!$revendedor) {
    header("Location: /dashboard.php?erro=sem_permissao"); exit;
}

// Comissões
$stmtCom = $pdo->prepare(
    "SELECT c.*, u.Nome as NomeComprador, u.Email as EmailComprador
     FROM ComissaoRevendedor c
     JOIN Usuario u ON u.IDUsuario = c.FKUsuarioComprador
     WHERE c.FKRevendedor = :rid
     ORDER BY c.CriadaEm DESC"
);
$stmtCom->execute([':rid' => $revendedor['IDRevendedor']]);
$comissoes = $stmtCom->fetchAll(PDO::FETCH_ASSOC);

$saldoPendente = array_sum(array_column(array_filter($comissoes, fn($c) => $c['Status'] === 'pendente'), 'ValorComissao'));
$totalPago     = array_sum(array_column(array_filter($comissoes, fn($c) => $c['Status'] === 'paga'),    'ValorComissao'));
$totalVendas   = count($comissoes);

// Protocolo e domínio para o link de indicação
$protocolo = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
$dominio   = $_SERVER['HTTP_HOST'];
$linkRef   = $protocolo . '://' . $dominio . '/usuario/cadastro.php?ref=' . $revendedor['CodigoIndicacao'];

$pageTitle = 'Painel do Revendedor — Auralis';
require_once '../geral/header.php';
?>

<main class="container py-4 flex-grow-1" style="max-width:900px;">

    <!-- Header -->
    <div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-3">
        <div>
            <h5 class="fw-bold text-light mb-0"><i class="bi bi-people-fill me-2" style="color:#d4af37;"></i>Painel do Revendedor</h5>
            <p class="text-secondary mb-0 small">Olá, <?= htmlspecialchars(explode(' ', $revendedor['Nome'])[0]) ?>. Veja suas comissões e link de indicação.</p>
        </div>
        <a href="/dashboard.php" class="btn btn-sm rounded-pill px-3"
            style="background:rgba(255,255,255,.06);color:#94a3b8;border:1px solid rgba(255,255,255,.1);">
            <i class="bi bi-arrow-left me-1"></i> Dashboard
        </a>
    </div>

    <!-- Link de indicação -->
    <div class="rounded-4 p-4 mb-4" style="background:rgba(212,175,55,.06);border:1px solid rgba(212,175,55,.2);">
        <div class="d-flex align-items-start gap-3 flex-wrap">
            <div class="rounded-3 d-flex align-items-center justify-content-center flex-shrink-0"
                style="width:44px;height:44px;background:rgba(212,175,55,.15);border:1px solid rgba(212,175,55,.3);">
                <i class="bi bi-link-45deg" style="color:#d4af37;font-size:1.3rem;"></i>
            </div>
            <div class="flex-grow-1">
                <div class="text-light fw-semibold mb-1">Seu link de indicação</div>
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <code id="linkRef" class="px-3 py-2 rounded-3 small"
                        style="background:rgba(0,0,0,.3);color:#d4af37;word-break:break-all;">
                        <?= htmlspecialchars($linkRef) ?>
                    </code>
                    <button onclick="copiarLink()" class="btn btn-sm rounded-pill px-3"
                        style="background:rgba(212,175,55,.15);color:#d4af37;border:1px solid rgba(212,175,55,.3);">
                        <i class="bi bi-clipboard me-1"></i><span id="btnCopiar">Copiar</span>
                    </button>
                </div>
                <p class="text-secondary mb-0 mt-2" style="font-size:.78rem;">
                    Compartilhe este link. Quando alguém se cadastrar por ele e assinar um plano, você ganha <strong class="text-light"><?= number_format($revendedor['ComissaoPercentual'], 0) ?>%</strong> de comissão.
                </p>
            </div>
        </div>
    </div>

    <!-- Cards de resumo -->
    <div class="row g-3 mb-4">
        <div class="col-4">
            <div class="rounded-4 p-3 text-center" style="background:var(--bg-card);border:1px solid rgba(255,255,255,.08);">
                <div class="text-secondary small mb-1">Vendas rastreadas</div>
                <div class="fw-bold text-light fs-4"><?= $totalVendas ?></div>
            </div>
        </div>
        <div class="col-4">
            <div class="rounded-4 p-3 text-center" style="background:var(--bg-card);border:1px solid rgba(245,158,11,.2);">
                <div class="text-secondary small mb-1">A receber</div>
                <div class="fw-bold fs-4" style="color:#fbbf24;">R$ <?= number_format($saldoPendente, 2, ',', '.') ?></div>
            </div>
        </div>
        <div class="col-4">
            <div class="rounded-4 p-3 text-center" style="background:var(--bg-card);border:1px solid rgba(34,197,94,.2);">
                <div class="text-secondary small mb-1">Total recebido</div>
                <div class="fw-bold fs-4" style="color:#86efac;">R$ <?= number_format($totalPago, 2, ',', '.') ?></div>
            </div>
        </div>
    </div>

    <?php if ($revendedor['ChavePix']): ?>
    <div class="rounded-3 px-3 py-2 mb-4 d-flex align-items-center gap-2" style="background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.07);">
        <i class="bi bi-key text-secondary"></i>
        <span class="text-secondary small">Chave PIX cadastrada: <strong class="text-light"><?= htmlspecialchars($revendedor['ChavePix']) ?></strong></span>
    </div>
    <?php endif; ?>

    <!-- Histórico de comissões -->
    <div class="rounded-4 overflow-hidden" style="background:var(--bg-card);border:1px solid rgba(255,255,255,.08);">
        <div class="px-4 py-3 border-bottom border-secondary-subtle">
            <h6 class="fw-semibold text-light mb-0">Histórico de comissões</h6>
        </div>
        <?php if (empty($comissoes)): ?>
            <div class="text-center py-5">
                <i class="bi bi-graph-up text-secondary" style="font-size:2.5rem;"></i>
                <p class="text-secondary mt-3 mb-0 small">Nenhuma venda rastreada ainda. Compartilhe seu link!</p>
            </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-dark align-middle mb-0" style="font-size:.85rem;">
                <thead>
                    <tr style="background:rgba(255,255,255,.04);">
                        <th class="py-2 px-4 border-0 text-secondary fw-semibold" style="font-size:.72rem;text-transform:uppercase;">Comprador</th>
                        <th class="py-2 px-3 border-0 text-secondary fw-semibold" style="font-size:.72rem;text-transform:uppercase;">Plano</th>
                        <th class="py-2 px-3 border-0 text-secondary fw-semibold" style="font-size:.72rem;text-transform:uppercase;">Comissão</th>
                        <th class="py-2 px-3 border-0 text-secondary fw-semibold" style="font-size:.72rem;text-transform:uppercase;">Data</th>
                        <th class="py-2 px-3 border-0 text-secondary fw-semibold" style="font-size:.72rem;text-transform:uppercase;">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($comissoes as $c): ?>
                    <tr style="border-color:rgba(255,255,255,.05);">
                        <td class="py-3 px-4 border-0">
                            <div class="text-light"><?= htmlspecialchars($c['NomeComprador']) ?></div>
                            <div class="text-secondary" style="font-size:.72rem;"><?= htmlspecialchars($c['EmailComprador']) ?></div>
                        </td>
                        <td class="py-3 px-3 border-0">
                            <span class="badge rounded-pill" style="background:rgba(124,58,237,.2);color:#a78bfa;font-size:.7rem;">
                                <?= strtoupper($c['Plano']) ?>
                            </span>
                        </td>
                        <td class="py-3 px-3 border-0 fw-semibold" style="color:#fbbf24;">
                            R$ <?= number_format($c['ValorComissao'], 2, ',', '.') ?>
                            <div class="text-secondary fw-normal" style="font-size:.7rem;"><?= $c['PercentualAplicado'] ?>% de R$ <?= number_format($c['ValorVenda'], 2, ',', '.') ?></div>
                        </td>
                        <td class="py-3 px-3 border-0 text-secondary"><?= date('d/m/Y', strtotime($c['CriadaEm'])) ?></td>
                        <td class="py-3 px-3 border-0">
                            <?php if ($c['Status'] === 'paga'): ?>
                                <span class="badge rounded-pill" style="background:rgba(34,197,94,.15);color:#86efac;border:1px solid rgba(34,197,94,.3);">
                                    <i class="bi bi-check2 me-1"></i>Paga <?= date('d/m', strtotime($c['PagaEm'])) ?>
                                </span>
                            <?php else: ?>
                                <span class="badge rounded-pill" style="background:rgba(245,158,11,.15);color:#fbbf24;border:1px solid rgba(245,158,11,.3);">
                                    Pendente
                                </span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

</main>

<script>
function copiarLink() {
    var texto = document.getElementById('linkRef').textContent.trim();
    navigator.clipboard.writeText(texto).then(function() {
        var btn = document.getElementById('btnCopiar');
        btn.textContent = 'Copiado!';
        setTimeout(function() { btn.textContent = 'Copiar'; }, 2000);
    });
}
</script>

<?php require_once '../geral/footer.php'; ?>
