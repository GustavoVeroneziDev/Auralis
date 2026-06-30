<?php
// ==============================================================================
// ADMIN/USUARIOS.PHP — Gestão manual de usuários e planos
// ==============================================================================
session_start();

if (!isset($_SESSION['usuario_id'])) {
    header("Location: /usuario/login.php");
    exit;
}

require_once '../config/conexao.php';
require_once '../config/funcoes.php';

// Apenas admin ou supremo
$nivelSessao = strtolower($_SESSION['nivel_acesso'] ?? '');
if (!in_array($nivelSessao, ['admin', 'supremo'])) {
    header("Location: /dashboard.php?erro=sem_permissao");
    exit;
}

$adminId = $_SESSION['usuario_id'];
$sucesso = $erro = null;

// ==============================================================================
// AÇÕES POST (PRG)
// ==============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $uid    = trim($_POST['usuario_id'] ?? '');

    if (empty($uid)) {
        $erro = "ID de usuário inválido.";
    } elseif ($action === 'dar_acesso') {
        $plano = in_array($_POST['plano'] ?? '', ['pro', 'vip']) ? $_POST['plano'] : '';
        $dias  = max(1, min(3650, (int)($_POST['dias'] ?? 30)));
        $valor = max(0.0, (float) str_replace(',', '.', preg_replace('/[^\d,]/', '', $_POST['valor_pago'] ?? '0')));

        if (!$plano) {
            $erro = "Selecione um plano válido.";
        } else {
            try {
                $dataInicio    = date('Y-m-d H:i:s');
                $dataExpiracao = date('Y-m-d H:i:s', strtotime("+{$dias} days"));

                $pdo->beginTransaction();

                // Cancela assinatura ativa anterior
                $pdo->prepare("UPDATE Assinatura SET Status = 'cancelada' WHERE FKUsuario = :uid AND Status = 'ativa'")
                    ->execute([':uid' => $uid]);

                // Insere nova assinatura manual
                $pdo->prepare("
                    INSERT INTO Assinatura
                        (IDAssinatura, FKUsuario, Plano, Status, Ciclo, ValorPago,
                         DataInicio, DataExpiracao, IDAssinaturaGW, EmailGateway, FontePagamento)
                    VALUES
                        (:id, :uid, :plano, 'ativa', 'manual', :valor,
                         :inicio, :exp, NULL, NULL, 'manual_admin')
                ")->execute([
                    ':id'    => gerarUuid(),
                    ':uid'   => $uid,
                    ':plano' => $plano,
                    ':valor' => $valor,
                    ':inicio' => $dataInicio,
                    ':exp'   => $dataExpiracao,
                ]);

                // Atualiza plano do usuário
                $pdo->prepare("UPDATE Usuario SET Plano = :plano WHERE IDUsuario = :uid")
                    ->execute([':plano' => $plano, ':uid' => $uid]);

                $pdo->commit();

                concederConquistaParaUsuario($pdo, $uid, $plano === 'vip' ? 'plano_vip' : 'plano_pro');

                $planoNome = strtoupper($plano);
                $dataFmt   = date('d/m/Y', strtotime($dataExpiracao));
                $plural    = $dias > 1 ? 's' : '';
                criarNotificacaoSistema($pdo, $uid,
                    "Você recebeu {$dias} dia{$plural} de plano {$planoNome}!",
                    "Boas notícias! Um administrador concedeu a você {$dias} dia{$plural} de acesso ao plano {$planoNome}.\n\nSeu acesso é válido até {$dataFmt}. Aproveite todos os recursos disponíveis no seu painel!"
                );

                header("Location: usuarios.php?sucesso=acesso_dado");
                exit;
            } catch (PDOException $e) {
                $pdo->rollBack();
                $erro = "Erro ao registrar acesso.";
            }
        }
    } elseif ($action === 'revogar') {
        try {
            $pdo->beginTransaction();
            $pdo->prepare("UPDATE Assinatura SET Status = 'cancelada' WHERE FKUsuario = :uid AND Status = 'ativa'")
                ->execute([':uid' => $uid]);
            $pdo->prepare("UPDATE Usuario SET Plano = 'free' WHERE IDUsuario = :uid")
                ->execute([':uid' => $uid]);
            $pdo->commit();

            criarNotificacaoSistema($pdo, $uid,
                "Seu plano foi alterado para Free",
                "Um administrador encerrou seu plano pago. Você voltou para o plano gratuito com recursos limitados.\n\nSe tiver dúvidas, entre em contato com o suporte."
            );

            header("Location: usuarios.php?sucesso=revogado");
            exit;
        } catch (PDOException $e) {
            $pdo->rollBack();
            $erro = "Erro ao revogar acesso.";
        }
    }
}

