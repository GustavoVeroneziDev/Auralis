<?php
// ==============================================================================
// ADMIN/NOTIFICACOES.PHP — Gestão de notificações para usuários
// ==============================================================================
session_start();
if (!isset($_SESSION['usuario_id'])) { header("Location: /usuario/login.php"); exit; }
require_once '../config/conexao.php';
require_once '../config/funcoes.php';

$nivelSessao = strtolower($_SESSION['nivel_acesso'] ?? '');
if (!in_array($nivelSessao, ['admin', 'supremo'])) {
    header("Location: /dashboard.php?erro=sem_permissao"); exit;
}

$adminId = $_SESSION['usuario_id'];
$sucesso = $erro = null;
$isAjax  = !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
           && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

// ==============================================================================
// POST ACTIONS
// ==============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ── Criar notificação ──────────────────────────────────
    if ($action === 'criar') {
        $titulo    = trim($_POST['titulo'] ?? '');
        $conteudo  = trim($_POST['conteudo'] ?? '');
        $destTipo  = in_array($_POST['destinatario_tipo'] ?? '', ['todos','free','pro','vip','selecionado'])
                     ? $_POST['destinatario_tipo'] : 'todos';
        $tipoInter = in_array($_POST['tipo_interacao'] ?? '', ['nenhuma','pesquisa'])
                     ? $_POST['tipo_interacao'] : 'nenhuma';
        $expiracao = !empty($_POST['data_expiracao']) ? $_POST['data_expiracao'] : null;
        $itensPesq = null;

        // Survey items
        if ($tipoInter === 'pesquisa' && !empty($_POST['pesquisa_json'])) {
            $decoded = json_decode($_POST['pesquisa_json'], true);
            if (is_array($decoded) && !empty($decoded)) $itensPesq = json_encode($decoded);
        }

        if (empty($titulo) || empty($conteudo)) {
            $erro = "Título e conteúdo são obrigatórios.";
        } else {
            try {
                $nid = gerarUuid();
                $pdo->prepare("
                    INSERT INTO Notificacao
                        (IDNotificacao, Titulo, Conteudo, DestinatarioTipo, TipoInteracao, ItensPesquisa, DataExpiracao)
                    VALUES (:id, :titulo, :conteudo, :dest, :tipo, :itens, :exp)
                ")->execute([
                    ':id'      => $nid,
                    ':titulo'  => $titulo,
                    ':conteudo'=> $conteudo,
                    ':dest'    => $destTipo,
                    ':tipo'    => $tipoInter,
                    ':itens'   => $itensPesq,
                    ':exp'     => $expiracao,
                ]);

                // Specific users
                if ($destTipo === 'selecionado' && !empty($_POST['usuarios_ids'])) {
                    $insU = $pdo->prepare("INSERT IGNORE INTO NotificacaoDestinatario (FKNotificacao, FKUsuario) VALUES (:nid, :uid)");
                    foreach ((array)$_POST['usuarios_ids'] as $uid) {
                        $insU->execute([':nid' => $nid, ':uid' => $uid]);
                    }
                }
                $sucesso = "Notificação criada com sucesso!";
            } catch (PDOException $e) {
                $erro = "Erro ao criar notificação.";
            }
        }
        if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['ok' => !$erro, 'msg' => $erro ?? $sucesso]); exit; }

    // ── Editar notificação ─────────────────────────────────
    } elseif ($action === 'editar') {
        $nid       = trim($_POST['notificacao_id'] ?? '');
        $titulo    = trim($_POST['titulo'] ?? '');
        $conteudo  = trim($_POST['conteudo'] ?? '');
        $destTipo  = in_array($_POST['destinatario_tipo'] ?? '', ['todos','free','pro','vip','selecionado'])
                     ? $_POST['destinatario_tipo'] : 'todos';
        $tipoInter = in_array($_POST['tipo_interacao'] ?? '', ['nenhuma','pesquisa'])
                     ? $_POST['tipo_interacao'] : 'nenhuma';
        $expiracao = !empty($_POST['data_expiracao']) ? $_POST['data_expiracao'] : null;
        $itensPesq = null;
        $ativo     = isset($_POST['ativo']) ? 1 : 0;

        if ($tipoInter === 'pesquisa' && !empty($_POST['pesquisa_json'])) {
            $decoded = json_decode($_POST['pesquisa_json'], true);
            if (is_array($decoded) && !empty($decoded)) $itensPesq = json_encode($decoded);
        }

        if (empty($nid) || empty($titulo) || empty($conteudo)) {
            $erro = "Dados inválidos.";
        } else {
            try {
                $pdo->prepare("
                    UPDATE Notificacao SET Titulo=:titulo, Conteudo=:conteudo,
                        DestinatarioTipo=:dest, TipoInteracao=:tipo,
                        ItensPesquisa=:itens, DataExpiracao=:exp, Ativo=:ativo
                    WHERE IDNotificacao=:id
                ")->execute([
                    ':titulo'  => $titulo, ':conteudo' => $conteudo,
                    ':dest'    => $destTipo, ':tipo'   => $tipoInter,
                    ':itens'   => $itensPesq, ':exp'   => $expiracao,
                    ':ativo'   => $ativo, ':id'         => $nid,
                ]);
                // Refresh specific users
                if ($destTipo === 'selecionado') {
                    $pdo->prepare("DELETE FROM NotificacaoDestinatario WHERE FKNotificacao = :nid")->execute([':nid' => $nid]);
                    if (!empty($_POST['usuarios_ids'])) {
                        $insU = $pdo->prepare("INSERT IGNORE INTO NotificacaoDestinatario (FKNotificacao, FKUsuario) VALUES (:nid, :uid)");
                        foreach ((array)$_POST['usuarios_ids'] as $uid) {
                            $insU->execute([':nid' => $nid, ':uid' => $uid]);
                        }
                    }
                }
                $sucesso = "Notificação atualizada!";
            } catch (PDOException $e) {
                $erro = "Erro ao editar notificação.";
            }
        }
        if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['ok' => !$erro, 'msg' => $erro ?? $sucesso]); exit; }

    // ── Excluir notificação ────────────────────────────────
    } elseif ($action === 'excluir') {
        $nid = trim($_POST['notificacao_id'] ?? '');
        if (empty($nid)) { $erro = "ID inválido."; }
        else {
            try {
                $pdo->prepare("UPDATE Notificacao SET Ativo = 0 WHERE IDNotificacao = :id")->execute([':id' => $nid]);
                $sucesso = "Notificação removida.";
            } catch (PDOException $e) { $erro = "Erro ao excluir."; }
        }
        if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['ok' => !$erro, 'msg' => $erro ?? $sucesso]); exit; }
    }

    if (!$isAjax) {
        if ($sucesso) { header("Location: notificacoes.php?sucesso=1&msg=" . urlencode($sucesso)); exit; }
        if ($erro)    { header("Location: notificacoes.php?erro=1&msg=" . urlencode($erro)); exit; }
    }
}

