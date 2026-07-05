<?php
// ==============================================================================
// CARTEIRA/ADMINISTRAR_CARTEIRA.PHP — Hub central de uma carteira compartilhada
// (Membros, Atividade e Permissões pro dono; Atividade + Sair pro convidado)
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

// Confirma que a carteira existe e é compartilhada
$stmtCart = $pdo->prepare("SELECT IDCarteira, TipoCarteira, Compartilhada, FKUsuarioDono, PermiteConvidadoExcluir FROM Carteira WHERE IDCarteira = :cid");
$stmtCart->execute([':cid' => $carteira_id]);
$carteira = $stmtCart->fetch(PDO::FETCH_ASSOC);

if (!$carteira || (int)$carteira['Compartilhada'] !== 1) {
    header("Location: listar_carteiras.php?erro=carteira_invalida");
    exit;
}

// Papel de quem acessa — dono vê tudo, convidado só Atividade + Sair. Sem acesso, fora.
$papel = carteiraPapelDoUsuario($pdo, $carteira_id, $usuario_id);
if ($papel === null) {
    header("Location: listar_carteiras.php?erro=carteira_invalida");
    exit;
}

$sucesso = null;
$erro    = null;

// --- CONVIDAR ALGUÉM PELO CÓDIGO PESSOAL (só dono) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'convidar' && $papel === 'dono') {
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

                    header("Location: administrar_carteira.php?carteira=" . urlencode($carteira_id) . "&aba=membros&sucesso=convite_enviado");
                    exit;
                }
            }
        } catch (PDOException $e) {
            $erro = "Erro ao enviar o convite.";
        }
    }
}

// --- REMOVER MEMBRO (ATIVO OU CONVITE PENDENTE) — só dono ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'remover_membro' && $papel === 'dono') {
    $membroUsuarioId = trim($_POST['usuario_id'] ?? '');
    try {
        $stmtNome = $pdo->prepare("SELECT Nome FROM Usuario WHERE IDUsuario = :uid");
        $stmtNome->execute([':uid' => $membroUsuarioId]);
        $nomeMembro = $stmtNome->fetchColumn() ?: 'Usuário';

        $pdo->prepare("DELETE FROM MembroCarteira WHERE FKCarteira = :cid AND FKUsuario = :uid")
            ->execute([':cid' => $carteira_id, ':uid' => $membroUsuarioId]);

        logAtividadeCarteira($pdo, $carteira_id, $usuario_id, 'removeu_membro', "Removeu {$nomeMembro}");

        header("Location: administrar_carteira.php?carteira=" . urlencode($carteira_id) . "&aba=membros&sucesso=membro_removido");
        exit;
    } catch (PDOException $e) {
        header("Location: administrar_carteira.php?carteira=" . urlencode($carteira_id) . "&aba=membros&erro=banco");
        exit;
    }
}

// --- SALVAR PERMISSÕES — só dono ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'salvar_permissoes' && $papel === 'dono') {
    $permiteExcluir = isset($_POST['permite_convidado_excluir']) ? 1 : 0;
    try {
        $pdo->prepare("UPDATE Carteira SET PermiteConvidadoExcluir = :v WHERE IDCarteira = :cid AND FKUsuarioDono = :uid")
            ->execute([':v' => $permiteExcluir, ':cid' => $carteira_id, ':uid' => $usuario_id]);
        $carteira['PermiteConvidadoExcluir'] = $permiteExcluir;
        header("Location: administrar_carteira.php?carteira=" . urlencode($carteira_id) . "&aba=permissoes&sucesso=permissoes_salvas");
        exit;
    } catch (PDOException $e) {
        header("Location: administrar_carteira.php?carteira=" . urlencode($carteira_id) . "&aba=permissoes&erro=banco");
        exit;
    }
}

$msgsSucesso = [
    'convite_enviado'     => 'Convite enviado! A pessoa precisa aceitar pra entrar na carteira.',
    'membro_removido'     => 'Pessoa removida da carteira.',
    'permissoes_salvas'   => 'Permissões atualizadas.',
];
if (isset($_GET['sucesso']) && isset($msgsSucesso[$_GET['sucesso']])) $sucesso = $msgsSucesso[$_GET['sucesso']];
if (($_GET['erro'] ?? '') === 'banco') $erro = "Erro ao salvar no banco de dados.";

// Aba ativa — convidado só enxerga Atividade, independente do que vier na URL
$aba = $_GET['aba'] ?? 'membros';
if ($papel !== 'dono') $aba = 'atividade';
if ($papel === 'dono' && !in_array($aba, ['membros', 'atividade', 'permissoes'], true)) $aba = 'membros';

