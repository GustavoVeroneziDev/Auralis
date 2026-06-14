<?php
// ==============================================================================
// ADMIN/CONFIGURACOES_PLANOS.PHP — Configuração dinâmica de planos e recursos
// ==============================================================================
session_start();

if (!isset($_SESSION['usuario_id'])) {
    header("Location: /usuario/login.php");
    exit;
}

require_once '../config/conexao.php';
require_once '../config/funcoes.php';

$nivelSessao = strtolower($_SESSION['nivel_acesso'] ?? '');
if (!in_array($nivelSessao, ['admin', 'supremo'])) {
    header("Location: /dashboard.php?erro=sem_permissao");
    exit;
}

$sucesso = $erro = null;

// ── Helper: normaliza texto para slug ───────────────────────────────────────
function _gerarSlug($texto) {
    $mapa = ['ã'=>'a','â'=>'a','á'=>'a','à'=>'a','ä'=>'a','ê'=>'e','é'=>'e',
             'è'=>'e','ë'=>'e','î'=>'i','í'=>'i','ì'=>'i','õ'=>'o','ô'=>'o',
             'ó'=>'o','ò'=>'o','û'=>'u','ú'=>'u','ù'=>'u','ç'=>'c','ñ'=>'n'];
    $slug = mb_strtolower($texto);
    $slug = strtr($slug, $mapa);
    $slug = preg_replace('/[^a-z0-9]+/', '_', $slug);
    return trim($slug, '_');
}

// ==============================================================================
// AÇÕES POST
// ==============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ── Salvar limites numéricos ─────────────────────────────────────────────
    if ($action === 'salvar_limites') {
        try {
            $planos = ['free', 'pro', 'vip'];
            $campos = ['transacoes_mes', 'carteiras', 'cartoes', 'categorias', 'parcelas_max', 'horas_teste'];

            $stmt = $pdo->prepare("
                UPDATE config_limites_plano
                SET transacoes_mes = :transacoes_mes,
                    carteiras      = :carteiras,
                    cartoes        = :cartoes,
                    categorias     = :categorias,
                    parcelas_max   = :parcelas_max,
                    horas_teste    = :horas_teste
                WHERE plano = :plano
            ");

            foreach ($planos as $pl) {
                $dados = [':plano' => $pl];
                foreach ($campos as $campo) {
                    $ilimitado = isset($_POST['ilimitado'][$pl][$campo]);
                    $valor     = $ilimitado ? -1 : (int)($_POST['limites'][$pl][$campo] ?? 0);
                    if ($campo === 'horas_teste' && $pl !== 'free') $valor = 0;
                    $dados[':' . $campo] = $valor;
                }
                $stmt->execute($dados);
            }
            header("Location: configuracoes_planos.php?sucesso=limites");
            exit;
        } catch (PDOException $e) {
            $erro = "Erro ao salvar limites.";
        }
    }

    // ── Salvar recursos existentes ───────────────────────────────────────────
    if ($action === 'salvar_recursos') {
        try {
            $stmt = $pdo->prepare("
                UPDATE config_recursos
                SET label              = :label,
                    disponivel_free    = :disp_free,
                    disponivel_pro     = :disp_pro,
                    disponivel_vip     = :disp_vip,
                    mostrar_nos_planos = :mostrar,
                    ordem              = :ordem
                WHERE slug = :slug
            ");

            foreach ($_POST['recursos'] ?? [] as $slug => $d) {
                $stmt->execute([
                    ':label'     => trim($d['label'] ?? $slug),
                    ':disp_free' => isset($d['disp_free']) ? 1 : 0,
                    ':disp_pro'  => isset($d['disp_pro'])  ? 1 : 0,
                    ':disp_vip'  => isset($d['disp_vip'])  ? 1 : 0,
                    ':mostrar'   => isset($d['mostrar'])   ? 1 : 0,
                    ':ordem'     => (int)($d['ordem'] ?? 0),
                    ':slug'      => $slug,
                ]);
            }
            header("Location: configuracoes_planos.php?sucesso=recursos");
            exit;
        } catch (PDOException $e) {
            $erro = "Erro ao salvar recursos.";
        }
    }

    // ── Criar novo recurso ───────────────────────────────────────────────────
    if ($action === 'novo_recurso') {
        $label  = trim($_POST['novo_label'] ?? '');
        $slug   = trim($_POST['novo_slug'] ?? '');
        if (!$slug && $label) $slug = _gerarSlug($label);

        if (!$label) {
            $erro = "Informe o nome do recurso.";
        } elseif (!preg_match('/^[a-z0-9_]+$/', $slug)) {
            $erro = "Slug inválido (use apenas letras minúsculas, números e _).";
        } else {
            try {
                $exists = $pdo->prepare("SELECT 1 FROM config_recursos WHERE slug = ? LIMIT 1");
                $exists->execute([$slug]);
                if ($exists->fetchColumn()) {
                    $erro = "Já existe um recurso com o slug \"" . htmlspecialchars($slug) . "\".";
                } else {
                    $maxOrdem = (int)$pdo->query("SELECT COALESCE(MAX(ordem), 0) FROM config_recursos")->fetchColumn();
                    $pdo->prepare("
                        INSERT INTO config_recursos
                            (slug, label, disponivel_free, disponivel_pro, disponivel_vip, mostrar_nos_planos, ordem)
                        VALUES (?, ?, ?, ?, ?, ?, ?)
                    ")->execute([
                        $slug, $label,
                        isset($_POST['novo_disp_free']) ? 1 : 0,
                        isset($_POST['novo_disp_pro'])  ? 1 : 0,
                        isset($_POST['novo_disp_vip'])  ? 1 : 0,
                        isset($_POST['novo_mostrar'])   ? 1 : 0,
                        $maxOrdem + 10,
                    ]);
                    header("Location: configuracoes_planos.php?sucesso=recurso_criado");
                    exit;
                }
            } catch (PDOException $e) {
                $erro = "Erro ao criar recurso.";
            }
        }
    }

    // ── Excluir recurso ──────────────────────────────────────────────────────
    if ($action === 'excluir_recurso') {
        $slug = trim($_POST['slug'] ?? '');
        if ($slug) {
            try {
                $pdo->prepare("DELETE FROM config_recursos WHERE slug = ?")->execute([$slug]);
                header("Location: configuracoes_planos.php?sucesso=recurso_excluido");
                exit;
            } catch (PDOException $e) {
                $erro = "Erro ao excluir recurso.";
            }
        }
    }
}

