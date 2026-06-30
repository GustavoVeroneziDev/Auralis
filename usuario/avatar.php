<?php
session_start();
if (!isset($_SESSION['usuario_id'])) {
    header("Location: /usuario/login.php");
    exit;
}
require_once '../config/conexao.php';
require_once '../config/funcoes.php';

// Carrega config salva
$stmtAv = $pdo->prepare("SELECT FotoPerfil FROM Usuario WHERE IDUsuario = :uid LIMIT 1");
$stmtAv->execute([':uid' => $_SESSION['usuario_id']]);
$fpRaw = $stmtAv->fetchColumn();

$savedConfig = [];
if ($fpRaw) {
    $decoded = json_decode($fpRaw, true);
    if (is_array($decoded) && ($decoded['style'] ?? '') === 'avataaars') {
        $savedConfig = $decoded;
    }
}

$cfg = array_merge([
    'skinColor'       => 'f8d25c',
    'hair'            => 'shortHairShortCurly',
    'hairColor'       => '2c1b18',
    'eyes'            => 'default',
    'eyebrows'        => 'default',
    'mouth'           => 'smile',
    'clothing'        => 'hoodie',
    'clothingColor'   => '3c4f5c',
    'accessories'     => '',
    'facialHair'      => '',
    'facialHairColor' => '2c1b18',
    'backgroundColor' => 'b6e3f4',
], $savedConfig);

include '../geral/header.php';
?>