if (isset($_GET['sucesso'])) $sucesso = htmlspecialchars(urldecode($_GET['msg'] ?? 'Operação realizada.'));
if (isset($_GET['erro']))    $erro    = htmlspecialchars(urldecode($_GET['msg'] ?? 'Ocorreu um erro.'));

// ==============================================================================
// BUSCA DE DADOS
// ==============================================================================
$notificacoes = [];
try {
    $notificacoes = $pdo->query("
        SELECT n.*,
               (SELECT COUNT(*) FROM NotificacaoLeitura nl WHERE nl.FKNotificacao = n.IDNotificacao) AS TotalLidas,
               (SELECT COUNT(*) FROM NotificacaoResposta nr WHERE nr.FKNotificacao = n.IDNotificacao) AS TotalRespostas
        FROM Notificacao n
        ORDER BY n.Ativo DESC, n.DataCriacao DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { $erro = "Erro ao buscar notificações."; }

// Todos os usuários (para o seletor)
$todosUsuarios = [];
try {
    $todosUsuarios = $pdo->query("
        SELECT IDUsuario, Nome, Email, Plano FROM Usuario ORDER BY Nome ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}

// Destinatários já salvos por notificação (para edição)
$destinatariosPorNotif = [];
try {
    $rows = $pdo->query("SELECT FKNotificacao, FKUsuario FROM NotificacaoDestinatario")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) $destinatariosPorNotif[$r['FKNotificacao']][] = $r['FKUsuario'];
} catch (PDOException $e) {}

require_once '../geral/header.php';
?>

<main class="container-fluid py-4 mt-2 flex-grow-1"
      style="max-width:1400px;padding-inline:var(--space-page-x);min-height:100vh;">

    <!-- Cabeçalho -->
    <div class="d-flex justify-content-between align-items-center mb-4 border-bottom border-secondary-subtle pb-3 gap-3 flex-wrap">
        <a href="/dashboard.php" class="btn btn-outline-secondary btn-sm rounded-pill px-3 flex-shrink-0">
            <i class="bi bi-arrow-left me-1"></i> Voltar
        </a>
        <button type="button" class="btn btn-sm rounded-pill px-3 fw-semibold ms-auto"
                style="background:var(--accent);color:#000;"
                data-bs-toggle="modal" data-bs-target="#modalNotif" data-mode="criar">
            <i class="bi bi-plus-lg me-1"></i> Nova Notificação
        </button>
    </div>

    <!-- Admin tabs -->
    <ul class="nav nav-pills gap-2 mb-4">
        <li class="nav-item">
            <a href="/admin/usuarios.php" class="nav-link rounded-pill"
               style="background:rgba(255,255,255,.05);color:#9ca3af;font-size:0.85rem;">
               <i class="bi bi-people me-1"></i> Usuários
            </a>
        </li>
        <li class="nav-item">
            <a href="/admin/configuracoes_planos.php" class="nav-link rounded-pill"
               style="background:rgba(255,255,255,.05);color:#9ca3af;font-size:0.85rem;">
               <i class="bi bi-sliders me-1"></i> Config. Planos
            </a>
        </li>
        <li class="nav-item">
            <a href="/admin/codigos.php" class="nav-link rounded-pill"
               style="background:rgba(255,255,255,.05);color:#9ca3af;font-size:0.85rem;">
               <i class="bi bi-gift-fill me-1"></i> Códigos
            </a>
        </li>
        <li class="nav-item">
            <a href="/admin/notificacoes.php" class="nav-link rounded-pill active"
               style="background:#7c3aed;color:#fff;font-size:0.85rem;">
               <i class="bi bi-bell-fill me-1"></i> Notificações
            </a>
        </li>
    </ul>

    <!-- Alerts -->
    <?php if ($sucesso): ?><script>window._pendingToast = <?= json_encode($sucesso) ?>;</script><?php endif; ?>
    <?php if ($erro): ?>
    <div class="alert alert-danger d-flex align-items-center gap-2 rounded-3 border-0 bg-danger bg-opacity-10 text-danger fw-semibold mb-4">
        <i class="bi bi-exclamation-triangle-fill"></i> <?= $erro ?>
    </div>
    <?php endif; ?>

    <!-- Stats -->
    <?php $ativas = array_filter($notificacoes, fn($n) => $n['Ativo']); ?>
    <div class="row g-3 mb-4">
        <?php foreach ([
            ['Total',   count($notificacoes), 'bi-bell',         '#7c3aed'],
            ['Ativas',  count($ativas),        'bi-bell-fill',    '#22c55e'],
            ['Inativas',count($notificacoes)-count($ativas), 'bi-bell-slash', '#6b7280'],
        ] as [$label, $val, $icon, $cor]): ?>
        <div class="col-6 col-md-4 col-lg-2">
            <div class="rounded-4 p-3 text-center shadow-sm" style="background:var(--bg-card);border:1px solid var(--card-border-color);">
                <i class="bi <?= $icon ?> fs-4 mb-1 d-block" style="color:<?= $cor ?>;"></i>
                <div class="fw-bold fs-5" style="color:var(--text-main);"><?= $val ?></div>
                <div style="font-size:0.75rem;color:var(--text-muted);"><?= $label ?></div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Tabela -->
    <div class="rounded-4 shadow-sm overflow-hidden" style="border:1px solid var(--card-border-color);">
    <?php if (empty($notificacoes)): ?>
        <div class="p-5 text-center text-secondary">
            <i class="bi bi-bell-slash fs-1 d-block mb-3 opacity-50"></i>
            Nenhuma notificação criada ainda.
        </div>
    <?php else: ?>
        <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 auralis-table">
            <thead class="small text-uppercase">
                <tr>
                    <th class="ps-4 py-3">Notificação</th>
                    <th class="py-3">Destinatários</th>
                    <th class="py-3 text-center">Tipo</th>
                    <th class="py-3 text-center">Lidas</th>
                    <th class="py-3 text-center">Status</th>
                    <th class="py-3 text-center">Criada em</th>
                    <th class="py-3 pe-4 text-end">Ações</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($notificacoes as $n):
                $destLabel = match($n['DestinatarioTipo']) {
                    'todos'       => '<span class="badge rounded-pill" style="background:#1d4ed8;color:#fff;font-size:0.7rem;">Todos</span>',
                    'free'        => '<span class="badge rounded-pill" style="background:#374151;color:#9ca3af;font-size:0.7rem;">Free</span>',
                    'pro'         => '<span class="badge rounded-pill" style="background:#7c3aed20;color:#a78bfa;border:1px solid #7c3aed40;font-size:0.7rem;">PRO</span>',
                    'vip'         => '<span class="badge rounded-pill" style="background:#d4af3720;color:#d4af37;border:1px solid #d4af3740;font-size:0.7rem;">VIP</span>',
                    'selecionado' => '<span class="badge rounded-pill" style="background:#0891b220;color:#38bdf8;border:1px solid #0891b240;font-size:0.7rem;"><i class="bi bi-person-check me-1"></i>Selecionados</span>',
                    default       => ''
                };
            ?>
            <tr style="<?= !$n['Ativo'] ? 'opacity:0.5;' : '' ?>">
                <td class="ps-4 py-3">
                    <div class="fw-semibold" style="color:var(--text-main);max-width:300px;">
                        <?= htmlspecialchars($n['Titulo']) ?>
                    </div>
                    <?php if ($n['TipoInteracao'] === 'pesquisa'): ?>
                    <small style="color:var(--accent);font-size:0.7rem;"><i class="bi bi-bar-chart-fill me-1"></i>Com pesquisa</small>
                    <?php endif; ?>
                </td>
                <td class="py-3"><?= $destLabel ?></td>
                <td class="py-3 text-center">
                    <?php if ($n['TipoInteracao'] === 'pesquisa'): ?>
                    <i class="bi bi-bar-chart-fill" title="Pesquisa" style="color:var(--accent);"></i>
                    <?php else: ?>
                    <i class="bi bi-chat-text" title="Informativo" style="color:var(--text-muted);"></i>
                    <?php endif; ?>
                </td>
                <td class="py-3 text-center">
                    <span style="color:var(--text-main);font-weight:600;"><?= (int)$n['TotalLidas'] ?></span>
                    <?php if ($n['TipoInteracao'] === 'pesquisa' && $n['TotalRespostas'] > 0): ?>
                    <br><small style="color:#22c55e;font-size:0.7rem;"><i class="bi bi-check2"></i> <?= $n['TotalRespostas'] ?> resp.</small>
                    <?php endif; ?>
                </td>
                <td class="py-3 text-center">
                    <?php if ($n['Ativo']): ?>
                    <span class="badge rounded-pill" style="background:#16a34a20;color:#4ade80;border:1px solid #16a34a30;font-size:0.7rem;">Ativa</span>
                    <?php else: ?>
                    <span class="badge rounded-pill" style="background:#37415120;color:#6b7280;border:1px solid #37415130;font-size:0.7rem;">Inativa</span>
                    <?php endif; ?>
                </td>
                <td class="py-3 text-center" style="font-size:0.82rem;color:var(--text-muted);">
                    <?= date('d/m/Y', strtotime($n['DataCriacao'])) ?>
                    <?php if ($n['DataExpiracao']): ?>
                    <br><small style="color:#f59e0b;font-size:0.7rem;"><i class="bi bi-clock me-1"></i><?= date('d/m/Y', strtotime($n['DataExpiracao'])) ?></small>
                    <?php endif; ?>
                </td>
                <td class="py-3 pe-4 text-end">
                    <button type="button"
                            class="btn btn-sm btn-outline-secondary rounded-pill me-1 btn-editar-notif"
                            data-notif='<?= htmlspecialchars(json_encode([
                                'id'               => $n['IDNotificacao'],
                                'titulo'           => $n['Titulo'],
                                'conteudo'         => $n['Conteudo'],
                                'destinatario_tipo'=> $n['DestinatarioTipo'],
                                'tipo_interacao'   => $n['TipoInteracao'],
                                'itens_pesquisa'   => $n['ItensPesquisa'] ?? '[]',
                                'data_expiracao'   => $n['DataExpiracao'] ?? '',
                                'ativo'            => $n['Ativo'],
                                'destinatarios'    => $destinatariosPorNotif[$n['IDNotificacao']] ?? [],
                            ]), ENT_QUOTES) ?>'
                            style="font-size:0.8rem;">
                        <i class="bi bi-pencil"></i>
                    </button>
                    <form method="POST" class="d-inline form-excluir-notif">
                        <input type="hidden" name="action" value="excluir">
                        <input type="hidden" name="notificacao_id" value="<?= htmlspecialchars($n['IDNotificacao']) ?>">
                        <button type="submit" class="btn btn-sm btn-outline-danger rounded-pill"
                                style="font-size:0.8rem;" title="Excluir">
                            <i class="bi bi-trash"></i>
                        </button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>
    </div>
