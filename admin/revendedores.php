<?php
require_once '../config/sessao.php';
if (!isset($_SESSION['usuario_id'])) { header("Location: /usuario/login.php"); exit; }
require_once '../config/conexao.php';
require_once '../config/funcoes.php';
require_once '../config/permissoes.php';

exigirAdmin();
garantirEstruturaComissaoRevendedor($pdo);
garantirEstruturaComissaoExpandida($pdo);

$sucesso = $erro = null;

// Lê e valida os campos de comissão (fixa ou em 2 partes) — compartilhado por atribuir/editar
function _lerCamposComissao(array $post): array
{
    $tipo = ($post['tipo_comissao'] ?? 'fixa') === 'duas_partes' ? 'duas_partes' : 'fixa';
    $perc = max(1, min(100, (float)str_replace(',', '.', $post['comissao'] ?? '20')));
    $pix  = trim($post['chave_pix'] ?? '');
    $obs  = trim($post['observacoes'] ?? '');
    $categoria = ($post['categoria'] ?? 'revendedor') === 'normal' ? 'normal' : 'revendedor';

    $perc2   = null;
    $limite  = null;
    $limiteD = null;
    $gatilho = 'clientes';
    if ($tipo === 'duas_partes') {
        $perc2   = max(1, min(100, (float)str_replace(',', '.', $post['comissao_parte2'] ?? '0')));
        $gatilho = ($post['gatilho_parte1'] ?? 'clientes') === 'dias' ? 'dias' : 'clientes';
        if ($gatilho === 'dias') {
            $limiteD = max(1, (int)($post['limite_dias_parte1'] ?? 0));
        } else {
            $limite = max(1, (int)($post['limite_parte1'] ?? 0));
        }
    }

    return [$tipo, $perc, $perc2, $limite, $pix, $obs, $categoria, $gatilho, $limiteD];
}

// ── POST HANDLERS ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Atribuir revendedor
    if ($action === 'atribuir') {
        $uid = trim($_POST['usuario_id'] ?? '');
        [$tipo, $perc, $perc2, $limite, $pix, $obs, $categoria, $gatilho, $limiteD] = _lerCamposComissao($_POST);
        if ($uid) {
            $pdo->prepare(
                "INSERT INTO Revendedor (IDRevendedor, FKUsuario, Categoria, ComissaoPercentual, TipoComissao, ComissaoPercentualParte2, LimiteClientesParte1, GatilhoParte1, LimiteDiasParte1, ChavePix, Observacoes)
                 VALUES (:id, :uid, :cat, :perc, :tipo, :perc2, :limite, :gat, :limiteD, :pix, :obs)
                 ON DUPLICATE KEY UPDATE Categoria = :cat2, ComissaoPercentual = :perc3, TipoComissao = :tipo2, ComissaoPercentualParte2 = :perc4, LimiteClientesParte1 = :limite2, GatilhoParte1 = :gat2, LimiteDiasParte1 = :limiteD2, ChavePix = :pix2, Observacoes = :obs2, Ativo = 1"
            )->execute([
                ':id' => gerarUuid(), ':uid' => $uid, ':cat' => $categoria,
                ':perc' => $perc, ':tipo' => $tipo, ':perc2' => $perc2, ':limite' => $limite,
                ':gat' => $gatilho, ':limiteD' => $limiteD,
                ':pix' => $pix ?: null, ':obs' => $obs ?: null,
                ':cat2' => $categoria, ':perc3' => $perc, ':tipo2' => $tipo, ':perc4' => $perc2, ':limite2' => $limite,
                ':gat2' => $gatilho, ':limiteD2' => $limiteD,
                ':pix2' => $pix ?: null, ':obs2' => $obs ?: null,
            ]);
            $sucesso = 'Revendedor configurado com sucesso.';
        }
    }

    // Editar revendedor existente
    if ($action === 'editar') {
        $rid = trim($_POST['revendedor_id'] ?? '');
        [$tipo, $perc, $perc2, $limite, $pix, $obs, $categoria, $gatilho, $limiteD] = _lerCamposComissao($_POST);
        if ($rid) {
            $pdo->prepare(
                "UPDATE Revendedor SET Categoria = :cat, ComissaoPercentual = :perc, TipoComissao = :tipo, ComissaoPercentualParte2 = :perc2, LimiteClientesParte1 = :limite, GatilhoParte1 = :gat, LimiteDiasParte1 = :limiteD, ChavePix = :pix, Observacoes = :obs WHERE IDRevendedor = :id"
            )->execute([
                ':cat' => $categoria, ':perc' => $perc, ':tipo' => $tipo, ':perc2' => $perc2, ':limite' => $limite,
                ':gat' => $gatilho, ':limiteD' => $limiteD,
                ':pix' => $pix ?: null, ':obs' => $obs ?: null, ':id' => $rid,
            ]);
            $sucesso = 'Revendedor atualizado.';
        }
    }

    // Salvar valor mínimo de saque (config global)
    if ($action === 'salvar_valor_minimo') {
        $valor    = max(0, (float)str_replace(',', '.', $_POST['valor_minimo'] ?? '50'));
        $valorStr = number_format($valor, 2, '.', '');
        $existe   = $pdo->prepare("SELECT 1 FROM ConfiguracaoSistema WHERE Chave = 'comissao_valor_minimo_saque' AND FKUsuario IS NULL LIMIT 1");
        $existe->execute();
        if ($existe->fetchColumn()) {
            $pdo->prepare("UPDATE ConfiguracaoSistema SET Valor = :v WHERE Chave = 'comissao_valor_minimo_saque' AND FKUsuario IS NULL")
                ->execute([':v' => $valorStr]);
        } else {
            $pdo->prepare("INSERT INTO ConfiguracaoSistema (Chave, Valor, FKUsuario) VALUES ('comissao_valor_minimo_saque', :v, NULL)")
                ->execute([':v' => $valorStr]);
        }
        $sucesso = 'Valor mínimo de saque atualizado.';
    }

    // Desativar revendedor
    if ($action === 'desativar') {
        $rid = trim($_POST['revendedor_id'] ?? '');
        if ($rid) {
            $pdo->prepare("UPDATE Revendedor SET Ativo = 0 WHERE IDRevendedor = :id")->execute([':id' => $rid]);
            $sucesso = 'Revendedor desativado.';
        }
    }

    // Reativar revendedor
    if ($action === 'reativar') {
        $rid = trim($_POST['revendedor_id'] ?? '');
        if ($rid) {
            $pdo->prepare("UPDATE Revendedor SET Ativo = 1 WHERE IDRevendedor = :id")->execute([':id' => $rid]);
            $sucesso = 'Revendedor reativado.';
        }
    }

    // Marcar comissão como paga
    if ($action === 'marcar_paga') {
        $cid = trim($_POST['comissao_id'] ?? '');
        if ($cid) {
            $pdo->prepare("UPDATE ComissaoRevendedor SET Status = 'paga', PagaEm = NOW() WHERE IDComissao = :id")
                ->execute([':id' => $cid]);
            $sucesso = 'Comissão marcada como paga.';
        }
    }

    // Marcar TODAS as pendentes de um revendedor como pagas
    if ($action === 'pagar_todas') {
        $rid = trim($_POST['revendedor_id'] ?? '');
        if ($rid) {
            $pdo->prepare("UPDATE ComissaoRevendedor SET Status = 'paga', PagaEm = NOW() WHERE FKRevendedor = :id AND Status = 'pendente'")
                ->execute([':id' => $rid]);
            $sucesso = 'Todas as comissões pendentes marcadas como pagas.';
        }
    }

    header("Location: revendedores.php" . ($sucesso ? "?ok=1" : "?erro=1")); exit;
}

