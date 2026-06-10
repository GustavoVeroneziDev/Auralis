<?php
// ==============================================================================
// 1. LÓGICA PHP (Processamento de Dados)
// ==============================================================================
if ($_SERVER['HTTP_HOST'] === 'localhost' || $_SERVER['HTTP_HOST'] === '127.0.0.1') {
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);
}
session_start();
if (!isset($_SESSION['usuario_id'])) {
    header("Location: usuario/login.php");
    exit;
}
require_once 'config/conexao.php';
require_once 'config/funcoes.php';

$usuario_id = $_SESSION['usuario_id'];
$sucesso = null;
$erro = null;

// Mensagens de Sucesso da URL
if (isset($_GET['sucesso'])) {
    if ($_GET['sucesso'] === 'kit_criado') $sucesso = "Seu Kit Inicial de categorias foi gerado!";
    if ($_GET['sucesso'] === 'excluida')   $sucesso = "Categoria excluída com sucesso!";
    if ($_GET['sucesso'] === 'criada')     $sucesso = "Categoria criada com sucesso!";
}

// --- 1.2 PROCESSA A CRIAÇÃO DE NOVA CATEGORIA MANUAL ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'nova_categoria') {
    $nomeCategoria  = trim($_POST['nome_categoria'] ?? '');
    $tipoCategoria  = $_POST['tipo_categoria'] ?? 'despesa';
    $iconeCategoria = $_POST['icone_categoria'] ?? 'bi-tag';

    if (empty($nomeCategoria)) {
        $erro = "O nome da categoria não pode estar vazio.";
    } else {
        try {
            $sqlInsert = "INSERT INTO Categoria (IDCategoria, NomeCategoria, TipoCategoria, IconeCategoria, FKUsuario) VALUES (:id, :nome, :tipo, :icone, :uid)";
            $stmtInsert = $pdo->prepare($sqlInsert);
            $stmtInsert->execute([
                ':id'    => gerarUuid(),
                ':nome'  => $nomeCategoria,
                ':tipo'  => $tipoCategoria,
                ':icone' => $iconeCategoria,
                ':uid'   => $usuario_id
            ]);

            // PRG: Trava contra F5
            header("Location: gerenciar_categorias.php?sucesso=criada");
            exit;
        } catch (PDOException $e) {
            $erro = "Erro ao criar categoria. Talvez ela já exista.";
        }
    }
}

// --- PROCESSA A EDIÇÃO DE CATEGORIA ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'editar_categoria') {
    $idCategoria    = $_POST['id_categoria'] ?? '';
    $nomeCategoria  = trim($_POST['nome_categoria'] ?? '');
    $tipoCategoria  = $_POST['tipo_categoria'] ?? 'despesa';
    $iconeCategoria = $_POST['icone_categoria'] ?? 'bi-tag';

    if (empty($nomeCategoria) || empty($idCategoria)) {
        $erro = "O nome da categoria não pode estar vazio.";
    } else {
        try {
            $sqlUpdate = "UPDATE Categoria SET NomeCategoria = :nome, TipoCategoria = :tipo, IconeCategoria = :icone WHERE IDCategoria = :id AND FKUsuario = :uid";
            $pdo->prepare($sqlUpdate)->execute([
                ':nome'  => $nomeCategoria,
                ':tipo'  => $tipoCategoria,
                ':icone' => $iconeCategoria,
                ':id'    => $idCategoria,
                ':uid'   => $usuario_id
            ]);
            header("Location: gerenciar_categorias.php?sucesso=editada");
            exit;
        } catch (PDOException $e) {
            $erro = "Erro ao atualizar categoria.";
        }
    }
}

// --- PROCESSA A EXCLUSÃO VIA MODAL (POST) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'excluir_categoria') {
    $idExcluir = $_POST['id_categoria'] ?? '';
    try {
        $sqlDelete = "DELETE FROM Categoria WHERE IDCategoria = :id AND FKUsuario = :uid";
        $pdo->prepare($sqlDelete)->execute([':id' => $idExcluir, ':uid' => $usuario_id]);
        header("Location: gerenciar_categorias.php?sucesso=excluida");
        exit;
    } catch (PDOException $e) {
        $erro = "Não é possível excluir uma categoria que já possui transações atreladas.";
    }
}

