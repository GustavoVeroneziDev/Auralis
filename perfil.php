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
$stmtU = $pdo->prepare("SELECT Nome, Email, Plano, Tema, MomentoCriacao, FotoPerfil FROM Usuario WHERE IDUsuario = :uid LIMIT 1");
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
        ORDER BY
            CASE WHEN uc.DataConquista IS NOT NULL THEN 0 ELSE 1 END ASC,
            CASE c.Raridade
                WHEN 'mitico'   THEN 1
                WHEN 'lendario' THEN 2
                WHEN 'epico'    THEN 3
                WHEN 'raro'     THEN 4
                WHEN 'incomum'  THEN 5
                WHEN 'comum'    THEN 6
                ELSE 7
            END ASC
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

// Avatar DiceBear
$avatarConfig = [];
$avatarPreviewUrl = '';
if (!empty($usuario['FotoPerfil'])) {
    $dec = json_decode($usuario['FotoPerfil'], true);
    if (is_array($dec) && ($dec['style'] ?? '') === 'avataaars') {
        $avatarConfig     = $dec;
        $avatarPreviewUrl = getAvatarUrl($dec);
    }
}
$avatarDefaults = [
    'skinColor' => 'd08b5b', 'hair' => 'shortCurly', 'hairColor' => '2c1b18',
    'eyes' => 'default', 'eyebrows' => 'default', 'mouth' => 'smile',
    'clothing' => 'hoodie', 'clothingColor' => '3c4f5c',
    'accessories' => '', 'facialHair' => '', 'facialHairColor' => '2c1b18',
    'backgroundColor' => 'transparent',
];
$cfg = array_merge($avatarDefaults, $avatarConfig);

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

    <?php if (isset($_GET['sucesso']) && $_GET['sucesso'] === 'personagem'): ?>
    <div class="alert alert-success rounded-3 py-2 px-3 d-flex align-items-center gap-2 mb-3" style="font-size:0.9rem;">
        <i class="bi bi-check-circle-fill"></i> Personagem salvo com sucesso!
    </div>
    <?php endif; ?>

    <!-- Hero -->
    <div class="perfil-hero mb-4 d-flex align-items-center gap-4 flex-wrap">
        <?php if ($avatarPreviewUrl): ?>
        <div style="width:80px;height:80px;border-radius:50%;overflow:hidden;flex-shrink:0;border:3px solid <?= htmlspecialchars($avatarCor) ?>88;background:#<?= htmlspecialchars($cfg['backgroundColor'] !== 'transparent' ? $cfg['backgroundColor'] : '1e2028') ?>;">
            <img src="<?= htmlspecialchars($avatarPreviewUrl) ?>" alt="Avatar" style="width:100%;height:100%;object-fit:cover;">
        </div>
        <?php else: ?>
        <div class="perfil-avatar"
             style="background:<?= htmlspecialchars($avatarCor) ?>22;color:<?= htmlspecialchars($avatarCor) ?>;border-color:<?= htmlspecialchars($avatarCor) ?>55;">
            <?= htmlspecialchars($iniciais) ?>
        </div>
        <?php endif; ?>
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

    <!-- ── Meu Personagem ─────────────────────────────────────────────────── -->
    <div id="personagem" class="mb-4">
        <h5 class="fw-bold mb-3"><i class="bi bi-person-bounding-box me-2" style="color:#ec4899;"></i>Meu Personagem</h5>

        <div class="row g-0 rounded-4 overflow-hidden" style="background:var(--bg-card);border:1px solid var(--bs-border-color);">

            <!-- Preview -->
            <div class="col-12 col-md-4 d-flex flex-column align-items-center justify-content-center p-4 gap-3"
                 style="border-right:1px solid var(--bs-border-color);">
                <div id="avatarWrapper"
                     style="width:260px;height:260px;border-radius:50%;overflow:hidden;border:4px solid var(--card-border-color);background:#<?= htmlspecialchars($cfg['backgroundColor'] !== 'transparent' ? $cfg['backgroundColor'] : '00000000') ?>;">
                    <img id="avatarPreview"
                         src="<?= htmlspecialchars($avatarPreviewUrl ?: getAvatarUrl($cfg)) ?>"
                         alt="Seu personagem" style="width:100%;height:100%;object-fit:cover;">
                </div>
                <form method="POST" action="/usuario/processa_avatar.php" id="avatarForm" class="w-100" style="max-width:260px;">
                    <input type="hidden" name="skinColor"       id="f_skinColor">
                    <input type="hidden" name="hair"            id="f_hair">
                    <input type="hidden" name="hairColor"       id="f_hairColor">
                    <input type="hidden" name="eyes"            id="f_eyes">
                    <input type="hidden" name="eyebrows"        id="f_eyebrows">
                    <input type="hidden" name="mouth"           id="f_mouth">
                    <input type="hidden" name="clothing"        id="f_clothing">
                    <input type="hidden" name="clothingColor"   id="f_clothingColor">
                    <input type="hidden" name="accessories"     id="f_accessories">
                    <input type="hidden" name="facialHair"      id="f_facialHair">
                    <input type="hidden" name="facialHairColor" id="f_facialHairColor">
                    <input type="hidden" name="backgroundColor" id="f_backgroundColor">
                    <button type="submit" class="btn w-100 fw-bold rounded-pill py-2"
                            style="background:var(--accent);color:#fff;">
                        <i class="bi bi-save me-1"></i> Salvar personagem
                    </button>
                </form>
            </div>

            <!-- Controles (scrollável) -->
            <div class="col-12 col-md-8 p-4" style="max-height:520px;overflow-y:auto;">

                <!-- Pele -->
                <div class="mb-4">
                    <label class="av-label">TOM DE PELE</label>
                    <div class="d-flex flex-wrap gap-2">
                        <?php foreach (['614335','ae5d29','d08b5b','edb98a','f8d25c','ffdbb4','fd9841','ffffff'] as $c): ?>
                        <button type="button" class="swatch-btn" data-key="skinColor" data-val="<?= $c ?>" style="background:#<?= $c ?>;"></button>
                        <?php endforeach; ?>
                    </div>
                </div>
                <hr class="av-sep">

                <!-- Cabelo -->
                <div class="mb-4">
                    <label class="av-label">CABELO</label>
                    <div class="cycle-row">
                        <button type="button" class="cycle-btn" id="hair_prev"><i class="bi bi-chevron-left"></i></button>
                        <span class="cycle-label" id="hair_label">–</span>
                        <button type="button" class="cycle-btn" id="hair_next"><i class="bi bi-chevron-right"></i></button>
                    </div>
                </div>

                <div class="mb-4" id="row_hairColor">
                    <label class="av-label">COR DO CABELO</label>
                    <div class="d-flex flex-wrap gap-2">
                        <?php foreach (['2c1b18','4a312c','b58143','c93305','e8e1e1','f59797','724133','a55728','d6b370'] as $c): ?>
                        <button type="button" class="swatch-btn" data-key="hairColor" data-val="<?= $c ?>" style="background:#<?= $c ?>;"></button>
                        <?php endforeach; ?>
                    </div>
                </div>
                <hr class="av-sep">

                <!-- Olhos -->
                <div class="mb-4">
                    <label class="av-label">OLHOS</label>
                    <div class="cycle-row">
                        <button type="button" class="cycle-btn" id="eyes_prev"><i class="bi bi-chevron-left"></i></button>
                        <span class="cycle-label" id="eyes_label">–</span>
                        <button type="button" class="cycle-btn" id="eyes_next"><i class="bi bi-chevron-right"></i></button>
                    </div>
                </div>

                <div class="mb-4">
                    <label class="av-label">SOBRANCELHAS</label>
                    <div class="cycle-row">
                        <button type="button" class="cycle-btn" id="eyebrows_prev"><i class="bi bi-chevron-left"></i></button>
                        <span class="cycle-label" id="eyebrows_label">–</span>
                        <button type="button" class="cycle-btn" id="eyebrows_next"><i class="bi bi-chevron-right"></i></button>
                    </div>
                </div>

                <div class="mb-4">
                    <label class="av-label">BOCA</label>
                    <div class="cycle-row">
                        <button type="button" class="cycle-btn" id="mouth_prev"><i class="bi bi-chevron-left"></i></button>
                        <span class="cycle-label" id="mouth_label">–</span>
                        <button type="button" class="cycle-btn" id="mouth_next"><i class="bi bi-chevron-right"></i></button>
                    </div>
                </div>
                <hr class="av-sep">

                <!-- Roupa -->
                <div class="mb-4">
                    <label class="av-label">ROUPA</label>
                    <div class="cycle-row">
                        <button type="button" class="cycle-btn" id="clothing_prev"><i class="bi bi-chevron-left"></i></button>
                        <span class="cycle-label" id="clothing_label">–</span>
                        <button type="button" class="cycle-btn" id="clothing_next"><i class="bi bi-chevron-right"></i></button>
                    </div>
                </div>

                <div class="mb-4">
                    <label class="av-label">COR DA ROUPA</label>
                    <div class="d-flex flex-wrap gap-2">
                        <?php foreach (['262e33','3c4f5c','65c9ff','929598','a7ffc4','b1e2ff','e6e6e6','ff5c5c','ff488e','ffafb9','ffd670','ffffed'] as $c): ?>
                        <button type="button" class="swatch-btn" data-key="clothingColor" data-val="<?= $c ?>" style="background:#<?= $c ?>;"></button>
                        <?php endforeach; ?>
                    </div>
                </div>
                <hr class="av-sep">

                <!-- Acessórios -->
                <div class="mb-4">
                    <label class="av-label">ACESSÓRIOS</label>
                    <div class="d-flex flex-wrap gap-2">
                        <?php foreach (['' => 'Nenhum','prescription01' => 'Óculos 1','prescription02' => 'Óculos 2','round' => 'Redondo','sunglasses' => 'Sol','kurt' => 'Kurt','wayfarers' => 'Wayfarer'] as $v => $lbl): ?>
                        <button type="button" class="chip-btn" data-key="accessories" data-val="<?= htmlspecialchars($v) ?>"><?= htmlspecialchars($lbl) ?></button>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Barba -->
                <div class="mb-4">
                    <label class="av-label">BARBA / BIGODE</label>
                    <div class="d-flex flex-wrap gap-2">
                        <?php foreach (['' => 'Nenhuma','beardLight' => 'Leve','beardMedium' => 'Média','beardMajestic' => 'Cheia','moustacheFancy' => 'Bigode','moustacheMagnum' => 'Big Bigode'] as $v => $lbl): ?>
                        <button type="button" class="chip-btn" data-key="facialHair" data-val="<?= htmlspecialchars($v) ?>"><?= htmlspecialchars($lbl) ?></button>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="mb-4" id="row_facialHairColor" style="display:none;">
                    <label class="av-label">COR DA BARBA</label>
                    <div class="d-flex flex-wrap gap-2">
                        <?php foreach (['2c1b18','4a312c','b58143','c93305','e8e1e1','f59797','724133','a55728','d6b370'] as $c): ?>
                        <button type="button" class="swatch-btn" data-key="facialHairColor" data-val="<?= $c ?>" style="background:#<?= $c ?>;"></button>
                        <?php endforeach; ?>
                    </div>
                </div>
                <hr class="av-sep">

                <!-- Fundo -->
                <div class="mb-2">
                    <label class="av-label">FUNDO</label>
                    <div class="d-flex flex-wrap gap-2">
                        <?php foreach (['b6e3f4','c0aede','d1d4f9','ffd5dc','ffeba4','transparent'] as $c): ?>
                        <button type="button"
                                class="swatch-btn <?= $c === 'transparent' ? 'swatch-transparent' : '' ?>"
                                data-key="backgroundColor" data-val="<?= $c ?>"
                                style="<?= $c !== 'transparent' ? "background:#{$c};" : '' ?>"></button>
                        <?php endforeach; ?>
                    </div>
                </div>

            </div><!-- /controles -->
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