if (isset($_GET['sucesso'])) {
    $msgs = [
        'limites'          => "Limites por plano salvos com sucesso!",
        'recursos'         => "Recursos salvos com sucesso!",
        'recurso_criado'   => "Recurso criado com sucesso!",
        'recurso_excluido' => "Recurso excluído.",
    ];
    $sucesso = $msgs[$_GET['sucesso']] ?? null;
}

// ==============================================================================
// LEITURA DOS DADOS
// ==============================================================================
$limitesDB = [];
try {
    $rows = $pdo->query("SELECT * FROM config_limites_plano ORDER BY FIELD(plano,'free','pro','vip')")
                ->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) $limitesDB[$r['plano']] = $r;
} catch (PDOException $e) {
    $erro = "Tabelas não encontradas. Execute config_planos.sql e config_planos_v2.sql primeiro.";
}

$recursosDB = [];
try {
    $recursosDB = $pdo->query("SELECT * FROM config_recursos ORDER BY ordem ASC")
                      ->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}

require_once '../geral/header.php';
?>

<main class="container-fluid py-4 mt-2 flex-grow-1" style="max-width: 1100px; padding-inline: var(--space-page-x); min-height: 100vh;">

    <!-- Cabeçalho -->
    <div class="d-flex justify-content-between align-items-center mb-3 border-bottom border-secondary-subtle pb-3 gap-3 flex-wrap">
        <h2 class="fw-bold text-light mb-0 d-flex align-items-center gap-2" style="font-size: clamp(1.1rem, 3vw, 1.4rem);">
            <i class="bi bi-shield-fill-check" style="color:#E63946;"></i>
            Painel Administrativo
            <span style="font-size:0.65rem;background:rgba(230,57,70,0.15);color:#f87171;border:1px solid rgba(230,57,70,0.3);border-radius:999px;padding:2px 10px;font-weight:700;letter-spacing:0.06em;">
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
            <a href="/admin/configuracoes_planos.php" class="nav-link rounded-pill active"
               style="background:#7c3aed;color:#fff;font-size:0.85rem;">
                <i class="bi bi-sliders me-1"></i> Configurações de Planos
            </a>
        </li>
        <li class="nav-item">
            <a href="/admin/codigos.php" class="nav-link rounded-pill"
               style="background:rgba(255,255,255,.05);color:#9ca3af;font-size:0.85rem;">
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

    <?php if (empty($limitesDB)): ?>
        <div class="alert rounded-3 border-0" style="background:#d4af3715;border:1px solid #d4af3740 !important;color:#d4af37;">
            <i class="bi bi-database-exclamation me-2"></i>
            <strong>Tabelas não encontradas.</strong>
            Execute <code>config_planos.sql</code> e depois <code>config_planos_v2.sql</code> no phpMyAdmin.
        </div>
    <?php else: ?>

    <!-- ════════════════════════════════════════════════════════
         SEÇÃO 1: LIMITES NUMÉRICOS
         ════════════════════════════════════════════════════════ -->
    <form method="POST" action="">
        <input type="hidden" name="action" value="salvar_limites">

        <div class="card rounded-4 border-secondary-subtle mb-4" style="background:#1c1f24;">
            <div class="card-body p-4">
                <h5 class="fw-bold text-light mb-1 d-flex align-items-center gap-2">
                    <i class="bi bi-sliders2" style="color:#a78bfa;"></i> Limites por Plano
                </h5>
                <p class="text-secondary mb-4" style="font-size:0.83rem;">
                    Marque <strong>∞ Ilimitado</strong> para sem limite. "Horas de teste" só se aplica ao Free.
                </p>

                <?php
                $colunas = [
                    'transacoes_mes' => 'Registros / mês',
                    'carteiras'      => 'Carteiras',
                    'cartoes'        => 'Cartões de crédito',
                    'categorias'     => 'Categorias',
                    'parcelas_max'   => 'Parcelas máx.',
                    'horas_teste'    => 'Horas de teste',
                ];
                $planosLabel = [
                    'free' => ['label' => 'Free', 'cor' => '#9ca3af'],
                    'pro'  => ['label' => 'PRO',  'cor' => '#a78bfa'],
                    'vip'  => ['label' => 'VIP',  'cor' => '#d4af37'],
                ];
                ?>

                <div class="table-responsive">
                    <table class="table table-dark table-borderless align-middle mb-0" style="font-size:0.85rem;">
                        <thead>
                            <tr style="border-bottom:1px solid #374151;">
                                <th class="text-secondary fw-semibold" style="width:28%;">Limite</th>
                                <?php foreach ($planosLabel as $pl => $info): ?>
                                    <th class="fw-bold text-center" style="color:<?= $info['cor'] ?>;"><?= $info['label'] ?></th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($colunas as $campo => $labelCampo): ?>
                            <tr style="border-bottom:1px solid #1f2937;">
                                <td class="text-light fw-medium"><?= htmlspecialchars($labelCampo) ?></td>
                                <?php foreach (['free','pro','vip'] as $pl):
                                    $val    = $limitesDB[$pl][$campo] ?? 0;
                                    $isIlim = ($val == -1);
                                    $isFreeOnly = ($campo === 'horas_teste' && $pl !== 'free');
                                ?>
                                <td class="text-center">
                                    <?php if ($isFreeOnly): ?>
                                        <span class="text-secondary" style="font-size:0.8rem;">—</span>
                                    <?php else: ?>
                                        <div class="d-flex flex-column align-items-center gap-1">
                                            <input type="number"
                                                   name="limites[<?= $pl ?>][<?= $campo ?>]"
                                                   value="<?= $isIlim ? '' : (int)$val ?>"
                                                   min="0"
                                                   class="form-control form-control-sm text-center rounded-2 limite-input"
                                                   style="width:90px;background:#111318;border:1px solid #374151;color:#e5e7eb;font-size:0.83rem;"
                                                   placeholder="<?= $isIlim ? '∞' : '' ?>"
                                                   <?= $isIlim ? 'disabled' : '' ?>>
                                            <div class="form-check form-switch mb-0 d-flex align-items-center gap-1" style="font-size:0.75rem;">
                                                <input class="form-check-input ilim-toggle" type="checkbox"
                                                       name="ilimitado[<?= $pl ?>][<?= $campo ?>]"
                                                       <?= $isIlim ? 'checked' : '' ?>
                                                       style="cursor:pointer;">
                                                <label class="form-check-label text-secondary" style="cursor:pointer;">∞</label>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="card-footer border-0 px-4 pb-4 pt-0 d-flex justify-content-end" style="background:transparent;">
                <button type="submit" class="btn rounded-pill fw-semibold px-4"
                        style="background:#7c3aed;color:#fff;border:none;">
                    <i class="bi bi-floppy me-1"></i> Salvar Limites
                </button>
            </div>
        </div>
    </form>

    <!-- ════════════════════════════════════════════════════════
         SEÇÃO 2: CONTROLE DE ACESSO POR RECURSO
         ════════════════════════════════════════════════════════ -->
    <?php if (!empty($recursosDB)): ?>
    <form method="POST" action="" id="frmRecursos">
        <input type="hidden" name="action" value="salvar_recursos">

        <div class="card rounded-4 border-secondary-subtle mb-4" style="background:#1c1f24;">
            <div class="card-body p-4">
                <h5 class="fw-bold text-light mb-1 d-flex align-items-center gap-2">
                    <i class="bi bi-key-fill" style="color:#d4af37;"></i> Controle de Acesso por Recurso
                </h5>
                <p class="text-secondary mb-4" style="font-size:0.83rem;">
                    Marque os planos que podem acessar cada recurso. Cada plano é <strong>independente</strong> — você pode liberar só para VIP, por exemplo.
                </p>

                <div class="table-responsive">
                    <table class="table table-dark table-borderless align-middle mb-0" style="font-size:0.85rem;">
                        <thead>
                            <tr style="border-bottom:1px solid #374151;">
                                <th class="text-secondary fw-semibold" style="min-width:220px;">Recurso / Label</th>
                                <th class="text-center fw-bold" style="color:#9ca3af;width:70px;">Free</th>
                                <th class="text-center fw-bold" style="color:#a78bfa;width:70px;">PRO</th>
                                <th class="text-center fw-bold" style="color:#d4af37;width:70px;">VIP</th>
                                <th class="text-center text-secondary fw-semibold" style="width:80px;">Exibir</th>
                                <th class="text-center text-secondary fw-semibold" style="width:70px;">Ordem</th>
                                <th style="width:44px;"></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($recursosDB as $r):
                            $slug = htmlspecialchars($r['slug']);
                        ?>
                            <tr style="border-bottom:1px solid #1f2937;">
                                <td>
                                    <input type="text"
                                           name="recursos[<?= $slug ?>][label]"
                                           value="<?= htmlspecialchars($r['label']) ?>"
                                           class="form-control form-control-sm rounded-2"
                                           style="background:#111318;border:1px solid #374151;color:#e5e7eb;font-size:0.83rem;"
                                           required>
                                    <code style="font-size:0.68rem;color:#4b5563;"><?= $slug ?></code>
                                </td>
                                <?php foreach (['disp_free' => 'disponivel_free', 'disp_pro' => 'disponivel_pro', 'disp_vip' => 'disponivel_vip'] as $postKey => $col): ?>
                                <td class="text-center">
                                    <input type="checkbox"
                                           name="recursos[<?= $slug ?>][<?= $postKey ?>]"
                                           class="form-check-input"
                                           <?= $r[$col] ? 'checked' : '' ?>
                                           style="width:1.15rem;height:1.15rem;cursor:pointer;">
                                </td>
                                <?php endforeach; ?>
                                <td class="text-center">
                                    <input type="checkbox"
                                           name="recursos[<?= $slug ?>][mostrar]"
                                           class="form-check-input"
                                           <?= $r['mostrar_nos_planos'] ? 'checked' : '' ?>
                                           style="width:1.15rem;height:1.15rem;cursor:pointer;">
                                </td>
                                <td class="text-center">
                                    <input type="number"
                                           name="recursos[<?= $slug ?>][ordem]"
                                           value="<?= (int)$r['ordem'] ?>"
                                           class="form-control form-control-sm text-center rounded-2"
                                           style="width:60px;background:#111318;border:1px solid #374151;color:#9ca3af;font-size:0.78rem;">
                                </td>
                                <td class="text-center">
                                    <button type="button"
                                            class="btn btn-sm rounded-circle p-0 d-inline-flex align-items-center justify-content-center"
                                            style="width:28px;height:28px;background:rgba(220,38,38,.12);color:#f87171;border:1px solid rgba(220,38,38,.3);"
                                            onclick="confirmarExclusao('<?= $slug ?>', '<?= htmlspecialchars($r['label'], ENT_QUOTES) ?>')"
                                            title="Excluir">
                                        <i class="bi bi-trash3" style="font-size:0.7rem;"></i>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="card-footer border-0 px-4 pb-4 pt-0 d-flex justify-content-end" style="background:transparent;">
                <button type="submit" class="btn rounded-pill fw-semibold px-4"
                        style="background:linear-gradient(90deg,#AA8C2C,#d4af37);color:#fff;border:none;">
                    <i class="bi bi-floppy me-1"></i> Salvar Recursos
                </button>
            </div>
        </div>
    </form>
    <?php endif; ?>

    <!-- ════════════════════════════════════════════════════════
         SEÇÃO 3: NOVO RECURSO
         ════════════════════════════════════════════════════════ -->
    <form method="POST" action="">
        <input type="hidden" name="action" value="novo_recurso">

        <div class="card rounded-4 border-secondary-subtle mb-4" style="background:#1c1f24;border-style:dashed !important;">
            <div class="card-body p-4">
                <h5 class="fw-bold text-light mb-3 d-flex align-items-center gap-2">
                    <i class="bi bi-plus-circle-fill" style="color:#22c55e;"></i> Novo Recurso
                </h5>
                <div class="row g-3 align-items-end">
                    <div class="col-12 col-md-5">
                        <label class="form-label text-secondary small mb-1">Nome (label)</label>
                        <input type="text" name="novo_label" placeholder="Ex: Relatório Avançado"
                               class="form-control rounded-3"
                               style="background:#111318;border:1px solid #374151;color:#e5e7eb;"
                               required>
                    </div>
                    <div class="col-12 col-md-3">
                        <label class="form-label text-secondary small mb-1">Slug <span class="text-secondary opacity-50">(auto se vazio)</span></label>
                        <input type="text" name="novo_slug" placeholder="relatorio_avancado"
                               class="form-control rounded-3"
                               pattern="[a-z0-9_]+"
                               style="background:#111318;border:1px solid #374151;color:#e5e7eb;">
                    </div>
                    <div class="col-12 col-md-4">
                        <label class="form-label text-secondary small mb-1">Disponível para</label>
                        <div class="d-flex gap-3 align-items-center flex-wrap">
                            <div class="form-check mb-0">
                                <input class="form-check-input" type="checkbox" name="novo_disp_free" id="ndf" style="cursor:pointer;">
                                <label class="form-check-label" for="ndf" style="color:#9ca3af;font-size:0.85rem;cursor:pointer;">Free</label>
                            </div>
                            <div class="form-check mb-0">
                                <input class="form-check-input" type="checkbox" name="novo_disp_pro" id="ndp" checked style="cursor:pointer;accent-color:#a78bfa;">
                                <label class="form-check-label" for="ndp" style="color:#a78bfa;font-size:0.85rem;cursor:pointer;">PRO</label>
                            </div>
                            <div class="form-check mb-0">
                                <input class="form-check-input" type="checkbox" name="novo_disp_vip" id="ndv" checked style="cursor:pointer;accent-color:#d4af37;">
                                <label class="form-check-label" for="ndv" style="color:#d4af37;font-size:0.85rem;cursor:pointer;">VIP</label>
                            </div>
                            <div class="form-check mb-0 ms-auto">
                                <input class="form-check-input" type="checkbox" name="novo_mostrar" id="ndm" checked style="cursor:pointer;">
                                <label class="form-check-label text-secondary" for="ndm" style="font-size:0.85rem;cursor:pointer;">Exibir</label>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="card-footer border-0 px-4 pb-4 pt-0 d-flex justify-content-end" style="background:transparent;">
                <button type="submit" class="btn rounded-pill fw-semibold px-4"
                        style="background:#16a34a;color:#fff;border:none;">
                    <i class="bi bi-plus-lg me-1"></i> Criar Recurso
                </button>
            </div>
        </div>
    </form>

    <?php endif; ?>

</main>

<!-- Form oculto para exclusão -->
<form id="frmDelete" method="POST" action="">
    <input type="hidden" name="action" value="excluir_recurso">
    <input type="hidden" name="slug" id="deleteSlug">
</form>

<script>
// Toggle ilimitado nos inputs numéricos
document.querySelectorAll('.ilim-toggle').forEach(function(chk) {
    const inp = chk.closest('td').querySelector('input[type="number"]');
    if (!inp) return;
    chk.addEventListener('change', function() {
        inp.disabled    = this.checked;
        inp.placeholder = this.checked ? '∞' : '';
        if (this.checked) inp.value = '';
    });
});

// Confirmação de exclusão de recurso
function confirmarExclusao(slug, label) {
    if (!confirm('Excluir o recurso "' + label + '" (' + slug + ')?\n\nEsta ação não pode ser desfeita.')) return;
    document.getElementById('deleteSlug').value = slug;
    document.getElementById('frmDelete').submit();
}
</script>

<?php require_once '../geral/footer.php'; ?>