<main class="container py-4" style="max-width:860px;">

    <div class="d-flex align-items-center gap-3 mb-4">
        <a href="/perfil.php" class="btn btn-sm btn-outline-secondary rounded-pill">
            <i class="bi bi-arrow-left"></i>
        </a>
        <div>
            <h4 class="mb-0 fw-bold">Criar Personagem</h4>
            <small class="text-secondary">Monte seu avatar e ele vai aparecer em todo o sistema</small>
        </div>
    </div>

    <?php if (isset($_GET['sucesso'])): ?>
    <div class="alert alert-success rounded-3 py-2 px-3 d-flex align-items-center gap-2 mb-3" style="font-size:0.9rem;">
        <i class="bi bi-check-circle-fill"></i> Personagem salvo com sucesso!
    </div>
    <?php endif; ?>

    <div class="row g-4">

        <!-- ── Preview ── -->
        <div class="col-12 col-md-4">
            <div class="card border-0 rounded-4 text-center p-4 sticky-md-top" style="background:var(--bg-card);top:80px;">
                <div class="d-flex justify-content-center mb-3">
                    <div id="avatarWrapper" style="width:180px;height:180px;border-radius:50%;overflow:hidden;background:#b6e3f4;border:4px solid var(--card-border-color);">
                        <img id="avatarPreview" src="" alt="Seu personagem" style="width:100%;height:100%;object-fit:cover;">
                    </div>
                </div>
                <p class="text-secondary small mb-3">Veja como ficará seu personagem</p>
                <form method="POST" action="/usuario/processa_avatar.php" id="avatarForm">
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
                            style="background:var(--accent);color:#fff;font-size:0.95rem;">
                        <i class="bi bi-save me-1"></i> Salvar personagem
                    </button>
                </form>
            </div>
        </div>

        <!-- ── Controles ── -->
        <div class="col-12 col-md-8">
            <div class="card border-0 rounded-4 p-4" style="background:var(--bg-card);">

                <!-- Pele -->
                <div class="mb-4">
                    <label class="fw-semibold mb-2 d-block" style="font-size:0.85rem;color:var(--text-secondary);">TOM DE PELE</label>
                    <div class="d-flex flex-wrap gap-2" id="grp_skinColor">
                        <?php foreach (['614335','ae5d29','d08b5b','edb98a','f8d25c','ffdbb4','fd9841','ffffff'] as $c): ?>
                        <button type="button" class="swatch-btn" data-key="skinColor" data-val="<?= $c ?>"
                                style="background:#<?= $c ?>;"></button>
                        <?php endforeach; ?>
                    </div>
                </div>

                <hr style="border-color:var(--card-border-color);">

                <!-- Cabelo -->
                <div class="mb-4">
                    <label class="fw-semibold mb-2 d-block" style="font-size:0.85rem;color:var(--text-secondary);">CABELO</label>
                    <div class="cycle-row" id="grp_hair">
                        <button type="button" class="cycle-btn" id="hair_prev"><i class="bi bi-chevron-left"></i></button>
                        <span class="cycle-label" id="hair_label">–</span>
                        <button type="button" class="cycle-btn" id="hair_next"><i class="bi bi-chevron-right"></i></button>
                    </div>
                </div>

                <!-- Cor do cabelo -->
                <div class="mb-4" id="row_hairColor">
                    <label class="fw-semibold mb-2 d-block" style="font-size:0.85rem;color:var(--text-secondary);">COR DO CABELO</label>
                    <div class="d-flex flex-wrap gap-2" id="grp_hairColor">
                        <?php foreach (['2c1b18','4a312c','b58143','c93305','e8e1e1','f59797','724133','a55728','d6b370'] as $c): ?>
                        <button type="button" class="swatch-btn" data-key="hairColor" data-val="<?= $c ?>"
                                style="background:#<?= $c ?>;"></button>
                        <?php endforeach; ?>
                    </div>
                </div>

                <hr style="border-color:var(--card-border-color);">

                <!-- Olhos -->
                <div class="mb-4">
                    <label class="fw-semibold mb-2 d-block" style="font-size:0.85rem;color:var(--text-secondary);">OLHOS</label>
                    <div class="cycle-row" id="grp_eyes">
                        <button type="button" class="cycle-btn" id="eyes_prev"><i class="bi bi-chevron-left"></i></button>
                        <span class="cycle-label" id="eyes_label">–</span>
                        <button type="button" class="cycle-btn" id="eyes_next"><i class="bi bi-chevron-right"></i></button>
                    </div>
                </div>

                <!-- Sobrancelhas -->
                <div class="mb-4">
                    <label class="fw-semibold mb-2 d-block" style="font-size:0.85rem;color:var(--text-secondary);">SOBRANCELHAS</label>
                    <div class="cycle-row" id="grp_eyebrows">
                        <button type="button" class="cycle-btn" id="eyebrows_prev"><i class="bi bi-chevron-left"></i></button>
                        <span class="cycle-label" id="eyebrows_label">–</span>
                        <button type="button" class="cycle-btn" id="eyebrows_next"><i class="bi bi-chevron-right"></i></button>
                    </div>
                </div>

                <!-- Boca -->
                <div class="mb-4">
                    <label class="fw-semibold mb-2 d-block" style="font-size:0.85rem;color:var(--text-secondary);">BOCA</label>
                    <div class="cycle-row" id="grp_mouth">
                        <button type="button" class="cycle-btn" id="mouth_prev"><i class="bi bi-chevron-left"></i></button>
                        <span class="cycle-label" id="mouth_label">–</span>
                        <button type="button" class="cycle-btn" id="mouth_next"><i class="bi bi-chevron-right"></i></button>
                    </div>
                </div>

                <hr style="border-color:var(--card-border-color);">

                <!-- Roupa -->
                <div class="mb-4">
                    <label class="fw-semibold mb-2 d-block" style="font-size:0.85rem;color:var(--text-secondary);">ROUPA</label>
                    <div class="cycle-row" id="grp_clothing">
                        <button type="button" class="cycle-btn" id="clothing_prev"><i class="bi bi-chevron-left"></i></button>
                        <span class="cycle-label" id="clothing_label">–</span>
                        <button type="button" class="cycle-btn" id="clothing_next"><i class="bi bi-chevron-right"></i></button>
                    </div>
                </div>

                <!-- Cor da roupa -->
                <div class="mb-4">
                    <label class="fw-semibold mb-2 d-block" style="font-size:0.85rem;color:var(--text-secondary);">COR DA ROUPA</label>
                    <div class="d-flex flex-wrap gap-2" id="grp_clothingColor">
                        <?php foreach (['262e33','3c4f5c','65c9ff','929598','a7ffc4','b1e2ff','e6e6e6','ff5c5c','ff488e','ffafb9','ffd670','ffffed'] as $c): ?>
                        <button type="button" class="swatch-btn" data-key="clothingColor" data-val="<?= $c ?>"
                                style="background:#<?= $c ?>;"></button>
                        <?php endforeach; ?>
                    </div>
                </div>

                <hr style="border-color:var(--card-border-color);">

                <!-- Acessórios -->
                <div class="mb-4">
                    <label class="fw-semibold mb-2 d-block" style="font-size:0.85rem;color:var(--text-secondary);">ACESSÓRIOS</label>
                    <div class="d-flex flex-wrap gap-2" id="grp_accessories">
                        <?php
                        $accs = [
                            '' => 'Nenhum', 'prescription01' => 'Óculos 1', 'prescription02' => 'Óculos 2',
                            'round' => 'Redondo', 'sunglasses' => 'Sol', 'kurt' => 'Kurt', 'wayfarers' => 'Wayfarer',
                        ];
                        foreach ($accs as $v => $lbl): ?>
                        <button type="button" class="chip-btn" data-key="accessories" data-val="<?= htmlspecialchars($v) ?>">
                            <?= htmlspecialchars($lbl) ?>
                        </button>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Barba / Bigode -->
                <div class="mb-4">
                    <label class="fw-semibold mb-2 d-block" style="font-size:0.85rem;color:var(--text-secondary);">BARBA / BIGODE</label>
                    <div class="d-flex flex-wrap gap-2" id="grp_facialHair">
                        <?php
                        $facial = [
                            '' => 'Nenhuma', 'beardLight' => 'Leve', 'beardMedium' => 'Média',
                            'beardMagestic' => 'Cheia', 'moustacheFancy' => 'Bigode', 'moustacheMagnum' => 'Big Bigode',
                        ];
                        foreach ($facial as $v => $lbl): ?>
                        <button type="button" class="chip-btn" data-key="facialHair" data-val="<?= htmlspecialchars($v) ?>">
                            <?= htmlspecialchars($lbl) ?>
                        </button>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Cor da barba -->
                <div class="mb-4" id="row_facialHairColor" style="display:none;">
                    <label class="fw-semibold mb-2 d-block" style="font-size:0.85rem;color:var(--text-secondary);">COR DA BARBA</label>
                    <div class="d-flex flex-wrap gap-2" id="grp_facialHairColor">
                        <?php foreach (['2c1b18','4a312c','b58143','c93305','e8e1e1','f59797','724133','a55728','d6b370'] as $c): ?>
                        <button type="button" class="swatch-btn" data-key="facialHairColor" data-val="<?= $c ?>"
                                style="background:#<?= $c ?>;"></button>
                        <?php endforeach; ?>
                    </div>
                </div>

                <hr style="border-color:var(--card-border-color);">

                <!-- Fundo -->
                <div class="mb-2">
                    <label class="fw-semibold mb-2 d-block" style="font-size:0.85rem;color:var(--text-secondary);">FUNDO</label>
                    <div class="d-flex flex-wrap gap-2" id="grp_backgroundColor">
                        <?php
                        $bgs = ['b6e3f4' => '#b6e3f4','c0aede' => '#c0aede','d1d4f9' => '#d1d4f9',
                                'ffd5dc' => '#ffd5dc','ffeba4' => '#ffeba4','transparent' => 'transparent'];
                        foreach ($bgs as $v => $bg): ?>
                        <button type="button" class="swatch-btn <?= $v === 'transparent' ? 'swatch-transparent' : '' ?>"
                                data-key="backgroundColor" data-val="<?= $v ?>"
                                style="<?= $v !== 'transparent' ? "background:#{$v};" : '' ?>"></button>
                        <?php endforeach; ?>
                    </div>
                </div>

            </div>
        </div>
    </div>