<style>
.av-label { display:block; font-size:0.75rem; font-weight:700; letter-spacing:.08em; color:var(--text-secondary); margin-bottom:8px; }
.av-sep   { border-color:var(--card-border-color); margin:0 0 1.25rem; }
.swatch-btn {
    width:34px; height:34px; border-radius:50%;
    border:3px solid transparent; cursor:pointer; flex-shrink:0;
    transition:transform .15s, border-color .15s;
}
.swatch-btn:hover  { transform:scale(1.15); }
.swatch-btn.active { border-color:var(--accent); transform:scale(1.18); }
.swatch-transparent {
    background: repeating-conic-gradient(#888 0% 25%, #fff 0% 50%) 0 0 / 10px 10px !important;
}
.cycle-row  { display:flex; align-items:center; gap:10px; }
.cycle-btn  {
    background:var(--bg-main); border:1px solid var(--card-border-color);
    color:var(--text-main); border-radius:8px;
    width:34px; height:34px; display:flex; align-items:center; justify-content:center;
    cursor:pointer; flex-shrink:0; transition:background .15s;
}
.cycle-btn:hover { background:var(--card-border-color); }
.cycle-label {
    flex:1; text-align:center; font-size:0.85rem; font-weight:600; color:var(--text-main);
    background:var(--bg-main); border:1px solid var(--card-border-color);
    border-radius:8px; padding:5px 10px;
}
.chip-btn {
    background:var(--bg-main); border:1px solid var(--card-border-color);
    color:var(--text-main); border-radius:999px; padding:3px 12px;
    font-size:0.8rem; cursor:pointer; transition:background .15s, border-color .15s, color .15s;
}
.chip-btn:hover  { border-color:var(--accent); }
.chip-btn.active { background:var(--accent); border-color:var(--accent); color:#fff; font-weight:600; }
</style>

<script>
(function () {
    var cfg = <?= json_encode($cfg) ?>;

    var OPTS = {
        hair: [
            {v:'shortCurly',           l:'Cacheado curto'},
            {v:'shortFlat',            l:'Liso curto'},
            {v:'shortWaved',           l:'Ondulado curto'},
            {v:'shortRound',           l:'Arredondado curto'},
            {v:'sides',                l:'Raspado nas laterais'},
            {v:'theCaesar',            l:'César'},
            {v:'theCaesarAndSidePart', l:'César com repartido'},
            {v:'frizzle',              l:'Frizzy'},
            {v:'shaggy',               l:'Despenteado'},
            {v:'shaggyMullet',         l:'Mullet'},
            {v:'dreads01',             l:'Dreads 1'},
            {v:'dreads02',             l:'Dreads 2'},
            {v:'bob',                  l:'Bob'},
            {v:'bun',                  l:'Coque'},
            {v:'curly',                l:'Cacheado longo'},
            {v:'curvy',                l:'Ondulado longo'},
            {v:'straight01',           l:'Liso longo'},
            {v:'straight02',           l:'Liso longo 2'},
            {v:'straightAndStrand',    l:'Liso com mecha'},
            {v:'fro',                  l:'Afro'},
            {v:'froBand',              l:'Afro com faixa'},
            {v:'bigHair',              l:'Volume'},
            {v:'dreads',               l:'Dreads longos'},
            {v:'frida',                l:'Frida'},
            {v:'miaWallace',           l:'Mia Wallace'},
            {v:'longButNotTooLong',    l:'Médio'},
            {v:'shavedSides',          l:'Raspado com comprido'},
            {v:'hat',                  l:'Boné'},
            {v:'hijab',                l:'Hijab'},
            {v:'turban',               l:'Turbante'},
            {v:'winterHat1',           l:'Touca 1'},
            {v:'winterHat02',          l:'Touca 2'},
            {v:'winterHat03',          l:'Touca 3'},
            {v:'winterHat04',          l:'Touca 4'},
        ],
        eyes: [
            {v:'default',   l:'Normal'},
            {v:'happy',     l:'Feliz'},
            {v:'wink',      l:'Piscada'},
            {v:'hearts',    l:'Coração'},
            {v:'squint',    l:'Semicerrado'},
            {v:'surprised', l:'Surpreso'},
            {v:'side',      l:'De lado'},
            {v:'closed',    l:'Fechado'},
            {v:'cry',       l:'Chorando'},
            {v:'xDizzy',    l:'Tonto'},
            {v:'eyeRoll',   l:'Revirado'},
            {v:'winkWacky', l:'Piscada louca'},
        ],
        eyebrows: [
            {v:'default',              l:'Normal'},
            {v:'defaultNatural',       l:'Natural'},
            {v:'raisedExcited',        l:'Levantado'},
            {v:'raisedExcitedNatural', l:'Levantado natural'},
            {v:'angry',                l:'Zangado'},
            {v:'angryNatural',         l:'Zangado natural'},
            {v:'upDown',               l:'Assimétrico'},
            {v:'upDownNatural',        l:'Assimétrico natural'},
            {v:'flatNatural',          l:'Plano'},
            {v:'frownNatural',         l:'Franzido'},
            {v:'sadConcerned',         l:'Triste'},
            {v:'sadConcernedNatural',  l:'Triste natural'},
            {v:'unibrowNatural',       l:'Unidos'},
        ],
        mouth: [
            {v:'smile',      l:'Sorriso'},
            {v:'default',    l:'Normal'},
            {v:'serious',    l:'Sério'},
            {v:'tongue',     l:'Língua'},
            {v:'twinkle',    l:'Encantado'},
            {v:'eating',     l:'Comendo'},
            {v:'sad',        l:'Triste'},
            {v:'concerned',  l:'Preocupado'},
            {v:'grimace',    l:'Grimace'},
            {v:'screamOpen', l:'Gritando'},
            {v:'disbelief',  l:'Descrença'},
            {v:'vomit',      l:'Enjoado'},
        ],
        clothing: [
            {v:'hoodie',          l:'Moletom'},
            {v:'shirtCrewNeck',   l:'Camiseta'},
            {v:'shirtVNeck',      l:'Camiseta V'},
            {v:'shirtScoopNeck',  l:'Decote oval'},
            {v:'blazerAndShirt',  l:'Blazer + Camisa'},
            {v:'blazerAndSweater',l:'Blazer + Suéter'},
            {v:'collarAndSweater',l:'Suéter'},
            {v:'overall',         l:'Macacão'},
            {v:'graphicShirt',    l:'Camiseta Estampada'},
        ],
    };

    var NO_HAIR_COLOR = ['noHair','hat','hijab','turban','winterHat1','winterHat2','winterHat3','winterHat4','eyepatch'];

    function idxOf(arr, val) {
        for (var i = 0; i < arr.length; i++) if (arr[i].v === val) return i;
        return 0;
    }

    function buildUrl() {
        var p = [];
        p.push('skinColor[]='    + cfg.skinColor);
        if (cfg.hair) p.push('top[]=' + cfg.hair);
        p.push('hairColor[]='    + cfg.hairColor);
        p.push('eyes[]='         + cfg.eyes);
        p.push('eyebrows[]='     + cfg.eyebrows);
        p.push('mouth[]='        + cfg.mouth);
        p.push('clothing[]='     + cfg.clothing);
        p.push('clothesColor[]=' + cfg.clothingColor);
        if (cfg.accessories) {
            p.push('accessories[]='         + cfg.accessories);
            p.push('accessoriesProbability=100');
        } else {
            p.push('accessoriesProbability=0');
        }
        if (cfg.facialHair) {
            p.push('facialHair[]='          + cfg.facialHair);
            p.push('facialHairColor[]='     + cfg.facialHairColor);
            p.push('facialHairProbability=100');
        } else {
            p.push('facialHairProbability=0');
        }
        if (cfg.backgroundColor !== 'transparent') {
            p.push('backgroundColor[]=' + cfg.backgroundColor);
        }
        return 'https://api.dicebear.com/9.x/avataaars/svg?' + p.join('&');
    }

    function sync() {
        // Preview
        document.getElementById('avatarPreview').src = buildUrl();
        var bg = cfg.backgroundColor === 'transparent' ? 'transparent' : '#' + cfg.backgroundColor;
        document.getElementById('avatarWrapper').style.background = bg;

        // Hidden inputs
        ['skinColor','hair','hairColor','eyes','eyebrows','mouth','clothing','clothingColor',
         'accessories','facialHair','facialHairColor','backgroundColor'].forEach(function(k) {
            var el = document.getElementById('f_' + k);
            if (el) el.value = cfg[k] || '';
        });

        // Mostrar/ocultar cor do cabelo
        var rHair = document.getElementById('row_hairColor');
        if (rHair) rHair.style.display = NO_HAIR_COLOR.indexOf(cfg.hair) !== -1 ? 'none' : '';

        // Mostrar/ocultar cor da barba
        var rFacial = document.getElementById('row_facialHairColor');
        if (rFacial) rFacial.style.display = cfg.facialHair ? '' : 'none';

        // Swatches
        document.querySelectorAll('.swatch-btn').forEach(function(b) {
            b.classList.toggle('active', b.dataset.val === cfg[b.dataset.key]);
        });
        document.querySelectorAll('.chip-btn').forEach(function(b) {
            b.classList.toggle('active', b.dataset.val === cfg[b.dataset.key]);
        });
    }

    // Controles de ciclo
    Object.keys(OPTS).forEach(function(name) {
        var opts   = OPTS[name];
        var label  = document.getElementById(name + '_label');
        var btnP   = document.getElementById(name + '_prev');
        var btnN   = document.getElementById(name + '_next');

        function render() {
            var i = idxOf(opts, cfg[name]);
            label.textContent = opts[i].l + ' (' + (i + 1) + '/' + opts.length + ')';
        }

        btnP.addEventListener('click', function() {
            var i = idxOf(opts, cfg[name]);
            cfg[name] = opts[(i - 1 + opts.length) % opts.length].v;
            render(); sync();
        });
        btnN.addEventListener('click', function() {
            var i = idxOf(opts, cfg[name]);
            cfg[name] = opts[(i + 1) % opts.length].v;
            render(); sync();
        });
        render();
    });

    // Swatches e chips
    document.querySelectorAll('.swatch-btn').forEach(function(b) {
        b.addEventListener('click', function() { cfg[b.dataset.key] = b.dataset.val; sync(); });
    });
    document.querySelectorAll('.chip-btn').forEach(function(b) {
        b.addEventListener('click', function() { cfg[b.dataset.key] = b.dataset.val; sync(); });
    });

    sync();
})();
</script>

<?php require_once 'geral/footer.php'; ?>
