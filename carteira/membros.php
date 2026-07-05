<?php
// ==============================================================================
// CARTEIRA/MEMBROS.PHP — Gerenciar membros de uma carteira compartilhada (dono)
// ==============================================================================
session_start();
if (!isset($_SESSION['usuario_id'])) {
    header("Location: ../usuario/login.php");
    exit;
}

require_once '../config/conexao.php';
require_once '../config/funcoes.php';
garantirEstruturaCarteirasCompartilhadas($pdo);

$usuario_id  = $_SESSION['usuario_id'];
$carteira_id = trim($_GET['carteira'] ?? '');

if (empty($carteira_id)) {
    header("Location: listar_carteiras.php?erro=carteira_invalida");
    exit;
}

// Confirma que a carteira existe, é compartilhada e que quem está acessando é o dono
$stmtCart = $pdo->prepare("SELECT IDCarteira, TipoCarteira, Compartilhada, FKUsuarioDono FROM Carteira WHERE IDCarteira = :cid");
$stmtCart->execute([':cid' => $carteira_id]);
$carteira = $stmtCart->fetch(PDO::FETCH_ASSOC);

if (!$carteira || (int)$carteira['Compartilhada'] !== 1 || $carteira['FKUsuarioDono'] !== $usuario_id) {
    header("Location: listar_carteiras.php?erro=carteira_invalida");
    exit;
}

$sucesso = null;
$erro    = null;

// --- CONVIDAR ALGUÉM PELO CÓDIGO PESSOAL ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'convidar') {
    $codigo = strtoupper(trim($_POST['codigo'] ?? ''));

    if (empty($codigo)) {
        $erro = "Informe o código da pessoa.";
    } else {
        try {
            $stmtBusca = $pdo->prepare("SELECT IDUsuario, Nome FROM Usuario WHERE CodigoConvite = :c LIMIT 1");
            $stmtBusca->execute([':c' => $codigo]);
            $convidadoUsuario = $stmtBusca->fetch(PDO::FETCH_ASSOC);

            if (!$convidadoUsuario) {
                $erro = "Nenhum usuário encontrado com esse código.";
            } elseif ($convidadoUsuario['IDUsuario'] === $usuario_id) {
                $erro = "Você não pode convidar a si mesmo(a).";
            } else {
                $stmtJa = $pdo->prepare("SELECT StatusConvite FROM MembroCarteira WHERE FKCarteira = :cid AND FKUsuario = :uid");
                $stmtJa->execute([':cid' => $carteira_id, ':uid' => $convidadoUsuario['IDUsuario']]);
                $jaExiste = $stmtJa->fetchColumn();

                if ($jaExiste !== false) {
                    $erro = ((int)$jaExiste === 1)
                        ? "{$convidadoUsuario['Nome']} já faz parte dessa carteira."
                        : "{$convidadoUsuario['Nome']} já tem um convite pendente pra essa carteira.";
                } elseif (!podeConvidarMaisMembros($pdo, $carteira_id)) {
                    $erro = "Limite de pessoas do seu plano atingido pra essa carteira. Remova alguém ou faça upgrade.";
                } else {
                    $pdo->prepare("
                        INSERT INTO MembroCarteira (IDMembroCarteira, FKCarteira, FKUsuario, StatusConvite)
                        VALUES (:id, :cid, :uid, 0)
                    ")->execute([':id' => gerarUuid(), ':cid' => $carteira_id, ':uid' => $convidadoUsuario['IDUsuario']]);

                    logAtividadeCarteira($pdo, $carteira_id, $usuario_id, 'convite_enviado', "Convidou {$convidadoUsuario['Nome']}");

                    header("Location: membros.php?carteira=" . urlencode($carteira_id) . "&sucesso=convite_enviado");
                    exit;
                }
            }
        } catch (PDOException $e) {
            $erro = "Erro ao enviar o convite.";
        }
    }
}

