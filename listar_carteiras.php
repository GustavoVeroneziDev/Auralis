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
}

// --- BUSCA AS CARTEIRAS E CALCULA O SALDO DE CADA UMA ---
$carteiras = [];
try {
    // SQL Inteligente: Já calcula o saldo exato de cada carteira direto no banco
    $sqlCarteiras = "
        SELECT c.IDCarteira, c.TipoCarteira,
               COALESCE(SUM(CASE WHEN r.TipoRegistro = 'receita' THEN r.Valor ELSE 0 END), 0) -
               COALESCE(SUM(CASE WHEN r.TipoRegistro = 'despesa' THEN r.Valor ELSE 0 END), 0) as SaldoAtual
        FROM Carteira c
        LEFT JOIN Registro r ON c.IDCarteira = r.FKCarteira AND r.StatusRegistro = 'efetivado'
        WHERE c.FKUsuarioDono = :uid
        GROUP BY c.IDCarteira, c.TipoCarteira
        ORDER BY c.TipoCarteira ASC
    ";
    $stmt = $pdo->prepare($sqlCarteiras);
    $stmt->execute([':uid' => $usuario_id]);
    $carteiras = $stmt->fetchAll();
} catch (PDOException $e) {
    $erro = "Erro ao buscar as suas carteiras.";
}

// Volta uma pasta para achar o header
require_once '../geral/header.php';
?>

<main class="container-fluid py-4 mt-2 flex-grow-1" style="max-width: 1500px; padding-inline: var(--space-page-x); min-height: 100vh;">
    
    <div class="d-flex justify-content-between align-items-center mb-4 border-bottom border-secondary-subtle pb-3 flex-wrap gap-3">
        <h2 class="fw-bold text-light mb-0">Minhas Carteiras</h2>
        <div class="d-flex gap-2">
            <a href="../dashboard.php" class="btn btn-outline-secondary btn-sm rounded-pill px-3 transition-hover d-flex align-items-center">
                <i class="bi bi-arrow-left me-1"></i> Voltar
            </a>
            <a href="nova_carteira.php" class="btn btn-gold btn-sm rounded-pill px-4 fw-bold text-dark transition-hover shadow-sm d-flex align-items-center">
                <i class="bi bi-plus-circle me-2"></i> Nova Carteira
            </a>
        </div>
    </div>

    <?php if ($sucesso): ?>
        <div class="alert alert-success d-flex align-items-center gap-2 rounded-3 shadow-sm border-0 bg-success bg-opacity-10 text-success fw-semibold mb-4">
            <i class="bi bi-check-circle-fill"></i> <span><?= htmlspecialchars($sucesso) ?></span>
        </div>
    <?php endif; ?>

    <?php if ($erro): ?>
        <div class="alert alert-danger d-flex align-items-center gap-2 rounded-3 shadow-sm border-0 bg-danger bg-opacity-10 text-danger fw-semibold mb-4">
            <i class="bi bi-exclamation-triangle-fill"></i> <span><?= htmlspecialchars($erro) ?></span>
        </div>
    <?php endif; ?>

    <div class="row g-4">
        
        <?php foreach ($carteiras as $cart): ?>
            <div class="col-md-6 col-lg-4">
                <div class="card bg-body-tertiary border-secondary-subtle shadow-sm h-100 rounded-4 auralis-wallet-card position-relative overflow-hidden">

                    <div class="card-body p-4 position-relative z-1 d-flex flex-column">
                        <div class="d-flex justify-content-between align-items-start mb-4">
                            <div class="d-flex align-items-center gap-3">
                                <div class="icon-circle bg-primary bg-opacity-10 d-flex justify-content-center align-items-center rounded-3 shadow-sm" style="width: 48px; height: 48px;">
                                    <i class="bi bi-bank text-primary fs-4" style="color: var(--primary-gold-analysis) !important;"></i>
                                </div>
                                <h5 class="fw-bold text-light mb-0"><?= htmlspecialchars($cart['TipoCarteira']) ?></h5>
                            </div>

                            <div class="dropdown">
                                <button class="btn btn-link text-secondary p-0 shadow-none border-0" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                    <i class="bi bi-three-dots-vertical fs-5"></i>
                                </button>
                                <ul class="dropdown-menu dropdown-menu-end bg-dark border-secondary-subtle shadow-lg">
                                    <li>
                                        <a class="dropdown-item text-light d-flex align-items-center transition-hover py-2" href="nova_carteira.php?editar=<?= $cart['IDCarteira'] ?>">
                                            <i class="bi bi-pencil-square me-2 text-warning"></i> <span class="text-warning">Editar Nome</span>
                                        </a>
                                    </li>
                                    <li>
                                        <button type="button" class="dropdown-item text-info d-flex align-items-center transition-hover py-2" 
                                                onclick="abrirModalMescla('<?= $cart['IDCarteira'] ?>', '<?= htmlspecialchars($cart['TipoCarteira']) ?>')">
                                            <i class="bi bi-shuffle me-2"></i> Mesclar / Transferir
                                        </button>
                                    </li>
                                    <li>
                                        <form method="POST" action="" class="m-0" onsubmit="return confirm('Deseja realmente excluir a carteira \'<?= htmlspecialchars($cart['TipoCarteira']) ?>\'?');">
                                            <input type="hidden" name="action" value="excluir_carteira">
                                            <input type="hidden" name="carteira_id" value="<?= $cart['IDCarteira'] ?>">
                                            <button type="submit" class="dropdown-item text-danger d-flex align-items-center transition-hover py-2">
                                                <i class="bi bi-trash3 me-2"></i> Excluir Carteira
                                            </button>
                                        </form>
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

        <div class="col-md-6 col-lg-4">
            <a href="nova_carteira.php" class="text-decoration-none">
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

    </div>
</main>

<div class="modal fade" id="modalMesclar" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content bg-dark border-info border-opacity-50 shadow-lg rounded-4">
            <div class="modal-header border-bottom border-secondary-subtle">
                <h5 class="modal-title text-info fw-bold"><i class="bi bi-shuffle me-2"></i> Mesclar Carteira</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
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
    :root {
        --primary-gold-analysis: #AA8C2C;
        --bg-card-analysis: #2A2A2A;
        --bg-charcoal-analysis: #222222;
        --border-color-analysis: #333333;
        --text-light-analysis: #E0E0E0;
    }
    
    .bg-dark { background-color: var(--bg-charcoal-analysis) !important; }
    .card.bg-body-tertiary { background-color: var(--bg-card-analysis) !important; border-color: var(--border-color-analysis) !important; }
    
    .auralis-wallet-card {
        transition: transform 0.2s ease, box-shadow 0.2s ease;
    }
    .auralis-wallet-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 12px 24px rgba(0,0,0,0.4) !important;
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

    .tracking-wide { letter-spacing: 0.05em; }
    
    .dropdown-item:hover {
        background-color: rgba(255, 255, 255, 0.05);
    }
</style>

<script>
    if (window.history.replaceState) {
        const url = new URL(window.location);
        if (url.searchParams.has('sucesso')) {
            url.searchParams.delete('sucesso');
            window.history.replaceState({path: url.href}, '', url.href);
        }
    }
</script>

<?php require_once '../geral/footer.php'; ?>