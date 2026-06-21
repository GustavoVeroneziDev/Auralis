<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$paginaAtual = basename($_SERVER['PHP_SELF']);

// Calcula horas de teste uma única vez — usado tanto no banner quanto nos badges do nav
$horasRestantes = 0;
if (isset($_SESSION['usuario_id']) && function_exists('obterHorasRestantesTeste')) {
    $horasRestantes = obterHorasRestantesTeste();
}
// Badges de nav: exibe tag de plano quando o usuário não tem acesso E não está em trial
$_emTrial = $horasRestantes > 0;
function _navBadgeRecurso($slug, $emTrial)
{
    if ($emTrial || !function_exists('recursoDisponivelParaPlano')) return false;
    if (!isset($_SESSION['usuario_id'])) return false;
    return !recursoDisponivelParaPlano($slug);
}
function _navBadgeTag($slug)
{
    $nivel = function_exists('nivelMinimoRecurso') ? strtoupper(nivelMinimoRecurso($slug)) : 'PRO';
    $cor   = $nivel === 'VIP' ? '#d4af37' : '#a78bfa';
    $borda = $nivel === 'VIP' ? '#d4af3755' : '#7c3aed55';
    $bg    = $nivel === 'VIP' ? '#d4af3722' : '#7c3aed22';
    return "<span style=\"background:{$bg};color:{$cor};border:1px solid {$borda};border-radius:999px;padding:1px 5px;font-size:0.5rem;font-weight:700;letter-spacing:0.04em;line-height:1.6;flex-shrink:0;\"><i class=\"bi bi-star-fill\" style=\"font-size:0.45rem;vertical-align:middle;margin-right:1px;\"></i>{$nivel}</span>";
}
// Mantém flag genérica para compatibilidade com código legado
$_ehFreeRestrito = !$_emTrial
    && isset($_SESSION['usuario_id'])
    && strtolower($_SESSION['plano'] ?? 'free') === 'free';

// ── Tema ─────────────────────────────────────────────────────────────────
$_temasDisponiveis = function_exists('temasDisponiveis') ? temasDisponiveis() : [
    'dark'  => ['bs_mode' => 'dark'],
    'white' => ['bs_mode' => 'light'],
];
$_temaAtual = isset($_SESSION['tema']) && isset($_temasDisponiveis[$_SESSION['tema']])
    ? $_SESSION['tema']
    : 'dark';
$_bsMode     = $_temasDisponiveis[$_temaAtual]['bs_mode'] ?? 'dark';
$_bsModeHtml = $_bsMode === 'auto' ? 'dark' : $_bsMode;
$_themeColor  = $_bsMode === 'light' ? '#f0f2f5' : '#121418';

// Preferência de navegação
$_navTipo    = isset($_SESSION['usuario_id']) ? ($_SESSION['nav_tipo'] ?? 'sidebar') : 'top';
$_useSidebar = isset($_SESSION['usuario_id']) && $_navTipo === 'sidebar';

// Plano do usuário logado (para a sidebar)
if (isset($_SESSION['usuario_id'])) {
    $primeiroNome = explode(' ', $_SESSION['usuario_nome'] ?? 'Usuário')[0];
    $planoNavSidebar = strtolower($_SESSION['plano'] ?? 'free');
    if ($planoNavSidebar === 'vip') {
        $corPlanoSidebar  = '#D4AF37';
        $iconePlanoSidebar = '<i class="fi fi-ss-gem d-flex" style="font-size:0.85rem;margin-top:2px;" title="VIP"></i>';
    } elseif ($planoNavSidebar === 'pro') {
        $corPlanoSidebar  = '#7c3aed';
        $iconePlanoSidebar = '<i class="fi fi-br-crown d-flex" style="font-size:0.85rem;margin-top:2px;" title="PRO"></i>';
    } else {
        $corPlanoSidebar  = 'var(--text-main)';
        $iconePlanoSidebar = '';
    }
}

// ── Carteira persistida: link base para pages com seletor ────────────
$_carteiraParam = (!empty($_SESSION['ultima_carteira']))
    ? '?carteira=' . urlencode($_SESSION['ultima_carteira'])
    : '';
