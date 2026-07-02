<?php
// ==============================================================================
// 1. LÓGICA PHP (Processamento de Dados)
// ==============================================================================
session_start();
if (!isset($_SESSION['usuario_id'])) {
    header("Location: ../usuario/login.php");
    exit;
}

// Volta uma pasta para achar a conexão
require_once '../config/conexao.php';

$usuario_id = $_SESSION['usuario_id'];
$sucesso = null;
$erro = null;

// --- PROCESSA A EXCLUSÃO DE CARTEIRA ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'excluir_carteira') {
    $id_carteira = $_POST['carteira_id'];

    try {
        // Trava de Segurança: Verifica se a carteira tem transações atreladas
        $sqlCheck = "SELECT COUNT(*) FROM Registro WHERE FKCarteira = :cid";
        $stmtCheck = $pdo->prepare($sqlCheck);
        $stmtCheck->execute([':cid' => $id_carteira]);
        $qtdRegistros = $stmtCheck->fetchColumn();

        if ($qtdRegistros > 0) {
            $erro = "Não é possível excluir esta carteira pois ela possui {$qtdRegistros} transação(ões) registrada(s). Exclua ou transfira os registros antes de apagar a carteira.";
        } else {
            // Se estiver vazia, pode deletar
            $sqlDel = "DELETE FROM Carteira WHERE IDCarteira = :cid AND FKUsuarioDono = :uid";
            $stmtDel = $pdo->prepare($sqlDel);
            $stmtDel->execute([':cid' => $id_carteira, ':uid' => $usuario_id]);

            // PRG: Redireciona para evitar reenvio de formulário
            header("Location: listar_carteiras.php?sucesso=excluida");
            exit;
        }
    } catch (PDOException $e) {
        $erro = "Erro ao tentar excluir a carteira.";
    }
}

// --- PROCESSA A MESCLA DE CARTEIRAS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'mesclar_carteira') {
    $carteira_origem = $_POST['carteira_origem'];
    $carteira_destino = $_POST['carteira_destino'];

    if (empty($carteira_destino) || $carteira_origem === $carteira_destino) {
        $erro = "Escolha uma carteira de destino válida e diferente da origem.";
    } else {
        try {
            $pdo->beginTransaction(); // Inicia uma transação segura

            // 1. Transfere todos os registros da carteira Velha para a Nova
            $sqlTransfer = "UPDATE Registro SET FKCarteira = :destino WHERE FKCarteira = :origem AND FKUsuario = :uid";
            $stmtTransfer = $pdo->prepare($sqlTransfer);
            $stmtTransfer->execute([
                ':destino' => $carteira_destino,
                ':origem' => $carteira_origem,
                ':uid' => $usuario_id
            ]);

            // 2. Apaga a carteira Velha (que agora está vazia)
            $sqlDel = "DELETE FROM Carteira WHERE IDCarteira = :cid AND FKUsuarioDono = :uid";
            $stmtDel = $pdo->prepare($sqlDel);
            $stmtDel->execute([':cid' => $carteira_origem, ':uid' => $usuario_id]);

            $pdo->commit(); // Confirma as alterações

            header("Location: listar_carteiras.php?sucesso=mesclada");
            exit;
        } catch (PDOException $e) {
            $pdo->rollBack(); // Se der erro no meio, desfaz tudo
            $erro = "Erro ao mesclar as carteiras.";
        }
    }
}

// Mensagens de sucesso vindas da URL
if (isset($_GET['sucesso'])) {
    if ($_GET['sucesso'] === 'excluida') $sucesso = "Carteira excluída com sucesso!";
    if ($_GET['sucesso'] === 'criada') $sucesso = "Nova carteira criada com sucesso!";
    if ($_GET['sucesso'] === 'editada') $sucesso = "Carteira atualizada com sucesso!";
    if ($_GET['sucesso'] === 'mesclada') $sucesso = "Carteiras mescladas com sucesso!";
    if ($_GET['sucesso'] === 'principal_definida') $sucesso = "Carteira principal definida! É nela que o sistema vai entrar por padrão.";
    if ($_GET['sucesso'] === 'principal_removida') $sucesso = "Carteira principal removida.";
}
if (isset($_GET['erro'])) {
    if ($_GET['erro'] === 'duplicada') $erro = "Já existe uma carteira com este nome exato.";
    if ($_GET['erro'] === 'vazio') $erro = "O nome da carteira não pode ficar vazio.";
    if ($_GET['erro'] === 'banco') $erro = "Ocorreu um erro interno ao salvar a carteira.";
    if ($_GET['erro'] === 'limite_plano') $erro = "Seu plano não permite criar mais carteiras. Faça upgrade para adicionar mais.";
    if ($_GET['erro'] === 'carteira_invalida') $erro = "Carteira inválida.";
}

