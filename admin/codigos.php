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
$pageTitle = 'Códigos de Ativação — Admin';

// ── POST ──────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'criar_codigo') {
        $codigo    = strtoupper(trim($_POST['codigo'] ?? ''));
        $desc      = trim($_POST['descricao'] ?? '');
        $plano     = in_array($_POST['plano'] ?? '', ['pro','vip']) ? $_POST['plano'] : 'vip';
        $dias      = max(1, (int)($_POST['dias'] ?? 30));
        $maxUsos   = trim($_POST['max_usos'] ?? '') !== '' ? max(1, (int)$_POST['max_usos']) : null;
        $expiracao = trim($_POST['data_expiracao'] ?? '') ?: null;

        if (!$codigo || !preg_match('/^[A-Z0-9_\-]+$/u', $codigo)) {
            $erro = 'Código inválido. Use letras, números, _ ou -.';
        } else {
            try {
                $pdo->prepare("
                    INSERT INTO codigos_ativacao (IDCodigo, Codigo, DescricaoInterna, PlanoRecompensa, DuracaoDias, MaxUsos, DataExpiracao)
                    VALUES (:id, :cod, :desc, :plano, :dias, :max, :exp)
                ")->execute([
                    ':id'    => gerarUuid(),
                    ':cod'   => $codigo,
                    ':desc'  => $desc ?: null,
                    ':plano' => $plano,
                    ':dias'  => $dias,
                    ':max'   => $maxUsos,
                    ':exp'   => $expiracao,
                ]);
                header("Location: codigos.php?sucesso=criado"); exit;
            } catch (PDOException $e) {
                $erro = strpos($e->getMessage(), 'Duplicate') !== false
                    ? 'Já existe um código com esse nome.'
                    : 'Erro ao criar código.';
            }
        }
    }

    if ($action === 'toggle_ativo') {
        $id = trim($_POST['id'] ?? '');
        if ($id) {
            $pdo->prepare("UPDATE codigos_ativacao SET Ativo = NOT Ativo WHERE IDCodigo = :id")->execute([':id' => $id]);
            header("Location: codigos.php?sucesso=atualizado"); exit;
        }
    }

    if ($action === 'excluir') {
        $id = trim($_POST['id'] ?? '');
        if ($id) {
            $pdo->beginTransaction();
            $pdo->prepare("DELETE FROM codigos_ativacao_usos WHERE FKCodigo = :id")->execute([':id' => $id]);
            $pdo->prepare("DELETE FROM codigos_ativacao WHERE IDCodigo = :id")->execute([':id' => $id]);
            $pdo->commit();
            header("Location: codigos.php?sucesso=excluido"); exit;
        }
    }
}

if (isset($_GET['sucesso'])) {
    $msgs = ['criado' => 'Código criado!', 'atualizado' => 'Código atualizado.', 'excluido' => 'Código excluído.'];
    $sucesso = $msgs[$_GET['sucesso']] ?? null;
}

// ── Leitura ───────────────────────────────────────────────────────────────────
$codigos = [];
try {
    $codigos = $pdo->query("SELECT * FROM codigos_ativacao ORDER BY CriadoEm DESC")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $erro = 'Tabela não encontrada. Execute add_codigos_ativacao.sql primeiro.';
}

require_once '../geral/header.php';
?>