if (isset($_GET['sucesso'])) {
    if ($_GET['sucesso'] === 'acesso_dado') $sucesso = "Acesso concedido com sucesso!";
    if ($_GET['sucesso'] === 'revogado')    $sucesso = "Plano revertido para Free.";
}

// ==============================================================================
// BUSCA DE USUÁRIOS COM ASSINATURA ATIVA
// ==============================================================================
$usuarios = [];
try {
    $usuarios = $pdo->query("
        SELECT
            u.IDUsuario, u.Nome, u.Email, u.Plano, u.StatusConta,
            u.NivelAcesso, u.MomentoCriacao,
            a.DataExpiracao, a.DataInicio, a.ValorPago, a.Ciclo, a.FontePagamento
        FROM Usuario u
        LEFT JOIN Assinatura a ON a.IDAssinatura = (
            SELECT IDAssinatura FROM Assinatura
            WHERE FKUsuario = u.IDUsuario AND Status = 'ativa'
            ORDER BY DataExpiracao DESC LIMIT 1
        )
        ORDER BY
            CASE u.Plano WHEN 'vip' THEN 1 WHEN 'pro' THEN 2 ELSE 3 END,
            u.MomentoCriacao DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $erro = "Erro ao buscar usuários.";
}

// Contadores por plano
$stats = ['total' => count($usuarios), 'free' => 0, 'pro' => 0, 'vip' => 0];
foreach ($usuarios as $u) {
    $p = strtolower($u['Plano'] ?? 'free');
    if (isset($stats[$p])) $stats[$p]++;
}

require_once '../geral/header.php';
?>

<main class="container-fluid py-4 mt-2 flex-grow-1" style="max-width: 1400px; padding-inline: var(--space-page-x); min-height: 100vh;">

    <!-- Cabeçalho -->
    <div class="d-flex justify-content-between align-items-center mb-4 border-bottom border-secondary-subtle pb-3 gap-3 flex-wrap">
        <a href="/dashboard.php" class="btn btn-outline-secondary btn-sm rounded-pill px-3 flex-shrink-0">
            <i class="bi bi-arrow-left me-1"></i> Voltar
        </a>
    </div>

    <!-- Tabs de navegação admin -->
    <ul class="nav nav-pills gap-2 mb-4">
        <li class="nav-item">
            <a href="/admin/usuarios.php" class="nav-link rounded-pill active"
                style="background:#7c3aed;color:#fff;font-size:0.85rem;">
                <i class="bi bi-people me-1"></i> Usuários
            </a>
        </li>
        <li class="nav-item">
            <a href="/admin/configuracoes_planos.php" class="nav-link rounded-pill"
                style="background:rgba(255,255,255,.05);color:#9ca3af;font-size:0.85rem;">
                <i class="bi bi-sliders me-1"></i> Configurações de Planos
            </a>
        </li>
        <li class="nav-item">
            <a href="/admin/codigos.php" class="nav-link rounded-pill"
                style="background:rgba(255,255,255,.05);color:#9ca3af;font-size:0.85rem;">
                <i class="bi bi-gift-fill me-1"></i> Códigos de Ativação
            </a>
        </li>
        <li class="nav-item">
            <a href="/admin/notificacoes.php" class="nav-link rounded-pill"
                style="background:rgba(255,255,255,.05);color:#9ca3af;font-size:0.85rem;">
                <i class="bi bi-bell-fill me-1"></i> Notificações
            </a>
        </li>
        <li class="nav-item">
            <a href="/admin/revendedores.php" class="nav-link rounded-pill"
                style="background:rgba(255,255,255,.05);color:#9ca3af;font-size:0.85rem;">
                <i class="bi bi-people-fill me-1"></i> Revendedores
            </a>
        </li>
        <li class="nav-item">
            <a href="/admin/indicacoes.php" class="nav-link rounded-pill"
                style="background:rgba(255,255,255,.05);color:#9ca3af;font-size:0.85rem;">
                <i class="bi bi-share-fill me-1"></i> Indicações
            </a>
        </li>
        <li class="nav-item">
            <a href="/admin/conquistas.php" class="nav-link rounded-pill"
                style="background:rgba(255,255,255,.05);color:#9ca3af;font-size:0.85rem;">
                <i class="bi bi-trophy-fill me-1"></i> Conquistas
            </a>
        </li>
    </ul>

    <!-- Alertas -->
    <?php if ($sucesso): ?>
        <script>window._pendingToast = <?= json_encode($sucesso) ?>;</script>
    <?php endif; ?>
    <?php if ($erro): ?>
        <div class="alert alert-danger d-flex align-items-center gap-2 rounded-3 border-0 bg-danger bg-opacity-10 text-danger fw-semibold mb-4">
            <i class="bi bi-exclamation-triangle-fill"></i> <?= htmlspecialchars($erro) ?>
        </div>
    <?php endif; ?>

    <!-- Cards de resumo -->
    <div class="row g-3 mb-4">
        <?php
        $cardStats = [
            ['label' => 'Total de usuários', 'valor' => $stats['total'], 'icon' => 'bi-people-fill',     'cor' => '#AA8C2C'],
            ['label' => 'Free',               'valor' => $stats['free'],  'icon' => 'bi-person',           'cor' => '#888888'],
            ['label' => 'PRO',                'valor' => $stats['pro'],   'icon' => 'fi fi-br-crown',      'cor' => '#a78bfa'],
            ['label' => 'VIP',                'valor' => $stats['vip'],   'icon' => 'fi fi-ss-gem',        'cor' => '#d4af37'],
        ];
        foreach ($cardStats as $cs): ?>
            <div class="col-6 col-md-3">
                <div class="card border-secondary-subtle rounded-4 p-3 text-center" style="background:#1c1f24;">
                    <div class="small mb-1 fw-semibold" style="color:<?= $cs['cor'] ?>; font-size:0.78rem;">
                        <i class="<?= $cs['icon'] ?> me-1"></i><?= $cs['label'] ?>
                    </div>
                    <div class="fw-bold fs-3" style="color:<?= $cs['cor'] ?>;"><?= $cs['valor'] ?></div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- Controles: busca + filtros -->
    <div class="mb-3">
        <!-- Linha 1: busca + contador + limpar -->
        <div class="d-flex align-items-center gap-3 mb-2 flex-wrap">
            <div class="position-relative flex-grow-1" style="max-width:340px;">
                <i class="bi bi-search position-absolute text-secondary" style="top:50%;left:12px;transform:translateY(-50%);font-size:0.8rem;pointer-events:none;"></i>
                <input type="text" id="buscaUsuario"
                    class="form-control bg-dark border-secondary-subtle text-light rounded-pill shadow-sm"
                    placeholder="Buscar por nome ou e-mail..."
                    style="padding-left:2.1rem; font-size:0.875rem;">
            </div>
            <span id="resultCount" class="text-secondary" style="font-size:0.75rem; white-space:nowrap;"></span>
            <button id="btnLimparFiltros" class="btn btn-sm rounded-pill px-3"
                style="display:none; font-size:0.73rem; background:rgba(230,57,70,0.1); color:#f87171; border:1px solid rgba(230,57,70,0.3);">
                <i class="bi bi-x-circle me-1"></i> Limpar filtros
            </button>
        </div>

        <!-- Linha 2: pills de filtro -->
        <div class="d-flex align-items-center gap-3 flex-wrap">

            <div class="d-flex align-items-center gap-2">
                <span style="font-size:0.68rem; color:#444; text-transform:uppercase; letter-spacing:0.07em; white-space:nowrap;">Plano</span>
                <div class="d-flex gap-1" id="filterPlano">
                    <button class="filter-pill active" data-value="">Todos</button>
                    <button class="filter-pill" data-value="free">Free</button>
                    <button class="filter-pill" data-value="pro" style="color:#a78bfa88;">
                        <i class="fi fi-br-crown" style="font-size:0.65rem; vertical-align:middle;"></i> PRO
                    </button>
                    <button class="filter-pill" data-value="vip" style="color:#d4af3788;">
                        <i class="fi fi-ss-gem" style="font-size:0.65rem; vertical-align:middle;"></i> VIP
                    </button>
                </div>
            </div>

            <div class="d-flex align-items-center gap-2">
                <span style="font-size:0.68rem; color:#444; text-transform:uppercase; letter-spacing:0.07em; white-space:nowrap;">Status</span>
                <div class="d-flex gap-1" id="filterStatus">
                    <button class="filter-pill active" data-value="">Todos</button>
                    <button class="filter-pill" data-value="ativo">Ativo</button>
                    <button class="filter-pill" data-value="inativo">Inativo</button>
                </div>
            </div>

            <div class="d-flex align-items-center gap-2">
                <span style="font-size:0.68rem; color:#444; text-transform:uppercase; letter-spacing:0.07em; white-space:nowrap;">Nível</span>
                <div class="d-flex gap-1" id="filterNivel">
                    <button class="filter-pill active" data-value="">Todos</button>
                    <button class="filter-pill" data-value="titular">Titular</button>
                    <button class="filter-pill" data-value="admin">Admin</button>
                    <button class="filter-pill" data-value="supremo">Supremo</button>
                </div>
            </div>

        </div>
    </div>

    <!-- Tabela -->
    <div class="card border-secondary-subtle shadow-sm rounded-4 overflow-hidden" style="background:#1c1f24;">
        <div class="table-responsive">
            <table class="table table-dark table-hover align-middle mb-0" id="tabelaUsuarios">
                <thead>
                    <tr style="background:#15171b; font-size:0.72rem; text-transform:uppercase; letter-spacing:0.06em; color:#555;">
                        <th class="py-3 ps-4 sortable" data-col="nome" style="font-weight:700; cursor:pointer; user-select:none; white-space:nowrap;">Usuário <span class="sort-icon" style="color:#333; font-size:0.65rem;">↕</span></th>
                        <th class="sortable" data-col="plano" style="font-weight:700; cursor:pointer; user-select:none; white-space:nowrap;">Plano <span class="sort-icon" style="color:#333; font-size:0.65rem;">↕</span></th>
                        <th class="sortable" data-col="expiracao" style="font-weight:700; cursor:pointer; user-select:none; white-space:nowrap;">Expira em <span class="sort-icon" style="color:#333; font-size:0.65rem;">↕</span></th>
                        <th style="font-weight:700;">Fonte</th>
                        <th class="sortable" data-col="status" style="font-weight:700; cursor:pointer; user-select:none; white-space:nowrap;">Status <span class="sort-icon" style="color:#333; font-size:0.65rem;">↕</span></th>
                        <th class="sortable" data-col="nivel" style="font-weight:700; cursor:pointer; user-select:none; white-space:nowrap;">Nível <span class="sort-icon" style="color:#333; font-size:0.65rem;">↕</span></th>
                        <th class="sortable" data-col="cadastro" style="font-weight:700; cursor:pointer; user-select:none; white-space:nowrap;">Cadastro <span class="sort-icon" style="color:#333; font-size:0.65rem;">↕</span></th>
                        <th class="pe-4 text-end" style="font-weight:700;">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($usuarios as $u):
                        $plano         = strtolower($u['Plano'] ?? 'free');
                        $nivel         = strtolower($u['NivelAcesso'] ?? 'titular');
                        $exp           = !empty($u['DataExpiracao']) ? new DateTime($u['DataExpiracao']) : null;
                        $hoje          = new DateTime();
                        $diasRestantes = $exp ? (int)$hoje->diff($exp)->format('%r%a') : null;
                        $criacao       = new DateTime($u['MomentoCriacao']);
                        $inicial       = mb_strtoupper(mb_substr($u['Nome'] ?? '?', 0, 1, 'UTF-8'), 'UTF-8');
                    ?>
                        <tr class="usuario-row"
                            data-nome="<?= htmlspecialchars(strtolower($u['Nome'] ?? '')) ?>"
                            data-email="<?= htmlspecialchars(strtolower($u['Email'] ?? '')) ?>"
                            data-plano="<?= $plano ?>"
                            data-nivel="<?= $nivel ?>"
                            data-status="<?= strtolower($u['StatusConta'] ?? 'inativo') ?>"
                            data-cadastro="<?= $criacao->format('Y-m-d') ?>"
                            data-expiracao="<?= $exp ? $exp->format('Y-m-d') : '' ?>">

                            <!-- Usuário -->
                            <td class="ps-4 py-3">
                                <div class="d-flex align-items-center gap-3">
                                    <div class="d-flex align-items-center justify-content-center rounded-circle fw-bold flex-shrink-0"
                                        style="width:36px;height:36px;background:rgba(170,140,44,0.15);color:#AA8C2C;font-size:0.88rem;">
                                        <?= $inicial ?>
                                    </div>
                                    <div style="min-width:0;">
                                        <div class="text-light fw-semibold text-truncate" style="font-size:0.88rem; max-width:180px;">
                                            <?= htmlspecialchars($u['Nome'] ?? '—') ?>
                                        </div>
                                        <div class="text-secondary text-truncate" style="font-size:0.73rem; max-width:180px;">
                                            <?= htmlspecialchars($u['Email'] ?? '') ?>
                                        </div>
                                    </div>
                                </div>
                            </td>

                            <!-- Plano -->
                            <td>
                                <?php if ($plano === 'vip'): ?>
                                    <span class="badge rounded-pill d-inline-flex align-items-center gap-1" style="background:rgba(212,175,55,0.15);color:#d4af37;border:1px solid rgba(212,175,55,0.4);font-size:0.7rem;padding:4px 9px;">
                                        <i class="fi fi-ss-gem d-flex align-items-center" style="font-size:0.8rem;margin-top:1px;"></i> VIP
                                    </span>
                                <?php elseif ($plano === 'pro'): ?>
                                    <span class="badge rounded-pill d-inline-flex align-items-center gap-1" style="background:rgba(124,58,237,0.15);color:#a78bfa;border:1px solid rgba(124,58,237,0.4);font-size:0.7rem;padding:4px 9px;">
                                        <i class="fi fi-br-crown d-flex align-items-center" style="font-size:0.8rem;margin-top:1px;"></i> PRO
                                    </span>
                                <?php else: ?>
                                    <span class="text-secondary" style="font-size:0.8rem;">Free</span>
                                <?php endif; ?>
                            </td>

                            <!-- Expiração -->
                            <td style="font-size:0.8rem;">
                                <?php if ($exp && $plano !== 'free'): ?>
                                    <?php if ($diasRestantes !== null && $diasRestantes >= 0): ?>
                                        <div class="<?= $diasRestantes <= 7 ? 'text-warning' : 'text-secondary' ?>">
                                            <?= $exp->format('d/m/Y') ?>
                                            <div style="font-size:0.7rem; opacity:0.7;"><?= $diasRestantes ?>d restantes</div>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-danger" style="font-size:0.78rem;">Expirado</span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="text-secondary opacity-40">—</span>
                                <?php endif; ?>
                            </td>

                            <!-- Fonte -->
                            <td style="font-size:0.73rem; color:#666;">
                                <?php
                                $fonte = $u['FontePagamento'] ?? '';
                                $fonteLabel = match ($fonte) {
                                    'mercadopago'  => 'MercadoPago',
                                    'manual_admin' => '<span style="color:#AA8C2C;">Manual</span>',
                                    ''             => '—',
                                    default        => htmlspecialchars($fonte),
                                };
                                echo $fonteLabel;
                                ?>
                            </td>

                            <!-- Status conta -->
                            <td>
                                <?php if (($u['StatusConta'] ?? '') === 'ativo'): ?>
                                    <span class="badge rounded-pill" style="background:rgba(6,214,160,0.12);color:#6ee7c7;border:1px solid rgba(6,214,160,0.3);font-size:0.68rem;padding:3px 8px;">ativo</span>
                                <?php else: ?>
                                    <span class="badge rounded-pill" style="background:rgba(230,57,70,0.12);color:#f87171;border:1px solid rgba(230,57,70,0.3);font-size:0.68rem;padding:3px 8px;"><?= htmlspecialchars($u['StatusConta'] ?? '?') ?></span>
                                <?php endif; ?>
                            </td>

                            <!-- Nível de acesso -->
                            <td style="font-size:0.78rem;">
                                <?php
                                $nivelCor = match ($nivel) {
                                    'supremo' => '#E63946',
                                    'admin'   => '#f87171',
                                    default   => '#666',
                                };
                                ?>
                                <span style="color:<?= $nivelCor ?>;"><?= ucfirst($nivel) ?></span>
                            </td>

                            <!-- Data de cadastro -->
                            <td style="font-size:0.75rem; color:#666;">
                                <?= $criacao->format('d/m/Y') ?>
                            </td>

                            <!-- Ações -->
                            <td class="pe-4">
                                <div class="d-flex gap-2 justify-content-end align-items-center">
                                    <button type="button"
                                        class="btn btn-sm rounded-pill px-3 fw-semibold btn-dar-acesso"
                                        style="background:rgba(212,175,55,0.12);color:#d4af37;border:1px solid rgba(212,175,55,0.35);font-size:0.73rem;white-space:nowrap;"
                                        data-bs-toggle="modal" data-bs-target="#modalDarAcesso"
                                        data-id="<?= htmlspecialchars($u['IDUsuario']) ?>"
                                        data-nome="<?= htmlspecialchars($u['Nome'] ?? '') ?>"
                                        data-email="<?= htmlspecialchars($u['Email'] ?? '') ?>"
                                        data-plano="<?= $plano ?>">
                                        <i class="bi bi-plus-circle me-1"></i> Dar acesso
                                    </button>
                                    <?php if ($plano !== 'free'): ?>
                                        <button type="button"
                                            class="btn btn-sm rounded-pill btn-revogar d-flex align-items-center justify-content-center"
                                            style="width:30px;height:30px;padding:0;background:rgba(230,57,70,0.1);color:#f87171;border:1px solid rgba(230,57,70,0.3);"
                                            title="Revogar plano"
                                            data-bs-toggle="modal" data-bs-target="#modalRevogar"
                                            data-id="<?= htmlspecialchars($u['IDUsuario']) ?>"
                                            data-nome="<?= htmlspecialchars($u['Nome'] ?? '') ?>"
                                            data-plano="<?= $plano ?>">
                                            <i class="bi bi-x-lg" style="font-size:0.7rem;"></i>
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </td>

                        </tr>
                    <?php endforeach; ?>

                    <?php if (empty($usuarios)): ?>
                        <tr>
                            <td colspan="8" class="text-center text-secondary py-5">Nenhum usuário encontrado.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</main>

<!-- ═══════════════════════════════════════════════════════════════════════════
     MODAL: DAR ACESSO
     ═══════════════════════════════════════════════════════════════════════ -->
<div class="modal fade" id="modalDarAcesso" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" style="max-width: 430px;">
        <div class="modal-content border-secondary-subtle rounded-4" style="background:#1a1d21;">
            <div class="modal-header border-bottom border-secondary-subtle px-4">
                <h5 class="modal-title text-light fw-bold d-flex align-items-center gap-2">
                    <i class="bi bi-star-fill" style="color:#d4af37;"></i> Conceder Acesso
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="">
                <div class="modal-body p-4">
                    <input type="hidden" name="action" value="dar_acesso">
                    <input type="hidden" name="usuario_id" id="dar_usuario_id">

                    <!-- Info do usuário alvo -->
                    <div class="rounded-3 p-3 mb-4" style="background:rgba(255,255,255,0.04);">
                        <div class="text-light fw-semibold" id="dar_nome" style="font-size:0.92rem;"></div>
                        <div class="text-secondary" id="dar_email" style="font-size:0.78rem;"></div>
                    </div>

                    <!-- Seleção de plano -->
                    <div class="mb-4">
                        <label class="form-label text-secondary small mb-2 d-block">Plano</label>
                        <div class="d-flex gap-3">
                            <div class="flex-grow-1">
                                <input type="radio" class="btn-check" name="plano" id="plano_pro" value="pro" required>
                                <label class="btn-plano w-100 fw-bold rounded-3 py-2 d-flex align-items-center justify-content-center gap-2" for="plano_pro"
                                    style="background:rgba(124,58,237,0.1);color:#a78bfa;border:1px solid rgba(124,58,237,0.4);cursor:pointer;transition:all .18s ease;">
                                    <i class="fi fi-br-crown d-flex align-items-center" style="font-size:1.05rem;margin-top:1px;"></i> PRO
                                </label>
                            </div>
                            <div class="flex-grow-1">
                                <input type="radio" class="btn-check" name="plano" id="plano_vip" value="vip" required>
                                <label class="btn-plano w-100 fw-bold rounded-3 py-2 d-flex align-items-center justify-content-center gap-2" for="plano_vip"
                                    style="background:rgba(212,175,55,0.1);color:#d4af37;border:1px solid rgba(212,175,55,0.4);cursor:pointer;transition:all .18s ease;">
                                    <i class="fi fi-ss-gem d-flex align-items-center" style="font-size:1.05rem;margin-top:1px;"></i> VIP
                                </label>
                            </div>
                        </div>
                    </div>

                    <!-- Duração -->
                    <div class="mb-4">
                        <label class="form-label text-secondary small mb-2 d-block">Duração</label>
                        <div class="d-flex gap-2 mb-2">
                            <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill px-3" onclick="setDias(30)">30d</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill px-3" onclick="setDias(60)">60d</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill px-3" onclick="setDias(180)">6m</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill px-3" onclick="setDias(365)">1 ano</button>
                        </div>
                        <div class="input-group">
                            <input type="number" name="dias" id="campoDias"
                                class="form-control bg-dark border-secondary-subtle text-light rounded-start-3"
                                value="30" min="1" max="3650" required>
                            <span class="input-group-text bg-dark border-secondary-subtle text-secondary">dias</span>
                        </div>
                    </div>

                    <!-- Valor cobrado (opcional) -->
                    <div class="mb-1">
                        <label class="form-label text-secondary small mb-2 d-block">
                            Valor cobrado <span class="opacity-50">(R$) — opcional, só para registro</span>
                        </label>
                        <div class="input-group">
                            <span class="input-group-text bg-dark border-secondary-subtle text-secondary">R$</span>
                            <input type="text" name="valor_pago" inputmode="numeric"
                                class="form-control bg-dark border-secondary-subtle text-light"
                                placeholder="R$ 0,00" autocomplete="off"
                                oninput="mascaraMoeda(this)">
                        </div>
                    </div>

                </div>
                <div class="modal-footer border-top border-secondary-subtle px-4 d-flex justify-content-between">
                    <button type="button" class="btn btn-link text-secondary text-decoration-none" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn fw-bold px-4 rounded-pill"
                        style="background:linear-gradient(135deg,#FFB800,#D4AF37);color:#000;">
                        <i class="bi bi-check-lg me-1"></i> Confirmar
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════
     MODAL: REVOGAR ACESSO
     ═══════════════════════════════════════════════════════════════════════ -->
<div class="modal fade" id="modalRevogar" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" style="max-width: 380px;">
        <div class="modal-content border-secondary-subtle rounded-4" style="background:#1a1d21;">
            <div class="modal-header border-bottom border-secondary-subtle px-4">
                <h5 class="modal-title text-light fw-bold d-flex align-items-center gap-2">
                    <i class="bi bi-x-circle text-danger"></i> Revogar Acesso
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="">
                <div class="modal-body p-4 text-center">
                    <input type="hidden" name="action" value="revogar">
                    <input type="hidden" name="usuario_id" id="revogar_usuario_id">
                    <p class="text-secondary mb-1">
                        Rebaixar <strong class="text-light" id="revogar_nome"></strong> para o plano <strong class="text-secondary">Free</strong>?
                    </p>
                    <p class="text-secondary small mb-0">A assinatura ativa será cancelada imediatamente.</p>
                </div>
                <div class="modal-footer border-top border-secondary-subtle px-4 d-flex justify-content-between">
                    <button type="button" class="btn btn-link text-secondary text-decoration-none" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-danger fw-bold px-4 rounded-pill">
                        Confirmar
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
    #tabelaUsuarios tbody tr:hover td {
        background-color: rgba(255, 255, 255, 0.025) !important;
    }

    .opacity-40 {
        opacity: 0.4;
    }

    /* Botões de seleção de plano */
    #plano_pro:checked+.btn-plano {
        background: rgba(124, 58, 237, 0.28) !important;
        border-color: #a78bfa !important;
        box-shadow: 0 0 0 2px rgba(124, 58, 237, 0.35);
        transform: scale(1.04);
    }

    #plano_vip:checked+.btn-plano {
        background: rgba(212, 175, 55, 0.28) !important;
        border-color: #d4af37 !important;
        box-shadow: 0 0 0 2px rgba(212, 175, 55, 0.35);
        transform: scale(1.04);
    }

    /* Filter pills */
    .filter-pill {
        font-size: 0.7rem;
        font-weight: 600;
        padding: 3px 10px;
        border-radius: 999px;
        border: 1px solid rgba(255, 255, 255, 0.1);
        background: transparent;
        color: #555;
        cursor: pointer;
        transition: all .15s ease;
        white-space: nowrap;
        line-height: 1.6;
    }

    .filter-pill:hover {
        border-color: rgba(255, 255, 255, 0.2);
        color: #888;
        background: rgba(255, 255, 255, 0.04);
    }

    .filter-pill.active {
        background: rgba(170, 140, 44, 0.15);
        border-color: rgba(170, 140, 44, 0.45);
        color: #AA8C2C;
    }

    /* Sortable header hover */
    .sortable:hover {
        color: #888 !important;
    }

    .sortable:hover .sort-icon {
        color: #888 !important;
    }

    .sortable.sorted .sort-icon {
        color: #AA8C2C !important;
    }