// --- BUSCA AS CARTEIRAS E CALCULA O SALDO DE CADA UMA ---
$carteiras = [];
try {
    // SQL Inteligente: Já calcula o saldo exato de cada carteira direto no banco
    $sqlCarteiras = "
        SELECT c.IDCarteira, c.TipoCarteira, c.Principal,
               COALESCE(SUM(CASE WHEN r.TipoRegistro = 'receita'               THEN  r.Valor ELSE 0 END), 0) +
               COALESCE(SUM(CASE WHEN r.TipoRegistro = 'cofrinho_retirada'     THEN  r.Valor ELSE 0 END), 0) +
               COALESCE(SUM(CASE WHEN r.TipoRegistro = 'transferencia_entrada' THEN  r.Valor ELSE 0 END), 0) -
               COALESCE(SUM(CASE WHEN r.TipoRegistro = 'despesa'               THEN  r.Valor ELSE 0 END), 0) -
               COALESCE(SUM(CASE WHEN r.TipoRegistro = 'cofrinho'              THEN  r.Valor ELSE 0 END), 0) -
               COALESCE(SUM(CASE WHEN r.TipoRegistro = 'transferencia_saida'   THEN  r.Valor ELSE 0 END), 0) as SaldoAtual
        FROM Carteira c
        LEFT JOIN Registro r ON c.IDCarteira = r.FKCarteira AND r.StatusRegistro = 'efetivado'
        WHERE c.FKUsuarioDono = :uid
        GROUP BY c.IDCarteira, c.TipoCarteira, c.Principal
        ORDER BY c.Principal DESC, c.TipoCarteira ASC
    ";
    $stmt = $pdo->prepare($sqlCarteiras);
    $stmt->execute([':uid' => $usuario_id]);
    $carteiras = $stmt->fetchAll();
} catch (PDOException $e) {
    $erro = "Erro ao buscar as suas carteiras.";
}

// Determina carteiras bloqueadas e carteiras "trial" (além do limite, mas dentro do período de teste)
require_once '../config/funcoes.php';
$_planoLC        = strtolower($_SESSION['plano'] ?? 'free');
$_testeLC        = function_exists('obterHorasRestantesTeste') ? (obterHorasRestantesTeste() > 0) : false;
$_limitesLC      = limitesDoPlano();
$_upgradeSlugLC  = ['free' => 'pro', 'pro' => 'vip'][$_planoLC] ?? 'vip';
$_nomePlanoLC    = strtoupper($_planoLC);
$_nomeUpgradeLC  = strtoupper($_upgradeSlugLC);
$carteiras_bloqueadas_ids = [];
$carteiras_trial_ids      = [];
if ($_limitesLC['carteiras'] !== PHP_INT_MAX) {
    for ($i = $_limitesLC['carteiras']; $i < count($carteiras); $i++) {
        $id = $carteiras[$i]['IDCarteira'];
        if ($_testeLC) {
            $carteiras_trial_ids[] = $id;   // trial: pode usar mas mostra badge PRO (teste)
        } else {
            $carteiras_bloqueadas_ids[] = $id; // sem trial: bloqueada
        }
    }
}

// Volta uma pasta para achar o header
require_once '../geral/header.php';
?>