</main>

<style>
.swatch-btn {
    width: 36px; height: 36px;
    border-radius: 50%;
    border: 3px solid transparent;
    cursor: pointer;
    transition: transform .15s, border-color .15s;
    flex-shrink: 0;
}
.swatch-btn:hover { transform: scale(1.15); }
.swatch-btn.active { border-color: var(--accent); transform: scale(1.18); }
.swatch-transparent {
    background: repeating-conic-gradient(#aaa 0% 25%, #fff 0% 50%) 0 0 / 12px 12px !important;
}
.cycle-row {
    display: flex; align-items: center; gap: 12px;
}
.cycle-btn {
    background: var(--bg-main); border: 1px solid var(--card-border-color);
    color: var(--text-main); border-radius: 8px;
    width: 36px; height: 36px;
    display: flex; align-items: center; justify-content: center;
    cursor: pointer; flex-shrink: 0; transition: background .15s;
}
.cycle-btn:hover { background: var(--card-border-color); }
.cycle-label {
    flex: 1; text-align: center; font-size: 0.9rem;
    font-weight: 600; color: var(--text-main);
    background: var(--bg-main); border: 1px solid var(--card-border-color);
    border-radius: 8px; padding: 6px 12px;
}
.chip-btn {
    background: var(--bg-main); border: 1px solid var(--card-border-color);
    color: var(--text-main); border-radius: 999px;
    padding: 4px 14px; font-size: 0.82rem; cursor: pointer;
    transition: background .15s, border-color .15s, color .15s;
}
.chip-btn:hover { border-color: var(--accent); }
.chip-btn.active {
    background: var(--accent); border-color: var(--accent); color: #fff; font-weight: 600;
}
</style>

<script>
(function () {
    var cfg = <?= json_encode($cfg) ?>;

    var HAIR_OPTS = [
        {v:'shortHairShortCurly',     l:'Cacheado curto'},
        {v:'shortHairShortFlat',      l:'Liso curto'},
        {v:'shortHairShortWaved',     l:'Ondulado curto'},
        {v:'shortHairShortRound',     l:'Arredondado curto'},
        {v:'shortHairSides',          l:'Raspado nas laterais'},
        {v:'shortHairTheCaesar',      l:'César'},
        {v:'shortHairFrizzle',        l:'Frizzy'},
        {v:'shortHairShaggyMullet',   l:'Mullet'},
        {v:'shortHairDreads01',       l:'Dreads'},
        {v:'longHairBob',             l:'Bob'},
        {v:'longHairBun',             l:'Coque'},
        {v:'longHairCurly',           l:'Cacheado longo'},
        {v:'longHairCurvy',           l:'Ondulado longo'},
        {v:'longHairStraight',        l:'Liso longo'},
        {v:'longHairStraight2',       l:'Liso longo 2'},
        {v:'longHairFro',             l:'Afro'},
        {v:'longHairFroBand',         l:'Afro com faixa'},
        {v:'longHairBigHair',         l:'Volume'},
        {v:'longHairMiaWallace',      l:'Mia Wallace'},
        {v:'longHairShavedSides',     l:'Raspado com comprido'},
        {v:'noHair',                  l:'Careca'},
        {v:'hat',                     l:'Boné'},
        {v:'hijab',                   l:'Hijab'},
        {v:'turban',                  l:'Turbante'},
        {v:'winterHat1',              l:'Touca'},
    ];
    var EYES_OPTS = [
        {v:'default',   l:'Normal'},    {v:'happy',     l:'Feliz'},
        {v:'wink',      l:'Piscada'},   {v:'hearts',    l:'Coração'},
        {v:'squint',    l:'Semicerrado'},{v:'surprised', l:'Surpreso'},
        {v:'side',      l:'De lado'},   {v:'close',     l:'Fechado'},
        {v:'cry',       l:'Chorando'},  {v:'dizzy',     l:'Tonto'},
        {v:'eyeRoll',   l:'Revirado'},  {v:'winkWacky', l:'Piscada louca'},
    ];
    var EYEBROWS_OPTS = [
        {v:'default',              l:'Normal'},
        {v:'defaultNatural',       l:'Natural'},
        {v:'raisedExcited',        l:'Levantado'},
        {v:'raisedExcitedNatural', l:'Levantado natural'},
        {v:'angry',                l:'Zangado'},
        {v:'angryNatural',         l:'Zangado natural'},
        {v:'upDown',               l:'Assimétrico'},
        {v:'flatNatural',          l:'Plano'},
        {v:'sadConcerned',         l:'Triste'},
        {v:'unibrowNatural',       l:'Unidos'},
    ];
    var MOUTH_OPTS = [
        {v:'smile',      l:'Sorriso'},   {v:'default',    l:'Normal'},
        {v:'serious',    l:'Sério'},     {v:'tongue',     l:'Língua'},
        {v:'twinkle',    l:'Encantado'}, {v:'eating',     l:'Comendo'},
        {v:'sad',        l:'Triste'},    {v:'concerned',  l:'Preocupado'},
        {v:'grimace',    l:'Grimace'},   {v:'screamOpen', l:'Gritando'},
    ];
    var CLOTHING_OPTS = [
        {v:'hoodie',          l:'Moletom'},
        {v:'shirtCrewNeck',   l:'Camiseta'},
        {v:'shirtVNeck',      l:'Camiseta V'},
        {v:'shirtScoopNeck',  l:'Decote oval'},
        {v:'blazerShirt',     l:'Blazer + Camisa'},
        {v:'blazerSweater',   l:'Blazer + Suéter'},
        {v:'collarSweater',   l:'Suéter'},
        {v:'overall',         l:'Macacão'},
        {v:'graphicShirt',    l:'Camiseta Estampada'},
    ];

    var cycles = {
        hair:      { opts: HAIR_OPTS,     key: 'hair' },
        eyes:      { opts: EYES_OPTS,     key: 'eyes' },
        eyebrows:  { opts: EYEBROWS_OPTS, key: 'eyebrows' },
        mouth:     { opts: MOUTH_OPTS,    key: 'mouth' },
        clothing:  { opts: CLOTHING_OPTS, key: 'clothing' },
    };

    function indexOf(opts, val) {
        for (var i = 0; i < opts.length; i++) if (opts[i].v === val) return i;
        return 0;
    }

    function buildUrl() {
        var base = 'https://api.dicebear.com/9.x/avataaars/svg';
        var p = [];
        p.push('skinColor[]='     + cfg.skinColor);
        if (cfg.hair)        p.push('hair[]='          + cfg.hair);
        p.push('hairColor[]='     + cfg.hairColor);
        p.push('eyes[]='          + cfg.eyes);
        p.push('eyebrows[]='      + cfg.eyebrows);
        p.push('mouth[]='         + cfg.mouth);
        p.push('clothing[]='      + cfg.clothing);
        p.push('clothingColor[]=' + cfg.clothingColor);
        if (cfg.accessories)  p.push('accessories[]='  + cfg.accessories);
        if (cfg.facialHair)   p.push('facialHair[]='   + cfg.facialHair);
        if (cfg.facialHair && cfg.facialHairColor) p.push('facialHairColor[]=' + cfg.facialHairColor);
        if (cfg.backgroundColor !== 'transparent') p.push('backgroundColor[]=' + cfg.backgroundColor);
        return base + '?' + p.join('&');
    }

    function updatePreview() {
        var img = document.getElementById('avatarPreview');
        img.src = buildUrl();

        // Atualiza o wrapper de fundo
        var wrapper = document.getElementById('avatarWrapper');
        wrapper.style.background = cfg.backgroundColor === 'transparent' ? 'transparent' : '#' + cfg.backgroundColor;

        // Sync hidden inputs
        var fields = ['skinColor','hair','hairColor','eyes','eyebrows','mouth',
                      'clothing','clothingColor','accessories','facialHair','facialHairColor','backgroundColor'];
        fields.forEach(function(k) {
            var el = document.getElementById('f_' + k);
            if (el) el.value = cfg[k] || '';
        });

        // Mostra/oculta cor do cabelo quando careca/chapéu
        var noColorHair = ['noHair','hat','hijab','turban','winterHat1','winterHat2','winterHat3','winterHat4','eyepatch'];
        var rowHairColor = document.getElementById('row_hairColor');
        if (rowHairColor) rowHairColor.style.display = noColorHair.indexOf(cfg.hair) !== -1 ? 'none' : '';

        // Mostra/oculta cor da barba
        var rowFacialColor = document.getElementById('row_facialHairColor');
        if (rowFacialColor) rowFacialColor.style.display = cfg.facialHair ? '' : 'none';

        // Atualiza swatches ativos
        document.querySelectorAll('.swatch-btn').forEach(function(btn) {
            btn.classList.toggle('active', btn.dataset.val === cfg[btn.dataset.key]);
        });
        document.querySelectorAll('.chip-btn').forEach(function(btn) {
            btn.classList.toggle('active', btn.dataset.val === cfg[btn.dataset.key]);
        });
    }

    // Ciclo prev/next
    Object.keys(cycles).forEach(function(name) {
        var c      = cycles[name];
        var label  = document.getElementById(name + '_label');
        var btnPrev = document.getElementById(name + '_prev');
        var btnNext = document.getElementById(name + '_next');

        function renderLabel() {
            var idx = indexOf(c.opts, cfg[c.key]);
            label.textContent = c.opts[idx].l + ' (' + (idx + 1) + '/' + c.opts.length + ')';
        }

        btnPrev.addEventListener('click', function() {
            var idx = indexOf(c.opts, cfg[c.key]);
            cfg[c.key] = c.opts[(idx - 1 + c.opts.length) % c.opts.length].v;
            renderLabel();
            updatePreview();
        });
        btnNext.addEventListener('click', function() {
            var idx = indexOf(c.opts, cfg[c.key]);
            cfg[c.key] = c.opts[(idx + 1) % c.opts.length].v;
            renderLabel();
            updatePreview();
        });

        renderLabel();
    });

    // Swatches (cores)
    document.querySelectorAll('.swatch-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            cfg[btn.dataset.key] = btn.dataset.val;
            updatePreview();
        });
    });

    // Chips (acessórios / barba)
    document.querySelectorAll('.chip-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            cfg[btn.dataset.key] = btn.dataset.val;
            updatePreview();
        });
    });

    // Init
    updatePreview();
})();
</script>

<?php include '../geral/footer.php'; ?>