// Mensagens de Sucesso da URL atualizadas
if (isset($_GET['sucesso'])) {
    if ($_GET['sucesso'] === 'kit_criado') $sucesso = "Seu Kit Inicial de categorias foi gerado!";
    if ($_GET['sucesso'] === 'excluida')   $sucesso = "Categoria excluída com sucesso!";
    if ($_GET['sucesso'] === 'criada')     $sucesso = "Categoria criada com sucesso!";
    if ($_GET['sucesso'] === 'editada')    $sucesso = "Categoria atualizada com sucesso!";
}

// --- 1.4 BUSCA AS CATEGORIAS SEPARANDO POR TIPO ---
$categorias_receita = [];
$categorias_despesa = [];

try {
    $sqlBusca = "
        SELECT c.IDCategoria, c.NomeCategoria, c.TipoCategoria, c.IconeCategoria, COUNT(r.IDRegistro) as total_usos
        FROM Categoria c
        LEFT JOIN Registro r ON c.IDCategoria = r.FKCategoria
        WHERE c.FKUsuario = :uid
        GROUP BY c.IDCategoria, c.NomeCategoria, c.TipoCategoria, c.IconeCategoria
        ORDER BY c.NomeCategoria ASC
    ";
    $stmtBusca = $pdo->prepare($sqlBusca);
    $stmtBusca->execute([':uid' => $usuario_id]);
    $todas = $stmtBusca->fetchAll();

    foreach ($todas as $cat) {
        if ($cat['TipoCategoria'] === 'receita') {
            $categorias_receita[] = $cat;
        } else {
            $categorias_despesa[] = $cat;
        }
    }
} catch (PDOException $e) {
    $erro = "Erro ao buscar categorias.";
}

require_once 'geral/header.php';

// Lista de ícones disponíveis
$listaIcones = [
    'bi-cart3',
    'bi-basket',
    'bi-cup-hot',
    'bi-shop',
    'bi-house-door',
    'bi-lightning-charge',
    'bi-droplet',
    'bi-wifi',
    'bi-wrench',
    'bi-tools',
    'bi-car-front',
    'bi-fuel-pump',
    'bi-bus-front',
    'bi-bicycle',
    'bi-airplane',
    'bi-heart-pulse',
    'bi-capsule',
    'bi-controller',
    'bi-film',
    'bi-music-note-beamed',
    'bi-bag-heart',
    'bi-scissors',
    'bi-sunglasses',
    'bi-book',
    'bi-mortarboard',
    'bi-people',
    'bi-gift',
    'bi-balloon',
    'bi-laptop',
    'bi-phone',
    'bi-briefcase',
    'bi-bank',
    'bi-cash-stack',
    'bi-coin',
    'bi-piggy-bank',
    'bi-wallet2',
    'bi-graph-up-arrow',
    'bi-shield-check',
    'bi-gear-fill',
    'bi-three-dots'
];
?>