<main class="container-fluid py-4 mt-2 flex-grow-1" style="max-width: 1500px; padding-inline: var(--space-page-x); min-height: 100vh;">

    <div class="d-flex justify-content-between align-items-center mb-4 border-bottom border-secondary-subtle pb-3 flex-wrap gap-3">
        <div class="d-flex gap-2">
            <a href="../dashboard.php" class="btn btn-outline-secondary btn-sm rounded-pill px-3 transition-hover d-flex align-items-center">
                <i class="bi bi-arrow-left me-1"></i> Voltar
            </a>
            <?php
            $_podeNovaCat = ($_limitesLC['carteiras'] === PHP_INT_MAX) || count($carteiras) < $_limitesLC['carteiras'] || $_testeLC;
            ?>
            <?php if ($_podeNovaCat): ?>
                <button type="button" onclick="abrirModalCarteira()" class="btn btn-gold btn-sm rounded-pill px-4 fw-bold text-dark transition-hover shadow-sm d-flex align-items-center">
                    <i class="bi bi-plus-circle me-2"></i> Nova Carteira
                </button>
            <?php else: ?>
                <a href="/planos.php?upgrade=<?= $_upgradeSlugLC ?>" class="btn btn-sm rounded-pill fw-semibold d-flex align-items-center gap-1" style="background:var(--color-card-bg);color:var(--color-card-text);border:1px solid var(--color-card-border);">
                    <i class="bi bi-lock-fill"></i> Limite atingido — <strong><?= $_nomeUpgradeLC ?></strong>
                </a>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($sucesso): ?>
        <script>window._pendingToast = <?= json_encode($sucesso) ?>;</script>
    <?php endif; ?>

    <?php if ($erro): ?>
        <div class="alert d-flex align-items-center gap-2 rounded-3 shadow-sm border-0 fw-semibold mb-4" style="background:var(--color-expense-bg);color:var(--color-expense-text);border:1px solid var(--color-expense-border) !important;">
            <i class="bi bi-exclamation-triangle-fill"></i>
            <span><?= htmlspecialchars($erro) ?></span>
            <?php if ($_GET['erro'] === 'limite_plano'): ?>
                &nbsp;<a href="/planos.php?upgrade=<?= $_upgradeSlugLC ?>" class="fw-bold" style="color:#f87171;">Assinar <?= $_nomeUpgradeLC ?> &rarr;</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($carteiras_bloqueadas_ids)): ?>
        <div class="alert d-flex align-items-start gap-3 rounded-3 border-0 mb-4" style="background:var(--color-pending-bg);border:1px solid var(--color-today-bg) !important;">
            <i class="bi bi-lock-fill mt-1 flex-shrink-0" style="color:var(--accent);"></i>
            <div>
                <strong class="text-light">Carteiras bloqueadas</strong>
                <p class="mb-1 text-secondary" style="font-size:0.85rem;">
                    Você tem <?= count($carteiras_bloqueadas_ids) ?> carteira(s) além do limite do plano <?= $_nomePlanoLC ?> (<?= $_limitesLC['carteiras'] ?> no total). Elas estão bloqueadas para novas transações, mas você ainda pode mesclar ou excluir.
                </p>
                <a href="/planos.php?upgrade=<?= $_upgradeSlugLC ?>" class="btn btn-sm rounded-pill fw-semibold" style="background:var(--color-pending-bg);color:var(--color-pending-text);border:1px solid var(--color-today-bg);font-size:0.8rem;">
                    <i class="bi bi-star-fill me-1"></i> Assinar <?= $_nomeUpgradeLC ?> — até <?= exibirLimite(limitesDoPlano($_upgradeSlugLC)['carteiras']) ?> carteiras
                </a>
            </div>
        </div>
    <?php endif; ?>

    <div class="row g-4">

        <?php foreach ($carteiras as $cart):
            $_cartBloqueada = in_array($cart['IDCarteira'], $carteiras_bloqueadas_ids);
            $_cartTrial     = in_array($cart['IDCarteira'], $carteiras_trial_ids);
        ?>
            <div class="col-md-6 col-lg-4">
                <div class="card bg-body-tertiary border-secondary-subtle shadow-sm h-100 rounded-4 auralis-wallet-card position-relative overflow-hidden"
                    <?= $_cartBloqueada ? 'style="opacity:0.55;border-color:rgba(124,58,237,0.35) !important;"' : '' ?>>

                    <?php if ($_cartTrial): ?>
                        <span class="position-absolute top-0 end-0 m-2 d-flex align-items-center gap-1"
                              style="background:rgba(124,58,237,0.18);color:#a78bfa;border:1px solid rgba(124,58,237,0.4);border-radius:999px;padding:2px 8px;font-size:0.6rem;font-weight:700;z-index:2;">
                            <i class="bi bi-star-fill" style="font-size:0.55rem;"></i> PRO (teste)
                        </span>
                    <?php elseif ($_cartBloqueada): ?>
                        <span class="position-absolute top-0 end-0 m-2 d-flex align-items-center gap-1"
                              style="background:rgba(124,58,237,0.18);color:#a78bfa;border:1px solid rgba(124,58,237,0.4);border-radius:999px;padding:2px 8px;font-size:0.6rem;font-weight:700;z-index:2;">
                            <i class="bi bi-lock-fill" style="font-size:0.55rem;"></i> PRO
                        </span>
                    <?php endif; ?>

                    <div class="card-body p-4 position-relative z-1 d-flex flex-column">
                        <div class="d-flex justify-content-between align-items-start mb-4">
                            <div class="d-flex align-items-center gap-3">
                                <div class="icon-circle bg-primary bg-opacity-10 d-flex justify-content-center align-items-center rounded-3 shadow-sm" style="width: 48px; height: 48px;">
                                    <i class="bi <?= $_cartBloqueada ? 'bi-lock-fill' : 'bi-bank' ?> fs-4" style="color: <?= $_cartBloqueada ? '#a78bfa' : 'var(--primary-gold-analysis)' ?> !important;"></i>
                                </div>
                                <div>
                                    <h5 class="fw-bold text-light mb-0 d-flex align-items-center gap-2">
                                        <?= htmlspecialchars($cart['TipoCarteira']) ?>
                                        <?php if ((int)$cart['Principal'] === 1): ?>
                                            <i class="bi bi-star-fill" style="color:#d4af37;font-size:0.8rem;" title="Carteira principal"></i>
                                        <?php endif; ?>
                                    </h5>
                                </div>
                            </div>

                            <div class="dropdown">
                                <button class="btn btn-link text-secondary p-0 shadow-none border-0" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                    <i class="bi bi-three-dots-vertical fs-5"></i>
                                </button>
                                <ul class="dropdown-menu dropdown-menu-end bg-dark border-secondary-subtle shadow-lg">
                                    <li>
                                        <form method="POST" action="marcar_principal.php" class="m-0">
                                            <input type="hidden" name="id_carteira" value="<?= htmlspecialchars($cart['IDCarteira']) ?>">
                                            <button type="submit" class="dropdown-item d-flex align-items-center transition-hover py-2"
                                                style="color:#d4af37;">
                                                <i class="bi <?= (int)$cart['Principal'] === 1 ? 'bi-star-fill' : 'bi-star' ?> me-2"></i>
                                                <?= (int)$cart['Principal'] === 1 ? 'Remover como Principal' : 'Marcar como Principal' ?>
                                            </button>
                                        </form>
                                    </li>
                                    <li>
                                        <button type="button" class="dropdown-item text-light d-flex align-items-center transition-hover py-2"
                                            onclick="abrirModalCarteira('<?= $cart['IDCarteira'] ?>', '<?= htmlspecialchars($cart['TipoCarteira'], ENT_QUOTES) ?>')">
                                            <i class="bi bi-pencil-square me-2 text-warning"></i> <span class="text-warning">Editar Nome</span>
                                        </button>
                                    </li>
                                    <li>
                                        <button type="button" class="dropdown-item text-info d-flex align-items-center transition-hover py-2"
                                            onclick="abrirModalMescla('<?= $cart['IDCarteira'] ?>', '<?= htmlspecialchars($cart['TipoCarteira']) ?>')">
                                            <i class="bi bi-shuffle me-2"></i> Mesclar / Transferir
                                        </button>
                                    </li>
                                    <li>
                                        <button type="button" class="dropdown-item text-danger d-flex align-items-center transition-hover py-2"
                                            onclick="abrirModalExcluirCarteira('<?= $cart['IDCarteira'] ?>', '<?= htmlspecialchars($cart['TipoCarteira'], ENT_QUOTES) ?>')">
                                            <i class="bi bi-trash3 me-2"></i> Excluir Carteira
                                        </button>
                                    </li>
                                </ul>
                            </div>
                        </div>

                        <div class="mt-auto">
                            <p class="text-secondary small mb-1 text-uppercase fw-semibold tracking-wide">Saldo Atual</p>
                            <h3 class="fw-bold mb-0 <?= $cart['SaldoAtual'] < 0 ? 'text-danger' : 'text-light' ?>" style="letter-spacing: -0.5px;">
                                R$ <?= number_format($cart['SaldoAtual'], 2, ',', '.') ?>
                            </h3>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>

        <?php if ($_podeNovaCat): ?>
            <div class="col-md-6 col-lg-4">
                <a href="#" onclick="abrirModalCarteira(); return false;" class="text-decoration-none">
                    <div class="card h-100 rounded-4 d-flex align-items-center justify-content-center auralis-add-card transition-hover" style="min-height: 180px;">
                        <div class="card-body text-center d-flex flex-column align-items-center justify-content-center p-4">
                            <div class="rounded-circle d-flex align-items-center justify-content-center mb-3" style="width: 50px; height: 50px; background-color: rgba(170, 140, 44, 0.1);">
                                <i class="bi bi-plus-lg fs-3" style="color: var(--primary-gold-analysis);"></i>
                            </div>
                            <h6 class="fw-bold text-secondary mb-0">Adicionar Nova Carteira</h6>
                        </div>
                    </div>
                </a>
            </div>
        <?php else: ?>
            <div class="col-md-6 col-lg-4">
                <a href="/planos.php?upgrade=<?= $_upgradeSlugLC ?>" class="text-decoration-none">
                    <div class="card h-100 rounded-4 d-flex align-items-center justify-content-center transition-hover" style="min-height:180px;background:var(--color-card-bg);border:1px dashed var(--color-card-border);">
                        <div class="card-body text-center d-flex flex-column align-items-center justify-content-center p-4">
                            <div class="rounded-circle d-flex align-items-center justify-content-center mb-3" style="width:50px;height:50px;background:var(--color-card-bg);">
                                <i class="bi bi-lock-fill fs-3" style="color:var(--color-card-text);"></i>
                            </div>
                            <h6 class="fw-semibold mb-1" style="color:var(--color-card-text);">Limite do plano <?= $_nomePlanoLC ?></h6>
                            <p class="text-secondary mb-0" style="font-size:0.75rem;">Assine o <?= $_nomeUpgradeLC ?> para até <?= exibirLimite(limitesDoPlano($_upgradeSlugLC)['carteiras']) ?> carteiras</p>
                        </div>
                    </div>
                </a>
            </div>
        <?php endif; ?>

    </div>