?>
<!DOCTYPE html>
<html lang="pt-BR" data-bs-theme="<?= htmlspecialchars($_bsModeHtml) ?>">

<head>
    <meta charset="UTF-8">
    <?php if ($_bsMode === 'auto'): ?>
    <script>
    (function(){
        var h = document.documentElement;
        var pref = window.matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark';
        h.setAttribute('data-bs-theme', pref);
        window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function(e){
            h.setAttribute('data-bs-theme', e.matches ? 'dark' : 'light');
        });
    })();
    </script>
    <?php endif; ?>
    <script>
    (function(){
        var key = 'auralis_scroll_' + location.pathname;
        var y = sessionStorage.getItem(key);
        if (y !== null) {
            sessionStorage.removeItem(key);
            history.scrollRestoration = 'manual';
            document.addEventListener('DOMContentLoaded', function() {
                document.documentElement.scrollTop = parseInt(y, 10);
            }, {once: true});
        }
    })();
    </script>
    <script>
    (function(){
        var c = new URLSearchParams(location.search).get('carteira');
        if (c && c !== 'todas') {
            try { localStorage.setItem('auralis_carteira', c); } catch(e) {}
        }
    })();
    </script>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="<?= htmlspecialchars($_themeColor) ?>">
    <title>Auralis</title>
    <link rel="shortcut icon" href="/geral/img/icone.ico" type="image/x-icon">
    <link rel="manifest" href="/manifest.json">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Auralis">
    <link rel="apple-touch-icon" href="/geral/img/LogoAuralisSemEscudo.png">

    <link href="/geral/fonts/inter.css" rel="stylesheet">
    <link href="/geral/fonts/aquire.css" rel="stylesheet">
    <link href="/geral/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link rel='stylesheet' href='https://cdn-uicons.flaticon.com/uicons-bold-rounded/css/uicons-bold-rounded.css'>
    <link rel='stylesheet' href='https://cdn-uicons.flaticon.com/uicons-solid-straight/css/uicons-solid-straight.css'>
    <?php
    $_cssV   = @filemtime(__DIR__ . '/style.css') ?: 1;
    $_temaV  = @filemtime(__DIR__ . '/temas/' . $_temaAtual . '.css') ?: 1;
    ?>
    <link rel="stylesheet" href="/geral/temas/<?= htmlspecialchars($_temaAtual) ?>.css?v=<?= $_temaV ?>">
    <link rel="stylesheet" href="/geral/style.css?v=<?= $_cssV ?>">
</head>