// ── Dados da aba Membros ──────────────────────────────────────────────────
$membros = [];
$podeConvidarMais = false;
$totalVagas = 0;
if ($papel === 'dono' && $aba === 'membros') {
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
    $totalVagas       = limitesDoPlano()['carteiras_compartilhadas_membros'] ?? 0;
}

// ── Dados da aba Atividade ────────────────────────────────────────────────
$logs = [];
$filtroAtividade = $_GET['filtro'] ?? 'tudo';
if (!in_array($filtroAtividade, ['tudo', 'movimentacao', 'membro'], true)) $filtroAtividade = 'tudo';

if ($aba === 'atividade') {
    try {
        $sqlLog = "
            SELECT l.Acao, l.Detalhe, l.CriadoEm, l.Categoria, u.Nome
            FROM LogAtividadeCarteira l
            JOIN Usuario u ON u.IDUsuario = l.FKUsuario
            WHERE l.FKCarteira = :cid
        ";
        $paramsLog = [':cid' => $carteira_id];
        if ($filtroAtividade !== 'tudo') {
            $sqlLog .= " AND l.Categoria = :categoria";
            $paramsLog[':categoria'] = $filtroAtividade;
        }
        $sqlLog .= " ORDER BY l.CriadoEm DESC LIMIT 150";
        $stmtLog = $pdo->prepare($sqlLog);
        $stmtLog->execute($paramsLog);
        $logs = $stmtLog->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
    }
}

$acoesLabel = [
    'convite_enviado'        => ['icone' => 'bi-person-plus',          'cor' => '#60a5fa', 'texto' => 'enviou um convite'],
    'removeu_membro'         => ['icone' => 'bi-person-dash',          'cor' => '#f87171', 'texto' => 'removeu alguém'],
    'saiu'                   => ['icone' => 'bi-box-arrow-left',       'cor' => '#f59e0b', 'texto' => 'saiu da carteira'],
    'aceitou_convite'        => ['icone' => 'bi-person-check',         'cor' => '#22c55e', 'texto' => 'entrou na carteira'],
    'recusou_convite'        => ['icone' => 'bi-person-x',             'cor' => '#a1a1aa', 'texto' => 'recusou o convite'],
    'lancamento_criado'      => ['icone' => 'bi-plus-circle',          'cor' => '#22c55e', 'texto' => 'lançou'],
    'lancamento_editado'     => ['icone' => 'bi-pencil-square',        'cor' => '#facc15', 'texto' => 'editou um lançamento'],
    'lancamento_excluido'    => ['icone' => 'bi-trash3',               'cor' => '#f87171', 'texto' => 'excluiu um lançamento'],
    'lancamento_efetivado'   => ['icone' => 'bi-check-circle',         'cor' => '#22c55e', 'texto' => 'efetivou um lançamento'],
    'lancamento_estornado'   => ['icone' => 'bi-arrow-counterclockwise', 'cor' => '#f59e0b', 'texto' => 'voltou um lançamento pra pendente'],
    'lancamento_transferido' => ['icone' => 'bi-arrow-left-right',     'cor' => '#60a5fa', 'texto' => 'transferiu um lançamento pra outra carteira'],
];

require_once '../geral/header.php';
?>

