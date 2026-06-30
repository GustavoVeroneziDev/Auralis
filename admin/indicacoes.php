<?php
session_start();
if (!isset($_SESSION['usuario_id'])) { header("Location: /usuario/login.php"); exit; }
require_once '../config/conexao.php';
require_once '../config/funcoes.php';

$nivelSessao = strtolower($_SESSION['nivel_acesso'] ?? '');
if (!in_array($nivelSessao, ['admin', 'supremo'])) {
    header("Location: /dashboard.php?erro=sem_permissao"); exit;
}

$sucesso = $erro = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'salvar') {
        $minInd = max(1, (int)($_POST['min_indicacoes'] ?? 0));
        $plano  = in_array($_POST['plano_recompensa'] ?? '', ['pro', 'vip']) ? $_POST['plano_recompensa'] : 'pro';
        $dias   = max(1, (int)($_POST['duracao_dias'] ?? 30));
        $desc   = trim($_POST['descricao'] ?? '');
        try {
            $pdo->prepare(
                "INSERT INTO indicacao_recompensa_config (IDConfig, MinIndicacoes, PlanoRecompensa, DuracaoDias, Descricao)
                 VALUES (:id, :min, :plano, :dias, :desc)
                 ON DUPLICATE KEY UPDATE PlanoRecompensa = :plano2, DuracaoDias = :dias2, Descricao = :desc2, Ativo = 1"
            )->execute([
                ':id' => gerarUuid(), ':min' => $minInd,
                ':plano' => $plano, ':dias' => $dias, ':desc' => $desc ?: null,
                ':plano2' => $plano, ':dias2' => $dias, ':desc2' => $desc ?: null,
            ]);
            $sucesso = 'Regra salva com sucesso.';
        } catch (PDOException $e) {
            $erro = 'Erro ao salvar regra.';
        }
    }

    if ($action === 'toggle') {
        $id    = trim($_POST['config_id'] ?? '');
        $ativo = (int)($_POST['ativo'] ?? 0);
        if ($id) {
            $pdo->prepare("UPDATE indicacao_recompensa_config SET Ativo = :a WHERE IDConfig = :id")
                ->execute([':a' => $ativo, ':id' => $id]);
            $sucesso = $ativo ? 'Regra ativada.' : 'Regra desativada.';
        }
    }

    if ($action === 'excluir') {
        $id = trim($_POST['config_id'] ?? '');
        if ($id) {
            $pdo->prepare("DELETE FROM indicacao_recompensa_config WHERE IDConfig = :id")->execute([':id' => $id]);
            $sucesso = 'Regra removida.';
        }
    }

    header("Location: indicacoes.php" . ($erro ? "?erro=1" : "?ok=1")); exit;
}

$sucesso = isset($_GET['ok'])   ? 'Operação realizada com sucesso.' : null;
$erro    = isset($_GET['erro']) ? 'Ocorreu um erro.' : null;

$regras = $pdo->query(
    "SELECT c.*,
            COUNT(irc.IDConcessao) as total_concedido
     FROM indicacao_recompensa_config c
     LEFT JOIN indicacao_recompensa_concedida irc ON irc.FKConfig = c.IDConfig
     GROUP BY c.IDConfig
     ORDER BY c.MinIndicacoes ASC"
)->fetchAll(PDO::FETCH_ASSOC);

// Estatísticas gerais de indicação
$stats = $pdo->query(
    "SELECT
         COUNT(DISTINCT u.IDUsuario)                                           as total_indicadores,
         COUNT(DISTINCT CASE WHEN a.Status IN ('ativa','trial') THEN u.IDUsuario END) as indicadores_convertidos,
         COUNT(u.IDUsuario)                                                    as total_indicados
     FROM Usuario u
     LEFT JOIN Assinatura a ON a.FKUsuario = u.IDUsuario AND a.Status IN ('ativa','trial')
     WHERE u.FKIndicadoPor IS NOT NULL"
)->fetch(PDO::FETCH_ASSOC);

