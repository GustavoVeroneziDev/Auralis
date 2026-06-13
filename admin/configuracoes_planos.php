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

// ==============================================================================
// AÇÕES POST
// ==============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ── Salvar limites numéricos ─────────────────────────────────────────────
    if ($action === 'salvar_limites') {
        try {
            $planos = ['free', 'pro', 'vip'];
            $campos = ['transacoes_mes', 'carteiras', 'categorias', 'parcelas_max', 'horas_teste'];

            $stmt = $pdo->prepare("
                UPDATE config_limites_plano
                SET transacoes_mes = :transacoes_mes,
                    carteiras      = :carteiras,
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
                    // horas_teste só faz sentido para free
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

    // ── Salvar níveis de acesso por recurso ──────────────────────────────────
    if ($action === 'salvar_recursos') {
        try {
            $niveis    = ['free', 'pro', 'vip'];
            $stmtR     = $pdo->prepare("
                UPDATE config_recursos
                SET nivel_minimo       = :nivel,
                    mostrar_nos_planos = :mostrar
                WHERE slug = :slug
            ");

            $slugsPost = $_POST['recursos'] ?? [];
            foreach ($slugsPost as $slug => $dados) {
                $nivel   = in_array($dados['nivel_minimo'] ?? '', $niveis)
                    ? $dados['nivel_minimo']
                    : 'pro';
                $mostrar = isset($dados['mostrar_nos_planos']) ? 1 : 0;
                $stmtR->execute([':nivel' => $nivel, ':mostrar' => $mostrar, ':slug' => $slug]);
            }
            header("Location: configuracoes_planos.php?sucesso=recursos");
            exit;
        } catch (PDOException $e) {
            $erro = "Erro ao salvar recursos.";
        }
    }
}

if (isset($_GET['sucesso'])) {
    if ($_GET['sucesso'] === 'limites')   $sucesso = "Limites por plano salvos com sucesso!";
    if ($_GET['sucesso'] === 'recursos')  $sucesso = "Controle de acesso salvo com sucesso!";
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
    $erro = "Tabela config_limites_plano não encontrada. Execute config_planos.sql primeiro.";
}

$recursosDB = [];
try {
    $recursosDB = $pdo->query("SELECT * FROM config_recursos ORDER BY ordem ASC")
        ->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
}

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

    <!-- Tabs de navegação admin -->
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
            <strong>Tabelas não encontradas.</strong> Execute o arquivo <code>config_planos.sql</code> no phpMyAdmin antes de usar esta página.
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
                        Use <strong>-1</strong> ou marque <strong>∞ Ilimitado</strong> para sem limite.
                        "Horas de teste" só se aplica ao plano Free.
                    </p>

                    <?php
                    $colunas = [
                        'transacoes_mes' => ['label' => 'Registros / mês',    'min' => 1,  'free_only' => false],
                        'carteiras'      => ['label' => 'Carteiras',           'min' => 1,  'free_only' => false],
                        'categorias'     => ['label' => 'Categorias',          'min' => 1,  'free_only' => false],
                        'parcelas_max'   => ['label' => 'Parcelas máx.',       'min' => 1,  'free_only' => false],
                        'horas_teste'    => ['label' => 'Horas de teste',      'min' => 0,  'free_only' => true],
                    ];
                    $planosLabel = ['free' => ['label' => 'Free', 'cor' => '#9ca3af'], 'pro' => ['label' => 'PRO', 'cor' => '#a78bfa'], 'vip' => ['label' => 'VIP', 'cor' => '#d4af37']];
                    ?>

                    <div class="table-responsive">
                        <table class="table table-dark table-borderless align-middle mb-0" style="font-size:0.85rem;">
                            <thead>
                                <tr style="border-bottom:1px solid #374151;">
                                    <th class="text-secondary fw-semibold" style="width:30%;">Limite</th>
                                    <?php foreach ($planosLabel as $pl => $info): ?>
                                        <th class="fw-bold text-center" style="color:<?= $info['cor'] ?>;"><?= $info['label'] ?></th>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($colunas as $campo => $cfg): ?>
                                    <tr style="border-bottom:1px solid #1f2937;">
                                        <td class="text-light fw-medium"><?= htmlspecialchars($cfg['label']) ?></td>
                                        <?php foreach (['free', 'pro', 'vip'] as $pl):
                                            $val     = $limitesDB[$pl][$campo] ?? 0;
                                            $isIlim  = ($val == -1);
                                            $disabled = ($cfg['free_only'] && $pl !== 'free') ? 'disabled' : '';
                                        ?>
                                            <td class="text-center">
                                                <?php if ($cfg['free_only'] && $pl !== 'free'): ?>
                                                    <span class="text-secondary" style="font-size:0.8rem;">—</span>
                                                <?php else: ?>
                                                    <div class="d-flex flex-column align-items-center gap-1">
                                                        <input type="number"
                                                            name="limites[<?= $pl ?>][<?= $campo ?>]"
                                                            value="<?= $isIlim ? '' : (int)$val ?>"
                                                            min="<?= $cfg['min'] ?>"
                                                            class="form-control form-control-sm text-center rounded-2"
                                                            style="width:90px;background:#111318;border:1px solid #374151;color:#e5e7eb;font-size:0.83rem;"
                                                            placeholder="<?= $isIlim ? '∞' : '' ?>"
                                                            <?= $isIlim ? 'disabled' : '' ?>>
                                                        <div class="form-check form-switch mb-0 d-flex align-items-center gap-1" style="font-size:0.75rem;">
                                                            <input class="form-check-input ilim-toggle" type="checkbox"
                                                                name="ilimitado[<?= $pl ?>][<?= $campo ?>]"
                                                                <?= $isIlim ? 'checked' : '' ?>
                                                                style="cursor:pointer;">
                                                            <label class="form-check-label text-secondary" style="cursor:pointer;">∞ Ilimitado</label>
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
            <form method="POST" action="">
                <input type="hidden" name="action" value="salvar_recursos">

                <div class="card rounded-4 border-secondary-subtle mb-4" style="background:#1c1f24;">
                    <div class="card-body p-4">
                        <h5 class="fw-bold text-light mb-1 d-flex align-items-center gap-2">
                            <i class="bi bi-key-fill" style="color:#d4af37;"></i> Controle de Acesso por Recurso
                        </h5>
                        <p class="text-secondary mb-4" style="font-size:0.83rem;">
                            Define o <strong>plano mínimo</strong> necessário para acessar cada funcionalidade.
                            Recursos com "Exibir nos planos" desativados não aparecem na página de planos.
                        </p>

                        <div class="table-responsive">
                            <table class="table table-dark table-borderless align-middle mb-0" style="font-size:0.85rem;">
                                <thead>
                                    <tr style="border-bottom:1px solid #374151;">
                                        <th class="text-secondary fw-semibold" style="width:35%;">Recurso</th>
                                        <th class="text-center fw-bold" style="color:#9ca3af;">Free</th>
                                        <th class="text-center fw-bold" style="color:#a78bfa;">PRO</th>
                                        <th class="text-center fw-bold" style="color:#d4af37;">VIP</th>
                                        <th class="text-center text-secondary fw-semibold">Exibir nos planos</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recursosDB as $r):
                                        $nivelAtual = $r['nivel_minimo'];
                                        $mostrar    = (bool)$r['mostrar_nos_planos'];
                                        $slug       = htmlspecialchars($r['slug']);
                                    ?>
                                        <tr style="border-bottom:1px solid #1f2937;">
                                            <td>
                                                <span class="text-light fw-medium"><?= htmlspecialchars($r['label']) ?></span>
                                                <br><code style="font-size:0.7rem;color:#6b7280;"><?= $slug ?></code>
                                            </td>
                                            <?php foreach (['free', 'pro', 'vip'] as $nv): ?>
                                                <td class="text-center">
                                                    <input type="radio"
                                                        name="recursos[<?= $slug ?>][nivel_minimo]"
                                                        value="<?= $nv ?>"
                                                        <?= $nivelAtual === $nv ? 'checked' : '' ?>
                                                        class="form-check-input"
                                                        style="width:1.1rem;height:1.1rem;cursor:pointer;
                                                  <?= $nv === 'free' ? 'accent-color:#9ca3af;' : ($nv === 'pro' ? 'accent-color:#a78bfa;' : 'accent-color:#d4af37;') ?>">
                                                </td>
                                            <?php endforeach; ?>
                                            <td class="text-center">
                                                <div class="form-check form-switch d-flex justify-content-center mb-0">
                                                    <input class="form-check-input" type="checkbox"
                                                        name="recursos[<?= $slug ?>][mostrar_nos_planos]"
                                                        <?= $mostrar ? 'checked' : '' ?>
                                                        style="cursor:pointer;">
                                                </div>
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
                            <i class="bi bi-floppy me-1"></i> Salvar Acessos
                        </button>
                    </div>
                </div>
            </form>
        <?php endif; ?>

    <?php endif; ?>

</main>

<script>
    // Toggle: ao marcar "∞ Ilimitado", desabilita o input numérico
    document.querySelectorAll('.ilim-toggle').forEach(function(chk) {
        const row = chk.closest('td');
        const inp = row.querySelector('input[type="number"]');
        if (!inp) return;

        chk.addEventListener('change', function() {
            inp.disabled = this.checked;
            inp.placeholder = this.checked ? '∞' : '';
            if (this.checked) inp.value = '';
        });
    });
</script>

<?php require_once '../geral/footer.php'; ?>