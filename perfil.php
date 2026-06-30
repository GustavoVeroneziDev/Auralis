<?php
// ==============================================================================
// PERFIL.PHP — Perfil do usuário com conquistas e insígnias
// ==============================================================================
session_start();
if (!isset($_SESSION['usuario_id'])) {
    header("Location: /usuario/login.php");
    exit;
}
require_once 'config/conexao.php';
require_once 'config/funcoes.php';

verificarExpiracao($pdo);

$uid = $_SESSION['usuario_id'];

// ── Dados do usuário ────────────────────────────────────────────────────────
$stmtU = $pdo->prepare("SELECT Nome, Email, Plano, Tema, MomentoCriacao FROM Usuario WHERE IDUsuario = :uid LIMIT 1");
$stmtU->execute([':uid' => $uid]);
$usuario = $stmtU->fetch(PDO::FETCH_ASSOC);
if (!$usuario) { header("Location: /dashboard.php"); exit; }

$primeiroNome  = explode(' ', $usuario['Nome'])[0];
$iniciais      = implode('', array_map(fn($p) => strtoupper($p[0]), array_filter(explode(' ', $usuario['Nome']))));
$iniciais      = mb_substr($iniciais, 0, 2);
$dataCriacao   = new DateTime($usuario['MomentoCriacao']);
$diasAtivo     = (int)(new DateTime())->diff($dataCriacao)->days;
$dataMembro    = $dataCriacao->format('d/m/Y');
$plano         = strtolower($usuario['Plano'] ?? 'free');

// ── Award conquista veterano_90 se aplicável ─────────────────────────────────
if ($diasAtivo >= 90) {
    concederConquista('veterano_90');
}