$sucesso = isset($_GET['ok'])  ? 'Operação realizada com sucesso.' : null;
$erro    = isset($_GET['erro']) ? 'Ocorreu um erro. Tente novamente.' : null;

// ── DADOS ─────────────────────────────────────────────────────────────────────

// Valor mínimo de saque configurado (comissões acumuladas, não-primeira) — usado pra
// destacar na lista quem já bateu o mínimo e está pronto pra receber
$valorMinimoSaque = valorMinimoSaqueComissao($pdo);

// Lista de revendedores com totais de comissão
$revendedores = $pdo->query(
    "SELECT r.*, u.Nome, u.Email, u.CodigoIndicacao,
            COUNT(DISTINCT c.IDComissao) as total_vendas,
            COALESCE(SUM(c.ValorComissao), 0) as total_comissao,
            COALESCE(SUM(CASE WHEN c.Status = 'pendente' THEN c.ValorComissao ELSE 0 END), 0) as saldo_pendente,
            COALESCE(SUM(CASE WHEN c.Status = 'paga'     THEN c.ValorComissao ELSE 0 END), 0) as total_pago,
            COALESCE(SUM(CASE WHEN c.Status = 'pendente' AND c.EhPrimeiraComissao = 1 THEN c.ValorComissao ELSE 0 END), 0) as saldo_primeira_pendente,
            COALESCE(SUM(CASE WHEN c.Status = 'pendente' AND c.EhPrimeiraComissao = 0 THEN c.ValorComissao ELSE 0 END), 0) as saldo_acumulado_pendente
     FROM Revendedor r
     JOIN Usuario u ON u.IDUsuario = r.FKUsuario
     LEFT JOIN ComissaoRevendedor c ON c.FKRevendedor = r.IDRevendedor
     GROUP BY r.IDRevendedor
     ORDER BY r.Ativo DESC, total_comissao DESC"
)->fetchAll(PDO::FETCH_ASSOC);

// Prioriza na lista quem precisa de ação: 1ª comissão pendente primeiro, depois quem já
// bateu o mínimo acumulado, mantendo a ordenação original (Ativo/total_comissao) como desempate
foreach ($revendedores as &$_r) {
    $_r['precisa_acao'] = ((float)$_r['saldo_primeira_pendente'] > 0) ? 2
        : ((float)$_r['saldo_acumulado_pendente'] >= $valorMinimoSaque && $valorMinimoSaque > 0 ? 1 : 0);
}
unset($_r);
usort($revendedores, fn($a, $b) => $b['precisa_acao'] <=> $a['precisa_acao']);

// IDs de usuários que já são revendedores (para filtrar o select de atribuição)
$idsRevendedores = array_column($revendedores, 'FKUsuario');

// Todos os usuários ativos (para o campo de busca ao atribuir novo revendedor)
$usuarios = $pdo->query(
    "SELECT IDUsuario, Nome, Email, Plano, CodigoIndicacao FROM Usuario
     WHERE StatusConta = 'ativo' ORDER BY Nome"
)->fetchAll(PDO::FETCH_ASSOC);

// Comissões detalhadas para o revendedor selecionado (via ?rev=ID)
$revSelecionado = null;
$comissoes      = [];
if (!empty($_GET['rev'])) {
    $stmtRev = $pdo->prepare(
        "SELECT r.*, u.Nome, u.Email, u.CodigoIndicacao FROM Revendedor r
         JOIN Usuario u ON u.IDUsuario = r.FKUsuario WHERE r.IDRevendedor = :id"
    );
    $stmtRev->execute([':id' => $_GET['rev']]);
    $revSelecionado = $stmtRev->fetch(PDO::FETCH_ASSOC);

    if ($revSelecionado) {
        $stmtCom = $pdo->prepare(
            "SELECT c.*, u.Nome as NomeComprador, u.Email as EmailComprador
             FROM ComissaoRevendedor c
             JOIN Usuario u ON u.IDUsuario = c.FKUsuarioComprador
             WHERE c.FKRevendedor = :rid
             ORDER BY c.CriadaEm DESC"
        );
        $stmtCom->execute([':rid' => $_GET['rev']]);
        $comissoes = $stmtCom->fetchAll(PDO::FETCH_ASSOC);
    }
}