// --- REMOVER MEMBRO (ATIVO OU CONVITE PENDENTE) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'remover_membro') {
    $membroUsuarioId = trim($_POST['usuario_id'] ?? '');
    try {
        $stmtNome = $pdo->prepare("SELECT Nome FROM Usuario WHERE IDUsuario = :uid");
        $stmtNome->execute([':uid' => $membroUsuarioId]);
        $nomeMembro = $stmtNome->fetchColumn() ?: 'Usuário';

        $pdo->prepare("DELETE FROM MembroCarteira WHERE FKCarteira = :cid AND FKUsuario = :uid")
            ->execute([':cid' => $carteira_id, ':uid' => $membroUsuarioId]);

        logAtividadeCarteira($pdo, $carteira_id, $usuario_id, 'removeu_membro', "Removeu {$nomeMembro}");

        header("Location: membros.php?carteira=" . urlencode($carteira_id) . "&sucesso=membro_removido");
        exit;
    } catch (PDOException $e) {
        header("Location: membros.php?carteira=" . urlencode($carteira_id) . "&erro=banco");
        exit;
    }
}

$msgsSucesso = [
    'convite_enviado'  => 'Convite enviado! A pessoa precisa aceitar pra entrar na carteira.',
    'membro_removido'  => 'Pessoa removida da carteira.',
];
if (isset($_GET['sucesso']) && isset($msgsSucesso[$_GET['sucesso']])) $sucesso = $msgsSucesso[$_GET['sucesso']];
if (($_GET['erro'] ?? '') === 'banco') $erro = "Erro ao salvar no banco de dados.";