// ── Stats ───────────────────────────────────────────────────────────────────
$stmtStats = $pdo->prepare("
    SELECT
        (SELECT COUNT(*) FROM Registro    WHERE FKUsuario = :uid  AND TipoRegistro IN ('receita','despesa')) AS transacoes,
        (SELECT COUNT(*) FROM Carteira    WHERE FKUsuarioDono = :uid2 ) AS carteiras,
        (SELECT COUNT(*) FROM Categoria   WHERE FKUsuario = :uid3 ) AS categorias
");
$stmtStats->execute([':uid' => $uid, ':uid2' => $uid, ':uid3' => $uid]);
$stats = $stmtStats->fetch(PDO::FETCH_ASSOC);

// ── Conquistas ───────────────────────────────────────────────────────────────
$conquistas = [];
try {
    $stmtC = $pdo->prepare("
        SELECT c.IDConquista, c.Slug, c.Nome, c.Descricao, c.Icone,
               COALESCE(c.ImagemUrl, '') AS ImagemUrl,
               c.Cor, c.Raridade, c.Ordem,
               uc.DataConquista
        FROM conquista c
        LEFT JOIN usuario_conquista uc
               ON uc.FKConquista = c.IDConquista AND uc.FKUsuario = :uid
        WHERE c.Ativo = 1
        ORDER BY c.Ordem ASC
    ");
    $stmtC->execute([':uid' => $uid]);
    $conquistas = $stmtC->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { /* colunas ainda não migradas em produção */ }

$totalConquistas     = count($conquistas);
$totalDesbloqueadas  = count(array_filter($conquistas, fn($c) => $c['DataConquista'] !== null));

// ── Mapa de raridade → label/cor ────────────────────────────────────────────
$raridadeInfo = [
    'comum'    => ['label' => 'Comum',    'cor' => '#808080'],
    'incomum'  => ['label' => 'Incomum',  'cor' => '#3eb23e'],
    'raro'     => ['label' => 'Raro',     'cor' => '#0070dd'],
    'epico'    => ['label' => 'Épico',    'cor' => '#a335ee'],
    'lendario' => ['label' => 'Lendário', 'cor' => '#ff8000'],
    'mitico'   => ['label' => 'Mítico',   'cor' => '#f3d3fd'],
];

// ── Cor da inicial do avatar baseada no plano ────────────────────────────────
$avatarCor = match($plano) {
    'vip'   => '#d4af37',
    'pro'   => '#7c3aed',
    default => '#6366f1',
};

$pageTitle = 'Perfil';
require_once 'geral/header.php';
?>
<style>
.perfil-avatar {
    width: 80px; height: 80px;
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.6rem; font-weight: 700;
    flex-shrink: 0;
    border: 3px solid;
}
.perfil-hero {
    background: var(--bg-card);
    border: 1px solid var(--bs-border-color);
    border-radius: 16px;
    padding: 2rem;
}
.stat-card {
    background: var(--bg-card);
    border: 1px solid var(--bs-border-color);
    border-radius: 12px;
    padding: 1.25rem 1rem;
    text-align: center;
}
.stat-value { font-size: 1.75rem; font-weight: 700; line-height: 1; }
.stat-label { font-size: 0.75rem; color: var(--text-muted); margin-top: 4px; }

.conquista-card {
    background: var(--bg-card);
    border: 1px solid var(--bs-border-color);
    border-radius: 14px;
    padding: 1.25rem;
    display: flex;
    align-items: flex-start;
    gap: 1rem;
    transition: transform .15s, box-shadow .15s;
}
.conquista-card:not(.bloqueada):hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(0,0,0,.25);
}
.conquista-card.bloqueada {
    opacity: .4;
    filter: grayscale(1);
}
.conquista-icon-wrap {
    width: 72px; height: 72px;
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.75rem;
    flex-shrink: 0;
    overflow: hidden;
    position: relative;
}

/* ── Frames por raridade ────────────────────────────── */
.badge-comum {
    background: rgba(128,128,128,0.08);
    border: 1.5px solid rgba(128,128,128,0.35);
}
.badge-incomum {
    background: rgba(62,178,62,0.1);
    border: 1.5px solid rgba(62,178,62,0.55);
    box-shadow: 0 0 8px rgba(62,178,62,0.2);
}
.badge-raro {
    background: rgba(0,112,221,0.1);
    border: 1.5px solid rgba(0,112,221,0.65);
    box-shadow: 0 0 10px rgba(0,112,221,0.25);
}
.badge-epico {
    background: linear-gradient(135deg,rgba(163,53,238,0.16),rgba(163,53,238,0.06));
    border: 2px solid rgba(163,53,238,0.75);
    box-shadow: 0 0 14px rgba(163,53,238,0.4), inset 0 0 8px rgba(163,53,238,0.08);
}
.badge-lendario {
    background: linear-gradient(135deg,rgba(255,128,0,0.18),rgba(255,128,0,0.06));
    border: 2px solid rgba(255,128,0,0.88);
    box-shadow: 0 0 16px rgba(255,128,0,0.5), inset 0 0 10px rgba(255,128,0,0.1);
    animation: glow-lendario 2.8s ease-in-out infinite;
}
@keyframes glow-lendario {
    0%,100% { box-shadow: 0 0 16px rgba(255,128,0,0.50), inset 0 0 10px rgba(255,128,0,0.10); }
    50%      { box-shadow: 0 0 28px rgba(255,128,0,0.75), inset 0 0 14px rgba(255,128,0,0.20); }
}
.badge-mitico {
    background: linear-gradient(135deg,rgba(243,211,253,0.15),rgba(243,211,253,0.04));
    border: 2px solid rgba(243,211,253,0.80);
    box-shadow: 0 0 20px rgba(243,211,253,0.5), inset 0 0 12px rgba(243,211,253,0.08);
    animation: glow-mitico 2.2s ease-in-out infinite;
}
@keyframes glow-mitico {
    0%,100% { box-shadow: 0 0 20px rgba(243,211,253,0.50), inset 0 0 12px rgba(243,211,253,0.08); }
    50%      { box-shadow: 0 0 38px rgba(243,211,253,0.88), inset 0 0 20px rgba(243,211,253,0.18); }
}
.conquista-card.bloqueada .conquista-icon-wrap {
    animation: none !important;
    box-shadow: none !important;
}

.conquista-nome { font-weight: 600; font-size: 0.9rem; margin-bottom: 2px; }
.conquista-desc { font-size: 0.78rem; color: var(--text-muted); line-height: 1.45; }
.conquista-footer { margin-top: 6px; font-size: 0.72rem; }
.raridade-pill {
    display: inline-flex; align-items: center;
    border-radius: 999px;
    padding: 2px 8px;
    font-size: 0.62rem;
    font-weight: 700;
    letter-spacing: .05em;
}
</style>

<main class="container-fluid py-4 mt-2 flex-grow-1" style="max-width:900px;padding-inline:var(--space-page-x);">

    <!-- Hero -->
    <div class="perfil-hero mb-4 d-flex align-items-center gap-4 flex-wrap">
        <div class="perfil-avatar"
             style="background:<?= htmlspecialchars($avatarCor) ?>22;color:<?= htmlspecialchars($avatarCor) ?>;border-color:<?= htmlspecialchars($avatarCor) ?>55;">
            <?= htmlspecialchars($iniciais) ?>
        </div>
        <div class="flex-grow-1">
            <h2 class="fw-bold mb-1" style="font-size:1.4rem;"><?= htmlspecialchars($usuario['Nome']) ?></h2>
            <div class="text-secondary small mb-2"><?= htmlspecialchars($usuario['Email']) ?></div>
            <div class="d-flex align-items-center gap-2 flex-wrap">
                <?php if ($plano === 'vip'): ?>
                    <span class="badge" style="background:#d4af3722;color:#d4af37;border:1px solid #d4af3755;font-size:0.72rem;">
                        <i class="bi bi-gem me-1"></i>VIP
                    </span>
                <?php elseif ($plano === 'pro'): ?>
                    <span class="badge" style="background:#7c3aed22;color:#a78bfa;border:1px solid #7c3aed55;font-size:0.72rem;">
                        <i class="bi bi-crown-fill me-1"></i>PRO
                    </span>
                <?php else: ?>
                    <span class="badge" style="background:#ffffff11;color:#9ca3af;border:1px solid #ffffff22;font-size:0.72rem;">FREE</span>
                <?php endif; ?>
                <span class="text-muted small">
                    <i class="bi bi-calendar3 me-1"></i>Membro desde <?= $dataMembro ?>
                </span>
                <span class="text-muted small">
                    <i class="bi bi-clock-history me-1"></i><?= $diasAtivo ?> dia<?= $diasAtivo !== 1 ? 's' : '' ?> no Auralis
                </span>
            </div>
        </div>
        <a href="/configuracoes.php" class="btn btn-sm btn-outline-secondary rounded-pill">
            <i class="bi bi-gear me-1"></i> Configurações
        </a>
    </div>

    <!-- Stats -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-4">
            <div class="stat-card">
                <div class="stat-value" style="color:var(--accent);"><?= number_format((int)$stats['transacoes']) ?></div>
                <div class="stat-label"><i class="bi bi-receipt me-1"></i>Transações</div>
            </div>
        </div>
        <div class="col-6 col-md-4">
            <div class="stat-card">
                <div class="stat-value" style="color:#22c55e;"><?= (int)$stats['carteiras'] ?></div>
                <div class="stat-label"><i class="bi bi-wallet2 me-1"></i>Carteiras</div>
            </div>
        </div>
        <div class="col-6 col-md-4">
            <div class="stat-card">
                <div class="stat-value" style="color:#06b6d4;"><?= (int)$stats['categorias'] ?></div>
                <div class="stat-label"><i class="bi bi-tag me-1"></i>Categorias</div>
            </div>
        </div>
    </div>

    <!-- Conquistas -->
    <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
        <div>
            <h5 class="fw-bold mb-0"><i class="bi bi-trophy-fill me-2" style="color:var(--accent);"></i>Conquistas</h5>
            <div class="text-muted small mt-1">
                <?= $totalDesbloqueadas ?> de <?= $totalConquistas ?> desbloqueada<?= $totalDesbloqueadas !== 1 ? 's' : '' ?>
            </div>
        </div>
        <?php if ($totalConquistas > 0): ?>
        <div style="height:6px;width:160px;background:var(--border-color);border-radius:999px;overflow:hidden;">
            <div style="height:100%;width:<?= round($totalDesbloqueadas / $totalConquistas * 100) ?>%;background:var(--accent);border-radius:999px;transition:width .4s;"></div>
        </div>
        <?php endif; ?>
    </div>

    <?php if (empty($conquistas)): ?>
    <div class="text-center py-5 text-muted">
        <i class="bi bi-trophy" style="font-size:2.5rem;opacity:.3;display:block;margin-bottom:1rem;"></i>
        <p class="mb-0">Nenhuma conquista cadastrada ainda.</p>
    </div>
    <?php else: ?>
    <div class="row g-3">
        <?php foreach ($conquistas as $c):
            $desbloqueada = $c['DataConquista'] !== null;
            $raridade     = $raridadeInfo[$c['Raridade']] ?? $raridadeInfo['comum'];

            // Data relativa de desbloqueio
            $dataDesbloq = '';
            if ($desbloqueada) {
                $diff = (new DateTime())->diff(new DateTime($c['DataConquista']));
                if ($diff->days === 0)      $dataDesbloq = 'hoje';
                elseif ($diff->days === 1)  $dataDesbloq = 'ontem';
                elseif ($diff->days < 30)   $dataDesbloq = "há {$diff->days} dias";
                elseif ($diff->days < 365)  $dataDesbloq = 'há ' . (int)($diff->days / 30) . ' mês' . ((int)($diff->days / 30) > 1 ? 'es' : '');
                else                        $dataDesbloq = 'há ' . (int)($diff->days / 365) . ' ano' . ((int)($diff->days / 365) > 1 ? 's' : '');
            }
        ?>
        <div class="col-12 col-md-6">
            <div class="conquista-card <?= $desbloqueada ? '' : 'bloqueada' ?>">
                <!-- Ícone -->
                <div class="conquista-icon-wrap badge-<?= htmlspecialchars($c['Raridade'] ?? 'comum') ?>">
                    <?php if (!$desbloqueada): ?>
                        <i class="bi bi-lock-fill" style="color:rgba(156,163,175,0.5);font-size:1.4rem;"></i>
                    <?php elseif (!empty($c['ImagemUrl'])): ?>
                        <img src="<?= htmlspecialchars($c['ImagemUrl']) ?>" alt="<?= htmlspecialchars($c['Nome']) ?>" style="width:78%;height:78%;object-fit:contain;border-radius:50%;">
                    <?php else: ?>
                        <i class="bi <?= htmlspecialchars($c['Icone']) ?>" style="color:<?= htmlspecialchars($c['Cor']) ?>;"></i>
                    <?php endif; ?>
                </div>

                <!-- Info -->
                <div class="flex-grow-1 min-w-0">
                    <div class="conquista-nome"><?= htmlspecialchars($c['Nome']) ?></div>
                    <div class="conquista-desc"><?= htmlspecialchars($c['Descricao']) ?></div>
                    <div class="conquista-footer d-flex align-items-center gap-2 flex-wrap">
                        <span class="raridade-pill"
                              style="background:<?= $raridade['cor'] ?>22;color:<?= $raridade['cor'] ?>;border:1px solid <?= $raridade['cor'] ?>44;">
                            <?= $raridade['label'] ?>
                        </span>
                        <?php if ($desbloqueada): ?>
                            <span class="text-muted"><i class="bi bi-check2-circle me-1" style="color:<?= htmlspecialchars($c['Cor']) ?>;"></i><?= $dataDesbloq ?></span>
                        <?php else: ?>
                            <span class="text-muted"><i class="bi bi-lock me-1"></i>Bloqueada</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

</main>

<?php require_once 'geral/footer.php'; ?>