<main class="container py-4 mt-2 flex-grow-1" style="min-height: 100vh; padding-inline: var(--space-page-x);">

    <div class="d-flex justify-content-between align-items-center mb-4 border-bottom border-secondary-subtle pb-3 gap-3">
        <h2 class="fw-bold text-light mb-0">Minhas Categorias</h2>
        <a href="dashboard.php" class="btn btn-outline-secondary btn-sm rounded-pill px-3 transition-hover">
            <i class="bi bi-arrow-left me-1"></i> Voltar ao Painel
        </a>
    </div>

    <?php if ($sucesso): ?>
        <div class="alert alert-success d-flex align-items-center gap-2 rounded-3 shadow-sm border-0 bg-success bg-opacity-10 text-success fw-semibold">
            <i class="bi bi-check-circle-fill"></i> <span><?= htmlspecialchars($sucesso) ?></span>
        </div>
    <?php endif; ?>

    <?php if ($erro): ?>
        <div class="alert alert-danger d-flex align-items-center gap-2 rounded-3 shadow-sm border-0 bg-danger bg-opacity-10 text-danger fw-semibold">
            <i class="bi bi-exclamation-triangle-fill"></i> <span><?= htmlspecialchars($erro) ?></span>
        </div>
    <?php endif; ?>

    <div class="row g-4">
        <div class="col-md-5 col-lg-4">
            <div class="card bg-body-tertiary border-secondary-subtle shadow-sm rounded-4 h-100">
                <div class="card-body p-4">
                    <h5 class="text-light fw-bold mb-4 d-flex align-items-center gap-2">
                        <i class="bi bi-plus-circle text-primary" style="color: var(--primary-gold-analysis) !important;"></i> Nova Categoria
                    </h5>
                    <?php
                    $gruposIcones = [
                        'Essenciais & Casa'   => ['bi-house-door', 'bi-cart3', 'bi-lightning-charge', 'bi-droplet', 'bi-wifi', 'bi-basket', 'bi-tools', 'bi-trash'],
                        'Transporte'          => ['bi-car-front', 'bi-bus-front', 'bi-bicycle', 'bi-fuel-pump', 'bi-airplane', 'bi-train-front'],
                        'Saúde & Bem-estar'   => ['bi-heart-pulse', 'bi-capsule', 'bi-bandaid', 'bi-activity', 'bi-clipboard-pulse'],
                        'Lazer & Estilo'      => ['bi-controller', 'bi-cup-hot', 'bi-ticket-perforated', 'bi-bag', 'bi-scissors', 'bi-palette', 'bi-music-note-beamed', 'bi-tv'],
                        'Trabalho & Finanças' => ['bi-briefcase', 'bi-cash-stack', 'bi-bank', 'bi-piggy-bank', 'bi-graph-up-arrow', 'bi-laptop', 'bi-credit-card', 'bi-wallet2'],
                        'Outros'              => ['bi-star', 'bi-box', 'bi-tag', 'bi-three-dots', 'bi-gift', 'bi-book', 'bi-shield-check']
                    ];
                    ?>
                    <form method="POST" action="" class="auralis-premium-form">
                        <input type="hidden" name="action" value="nova_categoria">

                        <div class="mb-4">
                            <label class="form-label text-secondary small mb-2 d-block">Tipo da Categoria</label>
                            <div class="d-flex gap-2">
                                <input type="radio" class="btn-check" name="tipo_categoria" id="tipo_despesa" value="despesa" checked>
                                <label class="btn btn-outline-danger flex-grow-1 rounded-pill fw-semibold fs-7 py-2" for="tipo_despesa">Despesa</label>

                                <input type="radio" class="btn-check" name="tipo_categoria" id="tipo_receita" value="receita">
                                <label class="btn btn-outline-success flex-grow-1 rounded-pill fw-semibold fs-7 py-2" for="tipo_receita">Receita</label>
                            </div>
                        </div>

                        <div class="mb-4 auralis-line-input pb-2">
                            <input type="text" name="nome_categoria" class="form-control bg-transparent border-0 text-light-analysis px-0 shadow-none fs-6 fw-bold" placeholder="Nome Ex: Supermercado" required autocomplete="off">
                        </div>

                        <div class="mb-4">
                            <label class="form-label text-secondary-analysis fs-7 mb-2">Escolha um ícone</label>

                            <div class="w-100">
                                <?php foreach ($gruposIcones as $nomeGrupo => $icones): ?>

                                    <div class="d-flex align-items-center mt-3 mb-2">
                                        <hr class="flex-grow-1" style="border-color: #D4AF37; opacity: 0.35;">
                                        <span class="mx-3" style="font-size: 0.65rem; font-weight: 800; text-transform: uppercase; letter-spacing: 1.5px; color: #D4AF37;">
                                            <?= $nomeGrupo ?>
                                        </span>
                                        <hr class="flex-grow-1" style="border-color: #D4AF37; opacity: 0.35;">
                                    </div>

                                    <div class="d-flex flex-wrap gap-2 justify-content-center">
                                        <?php foreach ($icones as $icone): ?>
                                            <div>
                                                <input type="radio" class="btn-check" name="icone_categoria" id="icone_<?= $icone ?>" value="<?= $icone ?>" autocomplete="off" required>
                                                <label class="btn-icon-select" for="icone_<?= $icone ?>" style="width: 45px; padding: 0;">
                                                    <i class="bi <?= $icone ?> fs-5"></i>
                                                </label>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>

                                <?php endforeach; ?>
                            </div>
                        </div>

                        <button type="submit" class="btn btn-gold fw-bold text-dark py-3 w-100 rounded-pill shadow-lg mt-4 transition-hover">
                            Salvar Categoria
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-md-7 col-lg-8">

            <div class="card bg-dark border-secondary-subtle shadow-sm rounded-4 overflow-hidden mb-4">
                <div class="card-header bg-charcoal-analysis border-secondary-subtle py-3 d-flex align-items-center">
                    <div class="bg-danger bg-opacity-10 rounded-circle me-3 d-flex align-items-center justify-content-center" style="width: 36px; height: 36px; flex-shrink: 0;">
                        <i class="bi bi-arrow-down-short text-danger fs-5"></i>
                    </div>
                    <h6 class="mb-0 text-light fw-bold fs-5">Categorias de Despesa</h6>
                </div>
                <div class="table-responsive">
                    <table class="table table-dark table-hover align-middle mb-0 auralis-table">
                        <tbody class="border-top-0">
                            <?php if (empty($categorias_despesa)): ?>
                                <tr>
                                    <td class="text-center text-secondary py-4 fs-7">Nenhuma despesa cadastrada.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($categorias_despesa as $cat): ?>
                                    <tr>
                                        <td class="ps-4 py-3 border-secondary-subtle w-50">
                                            <div class="d-flex align-items-center">
                                                <div class="icon-circle bg-secondary bg-opacity-10 me-3">
                                                    <i class="bi <?= htmlspecialchars($cat['IconeCategoria'] ?? 'bi-tag') ?> text-light fs-5"></i>
                                                </div>
                                                <span class="text-light fw-semibold fs-6"><?= htmlspecialchars($cat['NomeCategoria']) ?></span>
                                            </div>
                                        </td>
                                        <td class="py-3 border-secondary-subtle text-secondary small text-center fs-7">
                                            <?= $cat['total_usos'] ?> registro(s)
                                        </td>
                                        <td class="text-end pe-3 pe-md-4 py-3 border-secondary-subtle">
                                            <div class="d-flex align-items-center justify-content-end gap-2">
                                                <button type="button"
                                                    class="btn btn-sm btn-outline-warning rounded-circle transition-hover d-flex align-items-center justify-content-center"
                                                    style="width: 34px; height: 34px; padding: 0;"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#modalEditarCategoria"
                                                    data-id="<?= $cat['IDCategoria'] ?>"
                                                    data-nome="<?= htmlspecialchars($cat['NomeCategoria']) ?>"
                                                    data-tipo="<?= $cat['TipoCategoria'] ?>"
                                                    data-icone="<?= $cat['IconeCategoria'] ?>">
                                                    <i class="bi bi-pencil-square"></i>
                                                </button>

                                                <?php if ($cat['total_usos'] > 0): ?>
                                                    <button class="btn btn-sm btn-outline-secondary rounded-circle d-flex align-items-center justify-content-center" style="width: 34px; height: 34px; padding: 0;" disabled title="Categoria em uso">
                                                        <i class="bi bi-trash3"></i>
                                                    </button>
                                                <?php else: ?>
                                                    <button type="button"
                                                        class="btn btn-sm btn-outline-danger rounded-circle transition-hover d-flex align-items-center justify-content-center"
                                                        style="width: 34px; height: 34px; padding: 0;"
                                                        data-bs-toggle="modal"
                                                        data-bs-target="#modalExcluirCategoria"
                                                        data-id="<?= $cat['IDCategoria'] ?>"
                                                        data-nome="<?= htmlspecialchars($cat['NomeCategoria']) ?>">
                                                        <i class="bi bi-trash3"></i>
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card bg-dark border-secondary-subtle shadow-sm rounded-4 overflow-hidden">
                <div class="card-header bg-charcoal-analysis border-secondary-subtle py-3 d-flex align-items-center">
                    <div class="bg-success bg-opacity-10 rounded-circle me-3 d-flex align-items-center justify-content-center" style="width: 36px; height: 36px; flex-shrink: 0;">
                        <i class="bi bi-arrow-up-short text-success fs-5"></i>
                    </div>
                    <h6 class="mb-0 text-light fw-bold fs-5">Categorias de Receita</h6>
                </div>
                <div class="table-responsive">
                    <table class="table table-dark table-hover align-middle mb-0 auralis-table">
                        <tbody class="border-top-0">
                            <?php if (empty($categorias_receita)): ?>
                                <tr>
                                    <td class="text-center text-secondary py-4 fs-7">Nenhuma receita cadastrada.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($categorias_receita as $cat): ?>
                                    <tr>
                                        <td class="ps-4 py-3 border-secondary-subtle w-50">
                                            <div class="d-flex align-items-center">
                                                <div class="icon-circle bg-secondary bg-opacity-10 me-3">
                                                    <i class="bi <?= htmlspecialchars($cat['IconeCategoria'] ?? 'bi-tag') ?> text-light fs-5"></i>
                                                </div>
                                                <span class="text-light fw-semibold fs-6"><?= htmlspecialchars($cat['NomeCategoria']) ?></span>
                                            </div>
                                        </td>
                                        <td class="py-3 border-secondary-subtle text-secondary small text-center fs-7">
                                            <?= $cat['total_usos'] ?> registro(s)
                                        </td>
                                        <td class="text-end pe-3 pe-md-4 py-3 border-secondary-subtle">
                                            <div class="d-flex align-items-center justify-content-end gap-2">
                                                <button type="button"
                                                    class="btn btn-sm btn-outline-warning rounded-circle transition-hover d-flex align-items-center justify-content-center"
                                                    style="width: 34px; height: 34px; padding: 0;"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#modalEditarCategoria"
                                                    data-id="<?= $cat['IDCategoria'] ?>"
                                                    data-nome="<?= htmlspecialchars($cat['NomeCategoria']) ?>"
                                                    data-tipo="<?= $cat['TipoCategoria'] ?>"
                                                    data-icone="<?= $cat['IconeCategoria'] ?>">
                                                    <i class="bi bi-pencil-square"></i>
                                                </button>

                                                <?php if ($cat['total_usos'] > 0): ?>
                                                    <button class="btn btn-sm btn-outline-secondary rounded-circle d-flex align-items-center justify-content-center" style="width: 34px; height: 34px; padding: 0;" disabled title="Categoria em uso">
                                                        <i class="bi bi-trash3"></i>
                                                    </button>
                                                <?php else: ?>
                                                    <button type="button"
                                                        class="btn btn-sm btn-outline-danger rounded-circle transition-hover d-flex align-items-center justify-content-center"
                                                        style="width: 34px; height: 34px; padding: 0;"
                                                        data-bs-toggle="modal"
                                                        data-bs-target="#modalExcluirCategoria"
                                                        data-id="<?= $cat['IDCategoria'] ?>"
                                                        data-nome="<?= htmlspecialchars($cat['NomeCategoria']) ?>">
                                                        <i class="bi bi-trash3"></i>
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </div>
</main>