$pageTitle = 'Indicações — Admin Auralis';
require_once '../geral/header.php';
?>
<style>
.card-admin { background:var(--bg-card,#1a1d27); border:1px solid rgba(255,255,255,.08); border-radius:12px; }
.badge-pro { background:rgba(124,58,237,.2); color:#a78bfa; border:1px solid rgba(124,58,237,.3); }
.badge-vip { background:rgba(212,175,55,.15); color:#d4af37; border:1px solid rgba(212,175,55,.3); }
</style>

<main class="container-fluid py-4 mt-2 flex-grow-1" style="max-width:960px;padding-inline:var(--space-page-x);">

    <!-- Cabeçalho -->
    <div class="d-flex justify-content-between align-items-center mb-4 border-bottom border-secondary-subtle pb-3 gap-3 flex-wrap">
        <a href="/dashboard.php" class="btn btn-outline-secondary btn-sm rounded-pill px-3 flex-shrink-0">
            <i class="bi bi-arrow-left me-1"></i> Voltar
        </a>
        <button class="btn btn-sm rounded-pill px-4 fw-semibold"
            style="background:rgba(212,175,55,.15);color:#d4af37;border:1px solid rgba(212,175,55,.35);"
            data-bs-toggle="modal" data-bs-target="#modalNovaRegra">
            <i class="bi bi-plus-lg me-1"></i> Nova regra
        </button>
    </div>

    <!-- Tabs de navegação admin -->
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
            <a href="/admin/indicacoes.php" class="nav-link rounded-pill active"
               style="background:#7c3aed;color:#fff;font-size:0.85rem;">
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

    <?php if ($sucesso): ?>
    <div class="alert border-0 rounded-3 mb-4 py-2 px-3" style="background:rgba(34,197,94,.1);color:#86efac;">
        <i class="bi bi-check-circle me-2"></i><?= htmlspecialchars($sucesso) ?>
    </div>
    <?php endif; ?>

    <!-- Estatísticas -->
    <div class="row g-3 mb-4">
        <div class="col-4">
            <div class="card-admin p-3 text-center">
                <div class="text-secondary small mb-1">Usuários indicados</div>
                <div class="fw-bold text-light fs-4"><?= $stats['total_indicados'] ?? 0 ?></div>
            </div>
        </div>
        <div class="col-4">
            <div class="card-admin p-3 text-center">
                <div class="text-secondary small mb-1">Convertidos (com plano)</div>
                <div class="fw-bold fs-4" style="color:#86efac;"><?= $stats['indicadores_convertidos'] ?? 0 ?></div>
            </div>
        </div>
        <div class="col-4">
            <div class="card-admin p-3 text-center">
                <div class="text-secondary small mb-1">Indicadores ativos</div>
                <div class="fw-bold text-light fs-4"><?= $stats['total_indicadores'] ?? 0 ?></div>
            </div>
        </div>
    </div>

    <!-- Como funciona -->
    <div class="card-admin p-4 mb-4">
        <h6 class="fw-semibold text-light mb-3"><i class="bi bi-info-circle me-2 text-secondary"></i>Como funciona</h6>
        <div class="row g-3" style="font-size:.85rem;">
            <div class="col-md-4">
                <div class="d-flex gap-2">
                    <div class="rounded-2 flex-shrink-0 d-flex align-items-center justify-content-center"
                        style="width:28px;height:28px;background:rgba(212,175,55,.12);font-size:.75rem;color:var(--accent);font-weight:700;">1</div>
                    <div>
                        <div class="text-light fw-semibold">Cada usuário tem um código</div>
                        <div class="text-secondary">Gerado automaticamente no cadastro (ex: AUR-K7X2B4). Qualquer um pode compartilhar.</div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="d-flex gap-2">
                    <div class="rounded-2 flex-shrink-0 d-flex align-items-center justify-content-center"
                        style="width:28px;height:28px;background:rgba(212,175,55,.12);font-size:.75rem;color:var(--accent);font-weight:700;">2</div>
                    <div>
                        <div class="text-light fw-semibold">Amigo se cadastra com o link</div>
                        <div class="text-secondary"><code style="color:var(--accent);">auralis.com/cadastro?ref=CODIGO</code> — o vínculo é salvo automaticamente.</div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="d-flex gap-2">
                    <div class="rounded-2 flex-shrink-0 d-flex align-items-center justify-content-center"
                        style="width:28px;height:28px;background:rgba(212,175,55,.12);font-size:.75rem;color:var(--accent);font-weight:700;">3</div>
                    <div>
                        <div class="text-light fw-semibold">Recompensa automática</div>
                        <div class="text-secondary">Quando o indicado paga um plano, o indicador recebe a recompensa configurada aqui.</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="mt-3 p-3 rounded-3" style="background:rgba(212,175,55,.06);border:1px solid rgba(212,175,55,.15);font-size:.8rem;">
            <i class="bi bi-star-fill me-1" style="color:var(--accent);"></i>
            <strong class="text-light">Revendedores</strong> <span class="text-secondary">recebem <strong class="text-light">comissão em dinheiro</strong> em vez de recompensas de plano — e são gerenciados separadamente em <a href="revendedores.php" style="color:var(--accent);">Revendedores</a>.</span>
        </div>
    </div>

    <!-- Regras configuradas -->
    <div class="card-admin">
        <div class="p-4 border-bottom border-secondary-subtle">
            <h6 class="fw-semibold text-light mb-0">Regras de recompensa</h6>
            <p class="text-secondary mb-0 small mt-1">Configure marcos de indicações que desbloqueiam recompensas automáticas.</p>
        </div>
        <?php if (empty($regras)): ?>
        <div class="text-center py-5">
            <i class="bi bi-gift text-secondary" style="font-size:2.5rem;"></i>
            <p class="text-secondary mt-3 mb-0 small">Nenhuma regra configurada. Crie a primeira usando o botão acima.</p>
        </div>
        <?php else: ?>
        <div class="table-responsive rounded-3 overflow-hidden">
            <table class="table table-dark align-middle mb-0">
                <thead>
                    <tr style="background:rgba(255,255,255,.04);">
                        <th class="py-3 px-4 border-0">Marco</th>
                        <th class="py-3 px-3 border-0">Recompensa</th>
                        <th class="py-3 px-3 border-0">Duração</th>
                        <th class="py-3 px-3 border-0">Descrição para o usuário</th>
                        <th class="py-3 px-3 border-0">Já concedida</th>
                        <th class="py-3 px-3 border-0">Status</th>
                        <th class="py-3 px-3 border-0"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($regras as $r): ?>
                    <tr style="border-color:rgba(255,255,255,.05);" class="<?= $r['Ativo'] ? '' : 'opacity-50' ?>">
                        <td class="py-3 px-4 border-0">
                            <span class="fw-bold text-light" style="font-size:1.1rem;"><?= $r['MinIndicacoes'] ?></span>
                            <span class="text-secondary small"> indicações convertidas</span>
                        </td>
                        <td class="py-3 px-3 border-0">
                            <span class="badge rounded-pill badge-<?= $r['PlanoRecompensa'] ?>"><?= strtoupper($r['PlanoRecompensa']) ?></span>
                        </td>
                        <td class="py-3 px-3 border-0 text-light"><?= $r['DuracaoDias'] ?> dias</td>
                        <td class="py-3 px-3 border-0 text-secondary small"><?= htmlspecialchars($r['Descricao'] ?? '—') ?></td>
                        <td class="py-3 px-3 border-0 text-secondary"><?= $r['total_concedido'] ?> <?= $r['total_concedido'] == 1 ? 'vez' : 'vezes' ?></td>
                        <td class="py-3 px-3 border-0">
                            <form method="POST" class="d-inline">
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="config_id" value="<?= $r['IDConfig'] ?>">
                                <input type="hidden" name="ativo" value="<?= $r['Ativo'] ? 0 : 1 ?>">
                                <button class="badge rounded-pill border-0 py-1 px-2"
                                    style="cursor:pointer;background:<?= $r['Ativo'] ? 'rgba(34,197,94,.15)' : 'rgba(239,68,68,.1)' ?>;
                                           color:<?= $r['Ativo'] ? '#86efac' : '#fca5a5' ?>;
                                           border:1px solid <?= $r['Ativo'] ? 'rgba(34,197,94,.3)' : 'rgba(239,68,68,.2)' ?> !important;">
                                    <?= $r['Ativo'] ? 'Ativa' : 'Inativa' ?>
                                </button>
                            </form>
                        </td>
                        <td class="py-3 px-3 border-0 text-end">
                            <form method="POST" class="d-inline" onsubmit="return confirm('Remover esta regra?');">
                                <input type="hidden" name="action" value="excluir">
                                <input type="hidden" name="config_id" value="<?= $r['IDConfig'] ?>">
                                <button class="btn btn-sm p-1 border-0 text-secondary" title="Remover">
                                    <i class="bi bi-trash3" style="font-size:.8rem;"></i>
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

<!-- Modal Nova Regra -->
<div class="modal fade" id="modalNovaRegra" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-secondary-subtle shadow-lg" style="background:#1a1d27;">
            <form method="POST">
                <input type="hidden" name="action" value="salvar">
                <div class="modal-header border-secondary-subtle px-4 py-3">
                    <h6 class="modal-title fw-bold text-light mb-0"><i class="bi bi-gift me-2" style="color:var(--accent);"></i>Nova regra de recompensa</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body px-4 py-3">
                    <div class="row g-3">
                        <div class="col-4">
                            <label class="form-label text-secondary small fw-semibold">Indicações mínimas</label>
                            <input type="number" name="min_indicacoes" class="form-control" min="1" placeholder="5" required>
                            <div class="form-text text-secondary mt-1" style="font-size:.72rem;">Convertidas (pagaram)</div>
                        </div>
                        <div class="col-4">
                            <label class="form-label text-secondary small fw-semibold">Plano de recompensa</label>
                            <select name="plano_recompensa" class="form-select" required>
                                <option value="pro">PRO</option>
                                <option value="vip">VIP</option>
                            </select>
                        </div>
                        <div class="col-4">
                            <label class="form-label text-secondary small fw-semibold">Duração (dias)</label>
                            <input type="number" name="duracao_dias" class="form-control" min="1" value="30" required>
                        </div>
                    </div>
                    <div class="mt-3">
                        <label class="form-label text-secondary small fw-semibold">Descrição para o usuário</label>
                        <input type="text" name="descricao" class="form-control" placeholder="Ex: Indique 5 amigos e ganhe 30 dias PRO grátis!">
                    </div>
                </div>
                <div class="modal-footer border-secondary-subtle d-flex justify-content-between p-3">
                    <button type="button" class="btn btn-sm btn-link text-secondary text-decoration-none" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-sm fw-bold rounded-pill px-4"
                        style="background:rgba(212,175,55,.15);color:#d4af37;border:1px solid rgba(212,175,55,.35);">
                        <i class="bi bi-check-lg me-1"></i> Criar regra
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once '../geral/footer.php'; ?>