</main>

<div class="modal fade" id="modalMesclar" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content bg-dark border-info border-opacity-50 shadow-lg rounded-4">
            <div class="modal-header border-bottom border-secondary-subtle">
                <h5 class="modal-title text-info fw-bold"><i class="bi bi-shuffle me-2"></i> Mesclar Carteira</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="">
                <div class="modal-body p-4">
                    <input type="hidden" name="action" value="mesclar_carteira">
                    <input type="hidden" name="carteira_origem" id="id_carteira_origem">

                    <p class="text-light mb-4">
                        Todas as transações da carteira <strong class="text-warning" id="nome_carteira_origem"></strong> serão movidas. A carteira original será <strong>excluída</strong> após a transferência.
                    </p>

                    <div class="mb-3">
                        <label for="carteira_destino" class="form-label text-secondary small">Transferir tudo para:</label>
                        <select name="carteira_destino" id="carteira_destino" class="form-select form-select-lg bg-transparent border-secondary-subtle text-light shadow-none" required>
                            <option value="" class="bg-dark text-secondary">Selecione o destino...</option>
                            <?php foreach ($carteiras as $c): ?>
                                <option value="<?= $c['IDCarteira'] ?>" class="bg-dark text-light">
                                    <?= htmlspecialchars($c['TipoCarteira']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="modal-footer border-top border-secondary-subtle">
                    <button type="button" class="btn btn-secondary rounded-pill px-4 fw-semibold" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-info rounded-pill px-4 fw-bold text-dark">Confirmar Mescla</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    function abrirModalMescla(idOrigem, nomeOrigem) {
        document.getElementById('id_carteira_origem').value = idOrigem;
        document.getElementById('nome_carteira_origem').textContent = nomeOrigem;

        const selectDestino = document.getElementById('carteira_destino');
        Array.from(selectDestino.options).forEach(opt => opt.style.display = 'block');

        Array.from(selectDestino.options).forEach(opt => {
            if (opt.value === idOrigem) {
                opt.style.display = 'none';
            }
        });

        selectDestino.value = '';
        new bootstrap.Modal(document.getElementById('modalMesclar')).show();
    }