<main class="container py-4 mt-2 flex-grow-1" style="max-width:900px;padding-inline:var(--space-page-x);min-height:100vh;">

    <div class="d-flex justify-content-between align-items-center mb-4 border-bottom border-secondary-subtle pb-3 gap-3 flex-wrap">
        <a href="listar_carteiras.php" class="btn btn-outline-secondary btn-sm rounded-pill px-3 transition-hover">
            <i class="bi bi-arrow-left me-1"></i> Voltar
        </a>
    </div>

    <div class="mb-4">
        <div class="d-flex align-items-center gap-2 mb-1">
            <i class="bi bi-people-fill" style="color:#60a5fa;font-size:1.1rem;"></i>
            <h4 class="fw-bold text-light mb-0">Administrar Carteira — <?= htmlspecialchars($carteira['TipoCarteira']) ?></h4>
        </div>
        <p class="text-secondary small mb-0">
            <?= $papel === 'dono' ? 'Gerencie quem participa, acompanhe a atividade e defina permissões dessa carteira.' : 'Acompanhe a atividade dessa carteira compartilhada.' ?>
        </p>
    </div>

    <?php if ($sucesso): ?>
        <script>window._pendingToast = <?= json_encode($sucesso) ?>;</script>
    <?php endif; ?>

    <?php if ($erro): ?>
        <div class="alert d-flex align-items-center gap-2 rounded-3 shadow-sm border-0 fw-semibold mb-4" style="background:var(--color-expense-bg);color:var(--color-expense-text);border:1px solid var(--color-expense-border) !important;">
            <i class="bi bi-exclamation-triangle-fill"></i> <span><?= htmlspecialchars($erro) ?></span>
        </div>
    <?php endif; ?>

    <!-- ── Navegação interna (tipo admin: mesma página, troca por aba) ──────── -->
    <ul class="nav nav-pills gap-2 mb-4 flex-wrap">
        <?php if ($papel === 'dono'): ?>
            <li class="nav-item">
                <a href="?carteira=<?= urlencode($carteira_id) ?>&aba=membros" class="nav-link rounded-pill fw-semibold"
                    style="font-size:0.85rem;<?= $aba === 'membros' ? 'background:#60a5fa;color:#06111f;' : 'background:rgba(255,255,255,.05);color:#9ca3af;' ?>">
                    <i class="bi bi-people-fill me-1"></i> Membros
                </a>
            </li>
        <?php endif; ?>
        <li class="nav-item">
            <a href="?carteira=<?= urlencode($carteira_id) ?>&aba=atividade" class="nav-link rounded-pill fw-semibold"
                style="font-size:0.85rem;<?= $aba === 'atividade' ? 'background:#60a5fa;color:#06111f;' : 'background:rgba(255,255,255,.05);color:#9ca3af;' ?>">
                <i class="bi bi-clock-history me-1"></i> Atividade
            </a>
        </li>
        <?php if ($papel === 'dono'): ?>
            <li class="nav-item">
                <a href="?carteira=<?= urlencode($carteira_id) ?>&aba=permissoes" class="nav-link rounded-pill fw-semibold"
                    style="font-size:0.85rem;<?= $aba === 'permissoes' ? 'background:#60a5fa;color:#06111f;' : 'background:rgba(255,255,255,.05);color:#9ca3af;' ?>">
                    <i class="bi bi-shield-lock-fill me-1"></i> Permissões
                </a>
            </li>
        <?php endif; ?>
        <li class="nav-item ms-auto">
            <a href="../gerenciar_categorias.php?carteira=<?= urlencode($carteira_id) ?>" class="nav-link rounded-pill fw-semibold"
                style="font-size:0.85rem;background:rgba(212,175,55,0.15);color:var(--primary-gold-analysis);border:1px solid rgba(212,175,55,0.35);">
                <i class="bi bi-tags-fill me-1"></i> Categorias <i class="bi bi-box-arrow-up-right ms-1" style="font-size:0.7rem;"></i>
            </a>
        </li>
        <?php if ($papel === 'convidado'): ?>
            <li class="nav-item">
                <button type="button" class="nav-link rounded-pill fw-semibold border-0" onclick="abrirModalSairCarteira()"
                    style="font-size:0.85rem;background:rgba(248,113,113,0.12);color:#f87171;">
                    <i class="bi bi-box-arrow-left me-1"></i> Sair da Carteira
                </button>
            </li>
        <?php endif; ?>
    </ul>

    <?php if ($papel === 'dono' && $aba === 'membros'): ?>

        <!-- ── Convidar por código ──────────────────────────────────────────── -->
        <div class="card shadow-sm rounded-4 mb-4" style="background:var(--bg-card);border:1px solid var(--card-border-color);">
            <div class="card-body p-4">
                <h6 class="fw-bold text-light mb-3"><i class="bi bi-person-plus me-2" style="color:#60a5fa;"></i>Convidar por código</h6>
                <?php if ($podeConvidarMais): ?>
                    <form method="POST" action="?carteira=<?= urlencode($carteira_id) ?>&aba=membros" class="d-flex gap-2 flex-wrap">
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
        <div class="card shadow-sm rounded-4" style="background:var(--bg-card);border:1px solid var(--card-border-color);">
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
                        <form method="POST" action="?carteira=<?= urlencode($carteira_id) ?>&aba=membros" onsubmit="return confirm('Remover <?= htmlspecialchars(addslashes($m['Nome'])) ?> dessa carteira?');">
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

    <?php elseif ($aba === 'atividade'): ?>

        <!-- ── Filtro de 3 visões ────────────────────────────────────────────── -->
        <ul class="nav nav-pills gap-2 mb-3">
            <?php
            $_filtros = [
                'tudo'         => 'Tudo',
                'movimentacao' => 'Movimentações na Carteira',
                'membro'       => 'Movimentações de Membro',
            ];
            foreach ($_filtros as $key => $label):
            ?>
                <li class="nav-item">
                    <a href="?carteira=<?= urlencode($carteira_id) ?>&aba=atividade&filtro=<?= $key ?>" class="nav-link rounded-pill"
                        style="font-size:0.78rem;<?= $filtroAtividade === $key ? 'background:rgba(96,165,250,0.25);color:#60a5fa;' : 'background:rgba(255,255,255,.04);color:#9ca3af;' ?>">
                        <?= $label ?>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>

        <div class="card shadow-sm rounded-4" style="background:var(--bg-card);border:1px solid var(--card-border-color);">
            <div class="card-body p-0" style="max-height:520px;overflow-y:auto;">
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

    <?php elseif ($papel === 'dono' && $aba === 'permissoes'): ?>

        <div class="card shadow-sm rounded-4" style="background:var(--bg-card);border:1px solid var(--card-border-color);">
            <div class="card-header border-bottom border-secondary-subtle bg-transparent p-4">
                <h6 class="fw-bold text-light mb-0"><i class="bi bi-shield-lock-fill me-2"></i>Permissões dos convidados</h6>
            </div>
            <div class="card-body p-4">
                <form method="POST" action="?carteira=<?= urlencode($carteira_id) ?>&aba=permissoes">
                    <input type="hidden" name="action" value="salvar_permissoes">
                    <div class="d-flex align-items-start justify-content-between gap-3 p-3 rounded-3" style="background:rgba(255,255,255,.03);">
                        <div>
                            <div class="text-light fw-semibold mb-1">Convidados podem excluir os próprios lançamentos</div>
                            <div class="text-secondary" style="font-size:0.8rem;">
                                Desligado, só você (dono) pode excluir lançamentos — convidados ainda podem criar e editar os próprios, mas não apagar.
                                Toda exclusão sempre aparece em <strong class="text-light">Atividade</strong>, mesmo com isso ligado.
                            </div>
                        </div>
                        <div class="form-check form-switch fs-4 mb-0 flex-shrink-0 mt-1">
                            <input class="form-check-input" type="checkbox" role="switch" name="permite_convidado_excluir" value="1"
                                <?= (int)($carteira['PermiteConvidadoExcluir'] ?? 1) === 1 ? 'checked' : '' ?>>
                        </div>
                    </div>
                    <div class="d-flex justify-content-end mt-3">
                        <button type="submit" class="btn btn-sm rounded-pill fw-semibold px-4" style="background:rgba(96,165,250,0.18);color:#60a5fa;border:1px solid rgba(96,165,250,0.4);">
                            <i class="bi bi-check-lg me-1"></i> Salvar
                        </button>
                    </div>
                </form>
            </div>
        </div>

    <?php endif; ?>

