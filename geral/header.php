<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$paginaAtual = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="pt-BR" data-bs-theme="dark">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#121418">
    <title>Auralis | Gestão Financeira</title>
    <link rel="shortcut icon" href="/geral/img/icone.ico" type="image/x-icon">

    <link href="/geral/fonts/inter.css" rel="stylesheet">
    <link href="/geral/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="/geral/style.css">
</head>

<body class="d-flex flex-column min-vh-100">

    <nav class="navbar navbar-expand-lg border-bottom border-secondary-subtle sticky-top shadow-sm py-2"
        style="background-color: rgba(18, 20, 24, 0.85); backdrop-filter: blur(12px);">

        <div class="container-fluid px-3 px-xl-5" style="max-width: 1500px;">

            <a class="navbar-brand fw-bold fs-3 d-flex align-items-center" href="/geral/index.php" style="letter-spacing: -0.05em;">
                <img src="/geral/img/logoAuralis-SemFundo.png" alt="Logo Auralis" class="me-2" style="height: 38px; width: auto; object-fit: contain;">
                <span style="color: gold !important;">Aura</span><span class="text-light">TESTE2</span>
            </a>

            <button class="navbar-toggler border-0 shadow-none p-2" type="button" data-bs-toggle="collapse"
                data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false"
                aria-label="Toggle navigation">
                <i class="bi bi-list fs-1 text-light"></i>
            </button>

            <div class="collapse navbar-collapse mt-3 mt-lg-0" id="navbarNav">

                <ul class="navbar-nav mx-auto mb-3 mb-lg-0 fw-medium gap-1 gap-lg-3 text-start text-lg-center px-3 px-lg-0">
                    <?php if (isset($_SESSION['usuario_id'])): ?>
                        <li class="nav-item">
                            <a class="nav-link custom-link py-3 py-lg-2 <?php echo ($paginaAtual == 'dashboard.php') ? 'text-warning active' : ''; ?>" href="/dashboard.php">
                                <i class="bi bi-speedometer2 me-2"></i> Dashboard
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link custom-link py-3 py-lg-2 <?php echo ($paginaAtual == 'gerenciar_categorias.php') ? 'text-warning active' : ''; ?>" href="/gerenciar_categorias.php">
                                <i class="bi bi-list-task me-2"></i> Categorias
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link custom-link py-3 py-lg-2 <?php echo ($paginaAtual == 'analises.php') ? 'text-warning active' : ''; ?>" href="/analises.php">
                                <i class="bi bi-graph-up-arrow me-2"></i> Análises
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link custom-link py-3 py-lg-2 <?php echo ($paginaAtual == 'listar_carteiras.php') ? 'text-warning active' : ''; ?>" href="/carteira/listar_carteiras.php">
                                <i class="bi bi-wallet me-2"></i> Carteiras
                            </a>
                        </li>
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
                        <?php $primeiroNome = explode(' ', $_SESSION['usuario_nome'])[0]; ?>

                        <div class="dropdown w-100 text-start text-lg-end">
                            <a href="#" class="d-flex align-items-center justify-content-start justify-content-lg-end text-light text-decoration-none dropdown-toggle custom-link py-2"
                                id="menuUsuario" data-bs-toggle="dropdown" aria-expanded="false">
                                <span class="me-2 text-muted navbar-greeting">Olá, <strong class="text-light"><?php echo htmlspecialchars($primeiroNome); ?></strong></span>
                                <i style="color: gold !important;" class="bi bi-person-circle fs-4 cardCentral"></i>
                            </a>

                            <ul class="dropdown-menu dropdown-menu-lg-end shadow-lg border border-secondary-subtle mt-2 bg-dark w-100 w-lg-auto" aria-labelledby="menuUsuario">
                                <li>
                                    <a class="dropdown-item text-light d-flex align-items-center py-3 py-lg-2 transition-hover" href="/configuracoes.php">
                                        <i class="bi bi-gear me-3 me-lg-2" style="color: gold;"></i> Configurações
                                    </a>
                                </li>
                                <li>
                                    <hr class="dropdown-divider border-secondary-subtle d-none d-lg-block">
                                </li>
                                <li>
                                    <a class="dropdown-item d-flex align-items-center py-3 py-lg-2 text-danger transition-hover" href="/usuario/logout.php">
                                        <i class="bi bi-box-arrow-right me-3 me-lg-2"></i> Sair
                                    </a>
                                </li>
                            </ul>
                        </div>
                    <?php else: ?>
                        <a href="/usuario/login.php" class="btn btn-link text-light text-decoration-none custom-link px-0 px-lg-3 text-start">Login</a>
                        <a href="/usuario/cadastro.php" class="btn px-4 py-2 rounded-pill fw-bold shadow-sm w-100 w-lg-auto" style="background-color: gold; color: #121418;">Criar Conta</a>
                    <?php endif; ?>
                </div>

            </div>
        </div>
    </nav>