$pageTitle = 'Revendedores — Admin Auralis';
require_once '../geral/header.php';
?>
<style>
.card-admin { background:var(--bg-card,#1a1d27); border:1px solid rgba(255,255,255,.08); border-radius:12px; }
.badge-ativo   { background:rgba(34,197,94,.15);  color:#86efac; border:1px solid rgba(34,197,94,.3); }
.badge-inativo { background:rgba(239,68,68,.1);   color:#fca5a5; border:1px solid rgba(239,68,68,.2); }
.badge-pendente{ background:rgba(245,158,11,.15); color:#fbbf24; border:1px solid rgba(245,158,11,.3); }
.badge-paga    { background:rgba(34,197,94,.15);  color:#86efac; border:1px solid rgba(34,197,94,.3); }
</style>

<main class="container-fluid py-4 mt-2 flex-grow-1" style="max-width:1200px;padding-inline:var(--space-page-x);">

    <!-- Cabeçalho -->
    <div class="d-flex justify-content-between align-items-center mb-4 border-bottom border-secondary-subtle pb-3 gap-3 flex-wrap">
        <a href="/dashboard.php" class="btn btn-outline-secondary btn-sm rounded-pill px-3 flex-shrink-0">
            <i class="bi bi-arrow-left me-1"></i> Voltar
        </a>
        <button class="btn btn-sm rounded-pill px-4 fw-semibold"
            style="background:rgba(212,175,55,.15);color:#d4af37;border:1px solid rgba(212,175,55,.35);"
            data-bs-toggle="modal" data-bs-target="#modalAtribuir">
            <i class="bi bi-person-plus-fill me-1"></i> Atribuir revendedor
        </button>
    </div>

    <!-- Tabs de navegação admin -->
    <ul class="nav nav-pills gap-2 mb-4">
        <li class="nav-item">
            <a href="/admin/usuarios.php" class="nav-link rounded-pill"
               style="background:rgba(255,255,255,.05);color:#9ca3af;font-size:0.85rem;">
                <i class="bi bi-people me-1"></i> Usuários
            </a>
        </li>
        <?php if (ehSupremo()): ?>
        <li class="nav-item">
            <a href="/admin/configuracoes_planos.php" class="nav-link rounded-pill"
               style="background:rgba(255,255,255,.05);color:#9ca3af;font-size:0.85rem;">
                <i class="bi bi-sliders me-1"></i> Configurações de Planos
            </a>
        </li>
        <?php endif; ?>
        <li class="nav-item">
            <a href="/admin/codigos.php" class="nav-link rounded-pill"
               style="background:rgba(255,255,255,.05);color:#9ca3af;font-size:0.85rem;">
                <i class="bi bi-gift-fill me-1"></i> Códigos de Ativação
            </a>
        </li>
        <li class="nav-item">
            <a href="/admin/notificacoes.php" class="nav-link rounded-pill"
               style="background:rgba(255,255,255,.05);color:#9ca3af;font-size:0.85rem;">
                <i class="bi bi-bell-fill me-1"></i> Notificações
            </a>
        </li>
        <li class="nav-item">
            <a href="/admin/revendedores.php" class="nav-link rounded-pill active"
               style="background:#7c3aed;color:#fff;font-size:0.85rem;">
                <i class="bi bi-people-fill me-1"></i> Revendedores
            </a>
        </li>
        <li class="nav-item">
            <a href="/admin/indicacoes.php" class="nav-link rounded-pill"
               style="background:rgba(255,255,255,.05);color:#9ca3af;font-size:0.85rem;">
                <i class="bi bi-share-fill me-1"></i> Indicações
            </a>
        </li>
        <li class="nav-item">
            <a href="/admin/conquistas.php" class="nav-link rounded-pill"
               style="background:rgba(255,255,255,.05);color:#9ca3af;font-size:0.85rem;">
               <i class="bi bi-trophy-fill me-1"></i> Conquistas
            </a>
        </li>
    </ul>

    <!-- Alertas -->
    <?php if ($sucesso): ?>
    <div class="alert border-0 rounded-3 mb-4 py-2 px-3" style="background:rgba(34,197,94,.1);color:#86efac;border-left:3px solid #22c55e !important;">
        <i class="bi bi-check-circle me-2"></i><?= htmlspecialchars($sucesso) ?>
    </div>
    <?php endif; ?>
    <?php if ($erro): ?>
    <div class="alert border-0 rounded-3 mb-4 py-2 px-3" style="background:rgba(239,68,68,.1);color:#fca5a5;">
        <i class="bi bi-exclamation-triangle me-2"></i><?= htmlspecialchars($erro) ?>
    </div>
    <?php endif; ?>

    <?php if (!$revSelecionado): ?>
    <!-- Card: valor mínimo de saque (comissões acumuladas, exceto a 1ª) -->
    <div class="card-admin p-4 mb-4">
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-3">
            <div>
                <h6 class="fw-semibold text-light mb-1"><i class="bi bi-piggy-bank-fill me-2" style="color:var(--accent);"></i>Valor mínimo de saque</h6>
                <p class="text-secondary mb-0" style="font-size:.8rem;max-width:520px;">
                    A 1ª comissão de cada indicador é sempre paga na hora. As seguintes acumulam num saldo e só entram na fila de pagamento quando baterem esse valor.
                </p>
            </div>
            <form method="POST" class="d-flex align-items-center gap-2">
                <input type="hidden" name="action" value="salvar_valor_minimo">
                <span class="text-secondary">R$</span>
                <input type="number" name="valor_minimo" class="form-control" style="width:120px;" value="<?= number_format($valorMinimoSaque, 2, '.', '') ?>" min="0" step="0.01" required>
                <button type="submit" class="btn btn-sm fw-semibold rounded-pill px-3"
                    style="background:rgba(212,175,55,.15);color:#d4af37;border:1px solid rgba(212,175,55,.35);">
                    Salvar
                </button>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($revSelecionado): ?>
    <!-- ── DETALHE DE UM REVENDEDOR ──────────────────────────────────────────── -->
    <div class="mb-3">
        <a href="revendedores.php" class="text-secondary small"><i class="bi bi-arrow-left me-1"></i>Voltar para lista</a>
    </div>

    <!-- Card do revendedor -->
    <div class="card-admin p-4 mb-4">
        <div class="d-flex align-items-start justify-content-between flex-wrap gap-3">
            <div class="d-flex align-items-center gap-3">
                <div class="rounded-3 d-flex align-items-center justify-content-center"
                    style="width:48px;height:48px;background:rgba(212,175,55,.12);border:1px solid rgba(212,175,55,.25);">
                    <i class="bi bi-person-badge" style="color:var(--accent);font-size:1.4rem;"></i>
                </div>
                <div>
                    <div class="d-flex align-items-center gap-2 flex-wrap">
                        <h5 class="text-light fw-bold mb-0"><?= htmlspecialchars($revSelecionado['Nome']) ?></h5>
                        <span class="badge rounded-pill" style="font-size:.65rem;<?= ($revSelecionado['Categoria'] ?? 'normal') === 'revendedor' ? 'background:rgba(124,58,237,.18);color:#c4b5fd;border:1px solid rgba(124,58,237,.3);' : 'background:rgba(255,255,255,.08);color:#9ca3af;border:1px solid rgba(255,255,255,.12);' ?>">
                            <?= ($revSelecionado['Categoria'] ?? 'normal') === 'revendedor' ? 'Revendedor' : 'Indicação comum' ?>
                        </span>
                    </div>
                    <p class="text-secondary mb-0 small"><?= htmlspecialchars($revSelecionado['Email']) ?></p>
                    <code class="small" style="color:var(--accent);"><?= htmlspecialchars($revSelecionado['CodigoIndicacao']) ?></code>
                    <p class="text-secondary mb-0 mt-1" style="font-size:.78rem;">
                        <?php if (($revSelecionado['TipoComissao'] ?? 'fixa') === 'duas_partes'): ?>
                            <?php if (($revSelecionado['GatilhoParte1'] ?? 'clientes') === 'dias'): ?>
                                <i class="bi bi-layers me-1"></i>Em 2 partes: <?= number_format($revSelecionado['ComissaoPercentual'], 0) ?>% (primeiros <?= (int)$revSelecionado['LimiteDiasParte1'] ?> dias) → <?= number_format($revSelecionado['ComissaoPercentualParte2'], 0) ?>% (depois)
                            <?php else: ?>
                                <i class="bi bi-layers me-1"></i>Em 2 partes: <?= number_format($revSelecionado['ComissaoPercentual'], 0) ?>% (1º–<?= (int)$revSelecionado['LimiteClientesParte1'] ?>º cliente) → <?= number_format($revSelecionado['ComissaoPercentualParte2'], 0) ?>% (depois)
                            <?php endif; ?>
                        <?php else: ?>
                            <i class="bi bi-percent me-1"></i>Comissão fixa: <?= number_format($revSelecionado['ComissaoPercentual'], 0) ?>%
                        <?php endif; ?>
                    </p>
                </div>
            </div>
            <div class="d-flex gap-2">
                <button class="btn btn-sm rounded-pill px-3"
                    style="background:rgba(212,175,55,.1);color:#d4af37;border:1px solid rgba(212,175,55,.3);"
                    data-bs-toggle="modal" data-bs-target="#modalEditar"
                    data-rid="<?= $revSelecionado['IDRevendedor'] ?>"
                    data-categoria="<?= htmlspecialchars($revSelecionado['Categoria'] ?? 'normal') ?>"
                    data-perc="<?= $revSelecionado['ComissaoPercentual'] ?>"
                    data-tipo="<?= htmlspecialchars($revSelecionado['TipoComissao'] ?? 'fixa') ?>"
                    data-perc2="<?= htmlspecialchars($revSelecionado['ComissaoPercentualParte2'] ?? '') ?>"
                    data-gatilho="<?= htmlspecialchars($revSelecionado['GatilhoParte1'] ?? 'clientes') ?>"
                    data-limite="<?= htmlspecialchars($revSelecionado['LimiteClientesParte1'] ?? '') ?>"
                    data-limited="<?= htmlspecialchars($revSelecionado['LimiteDiasParte1'] ?? '') ?>"
                    data-pix="<?= htmlspecialchars($revSelecionado['ChavePix'] ?? '') ?>"
                    data-obs="<?= htmlspecialchars($revSelecionado['Observacoes'] ?? '') ?>">
                    <i class="bi bi-pencil me-1"></i> Editar
                </button>
                <form method="POST" onsubmit="return confirm('Pagar TODAS as comissões pendentes?');">
                    <input type="hidden" name="action" value="pagar_todas">
                    <input type="hidden" name="revendedor_id" value="<?= $revSelecionado['IDRevendedor'] ?>">
                    <button class="btn btn-sm rounded-pill px-3"
                        style="background:rgba(34,197,94,.1);color:#86efac;border:1px solid rgba(34,197,94,.3);">
                        <i class="bi bi-check2-all me-1"></i> Marcar todas como pagas
                    </button>
                </form>
            </div>
        </div>

        <!-- Resumo financeiro -->
        <?php
        $totalVendas  = count($comissoes);
        $saldoPend    = array_sum(array_column(array_filter($comissoes, fn($c) => $c['Status'] === 'pendente'), 'ValorComissao'));
        $totalPago    = array_sum(array_column(array_filter($comissoes, fn($c) => $c['Status'] === 'paga'),    'ValorComissao'));
        $totalBruto   = array_sum(array_column($comissoes, 'ValorVenda'));
        $primeiraPend = array_sum(array_column(array_filter($comissoes, fn($c) => $c['Status'] === 'pendente' && (int)$c['EhPrimeiraComissao'] === 1), 'ValorComissao'));
        $acumuladoPend = array_sum(array_column(array_filter($comissoes, fn($c) => $c['Status'] === 'pendente' && (int)$c['EhPrimeiraComissao'] === 0), 'ValorComissao'));
        ?>
        <?php if ($primeiraPend > 0): ?>
        <div class="alert border-0 rounded-3 mt-3 mb-0 py-2 px-3 d-flex align-items-center gap-2" style="background:rgba(239,68,68,.1);color:#fca5a5;border-left:3px solid #ef4444 !important;">
            <i class="bi bi-lightning-charge-fill"></i>
            <span><strong>1ª comissão pendente — R$ <?= number_format($primeiraPend, 2, ',', '.') ?>.</strong> Essa precisa ser paga na hora pra provar que o programa funciona.</span>
        </div>
        <?php elseif ($acumuladoPend >= $valorMinimoSaque && $valorMinimoSaque > 0): ?>
        <div class="alert border-0 rounded-3 mt-3 mb-0 py-2 px-3 d-flex align-items-center gap-2" style="background:rgba(245,158,11,.1);color:#fbbf24;border-left:3px solid #f59e0b !important;">
            <i class="bi bi-cash-coin"></i>
            <span><strong>Saldo acumulado bateu o mínimo (R$ <?= number_format($valorMinimoSaque, 2, ',', '.') ?>)</strong> — pronto pra entrar na fila de pagamento.</span>
        </div>
        <?php endif; ?>
        <div class="row g-3 mt-3">
            <div class="col-6 col-md-3">
                <div class="p-3 rounded-3" style="background:rgba(255,255,255,.04);">
                    <div class="text-secondary small mb-1">Vendas rastreadas</div>
                    <div class="fw-bold text-light fs-5"><?= $totalVendas ?></div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="p-3 rounded-3" style="background:rgba(255,255,255,.04);">
                    <div class="text-secondary small mb-1">Volume gerado</div>
                    <div class="fw-bold text-light fs-5">R$ <?= number_format($totalBruto, 2, ',', '.') ?></div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="p-3 rounded-3" style="background:rgba(245,158,11,.06);border:1px solid rgba(245,158,11,.15);">
                    <div class="text-secondary small mb-1">Saldo a pagar</div>
                    <div class="fw-bold fs-5" style="color:#fbbf24;">R$ <?= number_format($saldoPend, 2, ',', '.') ?></div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="p-3 rounded-3" style="background:rgba(34,197,94,.06);border:1px solid rgba(34,197,94,.15);">
                    <div class="text-secondary small mb-1">Total já pago</div>
                    <div class="fw-bold fs-5" style="color:#86efac;">R$ <?= number_format($totalPago, 2, ',', '.') ?></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Tabela de comissões -->
    <div class="card-admin p-4">
        <h6 class="fw-semibold text-light mb-3">Histórico de comissões</h6>
        <?php if (empty($comissoes)): ?>
            <p class="text-secondary text-center py-3 mb-0 small">Nenhuma venda rastreada ainda.</p>
        <?php else: ?>
        <div class="table-responsive rounded-3 overflow-hidden" style="border:1px solid rgba(255,255,255,.07);">
            <table class="table table-dark align-middle mb-0" style="font-size:.85rem;">
                <thead>
                    <tr style="background:rgba(255,255,255,.04);">
                        <th class="py-2 px-3 border-0">Comprador</th>
                        <th class="py-2 px-3 border-0">Plano</th>
                        <th class="py-2 px-3 border-0">Venda</th>
                        <th class="py-2 px-3 border-0">Comissão</th>
                        <th class="py-2 px-3 border-0">Data</th>
                        <th class="py-2 px-3 border-0">Status</th>
                        <th class="py-2 px-3 border-0"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($comissoes as $c): $ehPrimeira = (int)($c['EhPrimeiraComissao'] ?? 0) === 1; ?>
                    <tr style="border-color:rgba(255,255,255,.05);<?= ($ehPrimeira && $c['Status'] === 'pendente') ? 'background:rgba(239,68,68,.05);' : '' ?>">
                        <td class="py-2 px-3 border-0">
                            <div class="text-light fw-semibold"><?= htmlspecialchars($c['NomeComprador']) ?></div>
                            <div class="text-secondary" style="font-size:.75rem;"><?= htmlspecialchars($c['EmailComprador']) ?></div>
                        </td>
                        <td class="py-2 px-3 border-0">
                            <span class="badge rounded-pill" style="background:rgba(124,58,237,.2);color:#a78bfa;font-size:.7rem;">
                                <?= strtoupper($c['Plano']) ?>
                            </span>
                        </td>
                        <td class="py-2 px-3 border-0 text-light">R$ <?= number_format($c['ValorVenda'], 2, ',', '.') ?></td>
                        <td class="py-2 px-3 border-0 fw-semibold" style="color:#fbbf24;">
                            R$ <?= number_format($c['ValorComissao'], 2, ',', '.') ?>
                            <span class="text-secondary fw-normal" style="font-size:.7rem;">(<?= number_format($c['PercentualAplicado'], 0) ?>%)</span>
                        </td>
                        <td class="py-2 px-3 border-0 text-secondary"><?= date('d/m/Y', strtotime($c['CriadaEm'])) ?></td>
                        <td class="py-2 px-3 border-0">
                            <span class="badge rounded-pill badge-<?= $c['Status'] ?>"><?= $c['Status'] === 'paga' ? 'Paga' : 'Pendente' ?></span>
                            <?php if ($ehPrimeira): ?>
                            <span class="badge rounded-pill ms-1" style="background:rgba(239,68,68,.15);color:#fca5a5;font-size:.65rem;" title="1ª comissão desse indicador — prioridade de pagamento">
                                <i class="bi bi-lightning-charge-fill"></i> 1ª
                            </span>
                            <?php endif; ?>
                        </td>
                        <td class="py-2 px-3 border-0 text-end">
                            <?php if ($c['Status'] === 'pendente'): ?>
                            <form method="POST" class="d-inline">
                                <input type="hidden" name="action" value="marcar_paga">
                                <input type="hidden" name="comissao_id" value="<?= $c['IDComissao'] ?>">
                                <button class="btn btn-sm rounded-pill px-2 py-0"
                                    style="background:rgba(34,197,94,.1);color:#86efac;border:1px solid rgba(34,197,94,.25);font-size:.72rem;">
                                    Pagar
                                </button>
                            </form>
                            <?php else: ?>
                            <span class="text-secondary" style="font-size:.72rem;"><?= date('d/m/Y', strtotime($c['PagaEm'])) ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <?php else: ?>
    <!-- ── LISTA DE REVENDEDORES ─────────────────────────────────────────────── -->
    <div class="card-admin">
        <?php if (empty($revendedores)): ?>
            <div class="text-center py-5">
                <i class="bi bi-people text-secondary" style="font-size:3rem;"></i>
                <p class="text-secondary mt-3 mb-1">Nenhum revendedor cadastrado ainda.</p>
                <p class="text-secondary small">Clique em "Atribuir revendedor" para começar.</p>
            </div>
        <?php else: ?>
        <div class="table-responsive rounded-3 overflow-hidden">
            <table class="table table-dark table-hover align-middle mb-0">
                <thead>
                    <tr style="background:rgba(255,255,255,.04);">
                        <th class="py-3 px-4 border-0">Revendedor</th>
                        <th class="py-3 px-3 border-0">Categoria</th>
                        <th class="py-3 px-3 border-0">Código</th>
                        <th class="py-3 px-3 border-0">Comissão</th>
                        <th class="py-3 px-3 border-0">Vendas</th>
                        <th class="py-3 px-3 border-0">A pagar</th>
                        <th class="py-3 px-3 border-0">Total pago</th>
                        <th class="py-3 px-3 border-0">Status</th>
                        <th class="py-3 px-3 border-0"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($revendedores as $r): ?>
                    <tr style="border-color:rgba(255,255,255,.05);<?= $r['precisa_acao'] === 2 ? 'background:rgba(239,68,68,.05);' : ($r['precisa_acao'] === 1 ? 'background:rgba(245,158,11,.05);' : '') ?>" class="<?= $r['Ativo'] ? '' : 'opacity-50' ?>">
                        <td class="py-3 px-4 border-0">
                            <div class="text-light fw-semibold"><?= htmlspecialchars($r['Nome']) ?></div>
                            <div class="text-secondary small"><?= htmlspecialchars($r['Email']) ?></div>
                            <?php if ($r['ChavePix']): ?>
                            <div class="text-secondary" style="font-size:.72rem;"><i class="bi bi-key me-1"></i><?= htmlspecialchars($r['ChavePix']) ?></div>
                            <?php endif; ?>
                            <?php if ($r['precisa_acao'] === 2): ?>
                            <div class="mt-1"><span class="badge rounded-pill" style="background:rgba(239,68,68,.15);color:#fca5a5;font-size:.65rem;"><i class="bi bi-lightning-charge-fill"></i> 1ª comissão — pagar agora</span></div>
                            <?php elseif ($r['precisa_acao'] === 1): ?>
                            <div class="mt-1"><span class="badge rounded-pill" style="background:rgba(245,158,11,.15);color:#fbbf24;font-size:.65rem;"><i class="bi bi-cash-coin"></i> Pronto pra pagar</span></div>
                            <?php endif; ?>
                        </td>
                        <td class="py-3 px-3 border-0">
                            <span class="badge rounded-pill" style="font-size:.7rem;<?= ($r['Categoria'] ?? 'normal') === 'revendedor' ? 'background:rgba(124,58,237,.18);color:#c4b5fd;border:1px solid rgba(124,58,237,.3);' : 'background:rgba(255,255,255,.08);color:#9ca3af;border:1px solid rgba(255,255,255,.12);' ?>">
                                <?= ($r['Categoria'] ?? 'normal') === 'revendedor' ? 'Revendedor' : 'Comum' ?>
                            </span>
                        </td>
                        <td class="py-3 px-3 border-0">
                            <code style="color:var(--accent);font-size:.8rem;"><?= htmlspecialchars($r['CodigoIndicacao']) ?></code>
                        </td>
                        <td class="py-3 px-3 border-0 fw-semibold text-light">
                            <?php if (($r['TipoComissao'] ?? 'fixa') === 'duas_partes'): ?>
                                <?= number_format($r['ComissaoPercentual'], 0) ?>%→<?= number_format($r['ComissaoPercentualParte2'], 0) ?>%
                                <?php if (($r['GatilhoParte1'] ?? 'clientes') === 'dias'): ?>
                                <div class="text-secondary fw-normal" style="font-size:.7rem;">após <?= (int)$r['LimiteDiasParte1'] ?> dias</div>
                                <?php else: ?>
                                <div class="text-secondary fw-normal" style="font-size:.7rem;">após <?= (int)$r['LimiteClientesParte1'] ?> clientes</div>
                                <?php endif; ?>
                            <?php else: ?>
                                <?= number_format($r['ComissaoPercentual'], 0) ?>%
                            <?php endif; ?>
                        </td>
                        <td class="py-3 px-3 border-0 text-secondary"><?= $r['total_vendas'] ?></td>
                        <td class="py-3 px-3 border-0 fw-semibold" style="color:#fbbf24;">
                            R$ <?= number_format($r['saldo_pendente'], 2, ',', '.') ?>
                        </td>
                        <td class="py-3 px-3 border-0 text-secondary">R$ <?= number_format($r['total_pago'], 2, ',', '.') ?></td>
                        <td class="py-3 px-3 border-0">
                            <span class="badge rounded-pill badge-<?= $r['Ativo'] ? 'ativo' : 'inativo' ?>">
                                <?= $r['Ativo'] ? 'Ativo' : 'Inativo' ?>
                            </span>
                        </td>
                        <td class="py-3 px-3 border-0 text-end" style="white-space:nowrap;">
                            <a href="?rev=<?= $r['IDRevendedor'] ?>" class="btn btn-sm rounded-pill px-3 me-1"
                                style="background:rgba(255,255,255,.06);color:#e2e8f0;border:1px solid rgba(255,255,255,.1);font-size:.75rem;">
                                <i class="bi bi-eye me-1"></i>Detalhe
                            </a>
                            <?php if ($r['Ativo']): ?>
                            <button class="btn btn-sm rounded-pill px-3"
                                style="background:rgba(239,68,68,.08);color:#fca5a5;border:1px solid rgba(239,68,68,.2);font-size:.75rem;"
                                data-bs-toggle="modal" data-bs-target="#modalDesativar"
                                data-rid="<?= $r['IDRevendedor'] ?>" data-nome="<?= htmlspecialchars($r['Nome']) ?>">
                                Desativar
                            </button>
                            <?php else: ?>
                            <form method="POST" class="d-inline">
                                <input type="hidden" name="action" value="reativar">
                                <input type="hidden" name="revendedor_id" value="<?= $r['IDRevendedor'] ?>">
                                <button class="btn btn-sm rounded-pill px-3"
                                    style="background:rgba(34,197,94,.08);color:#86efac;border:1px solid rgba(34,197,94,.2);font-size:.75rem;">
                                    Reativar
                                </button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</main>

<!-- ── Modal: Atribuir revendedor ────────────────────────────────────────────── -->
<div class="modal fade" id="modalAtribuir" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-secondary-subtle shadow-lg" style="background:#1a1d27;">
            <form method="POST">
                <input type="hidden" name="action" value="atribuir">
                <div class="modal-header border-secondary-subtle px-4 py-3">
                    <h6 class="modal-title fw-bold text-light mb-0"><i class="bi bi-person-plus-fill me-2" style="color:var(--accent);"></i>Atribuir revendedor</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body px-4 py-3">
                    <div class="mb-3">
                        <label class="form-label text-secondary small fw-semibold">Usuário</label>
                        <select name="usuario_id" class="form-select" required>
                            <option value="">Selecione um usuário...</option>
                            <?php foreach ($usuarios as $u):
                                $jaRev = in_array($u['IDUsuario'], $idsRevendedores);
                            ?>
                            <option value="<?= $u['IDUsuario'] ?>" <?= $jaRev ? 'disabled' : '' ?>>
                                <?= htmlspecialchars($u['Nome']) ?> — <?= htmlspecialchars($u['Email']) ?>
                                <?= $jaRev ? '(já revendedor)' : '' ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text text-secondary mt-1" style="font-size:.75rem;">
                            O usuário usará seu código <code style="color:var(--accent);">CodigoIndicacao</code> para divulgar.
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label text-secondary small fw-semibold d-block">Categoria</label>
                        <div class="d-flex gap-2">
                            <input type="radio" class="btn-check" name="categoria" id="atr_cat_revendedor" value="revendedor" checked>
                            <label class="btn btn-outline-secondary flex-grow-1" for="atr_cat_revendedor">Revendedor</label>
                            <input type="radio" class="btn-check" name="categoria" id="atr_cat_normal" value="normal">
                            <label class="btn btn-outline-secondary flex-grow-1" for="atr_cat_normal">Indicação comum</label>
                        </div>
                        <div class="form-text text-secondary mt-1" style="font-size:.75rem;">
                            "Revendedor" é pra pequenos divulgadores com comissão mais agressiva. "Indicação comum" é a mesma categoria que o sistema já cria sozinho quando qualquer usuário indica alguém — normalmente só se usa esse formulário pra Revendedor.
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label text-secondary small fw-semibold d-block">Tipo de comissão</label>
                        <div class="d-flex gap-2">
                            <input type="radio" class="btn-check" name="tipo_comissao" id="atr_tipo_fixa" value="fixa" checked>
                            <label class="btn btn-outline-secondary flex-grow-1" for="atr_tipo_fixa">Fixa</label>
                            <input type="radio" class="btn-check" name="tipo_comissao" id="atr_tipo_2partes" value="duas_partes">
                            <label class="btn btn-outline-secondary flex-grow-1" for="atr_tipo_2partes">Em 2 partes</label>
                        </div>
                    </div>
                    <div class="row g-3">
                        <div class="col-6">
                            <label class="form-label text-secondary small fw-semibold" id="atr_label_comissao">Comissão (%)</label>
                            <input type="number" name="comissao" class="form-control" value="20" min="1" max="100" step="0.5" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label text-secondary small fw-semibold">Chave PIX</label>
                            <input type="text" name="chave_pix" class="form-control" placeholder="CPF, email ou telefone">
                        </div>
                    </div>
                    <div class="mt-3 d-none" id="atr_bloco_parte2">
                        <label class="form-label text-secondary small fw-semibold d-block">Gatilho da 2ª faixa</label>
                        <div class="d-flex gap-2 mb-2">
                            <input type="radio" class="btn-check" name="gatilho_parte1" id="atr_gat_clientes" value="clientes" checked>
                            <label class="btn btn-outline-secondary flex-grow-1" for="atr_gat_clientes">Por quantidade de clientes</label>
                            <input type="radio" class="btn-check" name="gatilho_parte1" id="atr_gat_dias" value="dias">
                            <label class="btn btn-outline-secondary flex-grow-1" for="atr_gat_dias">Por tempo</label>
                        </div>
                        <div class="row g-3">
                            <div class="col-6" id="atr_bloco_limite_clientes">
                                <label class="form-label text-secondary small fw-semibold">Limite de clientes na 1ª faixa</label>
                                <input type="number" name="limite_parte1" class="form-control" value="10" min="1" step="1">
                            </div>
                            <div class="col-6 d-none" id="atr_bloco_limite_dias">
                                <label class="form-label text-secondary small fw-semibold">Dias na 1ª faixa</label>
                                <input type="number" name="limite_dias_parte1" class="form-control" value="180" min="1" step="1">
                            </div>
                            <div class="col-6">
                                <label class="form-label text-secondary small fw-semibold">Comissão após o limite (%)</label>
                                <input type="number" name="comissao_parte2" class="form-control" value="10" min="1" max="100" step="0.5">
                            </div>
                        </div>
                        <div class="form-text text-secondary mt-1" style="font-size:.75rem;">
                            Por quantidade: os N primeiros clientes distintos indicados ficam na 1ª faixa pra sempre. Por tempo: vale a 1ª faixa durante os primeiros N dias desde que virou revendedor, independente de quantos clientes trouxer.
                        </div>
                    </div>
                    <div class="mt-3">
                        <label class="form-label text-secondary small fw-semibold">Observações internas</label>
                        <textarea name="observacoes" class="form-control" rows="2" placeholder="Ex: WhatsApp grupo finanças SP"></textarea>
                    </div>
                </div>
                <div class="modal-footer border-secondary-subtle d-flex justify-content-between p-3">
                    <button type="button" class="btn btn-sm btn-link text-secondary text-decoration-none" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-sm fw-bold rounded-pill px-4"
                        style="background:rgba(212,175,55,.15);color:#d4af37;border:1px solid rgba(212,175,55,.35);">
                        <i class="bi bi-person-check-fill me-1"></i> Confirmar
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ── Modal: Editar revendedor ──────────────────────────────────────────────── -->
<div class="modal fade" id="modalEditar" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-secondary-subtle shadow-lg" style="background:#1a1d27;">
            <form method="POST">
                <input type="hidden" name="action" value="editar">
                <input type="hidden" name="revendedor_id" id="editarRevId">
                <div class="modal-header border-secondary-subtle px-4 py-3">
                    <h6 class="modal-title fw-bold text-light mb-0">Editar revendedor</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body px-4 py-3">
                    <div class="mb-3">
                        <label class="form-label text-secondary small fw-semibold d-block">Categoria</label>
                        <div class="d-flex gap-2">
                            <input type="radio" class="btn-check" name="categoria" id="edit_cat_revendedor" value="revendedor">
                            <label class="btn btn-outline-secondary flex-grow-1" for="edit_cat_revendedor">Revendedor</label>
                            <input type="radio" class="btn-check" name="categoria" id="edit_cat_normal" value="normal">
                            <label class="btn btn-outline-secondary flex-grow-1" for="edit_cat_normal">Indicação comum</label>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label text-secondary small fw-semibold d-block">Tipo de comissão</label>
                        <div class="d-flex gap-2">
                            <input type="radio" class="btn-check" name="tipo_comissao" id="edit_tipo_fixa" value="fixa">
                            <label class="btn btn-outline-secondary flex-grow-1" for="edit_tipo_fixa">Fixa</label>
                            <input type="radio" class="btn-check" name="tipo_comissao" id="edit_tipo_2partes" value="duas_partes">
                            <label class="btn btn-outline-secondary flex-grow-1" for="edit_tipo_2partes">Em 2 partes</label>
                        </div>
                    </div>
                    <div class="row g-3 mb-0">
                        <div class="col-6">
                            <label class="form-label text-secondary small fw-semibold" id="edit_label_comissao">Comissão (%)</label>
                            <input type="number" name="comissao" id="editarComissao" class="form-control" min="1" max="100" step="0.5" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label text-secondary small fw-semibold">Chave PIX</label>
                            <input type="text" name="chave_pix" id="editarPix" class="form-control">
                        </div>
                    </div>
                    <div class="mt-3 d-none" id="edit_bloco_parte2">
                        <label class="form-label text-secondary small fw-semibold d-block">Gatilho da 2ª faixa</label>
                        <div class="d-flex gap-2 mb-2">
                            <input type="radio" class="btn-check" name="gatilho_parte1" id="edit_gat_clientes" value="clientes">
                            <label class="btn btn-outline-secondary flex-grow-1" for="edit_gat_clientes">Por quantidade de clientes</label>
                            <input type="radio" class="btn-check" name="gatilho_parte1" id="edit_gat_dias" value="dias">
                            <label class="btn btn-outline-secondary flex-grow-1" for="edit_gat_dias">Por tempo</label>
                        </div>
                        <div class="row g-3">
                            <div class="col-6" id="edit_bloco_limite_clientes">
                                <label class="form-label text-secondary small fw-semibold">Limite de clientes na 1ª faixa</label>
                                <input type="number" name="limite_parte1" id="editarLimite" class="form-control" min="1" step="1">
                            </div>
                            <div class="col-6 d-none" id="edit_bloco_limite_dias">
                                <label class="form-label text-secondary small fw-semibold">Dias na 1ª faixa</label>
                                <input type="number" name="limite_dias_parte1" id="editarLimiteDias" class="form-control" min="1" step="1">
                            </div>
                            <div class="col-6">
                                <label class="form-label text-secondary small fw-semibold">Comissão após o limite (%)</label>
                                <input type="number" name="comissao_parte2" id="editarComissaoParte2" class="form-control" min="1" max="100" step="0.5">
                            </div>
                        </div>
                        <div class="form-text text-secondary mt-1" style="font-size:.75rem;">
                            Clientes/dias que já ficaram travados numa faixa não mudam ao editar isso — só afeta daqui pra frente.
                        </div>
                    </div>
                    <div class="mt-3">
                        <label class="form-label text-secondary small fw-semibold">Observações</label>
                        <textarea name="observacoes" id="editarObs" class="form-control" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer border-secondary-subtle d-flex justify-content-between p-3">
                    <button type="button" class="btn btn-sm btn-link text-secondary text-decoration-none" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-sm fw-bold rounded-pill px-4"
                        style="background:rgba(212,175,55,.15);color:#d4af37;border:1px solid rgba(212,175,55,.35);">
                        Salvar
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ── Modal: Desativar ──────────────────────────────────────────────────────── -->
<div class="modal fade" id="modalDesativar" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content border-secondary-subtle shadow-lg" style="background:#1a1d27;">
            <form method="POST">
                <input type="hidden" name="action" value="desativar">
                <input type="hidden" name="revendedor_id" id="desativarRevId">
                <div class="modal-header border-secondary-subtle px-4 py-3">
                    <h6 class="modal-title fw-bold text-light mb-0">Desativar revendedor</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body px-4 py-3">
                    <p class="text-secondary mb-0" style="font-size:.85rem;">
                        <strong id="desativarNome" class="text-light"></strong> não poderá mais gerar comissões. Comissões pendentes permanecem registradas.
                    </p>
                </div>
                <div class="modal-footer border-secondary-subtle d-flex justify-content-between p-3">
                    <button type="button" class="btn btn-sm btn-link text-secondary text-decoration-none" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-sm fw-bold rounded-pill px-4"
                        style="background:rgba(239,68,68,.12);color:#fca5a5;border:1px solid rgba(239,68,68,.25);">
                        Desativar
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Alterna os campos da faixa 2 conforme o tipo de comissão escolhido (fixa / em 2 partes)
function _configurarToggleComissao(prefixo) {
    const radios = document.querySelectorAll(`input[name="tipo_comissao"]#${prefixo}_tipo_fixa, input[name="tipo_comissao"]#${prefixo}_tipo_2partes`);
    const bloco  = document.getElementById(`${prefixo}_bloco_parte2`);
    const label  = document.getElementById(`${prefixo}_label_comissao`);
    if (!bloco) return;
    function atualizar() {
        const emDuasPartes = document.getElementById(`${prefixo}_tipo_2partes`).checked;
        bloco.classList.toggle('d-none', !emDuasPartes);
        if (label) label.textContent = emDuasPartes ? 'Comissão até o limite (%)' : 'Comissão (%)';
    }
    radios.forEach(r => r.addEventListener('change', atualizar));
    atualizar();
}
_configurarToggleComissao('atr');
_configurarToggleComissao('edit');

// Alterna entre "limite de clientes" e "limite de dias" dentro da faixa 2
function _configurarToggleGatilho(prefixo) {
    const radios = document.querySelectorAll(`input[name="gatilho_parte1"]#${prefixo}_gat_clientes, input[name="gatilho_parte1"]#${prefixo}_gat_dias`);
    const blocoClientes = document.getElementById(`${prefixo}_bloco_limite_clientes`);
    const blocoDias      = document.getElementById(`${prefixo}_bloco_limite_dias`);
    if (!blocoClientes || !blocoDias) return;
    function atualizar() {
        const porDias = document.getElementById(`${prefixo}_gat_dias`).checked;
        blocoClientes.classList.toggle('d-none', porDias);
        blocoDias.classList.toggle('d-none', !porDias);
    }
    radios.forEach(r => r.addEventListener('change', atualizar));
    atualizar();
}
_configurarToggleGatilho('atr');
_configurarToggleGatilho('edit');

document.querySelectorAll('[data-bs-target="#modalEditar"]').forEach(function(btn) {
    btn.addEventListener('click', function() {
        document.getElementById('editarRevId').value    = this.dataset.rid;
        document.getElementById('editarComissao').value = this.dataset.perc;
        document.getElementById('editarPix').value      = this.dataset.pix;
        document.getElementById('editarObs').value      = this.dataset.obs;

        const ehCategoriaNormal = this.dataset.categoria === 'normal';
        document.getElementById(ehCategoriaNormal ? 'edit_cat_normal' : 'edit_cat_revendedor').checked = true;

        const ehDuasPartes = this.dataset.tipo === 'duas_partes';
        document.getElementById(ehDuasPartes ? 'edit_tipo_2partes' : 'edit_tipo_fixa').checked = true;
        document.getElementById('editarComissaoParte2').value = this.dataset.perc2 || '';
        document.getElementById('editarLimite').value         = this.dataset.limite || '';
        document.getElementById('editarLimiteDias').value     = this.dataset.limited || '';
        document.getElementById('edit_bloco_parte2').classList.toggle('d-none', !ehDuasPartes);
        document.getElementById('edit_label_comissao').textContent = ehDuasPartes ? 'Comissão até o limite (%)' : 'Comissão (%)';

        const ehGatilhoDias = this.dataset.gatilho === 'dias';
        document.getElementById(ehGatilhoDias ? 'edit_gat_dias' : 'edit_gat_clientes').checked = true;
        document.getElementById('edit_bloco_limite_clientes').classList.toggle('d-none', ehGatilhoDias);
        document.getElementById('edit_bloco_limite_dias').classList.toggle('d-none', !ehGatilhoDias);
    });
});
document.querySelectorAll('[data-bs-target="#modalDesativar"]').forEach(function(btn) {
    btn.addEventListener('click', function() {
        document.getElementById('desativarRevId').value       = this.dataset.rid;
        document.getElementById('desativarNome').textContent  = this.dataset.nome;
    });
});
</script>

<?php require_once '../geral/footer.php'; ?>