<style>
    :root {
        --primary-gold-analysis: #AA8C2C;
        --bg-card-analysis: #2A2A2A;
        --bg-charcoal-analysis: #222222;
        --border-color-analysis: #333333;
        --text-light-analysis: #E0E0E0;
        --text-muted-analysis: #888888;
    }

    .bg-dark {
        background-color: var(--bg-charcoal-analysis) !important;
    }

    .card {
        background-color: var(--bg-card-analysis) !important;
        border-color: var(--border-color-analysis) !important;
    }

    .auralis-premium-form input[type="text"]:focus {
        border-color: var(--primary-gold-analysis) !important;
        background-color: transparent !important;
        box-shadow: none;
    }

    .auralis-line-input {
        border-bottom: 1px solid var(--border-color-analysis);
        background-color: transparent !important;
    }

    .auralis-line-input .form-control {
        color: var(--text-light-analysis) !important;
    }

    .btn-gold {
        background: linear-gradient(135deg, #FFB800 0%, #D4AF37 100%);
        border: none;
    }

    .btn-gold:hover {
        background: linear-gradient(135deg, #FFD04F 0%, #E7C665 100%);
        color: #000;
        box-shadow: 0 4px 15px rgba(212, 175, 55, 0.4) !important;
    }

    .auralis-table>tbody>tr:hover>td {
        background-color: rgba(255, 255, 255, 0.02) !important;
    }

    .bg-charcoal-analysis {
        background-color: #1a1d21 !important;
    }

    .fs-7 {
        font-size: 0.85rem;
    }

    .icon-selector-grid {
        display: grid;
        grid-template-columns: repeat(5, 1fr);
        gap: 8px;
        max-height: 300px;
        width: 100%;
        overflow-y: auto;
        padding: 10px;
        margin: 0 auto;
        box-sizing: border-box;
    }

    .btn-icon-select {
        display: flex;
        align-items: center;
        justify-content: center;
        height: 45px;
        border-radius: 12px;
        background-color: var(--bg-charcoal-analysis);
        border: 1px solid var(--border-color-analysis);
        color: var(--text-muted-analysis);
        cursor: pointer;
        transition: all 0.2s ease;
    }

    .btn-icon-select:hover {
        background-color: #333;
        color: var(--text-light-analysis);
    }

    .btn-check:checked+.btn-icon-select {
        background-color: rgba(170, 140, 44, 0.15);
        border-color: var(--primary-gold-analysis);
        color: var(--primary-gold-analysis);
        transform: scale(1.05);
    }

    /* Círculo do Ícone na Tabela */
    .icon-circle {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
    }
</style>
<div class="modal fade" id="modalExcluirCategoria" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm" style="max-width: 400px;">
        <div class="modal-content bg-dark border-secondary-subtle shadow-lg rounded-4">
            <div class="modal-header border-bottom border-secondary-subtle p-3">
                <h6 class="modal-title text-light fw-bold">
                    <i class="bi bi-trash3 me-2 text-danger"></i> Excluir Categoria
                </h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="">
                <div class="modal-body p-4 text-center">
                    <p class="text-secondary mb-0">Tem certeza que deseja excluir a categoria <strong id="excluir_nome_cat" class="text-light"></strong>? Essa ação não pode ser desfeita.</p>
                    <input type="hidden" name="action" value="excluir_categoria">
                    <input type="hidden" name="id_categoria" id="excluir_id_cat">
                </div>
                <div class="modal-footer border-top border-secondary-subtle d-flex justify-content-between p-2">
                    <button type="button" class="btn btn-sm btn-link text-secondary text-decoration-none" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-sm btn-danger fw-bold px-3 rounded-pill">
                        Confirmar Exclusão
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="modalEditarCategoria" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content bg-body-tertiary border-secondary-subtle shadow-lg rounded-4">
            <div class="modal-header border-bottom border-secondary-subtle p-3">
                <h5 class="modal-title text-light fw-bold d-flex align-items-center gap-2">
                    <i class="bi bi-pencil-square" style="color: #D4AF37;"></i> Editar Categoria
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="" class="auralis-premium-form">
                <div class="modal-body p-4" style="max-height: 65vh; overflow-y: auto;">
                    <input type="hidden" name="action" value="editar_categoria">
                    <input type="hidden" name="id_categoria" id="edit_id_categoria">

                    <div class="mb-4">
                        <label class="form-label text-secondary small mb-2 d-block">Tipo da Categoria</label>
                        <div class="d-flex gap-2">
                            <input type="radio" class="btn-check" name="tipo_categoria" id="edit_tipo_despesa" value="despesa">
                            <label class="btn btn-outline-danger flex-grow-1 rounded-pill fw-semibold fs-7 py-2" for="edit_tipo_despesa">Despesa</label>

                            <input type="radio" class="btn-check" name="tipo_categoria" id="edit_tipo_receita" value="receita">
                            <label class="btn btn-outline-success flex-grow-1 rounded-pill fw-semibold fs-7 py-2" for="edit_tipo_receita">Receita</label>
                        </div>
                    </div>

                    <div class="mb-4 auralis-line-input pb-2">
                        <input type="text" name="nome_categoria" id="edit_nome_categoria" class="form-control bg-transparent border-0 text-light-analysis px-0 shadow-none fs-6 fw-bold" required autocomplete="off">
                    </div>

                    <div class="mb-4">
                        <label class="form-label text-secondary-analysis fs-7 mb-2">Escolha um ícone</label>
                        <div class="w-100">
                            <?php foreach ($gruposIcones as $nomeGrupo => $icones): ?>
                                <div class="d-flex align-items-center mt-3 mb-2">
                                    <hr class="flex-grow-1" style="border-color: #D4AF37; opacity: 0.35;">
                                    <span class="mx-3" style="font-size: 0.65rem; font-weight: 800; text-transform: uppercase; letter-spacing: 1.5px; color: #D4AF37;">
                                        <?= $nomeGrupo ?>
                                    </span>
                                    <hr class="flex-grow-1" style="border-color: #D4AF37; opacity: 0.35;">
                                </div>
                                <div class="d-flex flex-wrap gap-2 justify-content-center">
                                    <?php foreach ($icones as $icone): ?>
                                        <div>
                                            <input type="radio" class="btn-check" name="icone_categoria" id="edit_icone_<?= $icone ?>" value="<?= $icone ?>" autocomplete="off" required>
                                            <label class="btn-icon-select" for="edit_icone_<?= $icone ?>" style="width: 45px; padding: 0;">
                                                <i class="bi <?= $icone ?> fs-5"></i>
                                            </label>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-top border-secondary-subtle d-flex justify-content-between p-3">
                    <button type="button" class="btn btn-link text-secondary text-decoration-none" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-gold fw-bold text-dark px-4 rounded-pill transition-hover">
                        Salvar Alterações
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php require_once 'geral/footer.php'; ?>
<script>
    document.addEventListener("DOMContentLoaded", function() {
        // Captura os dados para o Modal de Exclusão
        const modalExcluirCategoria = document.getElementById('modalExcluirCategoria');
        if (modalExcluirCategoria) {
            modalExcluirCategoria.addEventListener('show.bs.modal', function(event) {
                const button = event.relatedTarget;
                document.getElementById('excluir_id_cat').value = button.getAttribute('data-id');
                document.getElementById('excluir_nome_cat').textContent = button.getAttribute('data-nome');
            });
        }

        // Captura os dados para o Modal de Edição
        const modalEditarCategoria = document.getElementById('modalEditarCategoria');
        if (modalEditarCategoria) {
            modalEditarCategoria.addEventListener('show.bs.modal', function(event) {
                const button = event.relatedTarget;
                const id = button.getAttribute('data-id');
                const nome = button.getAttribute('data-nome');
                const tipo = button.getAttribute('data-tipo');
                const icone = button.getAttribute('data-icone');

                document.getElementById('edit_id_categoria').value = id;
                document.getElementById('edit_nome_categoria').value = nome;

                // Marca o Tipo correspondente
                if (tipo === 'receita') {
                    document.getElementById('edit_tipo_receita').checked = true;
                } else {
                    document.getElementById('edit_tipo_despesa').checked = true;
                }

                // Marca o Ícone correspondente
                const radioIcone = document.getElementById('edit_icone_' + icone);
                if (radioIcone) {
                    radioIcone.checked = true;
                }
            });
        }
    });
</script>