<main class="container-fluid py-4 mt-2 flex-grow-1" style="max-width:1100px;padding-inline:var(--space-page-x);">

    <!-- Cabeçalho -->
    <div class="d-flex justify-content-between align-items-center mb-3 border-bottom border-secondary-subtle pb-3 gap-3 flex-wrap">
        <h2 class="fw-bold text-light mb-0 d-flex align-items-center gap-2" style="font-size:clamp(1.1rem,3vw,1.4rem);">
            <i class="bi bi-shield-fill-check" style="color:#E63946;"></i>
            Painel Administrativo
            <span style="font-size:0.65rem;background:rgba(230,57,70,.15);color:#f87171;border:1px solid rgba(230,57,70,.3);border-radius:999px;padding:2px 10px;font-weight:700;letter-spacing:.06em;">
                <?= strtoupper($nivelSessao) ?>
            </span>
        </h2>
        <a href="/dashboard.php" class="btn btn-outline-secondary btn-sm rounded-pill px-3 flex-shrink-0">
            <i class="bi bi-arrow-left me-1"></i> Voltar
        </a>
    </div>

    <!-- Tabs -->
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
            <a href="/admin/codigos.php" class="nav-link rounded-pill active"
               style="background:#d4af37;color:#000;font-size:0.85rem;">
                <i class="bi bi-gift-fill me-1"></i> Códigos de Ativação
            </a>
        </li>
    </ul>

    <!-- Alertas -->
    <?php if ($sucesso): ?>
        <div class="alert d-flex align-items-center gap-2 rounded-3 border-0 mb-4"
             style="background:#16a34a18;border:1px solid #16a34a44 !important;color:#4ade80;">
            <i class="bi bi-check-circle-fill"></i> <?= htmlspecialchars($sucesso) ?>
        </div>
    <?php endif; ?>
    <?php if ($erro): ?>
        <div class="alert d-flex align-items-center gap-2 rounded-3 border-0 mb-4"
             style="background:#dc262618;border:1px solid #dc262644 !important;color:#f87171;">
            <i class="bi bi-exclamation-triangle-fill"></i> <?= htmlspecialchars($erro) ?>
        </div>
    <?php endif; ?>

    <!-- ── Criar novo código ─────────────────────────────────────────────── -->
    <form method="POST">
        <input type="hidden" name="action" value="criar_codigo">
        <div class="card rounded-4 border-secondary-subtle mb-4" style="background:#1c1f24;border-style:dashed !important;">
            <div class="card-body p-4">
                <h5 class="fw-bold text-light mb-3 d-flex align-items-center gap-2">
                    <i class="bi bi-plus-circle-fill" style="color:#d4af37;"></i> Novo Código
                </h5>
                <div class="row g-3">
                    <div class="col-12 col-md-3">
                        <label class="form-label text-secondary small">Código *</label>
                        <input type="text" name="codigo" required maxlength="50"
                               placeholder="EX: COMEÇANDOBEM"
                               class="form-control rounded-3 fw-bold"
                               style="background:#111318;border:1px solid #374151;color:#d4af37;letter-spacing:.06em;text-transform:uppercase;"
                               oninput="this.value=this.value.toUpperCase()">
                        <div class="form-text text-secondary" style="font-size:.7rem;">Letras, números, _ ou -</div>
                    </div>
                    <div class="col-12 col-md-3">
                        <label class="form-label text-secondary small">Descrição interna</label>
                        <input type="text" name="descricao" maxlength="255" placeholder="Ex: Cartão de visita Jul/26"
                               class="form-control rounded-3"
                               style="background:#111318;border:1px solid #374151;color:#e5e7eb;">
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label text-secondary small">Plano recompensa</label>
                        <select name="plano" class="form-select rounded-3"
                                style="background:#111318;border:1px solid #374151;color:#e5e7eb;">
                            <option value="vip">VIP</option>
                            <option value="pro">PRO</option>
                        </select>
                    </div>
                    <div class="col-6 col-md-1">
                        <label class="form-label text-secondary small">Dias</label>
                        <input type="number" name="dias" value="30" min="1" max="3650"
                               class="form-control rounded-3 text-center"
                               style="background:#111318;border:1px solid #374151;color:#e5e7eb;">
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label text-secondary small">Máx. usos <span class="opacity-50">(vazio=∞)</span></label>
                        <input type="number" name="max_usos" min="1" placeholder="∞"
                               class="form-control rounded-3 text-center"
                               style="background:#111318;border:1px solid #374151;color:#e5e7eb;">
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label text-secondary small">Expira em <span class="opacity-50">(vazio=nunca)</span></label>
                        <input type="date" name="data_expiracao"
                               class="form-control rounded-3"
                               style="background:#111318;border:1px solid #374151;color:#e5e7eb;">
                    </div>
                </div>
            </div>
            <div class="card-footer border-0 px-4 pb-4 pt-0 d-flex justify-content-end" style="background:transparent;">
                <button type="submit" class="btn rounded-pill fw-semibold px-4"
                        style="background:linear-gradient(135deg,#d4af37,#AA8C2C);color:#000;border:none;">
                    <i class="bi bi-plus-lg me-1"></i> Criar Código
                </button>
            </div>
        </div>
    </form>

    <!-- ── Lista de códigos ──────────────────────────────────────────────── -->
    <?php if (!empty($codigos)): ?>
    <div class="card rounded-4 border-secondary-subtle" style="background:#1c1f24;">
        <div class="card-body p-4">
            <h5 class="fw-bold text-light mb-3 d-flex align-items-center gap-2">
                <i class="bi bi-list-ul" style="color:#a78bfa;"></i> Códigos existentes
            </h5>
            <div class="table-responsive">
                <table class="table table-dark table-borderless align-middle mb-0" style="font-size:0.84rem;">
                    <?php $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST']; ?>
                    <thead>
                        <tr style="border-bottom:1px solid #374151;">
                            <th class="text-secondary fw-semibold">Código</th>
                            <th class="text-secondary fw-semibold">Descrição</th>
                            <th class="text-secondary fw-semibold text-center">Plano</th>
                            <th class="text-secondary fw-semibold text-center">Dias</th>
                            <th class="text-secondary fw-semibold text-center">Usos</th>
                            <th class="text-secondary fw-semibold text-center">Expira</th>
                            <th class="text-secondary fw-semibold text-center">Status</th>
                            <th class="text-secondary fw-semibold text-center">Link</th>
                            <th style="width:44px;"></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($codigos as $c):
                        $linkCodigo = $baseUrl . '/resgatar.php?codigo=' . urlencode($c['Codigo']);
                    ?>
                    <tr style="border-bottom:1px solid #1f2937;">
                        <td>
                            <span class="fw-bold" style="color:#d4af37;letter-spacing:.06em;"><?= htmlspecialchars($c['Codigo']) ?></span>
                        </td>
                        <td class="text-secondary"><?= htmlspecialchars($c['DescricaoInterna'] ?? '—') ?></td>
                        <td class="text-center">
                            <?php if ($c['PlanoRecompensa'] === 'vip'): ?>
                                <span class="badge rounded-pill" style="background:rgba(212,175,55,.15);color:#d4af37;border:1px solid rgba(212,175,55,.3);">VIP</span>
                            <?php else: ?>
                                <span class="badge rounded-pill" style="background:rgba(167,139,250,.15);color:#a78bfa;border:1px solid rgba(167,139,250,.3);">PRO</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center text-light"><?= $c['DuracaoDias'] ?> dias</td>
                        <td class="text-center text-light">
                            <?= $c['UsoAtual'] ?>
                            <?= $c['MaxUsos'] !== null ? ' / ' . $c['MaxUsos'] : ' / ∞' ?>
                        </td>
                        <td class="text-center text-secondary">
                            <?php if (!$c['DataExpiracao']): ?>
                                <span class="text-secondary">Nunca</span>
                            <?php elseif ($c['DataExpiracao'] < date('Y-m-d')): ?>
                                <span style="color:#f87171;"><?= date('d/m/Y', strtotime($c['DataExpiracao'])) ?></span>
                            <?php else: ?>
                                <?= date('d/m/Y', strtotime($c['DataExpiracao'])) ?>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <form method="POST" class="d-inline">
                                <input type="hidden" name="action" value="toggle_ativo">
                                <input type="hidden" name="id" value="<?= $c['IDCodigo'] ?>">
                                <button type="submit" class="btn btn-sm rounded-pill px-2 py-0"
                                        style="<?= $c['Ativo'] ? 'background:rgba(22,163,74,.15);color:#4ade80;border:1px solid rgba(22,163,74,.3);' : 'background:rgba(107,114,128,.12);color:#6b7280;border:1px solid rgba(107,114,128,.3);' ?>font-size:.72rem;">
                                    <?= $c['Ativo'] ? 'Ativo' : 'Inativo' ?>
                                </button>
                            </form>
                        </td>
                        <td class="text-center">
                            <button type="button"
                                    onclick="copiarLink('<?= htmlspecialchars($linkCodigo, ENT_QUOTES) ?>', this)"
                                    class="btn btn-sm rounded-pill px-2"
                                    style="background:rgba(99,102,241,.12);color:#818cf8;border:1px solid rgba(99,102,241,.3);font-size:.72rem;white-space:nowrap;"
                                    title="<?= htmlspecialchars($linkCodigo) ?>">
                                <i class="bi bi-link-45deg me-1"></i>Copiar
                            </button>
                        </td>
                        <td class="text-center">
                            <form method="POST" class="d-inline"
                                  onsubmit="return confirm('Excluir o código <?= htmlspecialchars($c['Codigo'], ENT_QUOTES) ?>?')">
                                <input type="hidden" name="action" value="excluir">
                                <input type="hidden" name="id" value="<?= $c['IDCodigo'] ?>">
                                <button type="submit"
                                        class="btn btn-sm rounded-circle p-0 d-inline-flex align-items-center justify-content-center"
                                        style="width:28px;height:28px;background:rgba(220,38,38,.12);color:#f87171;border:1px solid rgba(220,38,38,.3);">
                                    <i class="bi bi-trash3" style="font-size:.7rem;"></i>
                                </button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

</main>

<script>
function copiarLink(url, btn) {
    navigator.clipboard.writeText(url).then(function() {
        const orig = btn.innerHTML;
        btn.innerHTML = '<i class="bi bi-check-lg me-1"></i>Copiado!';
        btn.style.color = '#4ade80';
        btn.style.borderColor = 'rgba(22,163,74,.4)';
        btn.style.background  = 'rgba(22,163,74,.12)';
        setTimeout(function() {
            btn.innerHTML = orig;
            btn.style.color = '#818cf8';
            btn.style.borderColor = 'rgba(99,102,241,.3)';
            btn.style.background  = 'rgba(99,102,241,.12)';
        }, 2000);
    });
}
</script>
<?php require_once '../geral/footer.php'; ?>
