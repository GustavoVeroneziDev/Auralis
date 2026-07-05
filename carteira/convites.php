<?php
// ==============================================================================
// CARTEIRA/CONVITES.PHP — Inbox de convites de carteira compartilhada (convidado)
// ==============================================================================
session_start();
if (!isset($_SESSION['usuario_id'])) {
    header("Location: ../usuario/login.php");
    exit;
}

require_once '../config/conexao.php';
require_once '../config/funcoes.php';
garantirEstruturaCarteirasCompartilhadas($pdo);

$usuario_id = $_SESSION['usuario_id'];
$sucesso    = null;
$erro       = null;

// --- ACEITAR CONVITE ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'aceitar') {
    $idMembro = trim($_POST['id_membro'] ?? '');
    try {
        $stmt = $pdo->prepare("SELECT FKCarteira FROM MembroCarteira WHERE IDMembro = :id AND FKUsuario = :uid AND StatusConvite = 0");
        $stmt->execute([':id' => $idMembro, ':uid' => $usuario_id]);
        $carteiraId = $stmt->fetchColumn();

        if (!$carteiraId) {
            $erro = "Convite não encontrado (talvez já tenha sido respondido).";
        } else {
            $pdo->prepare("UPDATE MembroCarteira SET StatusConvite = 1, DataResposta = NOW() WHERE IDMembro = :id")
                ->execute([':id' => $idMembro]);
            logAtividadeCarteira($pdo, $carteiraId, $usuario_id, 'aceitou_convite');
            header("Location: convites.php?sucesso=aceito");
            exit;
        }
    } catch (PDOException $e) {
        $erro = "Erro ao aceitar o convite.";
    }
}

// --- RECUSAR CONVITE ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'recusar') {
    $idMembro = trim($_POST['id_membro'] ?? '');
    try {
        $stmt = $pdo->prepare("SELECT FKCarteira FROM MembroCarteira WHERE IDMembro = :id AND FKUsuario = :uid AND StatusConvite = 0");
        $stmt->execute([':id' => $idMembro, ':uid' => $usuario_id]);
        $carteiraId = $stmt->fetchColumn();

        if ($carteiraId) {
            logAtividadeCarteira($pdo, $carteiraId, $usuario_id, 'recusou_convite');
            $pdo->prepare("DELETE FROM MembroCarteira WHERE IDMembro = :id")->execute([':id' => $idMembro]);
        }
        header("Location: convites.php?sucesso=recusado");
        exit;
    } catch (PDOException $e) {
        $erro = "Erro ao recusar o convite.";
    }
}

$msgsSucesso = [
    'aceito'   => 'Convite aceito! A carteira já aparece na sua lista.',
    'recusado' => 'Convite recusado.',
];
if (isset($_GET['sucesso']) && isset($msgsSucesso[$_GET['sucesso']])) $sucesso = $msgsSucesso[$_GET['sucesso']];

$convites = [];
try {
    $stmt = $pdo->prepare("
        SELECT mc.IDMembro, mc.DataConvite, c.TipoCarteira, u.Nome AS NomeDono
        FROM MembroCarteira mc
        JOIN Carteira c ON c.IDCarteira = mc.FKCarteira
        JOIN Usuario u ON u.IDUsuario = c.FKUsuarioDono
        WHERE mc.FKUsuario = :uid AND mc.StatusConvite = 0
        ORDER BY mc.DataConvite DESC
    ");
    $stmt->execute([':uid' => $usuario_id]);
    $convites = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
}

require_once '../geral/header.php';
?>

<main class="container py-4 mt-2 flex-grow-1" style="max-width:800px;padding-inline:var(--space-page-x);min-height:100vh;">

    <div class="d-flex justify-content-between align-items-center mb-4 border-bottom border-secondary-subtle pb-3 gap-3">
        <a href="/dashboard.php" class="btn btn-outline-secondary btn-sm rounded-pill px-3 transition-hover">
            <i class="bi bi-arrow-left me-1"></i> Voltar ao Painel
        </a>
    </div>

    <div class="mb-4">
        <div class="d-flex align-items-center gap-2 mb-1">
            <i class="bi bi-envelope-paper" style="color:#60a5fa;font-size:1.1rem;"></i>
            <h4 class="fw-bold text-light mb-0">Convites de Carteira Compartilhada</h4>
        </div>
        <p class="text-secondary small mb-0">Convites pra participar de carteiras compartilhadas de outras pessoas.</p>
    </div>

    <?php if ($sucesso): ?>
        <script>window._pendingToast = <?= json_encode($sucesso) ?>;</script>
    <?php endif; ?>

    <?php if ($erro): ?>
        <div class="alert d-flex align-items-center gap-2 rounded-3 shadow-sm border-0 fw-semibold mb-4" style="background:var(--color-expense-bg);color:var(--color-expense-text);border:1px solid var(--color-expense-border) !important;">
            <i class="bi bi-exclamation-triangle-fill"></i> <span><?= htmlspecialchars($erro) ?></span>
        </div>
    <?php endif; ?>

    <?php if (empty($convites)): ?>
        <div class="card shadow-sm rounded-4" style="background:var(--bg-card);border:1px solid var(--card-border-color);">
            <div class="card-body p-5 text-center">
                <i class="bi bi-envelope-open text-secondary opacity-25 mb-3 d-block" style="font-size:3rem;"></i>
                <h6 class="text-light fw-bold mb-1">Nenhum convite pendente</h6>
                <p class="text-secondary small mb-0">Quando alguém te convidar pra uma carteira compartilhada, aparece aqui.</p>
            </div>
        </div>
    <?php else: ?>
        <?php foreach ($convites as $conv): ?>
            <div class="card shadow-sm rounded-4 mb-3" style="background:var(--bg-card);border:1px solid var(--card-border-color);">
                <div class="card-body p-4 d-flex align-items-center justify-content-between flex-wrap gap-3">
                    <div class="d-flex align-items-center gap-3">
                        <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width:44px;height:44px;background:rgba(96,165,250,0.15);">
                            <i class="bi bi-people-fill" style="color:#60a5fa;font-size:1.2rem;"></i>
                        </div>
                        <div>
                            <div class="text-light fw-semibold"><?= htmlspecialchars($conv['TipoCarteira']) ?></div>
                            <div class="text-secondary small">Convite de <?= htmlspecialchars($conv['NomeDono']) ?> &middot; <?= date('d/m/Y', strtotime($conv['DataConvite'])) ?></div>
                        </div>
                    </div>
                    <div class="d-flex gap-2">
                        <form method="POST" action="">
                            <input type="hidden" name="action" value="recusar">
                            <input type="hidden" name="id_membro" value="<?= htmlspecialchars($conv['IDMembro']) ?>">
                            <button type="submit" class="btn btn-sm btn-outline-secondary rounded-pill px-3">Recusar</button>
                        </form>
                        <form method="POST" action="">
                            <input type="hidden" name="action" value="aceitar">
                            <input type="hidden" name="id_membro" value="<?= htmlspecialchars($conv['IDMembro']) ?>">
                            <button type="submit" class="btn btn-sm rounded-pill fw-semibold px-3" style="background:rgba(96,165,250,0.18);color:#60a5fa;border:1px solid rgba(96,165,250,0.4);">
                                <i class="bi bi-check-lg me-1"></i> Aceitar
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

</main>

<?php require_once '../geral/footer.php'; ?>