</main>

<!-- ══════════════════════════════════════════════════════════
     MODAL: CRIAR / EDITAR NOTIFICAÇÃO
     ══════════════════════════════════════════════════════════ -->
<div class="modal fade" id="modalNotif" tabindex="-1" aria-hidden="true">
<div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
<div class="modal-content rounded-4 border-0 shadow-lg" style="background:var(--bg-card);">
<form id="formNotif" method="POST">
    <input type="hidden" name="action" id="formAction" value="criar">
    <input type="hidden" name="notificacao_id" id="formNotifId" value="">
    <input type="hidden" name="pesquisa_json" id="pesquisaJson" value="[]">

    <div class="modal-header border-bottom border-secondary-subtle p-4">
        <h5 class="modal-title fw-bold" id="modalNotifTitle" style="color:var(--text-main);">
            <i class="bi bi-bell-fill me-2" style="color:var(--accent);"></i>Nova Notificação
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
    </div>

    <div class="modal-body p-4">
        <div class="row g-3">

            <!-- Título -->
            <div class="col-12">
                <label class="form-label fw-semibold" style="color:var(--text-muted);font-size:0.8rem;text-transform:uppercase;">Título *</label>
                <input type="text" class="form-control rounded-3" name="titulo" id="fTitulo"
                       placeholder="Ex: Nova funcionalidade disponível!" required
                       style="background:var(--bg-hover);border-color:var(--card-border-color);color:var(--text-main);">
            </div>

            <!-- Conteúdo -->
            <div class="col-12">
                <label class="form-label fw-semibold" style="color:var(--text-muted);font-size:0.8rem;text-transform:uppercase;">Conteúdo *</label>
                <textarea class="form-control rounded-3" name="conteudo" id="fConteudo" rows="4" required
                          placeholder="Descreva a notificação em detalhes..."
                          style="background:var(--bg-hover);border-color:var(--card-border-color);color:var(--text-main);resize:vertical;"></textarea>
            </div>

            <!-- Destinatários -->
            <div class="col-md-6">
                <label class="form-label fw-semibold" style="color:var(--text-muted);font-size:0.8rem;text-transform:uppercase;">Destinatários</label>
                <select class="form-select rounded-3" name="destinatario_tipo" id="fDestTipo"
                        style="background:var(--bg-hover);border-color:var(--card-border-color);color:var(--text-main);">
                    <option value="todos">Todos os usuários</option>
                    <option value="free">Somente Free</option>
                    <option value="pro">Somente PRO</option>
                    <option value="vip">Somente VIP</option>
                    <option value="selecionado">Usuários selecionados</option>
                </select>
            </div>

            <!-- Tipo de interação -->
            <div class="col-md-6">
                <label class="form-label fw-semibold" style="color:var(--text-muted);font-size:0.8rem;text-transform:uppercase;">Interação</label>
                <select class="form-select rounded-3" name="tipo_interacao" id="fTipoInter"
                        style="background:var(--bg-hover);border-color:var(--card-border-color);color:var(--text-main);">
                    <option value="nenhuma">Somente informativa</option>
                    <option value="pesquisa">Com pesquisa / perguntas</option>
                </select>
            </div>

            <!-- Expiração -->
            <div class="col-md-6">
                <label class="form-label fw-semibold" style="color:var(--text-muted);font-size:0.8rem;text-transform:uppercase;">Expirar em (opcional)</label>
                <input type="date" class="form-control rounded-3" name="data_expiracao" id="fExpiracao"
                       style="background:var(--bg-hover);border-color:var(--card-border-color);color:var(--text-main);">
            </div>

            <!-- Ativo (só na edição) -->
            <div class="col-md-6 d-none" id="rowAtivo">
                <label class="form-label fw-semibold" style="color:var(--text-muted);font-size:0.8rem;text-transform:uppercase;">Estado</label>
                <div class="form-check form-switch mt-2">
                    <input class="form-check-input" type="checkbox" name="ativo" id="fAtivo" value="1" checked>
                    <label class="form-check-label" for="fAtivo" style="color:var(--text-main);">Notificação ativa</label>
                </div>
            </div>

            <!-- Usuários selecionados -->
            <div class="col-12 d-none" id="rowUsuarios">
                <label class="form-label fw-semibold" style="color:var(--text-muted);font-size:0.8rem;text-transform:uppercase;">
                    Selecionar usuários
                </label>
                <input type="text" id="userSearch" class="form-control form-control-sm rounded-3 mb-2"
                       placeholder="Buscar por nome ou e-mail..."
                       style="background:var(--bg-hover);border-color:var(--card-border-color);color:var(--text-main);">
                <div id="userList" class="rounded-3 p-2" style="background:var(--bg-hover);border:1px solid var(--card-border-color);max-height:200px;overflow-y:auto;">
                    <?php foreach ($todosUsuarios as $u): ?>
                    <label class="d-flex align-items-center gap-2 px-2 py-1 rounded-2 user-check-row"
                           style="cursor:pointer;color:var(--text-main);">
                        <input type="checkbox" name="usuarios_ids[]"
                               value="<?= htmlspecialchars($u['IDUsuario']) ?>"
                               class="user-checkbox flex-shrink-0">
                        <span class="user-check-label flex-grow-1" style="font-size:0.85rem;">
                            <?= htmlspecialchars($u['Nome']) ?>
                            <small class="text-secondary ms-1"><?= htmlspecialchars($u['Email']) ?></small>
                        </span>
                        <span class="badge rounded-pill" style="font-size:0.65rem;
                            <?= $u['Plano'] === 'vip' ? 'background:#d4af3720;color:#d4af37;border:1px solid #d4af3740;' :
                               ($u['Plano'] === 'pro' ? 'background:#7c3aed20;color:#a78bfa;border:1px solid #7c3aed40;' :
                                                        'background:#37415120;color:#6b7280;border:1px solid #37415130;') ?>">
                            <?= strtoupper($u['Plano'] ?? 'FREE') ?>
                        </span>
                    </label>
                    <?php endforeach; ?>
                </div>
                <small style="color:var(--text-muted);font-size:0.75rem;">
                    <span id="selectedCount">0</span> selecionado(s)
                </small>
            </div>

            <!-- Survey builder -->
            <div class="col-12 d-none" id="rowPesquisa">
                <div class="rounded-3 p-3" style="background:var(--bg-hover);border:1px solid var(--card-border-color);">
                    <div class="d-flex align-items-center justify-content-between mb-3">
                        <span class="fw-semibold" style="color:var(--text-main);font-size:0.9rem;">
                            <i class="bi bi-bar-chart-fill me-2" style="color:var(--accent);"></i>Itens da pesquisa
                        </span>
                        <div class="d-flex gap-2">
                            <button type="button" class="btn btn-sm rounded-pill" onclick="addSurveyItem('radio')"
                                    style="background:var(--accent);color:#000;font-size:0.78rem;">
                                <i class="bi bi-record-circle me-1"></i>Múltipla escolha
                            </button>
                            <button type="button" class="btn btn-sm rounded-pill" onclick="addSurveyItem('checkbox')"
                                    style="background:#7c3aed;color:#fff;font-size:0.78rem;">
                                <i class="bi bi-check-square me-1"></i>Caixa de seleção
                            </button>
                            <button type="button" class="btn btn-sm rounded-pill" onclick="addSurveyItem('texto')"
                                    style="background:#374151;color:#d1d5db;font-size:0.78rem;">
                                <i class="bi bi-textarea-t me-1"></i>Texto livre
                            </button>
                        </div>
                    </div>
                    <div id="surveyItems" class="d-flex flex-column gap-2">
                        <div class="text-center text-secondary small py-3" id="surveyEmpty">
                            Clique nos botões acima para adicionar perguntas à pesquisa.
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>

    <div class="modal-footer border-top border-secondary-subtle p-4 gap-2">
        <button type="button" class="btn btn-outline-secondary rounded-pill px-4" data-bs-dismiss="modal">Cancelar</button>
        <button type="submit" class="btn rounded-pill px-4 fw-semibold"
                style="background:var(--accent);color:#000;">
            <i class="bi bi-check-lg me-1"></i><span id="formSubmitLabel">Criar Notificação</span>
        </button>
    </div>