// Lista de membros (ativos e pendentes)
$membros = [];
try {
    $stmtMembros = $pdo->prepare("
        SELECT u.IDUsuario, u.Nome, u.Email, mc.StatusConvite, mc.MomentoCriacao
        FROM MembroCarteira mc
        JOIN Usuario u ON u.IDUsuario = mc.FKUsuario
        WHERE mc.FKCarteira = :cid
        ORDER BY mc.StatusConvite DESC, mc.MomentoCriacao ASC
    ");
    $stmtMembros->execute([':cid' => $carteira_id]);
    $membros = $stmtMembros->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
}

$podeConvidarMais = podeConvidarMaisMembros($pdo, $carteira_id);
$limites             = limitesDoPlano();
$totalVagas           = $limites['carteiras_compartilhadas_membros'] ?? 0;

// Log de atividade (mais recentes primeiro)
$logs = [];
try {
    $stmtLog = $pdo->prepare("
        SELECT l.Acao, l.Detalhe, l.CriadoEm, u.Nome
        FROM LogAtividadeCarteira l
        JOIN Usuario u ON u.IDUsuario = l.FKUsuario
        WHERE l.FKCarteira = :cid
        ORDER BY l.CriadoEm DESC
        LIMIT 50
    ");
    $stmtLog->execute([':cid' => $carteira_id]);
    $logs = $stmtLog->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
}

$acoesLabel = [
    'convite_enviado' => ['icone' => 'bi-person-plus', 'cor' => '#60a5fa', 'texto' => 'enviou um convite'],
    'removeu_membro'  => ['icone' => 'bi-person-dash',  'cor' => '#f87171', 'texto' => 'removeu alguém'],
    'saiu'            => ['icone' => 'bi-box-arrow-left', 'cor' => '#f59e0b', 'texto' => 'saiu da carteira'],
    'aceitou_convite' => ['icone' => 'bi-person-check', 'cor' => '#22c55e', 'texto' => 'entrou na carteira'],
    'recusou_convite' => ['icone' => 'bi-person-x', 'cor' => '#a1a1aa', 'texto' => 'recusou o convite'],
];

require_once '../geral/header.php';
?>

<main class="container py-4 mt-2 flex-grow-1" style="max-width:900px;padding-inline:var(--space-page-x);min-height:100vh;">

    <div class="d-flex justify-content-between align-items-center mb-4 border-bottom border-secondary-subtle pb-3 gap-3 flex-wrap">
        <a href="listar_carteiras.php" class="btn btn-outline-secondary btn-sm rounded-pill px-3 transition-hover">
            <i class="bi bi-arrow-left me-1"></i> Voltar
        </a>
        <a href="../gerenciar_categorias.php?carteira=<?= urlencode($carteira_id) ?>" class="btn btn-outline-secondary btn-sm rounded-pill px-3 transition-hover">
            <i class="bi bi-tags me-1"></i> Gerenciar Categorias desta Carteira
        </a>
    </div>

    <div class="mb-4">
        <div class="d-flex align-items-center gap-2 mb-1">
            <i class="bi bi-people-fill" style="color:#60a5fa;font-size:1.1rem;"></i>
            <h4 class="fw-bold text-light mb-0">Membros — <?= htmlspecialchars($carteira['TipoCarteira']) ?></h4>
        </div>
        <p class="text-secondary small mb-0">Convide pessoas pra ver e lançar transações nessa carteira.</p>
    </div>

    <?php if ($sucesso): ?>
        <script>window._pendingToast = <?= json_encode($sucesso) ?>;</script>
    <?php endif; ?>

    <?php if ($erro): ?>
        <div class="alert d-flex align-items-center gap-2 rounded-3 shadow-sm border-0 fw-semibold mb-4" style="background:var(--color-expense-bg);color:var(--color-expense-text);border:1px solid var(--color-expense-border) !important;">
            <i class="bi bi-exclamation-triangle-fill"></i> <span><?= htmlspecialchars($erro) ?></span>
        </div>
    <?php endif; ?>

    <!-- ── Convidar por código ──────────────────────────────────────────── -->
    <div class="card shadow-sm rounded-4 mb-4" style="background:var(--bg-card);border:1px solid var(--card-border-color);">
        <div class="card-body p-4">
            <h6 class="fw-bold text-light mb-3"><i class="bi bi-person-plus me-2" style="color:#60a5fa;"></i>Convidar por código</h6>
            <?php if ($podeConvidarMais): ?>
                <form method="POST" action="" class="d-flex gap-2 flex-wrap">
                    <input type="hidden" name="action" value="convidar">
                    <input type="text" name="codigo" class="form-control bg-body-tertiary border-secondary-subtle text-light shadow-none flex-grow-1"
                           placeholder="Ex: USR-AB12CD" style="max-width:260px;" required>
                    <button type="submit" class="btn btn-sm rounded-pill fw-semibold px-3" style="background:rgba(96,165,250,0.18);color:#60a5fa;border:1px solid rgba(96,165,250,0.4);">
                        <i class="bi bi-send me-1"></i> Enviar convite
                    </button>
                </form>
                <p class="text-secondary small mb-0 mt-2">Peça pra pessoa abrir <strong class="text-light">Configurações</strong> e te passar o código dela.</p>
            <?php else: ?>
                <div class="d-flex align-items-center justify-content-between gap-2 flex-wrap">
                    <span class="text-secondary small"><i class="bi bi-lock-fill me-1"></i>Limite de <?= (int)$totalVagas ?> pessoa(s) do seu plano atingido nessa carteira.</span>
                    <a href="/planos.php?upgrade=vip" class="btn btn-sm rounded-pill fw-semibold" style="background:var(--color-card-bg);color:var(--color-card-text);border:1px solid var(--color-card-border);">Assinar VIP</a>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ── Lista de membros ─────────────────────────────────────────────── -->
    <div class="card shadow-sm rounded-4 mb-4" style="background:var(--bg-card);border:1px solid var(--card-border-color);">
        <div class="card-header border-bottom border-secondary-subtle bg-transparent p-4">
            <h6 class="fw-bold text-light mb-0">Pessoas nessa carteira</h6>
        </div>
        <div class="card-body p-0">
            <!-- O dono sempre aparece primeiro, fixo -->
            <div class="d-flex align-items-center justify-content-between px-4 py-3 border-bottom border-secondary-subtle">
                <div class="d-flex align-items-center gap-3">
                    <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width:38px;height:38px;background:rgba(212,175,55,0.15);color:var(--primary-gold-analysis);font-weight:700;">
                        <i class="bi bi-star-fill" style="font-size:0.9rem;"></i>
                    </div>
                    <div>
                        <div class="text-light fw-semibold">Você</div>
                        <div class="text-secondary" style="font-size:0.75rem;">Dono(a) da carteira</div>
                    </div>
                </div>
            </div>

            <?php if (empty($membros)): ?>
                <div class="p-4 text-center text-secondary small">Ninguém convidado ainda.</div>
            <?php else: ?>
                <?php foreach ($membros as $m):
                    $ativo = (int)$m['StatusConvite'] === 1;
                    $iniciais = mb_strtoupper(mb_substr($m['Nome'], 0, 1));
                ?>
                <div class="d-flex align-items-center justify-content-between px-4 py-3 border-bottom border-secondary-subtle">
                    <div class="d-flex align-items-center gap-3">
                        <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width:38px;height:38px;background:rgba(96,165,250,0.15);color:#60a5fa;font-weight:700;">
                            <?= htmlspecialchars($iniciais) ?>
                        </div>
                        <div>
                            <div class="text-light fw-semibold"><?= htmlspecialchars($m['Nome']) ?></div>
                            <div class="<?= $ativo ? 'text-secondary' : '' ?>" style="font-size:0.75rem;<?= $ativo ? '' : 'color:#f59e0b;' ?>">
                                <?= $ativo ? 'Ativo(a)' : 'Convite pendente' ?>
                            </div>
                        </div>
                    </div>
                    <form method="POST" action="" onsubmit="return confirm('Remover <?= htmlspecialchars(addslashes($m['Nome'])) ?> dessa carteira?');">
                        <input type="hidden" name="action" value="remover_membro">
                        <input type="hidden" name="usuario_id" value="<?= htmlspecialchars($m['IDUsuario']) ?>">
                        <button type="submit" class="btn btn-sm btn-outline-danger rounded-pill" style="font-size:0.72rem;">
                            <i class="bi bi-person-dash me-1"></i> Remover
                        </button>
                    </form>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- ── Log de atividade ─────────────────────────────────────────────── -->
    <div class="card shadow-sm rounded-4" style="background:var(--bg-card);border:1px solid var(--card-border-color);">
        <div class="card-header border-bottom border-secondary-subtle bg-transparent p-4">
            <h6 class="fw-bold text-light mb-0"><i class="bi bi-clock-history me-2"></i>Log de atividade</h6>
        </div>
        <div class="card-body p-0" style="max-height:360px;overflow-y:auto;">
            <?php if (empty($logs)): ?>
                <div class="p-4 text-center text-secondary small">Nenhuma atividade registrada ainda.</div>
            <?php else: ?>
                <?php foreach ($logs as $log):
                    $info = $acoesLabel[$log['Acao']] ?? ['icone' => 'bi-dot', 'cor' => '#a1a1aa', 'texto' => $log['Acao']];
                ?>
                <div class="d-flex align-items-start gap-3 px-4 py-3 border-bottom border-secondary-subtle">
                    <i class="bi <?= $info['icone'] ?> mt-1" style="color:<?= $info['cor'] ?>;"></i>
                    <div class="flex-grow-1">
                        <span class="text-light fw-semibold"><?= htmlspecialchars($log['Nome']) ?></span>
                        <span class="text-secondary"><?= htmlspecialchars($info['texto']) ?></span>
                        <?php if (!empty($log['Detalhe'])): ?>
                            <span class="text-secondary">— <?= htmlspecialchars($log['Detalhe']) ?></span>
                        <?php endif; ?>
                    </div>
                    <span class="text-secondary flex-shrink-0" style="font-size:0.72rem;"><?= date('d/m/Y H:i', strtotime($log['CriadoEm'])) ?></span>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

</main>

<?php require_once '../geral/footer.php'; ?>