<?php if ($_useSidebar): ?>
<body class="d-flex min-vh-100">

    <!-- Overlay móvel -->
    <div id="sidebarOverlay" class="sidebar-overlay"></div>

    <!-- ═══════════════════════════════════════════════════════════════════
         SIDEBAR
         ═══════════════════════════════════════════════════════════════════ -->
    <aside id="auralis-sidebar" class="auralis-sidebar">

        <!-- Logo -->
        <div class="sidebar-logo">
            <a href="/dashboard.php<?= htmlspecialchars($_carteiraParam) ?>">
                <img src="/geral/img/LogoAuralisSemEscudo.png" alt="Auralis">
                <span class="sidebar-logo-text sidebar-label">
                    <span style="color:gold;">Aura</span><span style="color:var(--text-main);">lis</span>
                </span>
            </a>
        </div>

        <!-- Nav items -->
        <nav class="sidebar-nav">
            <a href="/dashboard.php<?= htmlspecialchars($_carteiraParam) ?>"
               class="sidebar-item <?= $paginaAtual === 'dashboard.php' ? 'active' : '' ?>">
                <i class="bi bi-speedometer2"></i>
                <span class="sidebar-label">Dashboard</span>
            </a>
            <a href="/gerenciar_categorias.php"
               class="sidebar-item <?= $paginaAtual === 'gerenciar_categorias.php' ? 'active' : '' ?>">
                <i class="bi bi-list-task"></i>
                <span class="sidebar-label">Categorias</span>
            </a>
            <a href="/analises.php<?= htmlspecialchars($_carteiraParam) ?>"
               class="sidebar-item <?= $paginaAtual === 'analises.php' ? 'active' : '' ?>">
                <i class="bi bi-graph-up-arrow"></i>
                <span class="sidebar-label">Análises</span>
                <?php if (_navBadgeRecurso('analises', $_emTrial)): ?>
                    <span class="sidebar-badge sidebar-label"><?= _navBadgeTag('analises') ?></span>
                <?php endif; ?>
            </a>
            <a href="/agenda.php"
               class="sidebar-item <?= $paginaAtual === 'agenda.php' ? 'active' : '' ?>">
                <i class="bi bi-calendar3"></i>
                <span class="sidebar-label">Agenda</span>
                <?php if (_navBadgeRecurso('agenda', $_emTrial)): ?>
                    <span class="sidebar-badge sidebar-label"><?= _navBadgeTag('agenda') ?></span>
                <?php endif; ?>
            </a>
            <a href="/carteira/listar_carteiras.php"
               class="sidebar-item <?= $paginaAtual === 'listar_carteiras.php' ? 'active' : '' ?>">
                <i class="bi bi-wallet"></i>
                <span class="sidebar-label">Carteiras</span>
            </a>
            <a href="/cartao_credito/index.php"
               class="sidebar-item <?= (strpos($_SERVER['PHP_SELF'], '/cartao_credito/') !== false) ? 'active' : '' ?>">
                <i class="bi bi-credit-card-2-front"></i>
                <span class="sidebar-label">Cartões</span>
            </a>
            <?php if ($_ehFreeRestrito): ?>
            <a href="/planos.php"
               class="sidebar-item <?= $paginaAtual === 'planos.php' ? 'active' : '' ?>">
                <i class="bi bi-star"></i>
                <span class="sidebar-label">Planos</span>
            </a>
            <?php endif; ?>
            <?php if (in_array(strtolower($_SESSION['nivel_acesso'] ?? ''), ['admin', 'supremo'])): ?>
            <a href="/admin/usuarios.php"
               class="sidebar-item <?= $paginaAtual === 'usuarios.php' ? 'active' : '' ?>"
               style="color:#E63946;">
                <i class="bi bi-shield-fill-check" style="color:#E63946;"></i>
                <span class="sidebar-label">Admin</span>
            </a>
            <?php endif; ?>
        </nav>

        <!-- Usuário + toggle -->
        <div class="sidebar-bottom">
            <div class="dropup">
                <?php
                $plano = function_exists('obterPlanoAtual') ? obterPlanoAtual() : ($_SESSION['plano'] ?? 'free');
                ?>
                <button class="sidebar-user-btn dropdown-toggle" type="button"
                    data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="bi bi-person-circle flex-shrink-0"
                       style="color:<?= $corPlanoSidebar ?>;font-size:1.4rem;"></i>
                    <span class="sidebar-label d-flex align-items-center gap-1"
                          style="color:<?= $corPlanoSidebar ?>;font-size:0.875rem;font-weight:600;">
                        <?= htmlspecialchars($primeiroNome) ?>
                        <?= $iconePlanoSidebar ?>
                    </span>
                </button>
                <ul class="dropdown-menu shadow-lg border border-secondary-subtle mb-1"
                    style="background:var(--bg-card);min-width:200px;">
                    <li class="px-3 py-2 border-bottom border-secondary-subtle">
                        <small class="text-secondary d-block mb-1"><?= htmlspecialchars($_SESSION['usuario_nome'] ?? '') ?></small>
                        <a href="/planos.php" class="text-decoration-none" style="font-size:0.7rem;">
                            <?= function_exists('badgePlano') && badgePlano($plano) ? badgePlano($plano) : '<span style="font-size:0.7rem;color:#6b7280;font-weight:bold;text-transform:uppercase;">' . htmlspecialchars($plano) . '</span>' ?>
                        </a>
                        <?php if ($plano === 'free'): ?>
                        <a href="/planos.php" class="btn btn-sm w-100 mt-2 fw-semibold rounded-pill"
                           style="background:#d4af3718;color:#d4af37;border:1px solid #d4af3744;font-size:0.75rem;">
                            <i class="bi bi-stars me-1"></i> Fazer upgrade
                        </a>
                        <?php endif; ?>
                    </li>
                    <li>
                        <a class="dropdown-item d-flex align-items-center py-2 transition-hover"
                           href="/configuracoes.php" style="color:var(--text-main);">
                            <i class="bi bi-gear me-2" style="color:gold;"></i> Configurações
                        </a>
                    </li>
                    <li class="btn-instalar-app" style="display:none;">
                        <a class="dropdown-item d-flex align-items-center py-2 transition-hover"
                           href="#" onclick="auralisInstalar(); return false;" style="color:var(--text-main);">
                            <i class="bi bi-download me-2" style="color:gold;"></i> Instalar como App
                        </a>
                    </li>
                    <li><hr class="dropdown-divider border-secondary-subtle"></li>
                    <li>
                        <a class="dropdown-item d-flex align-items-center py-2 text-danger transition-hover"
                           href="/usuario/logout.php">
                            <i class="bi bi-box-arrow-right me-2"></i> Sair
                        </a>
                    </li>
                </ul>
            </div>

            <button id="sidebarToggle" class="sidebar-toggle" title="Recolher menu">
                <i class="bi bi-layout-sidebar-reverse" style="font-size:1rem;"></i>
            </button>
        </div>
    </aside>

    <!-- Aplica estado colapsado antes do primeiro paint -->
    <script>
    (function(){
        var s = document.getElementById('auralis-sidebar');
        if (s && localStorage.getItem('auralis_sidebar') === 'collapsed') {
            s.classList.add('sidebar-collapsed');
        }
    })();
    </script>

    <!-- ═══════════════════════════════════════════════════════════════════
         CONTEÚDO
         ═══════════════════════════════════════════════════════════════════ -->
    <div class="auralis-content d-flex flex-column flex-grow-1" style="min-height:100vh;min-width:0;">

        <!-- Topbar móvel -->
        <div class="auralis-mobile-topbar">
            <button id="sidebarMobileToggle" class="btn btn-sm p-1 me-2"
                    style="color:var(--text-main);background:none;border:none;">
                <i class="bi bi-list" style="font-size:1.7rem;"></i>
            </button>
            <a href="/dashboard.php<?= htmlspecialchars($_carteiraParam) ?>" class="d-flex align-items-center text-decoration-none"
               style="font-family:'Aquire';font-size:1.1rem;">
                <img src="/geral/img/LogoAuralisSemEscudo.png" alt="" style="height:24px;" class="me-1">
                <span style="color:gold;">Aura</span><span style="color:var(--text-main);">lis</span>
            </a>
        </div>

        <!-- Banner de teste/trial -->
        <?php if ($horasRestantes > 0): ?>
        <div class="container-fluid px-0">
            <div class="alert mb-0 text-center shadow-sm"
                 style="background:linear-gradient(90deg,#ca8a04,#eab308);color:#fff;border:none;border-radius:0;padding:0.5rem;">
                <div class="d-none d-md-flex align-items-center justify-content-center gap-2"
                     style="font-size:0.95rem;font-weight:600;">
                    <i class="bi bi-clock-history fs-5"></i>
                    Você possui <strong><?= $horasRestantes ?> horas</strong> restantes de Acesso Total. Aproveite para conhecer o Auralis!
                </div>
                <div class="d-flex d-md-none align-items-center justify-content-center gap-2"
                     style="font-size:0.85rem;font-weight:700;">
                    <i class="bi bi-clock-history"></i>
                    Teste VIP: <?= $horasRestantes ?>h restantes
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Modal: Instalar Auralis como App -->
        <div class="modal fade" id="modalInstalarApp" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered" style="max-width:440px;">
                <div class="modal-content border-0 rounded-4 overflow-hidden position-relative"
                     style="background-color:#181A1F;border:1px solid rgba(255,255,255,0.08) !important;box-shadow:0 25px 50px -12px rgba(0,0,0,0.7);">
                    <div class="position-absolute top-0 start-0 w-100 h-100"
                         style="background:radial-gradient(circle at top right,rgba(212,175,55,0.12),transparent 60%);pointer-events:none;"></div>
                    <div class="modal-body p-5 text-center position-relative">
                        <div class="mb-4 d-inline-flex justify-content-center align-items-center bg-dark border border-secondary-subtle rounded-circle shadow-lg"
                             style="width:80px;height:80px;">
                            <i id="installModalIcon" class="bi bi-phone" style="font-size:2.2rem;color:var(--accent);"></i>
                        </div>
                        <h4 id="installModalTitle" class="text-light fw-bold mb-2">Instale o Auralis</h4>
                        <p id="installModalDesc" class="text-secondary mb-4" style="font-size:0.9rem;line-height:1.6;">
                            Acesse suas finanças direto da tela inicial — sem abrir o navegador, sem digitar endereço.
                        </p>
                        <div id="installModalAction">
                            <button onclick="auralisInstalar(); bootstrap.Modal.getInstance(document.getElementById('modalInstalarApp')).hide();"
                                class="btn w-100 fw-bold text-dark rounded-pill py-3 mb-3 shadow-lg"
                                style="background:linear-gradient(135deg,#FFB800 0%,#D4AF37 100%);font-size:0.95rem;">
                                <i class="bi bi-download me-2"></i> Instalar Agora
                            </button>
                        </div>
                        <button type="button" class="btn btn-link text-secondary text-decoration-none w-100"
                                data-bs-dismiss="modal" style="font-size:0.8rem;">
                            Agora não
                        </button>
                    </div>
                </div>
            </div>
        </div>