</main>

<?php if ($papel === 'convidado'): ?>
<!-- MODAL: SAIR DE CARTEIRA COMPARTILHADA (submete pra listar_carteiras.php, que já processa isso) -->
<div class="modal fade" id="modalSairCarteira" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm" style="max-width: 400px;">
        <div class="modal-content bg-dark border-secondary-subtle shadow-lg rounded-4">
            <div class="modal-header border-bottom border-secondary-subtle p-3">
                <h6 class="modal-title text-light fw-bold">
                    <i class="bi bi-box-arrow-left me-2 text-danger"></i> Sair da Carteira
                </h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="listar_carteiras.php">
                <div class="modal-body p-4 text-center">
                    <input type="hidden" name="action" value="sair_carteira">
                    <input type="hidden" name="carteira_id" value="<?= htmlspecialchars($carteira_id) ?>">
                    <p class="text-secondary mb-0 fs-6">
                        Tem certeza que deseja sair da carteira <br>
                        <strong class="text-light fs-5"><?= htmlspecialchars($carteira['TipoCarteira']) ?></strong>?
                    </p>
                    <p class="text-secondary small mt-2 opacity-75">Suas transações já lançadas continuam lá — você só perde o acesso.</p>
                </div>
                <div class="modal-footer border-top border-secondary-subtle d-flex justify-content-between p-2">
                    <button type="button" class="btn btn-sm btn-link text-secondary text-decoration-none" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-sm btn-danger fw-bold px-4 rounded-pill">Sair</button>
                </div>
            </form>
        </div>
    </div>
</div>
<script>
    function abrirModalSairCarteira() {
        new bootstrap.Modal(document.getElementById('modalSairCarteira')).show();
    }
</script>
<?php endif; ?>

<?php require_once '../geral/footer.php'; ?>