</script>

<style>
    .bg-dark {
        background-color: var(--bg-charcoal-analysis) !important;
    }

    .card.bg-body-tertiary {
        background-color: var(--bg-card-analysis) !important;
        border-color: var(--border-color-analysis) !important;
    }

    .auralis-wallet-card {
        transition: transform 0.2s ease, box-shadow 0.2s ease;
    }

    .auralis-wallet-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 12px 24px rgba(0, 0, 0, 0.4) !important;
        border-color: rgba(170, 140, 44, 0.3) !important;
    }

    .auralis-add-card {
        background-color: transparent !important;
        border: 2px dashed var(--border-color-analysis) !important;
        transition: all 0.2s ease;
    }

    .auralis-add-card:hover {
        border-color: var(--primary-gold-analysis) !important;
        background-color: rgba(170, 140, 44, 0.05) !important;
    }

    .auralis-add-card:hover h6 {
        color: var(--text-light-analysis) !important;
    }

    .btn-gold {
        background: linear-gradient(135deg, #FFB800 0%, #D4AF37 100%);
        border: none;
    }

    .btn-gold:hover {
        background: linear-gradient(135deg, #FFD04F 0%, #E7C665 100%);
        color: #000 !important;
        box-shadow: 0 4px 15px rgba(212, 175, 55, 0.4) !important;
    }

    .tracking-wide {
        letter-spacing: 0.05em;
    }

    .dropdown-item:hover {
        background-color: rgba(255, 255, 255, 0.05);
    }
</style>
<!-- MODAL: CRIAR / EDITAR CARTEIRA -->
<div class="modal fade" id="modalCarteira" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content bg-dark border-secondary-subtle shadow-lg rounded-4">
            <div class="modal-header border-bottom border-secondary-subtle p-3">
                <h5 class="modal-title text-light fw-bold" id="modalCarteiraTitle">
                    <i class="bi bi-wallet-fill me-2" style="color: var(--primary-gold-analysis) !important;" id="modalCarteiraIcon"></i>
                    <span id="modalCarteiraTitleText">Nova Carteira</span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="processa_carteira.php" method="POST" id="formCarteira">
                <div class="modal-body p-4">
                    <!-- Escondidos: ID para edição e Origem para voltar à página correta -->
                    <input type="hidden" name="id_carteira" id="input_id_carteira" value="">
                    <input type="hidden" name="origem" value="listar_carteiras">

                    <div class="mb-3">
                        <label for="input_tipo_carteira" class="form-label text-secondary small fw-semibold">Nome ou Tipo da Carteira</label>
                        <div class="input-group input-group-lg shadow-sm">
                            <span class="input-group-text bg-body-tertiary border-secondary-subtle text-secondary border-end-0">
                                <i class="bi bi-tag-fill"></i>
                            </span>
                            <input type="text" class="form-control bg-body-tertiary border-secondary-subtle border-start-0 text-light shadow-none fw-semibold"
                                id="input_tipo_carteira" name="tipo_carteira" required
                                placeholder="Ex: Conta Pessoal, Nubank...">
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-top border-secondary-subtle p-3 d-flex justify-content-between">
                    <button type="button" class="btn btn-link text-secondary text-decoration-none fw-semibold" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-gold rounded-pill px-4 fw-bold text-dark d-flex align-items-center" id="btnSalvarCarteira">
                        <i class="bi bi-check-lg me-2" id="modalCarteiraBtnIcon"></i> <span id="modalCarteiraBtnText">Salvar Carteira</span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- MODAL: EXCLUIR CARTEIRA -->
<div class="modal fade" id="modalExcluirCarteira" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm" style="max-width: 400px;">
        <div class="modal-content bg-dark border-secondary-subtle shadow-lg rounded-4">
            <div class="modal-header border-bottom border-secondary-subtle p-3">
                <h6 class="modal-title text-light fw-bold">
                    <i class="bi bi-trash3 me-2 text-danger"></i> Excluir Carteira
                </h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <!-- A action vazia ("") diz ao formulário para processar na mesma página,
                 onde a lógica PHP de exclusão que já existe lá no topo fará o trabalho. -->
            <form method="POST" action="">
                <div class="modal-body p-4 text-center">
                    <input type="hidden" name="action" value="excluir_carteira">
                    <input type="hidden" name="carteira_id" id="input_excluir_carteira_id">

                    <div class="mb-3 d-inline-flex justify-content-center align-items-center bg-danger bg-opacity-10 rounded-circle" style="width: 60px; height: 60px;">
                        <i class="bi bi-exclamation-triangle-fill text-danger fs-3"></i>
                    </div>

                    <p class="text-secondary mb-0 fs-6">
                        Tem certeza que deseja excluir a carteira <br>
                        <strong class="text-light fs-5" id="text_excluir_carteira_nome"></strong>?
                    </p>
                    <p class="text-danger small mt-2 fw-semibold opacity-75">Essa ação não pode ser desfeita.</p>
                </div>
                <div class="modal-footer border-top border-secondary-subtle d-flex justify-content-between p-2">
                    <button type="button" class="btn btn-sm btn-link text-secondary text-decoration-none" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-sm btn-danger fw-bold px-4 rounded-pill">
                        Confirmar
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    // Dinâmica do Modal (Alterna entre Modo Criar e Modo Editar perfeitamente)
    function abrirModalCarteira(id = '', nome = '') {
        document.getElementById('input_id_carteira').value = id;
        document.getElementById('input_tipo_carteira').value = nome;

        const titleText = document.getElementById('modalCarteiraTitleText');
        const icon = document.getElementById('modalCarteiraIcon');
        const btnText = document.getElementById('modalCarteiraBtnText');
        const btnIcon = document.getElementById('modalCarteiraBtnIcon');

        if (id !== '') {
            // MODO EDIÇÃO
            titleText.textContent = 'Editar Carteira';
            icon.className = 'bi bi-pencil-square me-2';
            btnText.textContent = 'Atualizar Carteira';
            btnIcon.className = 'bi bi-arrow-repeat me-2';
        } else {
            // MODO CRIAÇÃO
            titleText.textContent = 'Nova Carteira';
            icon.className = 'bi bi-wallet-fill me-2';
            btnText.textContent = 'Salvar Carteira';
            btnIcon.className = 'bi bi-check-lg me-2';
        }

        new bootstrap.Modal(document.getElementById('modalCarteira')).show();
    }

    // Alimenta o modal de exclusão dinamicamente com os dados da carteira clicada
    function abrirModalExcluirCarteira(id, nome) {
        document.getElementById('input_excluir_carteira_id').value = id;
        document.getElementById('text_excluir_carteira_nome').textContent = nome;
        new bootstrap.Modal(document.getElementById('modalExcluirCarteira')).show();
    }

    // Trava de Anti-Spam (Blindagem no clique duplo)
    const formCarteira = document.getElementById('formCarteira');
    const btnSalvarCarteira = document.getElementById('btnSalvarCarteira');

    if (formCarteira) {
        formCarteira.addEventListener('submit', function() {
            btnSalvarCarteira.disabled = true;
            btnSalvarCarteira.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span> Salvando...';
        });
    }

    // Limpeza da URL para erros e sucessos
    if (window.history.replaceState) {
        const url = new URL(window.location);
        if (url.searchParams.has('sucesso') || url.searchParams.has('erro')) {
            url.searchParams.delete('sucesso');
            url.searchParams.delete('erro');
            window.history.replaceState({
                path: url.href
            }, '', url.href);
        }
    }

    if (window.history.replaceState) {
        const url = new URL(window.location);
        if (url.searchParams.has('sucesso')) {
            url.searchParams.delete('sucesso');
            window.history.replaceState({
                path: url.href
            }, '', url.href);
        }
    }
</script>

<?php require_once '../geral/footer.php'; ?>