</form>
</div>
</div>
</div>

<?php require_once '../geral/footer.php'; ?>

<script>
// ── Users JSON for selection ───────────────────────────
var _todosUsuarios = <?= json_encode(array_map(fn($u) => [
    'id'    => $u['IDUsuario'],
    'nome'  => $u['Nome'],
    'email' => $u['Email'],
    'plano' => $u['Plano'],
], $todosUsuarios)) ?>;

// ── Confirm delete ─────────────────────────────────────
document.querySelectorAll('.form-excluir-notif').forEach(function(f) {
    f.addEventListener('submit', function(e) {
        if (!confirm('Excluir esta notificação? Ela ficará invisível para os usuários.')) e.preventDefault();
    });
});

// ── Dest tipo toggle ───────────────────────────────────
var destSel  = document.getElementById('fDestTipo');
var tipoSel  = document.getElementById('fTipoInter');
var rowU     = document.getElementById('rowUsuarios');
var rowP     = document.getElementById('rowPesquisa');
var rowAtivo = document.getElementById('rowAtivo');

function toggleRows() {
    rowU.classList.toggle('d-none', destSel.value !== 'selecionado');
    rowP.classList.toggle('d-none', tipoSel.value !== 'pesquisa');
}
destSel.addEventListener('change', toggleRows);
tipoSel.addEventListener('change', toggleRows);