</style>

<script>
    // ── Modais ───────────────────────────────────────────────────────────────
    document.getElementById('modalDarAcesso').addEventListener('show.bs.modal', function(e) {
        const btn = e.relatedTarget;
        document.getElementById('dar_usuario_id').value = btn.dataset.id;
        document.getElementById('dar_nome').textContent = btn.dataset.nome;
        document.getElementById('dar_email').textContent = btn.dataset.email;
        document.getElementById(btn.dataset.plano === 'vip' ? 'plano_vip' : 'plano_pro').checked = true;
        document.getElementById('campoDias').value = 30;
    });

    document.getElementById('modalRevogar').addEventListener('show.bs.modal', function(e) {
        const btn = e.relatedTarget;
        document.getElementById('revogar_usuario_id').value = btn.dataset.id;
        document.getElementById('revogar_nome').textContent = btn.dataset.nome;
    });

    function setDias(n) {
        document.getElementById('campoDias').value = n;
    }

    // ── Estado de filtros + ordenação ────────────────────────────────────────
    const filtros = {
        q: '',
        plano: '',
        status: '',
        nivel: ''
    };
    let sortCol = '',
        sortDir = 1;

    function aplicar() {
        const rows = [...document.querySelectorAll('.usuario-row')];

        // Filtragem
        rows.forEach(row => {
            const ok =
                (!filtros.q || row.dataset.nome.includes(filtros.q) || row.dataset.email.includes(filtros.q)) &&
                (!filtros.plano || row.dataset.plano === filtros.plano) &&
                (!filtros.status || row.dataset.status === filtros.status) &&
                (!filtros.nivel || row.dataset.nivel === filtros.nivel);
            row.style.display = ok ? '' : 'none';
        });

        // Ordenação (somente sobre linhas visíveis)
        if (sortCol) {
            const tbody = document.querySelector('#tabelaUsuarios tbody');
            const visiveis = rows.filter(r => r.style.display !== 'none');
            visiveis.sort((a, b) => {
                let va = a.dataset[sortCol] || '';
                let vb = b.dataset[sortCol] || '';
                // datas: vazio vai para o fim
                if (sortCol === 'expiracao') {
                    if (!va && !vb) return 0;
                    if (!va) return 1;
                    if (!vb) return -1;
                }
                return va.localeCompare(vb, 'pt', {
                    sensitivity: 'base'
                }) * sortDir;
            });
            visiveis.forEach(r => tbody.appendChild(r));
        }

        // Contador
        const n = rows.filter(r => r.style.display !== 'none').length;
        document.getElementById('resultCount').textContent = n + ' de ' + rows.length + ' usuários';

        // Botão limpar
        const temFiltro = filtros.q || filtros.plano || filtros.status || filtros.nivel || sortCol;
        document.getElementById('btnLimparFiltros').style.display = temFiltro ? '' : 'none';

        // Ícones de ordenação
        document.querySelectorAll('.sortable').forEach(th => {
            const ico = th.querySelector('.sort-icon');
            if (th.dataset.col === sortCol) {
                ico.textContent = sortDir === 1 ? '↑' : '↓';
                th.classList.add('sorted');
            } else {
                ico.textContent = '↕';
                th.classList.remove('sorted');
            }
        });
    }

    // ── Busca ────────────────────────────────────────────────────────────────
    document.getElementById('buscaUsuario').addEventListener('input', function() {
        filtros.q = this.value.toLowerCase().trim();
        aplicar();
    });

    // ── Pills de filtro ──────────────────────────────────────────────────────
    [
        ['filterPlano', 'plano'],
        ['filterStatus', 'status'],
        ['filterNivel', 'nivel']
    ].forEach(([id, chave]) => {
        document.getElementById(id).querySelectorAll('.filter-pill').forEach(btn => {
            btn.addEventListener('click', function() {
                document.getElementById(id).querySelectorAll('.filter-pill').forEach(b => b.classList.remove('active'));
                this.classList.add('active');
                filtros[chave] = this.dataset.value;
                aplicar();
            });
        });
    });

    // ── Colunas clicáveis para ordenar ───────────────────────────────────────
    document.querySelectorAll('.sortable').forEach(th => {
        th.addEventListener('click', function() {
            const col = this.dataset.col;
            sortDir = (sortCol === col) ? sortDir * -1 : 1;
            sortCol = col;
            aplicar();
        });
    });

    // ── Limpar tudo ──────────────────────────────────────────────────────────
    document.getElementById('btnLimparFiltros').addEventListener('click', function() {
        filtros.q = filtros.plano = filtros.status = filtros.nivel = '';
        sortCol = '';
        sortDir = 1;
        document.getElementById('buscaUsuario').value = '';
        document.querySelectorAll('.filter-pill').forEach(b => b.classList.toggle('active', b.dataset.value === ''));
        aplicar();
    });

    // Inicializa contagem ao carregar
    aplicar();
</script>

<?php require_once '../geral/footer.php'; ?>