<?php else: ?>
<body class="d-flex flex-column min-vh-100">

    <nav class="navbar navbar-expand-lg border-bottom border-secondary-subtle sticky-top shadow-sm py-2">
        <div class="container-fluid px-3 px-xl-5" style="max-width:1500px;">

            <a class="navbar-brand d-flex align-items-center" href="<?= isset($_SESSION['usuario_id']) ? '/dashboard.php' . htmlspecialchars($_carteiraParam) : '/geral/index.php' ?>"
               style="font-family:'Aquire',sans-serif;font-weight:700;font-size:1.6rem;letter-spacing:0.04em;text-decoration:none;">
                <img src="/geral/img/LogoAuralisSemEscudo.png" alt="Logo Auralis" class="me-2" style="height:36px;width:auto;object-fit:contain;">
                <span style="color:gold;">Aura</span><span style="color:var(--text-main);font-weight:700;">lis</span>
            </a>

            <button class="navbar-toggler border-0 shadow-none p-2" type="button"
                    data-bs-toggle="collapse" data-bs-target="#navbarNav"
                    aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
                <i class="bi bi-list fs-1 text-light"></i>
            </button>

            <div class="collapse navbar-collapse mt-3 mt-lg-0" id="navbarNav">
                <ul class="navbar-nav mx-auto mb-3 mb-lg-0 fw-medium gap-1 gap-lg-3 text-start text-lg-center px-3 px-lg-0">
                    <?php if (isset($_SESSION['usuario_id'])): ?>
                        <li class="nav-item">
                            <a class="nav-link custom-link py-3 py-lg-2 <?= $paginaAtual === 'dashboard.php' ? 'text-warning active' : '' ?>" href="/dashboard.php<?= htmlspecialchars($_carteiraParam) ?>">
                                <i class="bi bi-speedometer2 me-2"></i> Dashboard
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link custom-link py-3 py-lg-2 <?= $paginaAtual === 'gerenciar_categorias.php' ? 'text-warning active' : '' ?>" href="/gerenciar_categorias.php">
                                <i class="bi bi-list-task me-2"></i> Categorias
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link custom-link py-3 py-lg-2 d-flex align-items-center gap-1 <?= $paginaAtual === 'analises.php' ? 'text-warning active' : '' ?>" href="/analises.php<?= htmlspecialchars($_carteiraParam) ?>">
                                <i class="bi bi-graph-up-arrow me-2"></i> Análises
                                <?php if (_navBadgeRecurso('analises', $_emTrial)): ?><?= _navBadgeTag('analises') ?><?php endif; ?>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link custom-link py-3 py-lg-2 d-flex align-items-center gap-1 <?= $paginaAtual === 'agenda.php' ? 'text-warning active' : '' ?>" href="/agenda.php">
                                <i class="bi bi-calendar3 me-2"></i> Agenda
                                <?php if (_navBadgeRecurso('agenda', $_emTrial)): ?><?= _navBadgeTag('agenda') ?><?php endif; ?>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link custom-link py-3 py-lg-2 <?= $paginaAtual === 'listar_carteiras.php' ? 'text-warning active' : '' ?>" href="/carteira/listar_carteiras.php">
                                <i class="bi bi-wallet me-2"></i> Carteiras
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link custom-link py-3 py-lg-2 <?= strpos($_SERVER['PHP_SELF'], '/cartao_credito/') !== false ? 'text-warning active' : '' ?>" href="/cartao_credito/index.php">
                                <i class="bi bi-credit-card-2-front me-2"></i> Cartões
                            </a>
                        </li>
                        <?php if ($_ehFreeRestrito): ?>
                        <li class="nav-item">
                            <a class="nav-link custom-link py-3 py-lg-2 <?= $paginaAtual === 'planos.php' ? 'text-warning active' : '' ?>" href="/planos.php">
                                <i class="bi bi-star me-2"></i> Planos
                            </a>
                        </li>
                        <?php endif; ?>
                        <?php if (in_array(strtolower($_SESSION['nivel_acesso'] ?? ''), ['admin', 'supremo'])): ?>
                        <li class="nav-item">
                            <a class="nav-link custom-link py-3 py-lg-2 <?= $paginaAtual === 'usuarios.php' ? 'text-warning active' : '' ?>" href="/admin/usuarios.php">
                                <i class="bi bi-shield-fill-check me-2" style="color:#E63946;"></i>
                                <span style="color:#E63946;">Admin</span>
                            </a>
                        </li>
                        <?php endif; ?>
                    <?php else: ?>
                        <li class="nav-item">
                            <a class="nav-link custom-link py-3 py-lg-2" href="/geral/index.php">Início</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link custom-link py-3 py-lg-2" href="/geral/sobre.php#título">Sobre nós</a>
                        </li>
                    <?php endif; ?>
                </ul>

                <div class="d-flex flex-column flex-lg-row gap-3 align-items-lg-center px-3 px-lg-0 pb-3 pb-lg-0">
                    <?php if (isset($_SESSION['usuario_id'])): ?>
                        <?php
                        $planoNavTop = strtolower($_SESSION['plano'] ?? 'free');
                        $primeiroNomeTop = explode(' ', $_SESSION['usuario_nome'] ?? 'Usuário')[0];
                        if ($planoNavTop === 'vip') { $corTop = '#D4AF37'; $iconeTop = '<i class="fi fi-ss-gem d-flex align-items-center" style="font-size:1rem;margin-top:2px;"></i>'; }
                        elseif ($planoNavTop === 'pro') { $corTop = '#7c3aed'; $iconeTop = '<i class="fi fi-br-crown d-flex align-items-center" style="font-size:1rem;margin-top:2px;"></i>'; }
                        else { $corTop = 'var(--text-main)'; $iconeTop = ''; }
                        $planoDisplay = function_exists('obterPlanoAtual') ? obterPlanoAtual() : ($_SESSION['plano'] ?? 'free');
                        ?>
                        <div class="dropdown w-100 text-start text-lg-end">
                            <a href="#" class="d-flex align-items-center justify-content-start justify-content-lg-end text-light text-decoration-none dropdown-toggle custom-link py-2"
                               data-bs-toggle="dropdown" aria-expanded="false">
                                <span class="me-2 fw-semibold d-flex align-items-center gap-2" style="font-size:1rem;color:<?= $corTop ?>;">
                                    <?= htmlspecialchars($primeiroNomeTop) ?> <?= $iconeTop ?>
                                </span>
                                <i class="bi bi-person-circle" style="color:<?= $corTop ?>;font-size:1.75rem;"></i>
                            </a>
                            <ul class="dropdown-menu dropdown-menu-lg-end shadow-lg border border-secondary-subtle mt-2 bg-dark w-100 w-lg-auto">
                                <li class="px-3 py-2 border-bottom border-secondary-subtle">
                                    <div class="d-flex align-items-center justify-content-between gap-3">
                                        <small class="text-secondary"><?= htmlspecialchars($_SESSION['usuario_nome'] ?? '') ?></small>
                                        <a href="/planos.php" class="text-decoration-none" style="font-size:0.7rem;">
                                            <?= function_exists('badgePlano') && badgePlano($planoDisplay) ? badgePlano($planoDisplay) : '<span style="font-size:0.7rem;color:#6b7280;font-weight:bold;text-transform:uppercase;">' . htmlspecialchars($planoDisplay) . '</span>' ?>
                                        </a>
                                    </div>
                                    <?php if ($planoDisplay === 'free'): ?>
                                    <a href="/planos.php" class="btn btn-sm w-100 mt-2 fw-semibold rounded-pill" style="background:#d4af3718;color:#d4af37;border:1px solid #d4af3744;font-size:0.75rem;">
                                        <i class="bi bi-stars me-1"></i> Fazer upgrade
                                    </a>
                                    <?php endif; ?>
                                </li>
                                <li><a class="dropdown-item text-light d-flex align-items-center py-2" href="/configuracoes.php"><i class="bi bi-gear me-2" style="color:gold;"></i> Configurações</a></li>
                                <li class="btn-instalar-app" style="display:none;"><a class="dropdown-item text-light d-flex align-items-center py-2" href="#" onclick="auralisInstalar();return false;"><i class="bi bi-download me-2" style="color:gold;"></i> Instalar como App</a></li>
                                <li><hr class="dropdown-divider border-secondary-subtle"></li>
                                <li><a class="dropdown-item d-flex align-items-center py-2 text-danger" href="/usuario/logout.php"><i class="bi bi-box-arrow-right me-2"></i> Sair</a></li>
                            </ul>
                        </div>
                    <?php else: ?>
                        <div class="nav-auth-pill">
                            <a href="/usuario/login.php" class="nav-auth-login"><i class="bi bi-person-fill"></i><span>Entrar</span></a>
                            <a href="/usuario/cadastro.php" class="nav-auth-signup"><i class="bi bi-stars"></i><span>Criar conta</span></a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </nav>

    <?php if ($horasRestantes > 0): ?>
    <div class="container-fluid px-0">
        <div class="alert mb-0 text-center shadow-sm" style="background:linear-gradient(90deg,#ca8a04,#eab308);color:#fff;border:none;border-radius:0;padding:0.5rem;">
            <div class="d-none d-md-flex align-items-center justify-content-center gap-2" style="font-size:0.95rem;font-weight:600;">
                <i class="bi bi-clock-history fs-5"></i>
                Você possui <strong><?= $horasRestantes ?> horas</strong> restantes de Acesso Total.
            </div>
            <div class="d-flex d-md-none align-items-center justify-content-center gap-2" style="font-size:0.85rem;font-weight:700;">
                <i class="bi bi-clock-history"></i> Teste VIP: <?= $horasRestantes ?>h restantes
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if (isset($_SESSION['usuario_id'])): ?>
    <div class="modal fade" id="modalInstalarApp" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered" style="max-width:440px;">
            <div class="modal-content border-0 rounded-4 overflow-hidden position-relative" style="background-color:#181A1F;border:1px solid rgba(255,255,255,0.08) !important;">
                <div class="modal-body p-5 text-center position-relative">
                    <div class="mb-4 d-inline-flex justify-content-center align-items-center bg-dark border border-secondary-subtle rounded-circle shadow-lg" style="width:80px;height:80px;">
                        <i id="installModalIcon" class="bi bi-phone" style="font-size:2.2rem;color:var(--accent);"></i>
                    </div>
                    <h4 id="installModalTitle" class="text-light fw-bold mb-2">Instale o Auralis</h4>
                    <p id="installModalDesc" class="text-secondary mb-4" style="font-size:0.9rem;">Acesse suas finanças direto da tela inicial.</p>
                    <div id="installModalAction">
                        <button onclick="auralisInstalar();bootstrap.Modal.getInstance(document.getElementById('modalInstalarApp')).hide();"
                            class="btn w-100 fw-bold text-dark rounded-pill py-3 mb-3" style="background:linear-gradient(135deg,#FFB800,#D4AF37);font-size:0.95rem;">
                            <i class="bi bi-download me-2"></i> Instalar Agora
                        </button>
                    </div>
                    <button type="button" class="btn btn-link text-secondary text-decoration-none w-100" data-bs-dismiss="modal" style="font-size:0.8rem;">Agora não</button>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

<?php endif; ?>