// ── User search filter ─────────────────────────────────
var userSearch = document.getElementById('userSearch');
if (userSearch) {
    userSearch.addEventListener('input', function() {
        var q = this.value.toLowerCase();
        document.querySelectorAll('.user-check-row').forEach(function(row) {
            var label = row.querySelector('.user-check-label').textContent.toLowerCase();
            row.style.display = label.includes(q) ? '' : 'none';
        });
    });
}

// Selected count
document.getElementById('userList').addEventListener('change', function() {
    document.getElementById('selectedCount').textContent =
        document.querySelectorAll('.user-checkbox:checked').length;
});

// ── Survey builder ─────────────────────────────────────
var surveyData = [];

function renderSurveyItems() {
    var container = document.getElementById('surveyItems');
    var empty     = document.getElementById('surveyEmpty');
    if (surveyData.length === 0) {
        container.innerHTML = '';
        container.appendChild(empty);
        empty.style.display = '';
        return;
    }
    empty.style.display = 'none';
    container.innerHTML = '';

    surveyData.forEach(function(item, idx) {
        var div = document.createElement('div');
        div.className = 'survey-item-card rounded-3 p-3';
        div.style.cssText = 'background:var(--bg-card);border:1px solid var(--card-border-color);';

        var typeLabel = item.tipo === 'radio' ? 'Múltipla escolha' :
                        item.tipo === 'checkbox' ? 'Caixa de seleção' : 'Texto livre';
        var typeIcon  = item.tipo === 'radio' ? 'bi-record-circle' :
                        item.tipo === 'checkbox' ? 'bi-check-square' : 'bi-textarea-t';

        var optsHtml = '';
        if (item.tipo !== 'texto') {
            var opts = item.opcoes || [''];
            optsHtml = '<div class="mt-2"><label class="mb-1" style="font-size:0.75rem;color:var(--text-muted);">Opções:</label>';
            opts.forEach(function(opt, oi) {
                optsHtml += '<div class="d-flex gap-2 mb-1 align-items-center survey-opt-row">'
                    + '<input type="text" class="form-control form-control-sm rounded-2 survey-opt-input" '
                    + '       value="' + opt.replace(/"/g, '&quot;') + '" placeholder="Opção ' + (oi+1) + '"'
                    + '       style="background:var(--bg-hover);border-color:var(--card-border-color);color:var(--text-main);font-size:0.82rem;"'
                    + '       data-idx="' + idx + '" data-oi="' + oi + '">'
                    + '<button type="button" class="btn btn-sm btn-outline-danger rounded-2 survey-remove-opt" '
                    + '        data-idx="' + idx + '" data-oi="' + oi + '" title="Remover opção">'
                    + '  <i class="bi bi-dash"></i></button></div>';
            });
            optsHtml += '<button type="button" class="btn btn-sm btn-outline-secondary rounded-2 mt-1 survey-add-opt" data-idx="' + idx + '">'
                      + '<i class="bi bi-plus me-1"></i>Adicionar opção</button></div>';
        }

        div.innerHTML = '<div class="d-flex align-items-center justify-content-between mb-2">'
            + '  <span style="font-size:0.75rem;color:var(--accent);font-weight:600;">'
            + '    <i class="bi ' + typeIcon + ' me-1"></i>' + typeLabel + '</span>'
            + '  <button type="button" class="btn btn-sm btn-outline-danger rounded-2 survey-remove-item" data-idx="' + idx + '">'
            + '    <i class="bi bi-trash"></i></button></div>'
            + '<input type="text" class="form-control form-control-sm rounded-2 survey-q-input" '
            + '       value="' + (item.pergunta || '').replace(/"/g, '&quot;') + '" placeholder="Digite a pergunta..." '
            + '       data-idx="' + idx + '"'
            + '       style="background:var(--bg-hover);border-color:var(--card-border-color);color:var(--text-main);">'
            + optsHtml;
        container.appendChild(div);
    });

    // Bind survey events
    container.querySelectorAll('.survey-q-input').forEach(function(el) {
        el.addEventListener('input', function() { surveyData[this.dataset.idx].pergunta = this.value; syncSurveyJson(); });
    });
    container.querySelectorAll('.survey-opt-input').forEach(function(el) {
        el.addEventListener('input', function() {
            surveyData[this.dataset.idx].opcoes[this.dataset.oi] = this.value; syncSurveyJson();
        });
    });
    container.querySelectorAll('.survey-remove-opt').forEach(function(el) {
        el.addEventListener('click', function() {
            surveyData[this.dataset.idx].opcoes.splice(parseInt(this.dataset.oi), 1);
            renderSurveyItems(); syncSurveyJson();
        });
    });
    container.querySelectorAll('.survey-add-opt').forEach(function(el) {
        el.addEventListener('click', function() {
            surveyData[this.dataset.idx].opcoes.push('');
            renderSurveyItems(); syncSurveyJson();
        });
    });
    container.querySelectorAll('.survey-remove-item').forEach(function(el) {
        el.addEventListener('click', function() {
            surveyData.splice(parseInt(this.dataset.idx), 1);
            renderSurveyItems(); syncSurveyJson();
        });
    });
}

function syncSurveyJson() {
    document.getElementById('pesquisaJson').value = JSON.stringify(surveyData);
}

window.addSurveyItem = function(tipo) {
    var item = {tipo: tipo, pergunta: ''};
    if (tipo !== 'texto') item.opcoes = ['', ''];
    surveyData.push(item);
    renderSurveyItems();
    syncSurveyJson();
};

// ── Modal: open for create ────────────────────────────
var modalEl = document.getElementById('modalNotif');
modalEl.addEventListener('show.bs.modal', function(event) {
    var btn = event.relatedTarget;
    if (!btn) return;
    var mode = btn.dataset.mode;

    if (mode === 'criar') {
        document.getElementById('modalNotifTitle').innerHTML = '<i class="bi bi-bell-fill me-2" style="color:var(--accent);"></i>Nova Notificação';
        document.getElementById('formAction').value = 'criar';
        document.getElementById('formNotifId').value = '';
        document.getElementById('fTitulo').value = '';
        document.getElementById('fConteudo').value = '';
        document.getElementById('fDestTipo').value = 'todos';
        document.getElementById('fTipoInter').value = 'nenhuma';
        document.getElementById('fExpiracao').value = '';
        document.getElementById('fAtivo').checked = true;
        document.getElementById('formSubmitLabel').textContent = 'Criar Notificação';
        rowAtivo.classList.add('d-none');
        document.querySelectorAll('.user-checkbox').forEach(function(cb) { cb.checked = false; });
        document.getElementById('selectedCount').textContent = '0';
        surveyData = [];
        renderSurveyItems(); syncSurveyJson();
        toggleRows();
        return;
    }
});

// ── Modal: open for edit ──────────────────────────────
document.querySelectorAll('.btn-editar-notif').forEach(function(btn) {
    btn.addEventListener('click', function() {
        var data = JSON.parse(this.dataset.notif);
        var modal = bootstrap.Modal.getOrCreateInstance(modalEl);

        document.getElementById('modalNotifTitle').innerHTML = '<i class="bi bi-pencil-fill me-2" style="color:var(--accent);"></i>Editar Notificação';
        document.getElementById('formAction').value = 'editar';
        document.getElementById('formNotifId').value = data.id;
        document.getElementById('fTitulo').value = data.titulo;
        document.getElementById('fConteudo').value = data.conteudo;
        document.getElementById('fDestTipo').value = data.destinatario_tipo;
        document.getElementById('fTipoInter').value = data.tipo_interacao;
        document.getElementById('fExpiracao').value = data.data_expiracao || '';
        document.getElementById('fAtivo').checked = !!data.ativo;
        document.getElementById('formSubmitLabel').textContent = 'Salvar Alterações';
        rowAtivo.classList.remove('d-none');

        // Specific users
        var selIds = data.destinatarios || [];
        document.querySelectorAll('.user-checkbox').forEach(function(cb) {
            cb.checked = selIds.includes(cb.value);
        });
        document.getElementById('selectedCount').textContent = selIds.length;

        // Survey items
        try { surveyData = JSON.parse(data.itens_pesquisa) || []; } catch(e) { surveyData = []; }
        renderSurveyItems(); syncSurveyJson();
        toggleRows();
        modal.show();
    });
});
</script>
