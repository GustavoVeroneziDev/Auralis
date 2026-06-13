<?php
session_start();
require_once 'config/conexao.php';
exigirAcessoMinimo(1);

$planoAtual = obterPlanoAtual();
$upgrade    = $_GET['upgrade'] ?? '';
$pageTitle  = "Planos — Auralis";

if ($upgrade === 'pro') {
    $msg_upgrade = 'Este recurso é exclusivo do <strong>Auralis PRO</strong>. Faça upgrade para desbloquear.';
} elseif ($upgrade === 'vip') {
    $msg_upgrade = 'Este recurso é exclusivo do <strong>Auralis VIP</strong>. Faça upgrade para desbloquear.';
} else {
    $msg_upgrade = '';
}

// ── Carrega limites e recursos do banco ──────────────────────────────────
$limitesRaw = [];   // valores brutos do banco (com -1 para ilimitado)
try {
    $rows = $pdo->query("SELECT * FROM config_limites_plano")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) $limitesRaw[$r['plano']] = $r;
} catch (PDOException $e) {
}

// Fallback se a tabela ainda não existir
if (empty($limitesRaw)) {
    $limitesRaw = [
        'free' => ['transacoes_mes' => 35, 'carteiras' => 1,  'categorias' => 10, 'parcelas_max' => 3],
        'pro'  => ['transacoes_mes' => -1, 'carteiras' => 3,  'categorias' => -1, 'parcelas_max' => 48],
        'vip'  => ['transacoes_mes' => -1, 'carteiras' => -1, 'categorias' => -1, 'parcelas_max' => 48],
    ];
}

$recursos = recursosParaExibicao();   // ['slug', 'label', 'nivel_minimo']

// ── Helper: gera itens de limite para um card ─────────────────────────────
function _itensLimite($row)
{
    $itens = [];
    // Carteiras
    if ($row['carteiras'] == -1)     $itens[] = ['ok', 'Carteiras ilimitadas'];
    elseif ($row['carteiras'] == 1)  $itens[] = ['ok', '1 carteira'];
    else                             $itens[] = ['ok', "Até {$row['carteiras']} carteiras"];
    // Registros / mês
    if ($row['transacoes_mes'] == -1) $itens[] = ['ok', 'Registros ilimitados'];
    else                              $itens[] = ['ok', "Até {$row['transacoes_mes']} registros/mês"];
    // Categorias
    if ($row['categorias'] == -1)  $itens[] = ['ok', 'Categorias ilimitadas'];
    else                           $itens[] = ['ok', "Até {$row['categorias']} categorias"];
    // Parcelas
    if (($row['parcelas_max'] ?? 3) <= 3) $itens[] = ['ok', "Parcelamento em até {$row['parcelas_max']}x"];
    else                                  $itens[] = ['ok', "Parcelamento em até {$row['parcelas_max']}x (com juros)"];
    return $itens;
}

// ── Helper: adiciona itens de recurso (✅/❌) para um card ───────────────
function _itensRecursos($planoCarta, $recursos)
{
    $hierarquia = ['free' => 0, 'pro' => 1, 'vip' => 2];
    $nivel      = $hierarquia[$planoCarta] ?? 0;
    $itens      = [];
    foreach ($recursos as $r) {
        $minimo      = $hierarquia[$r['nivel_minimo']] ?? 0;
        $disponivel  = $nivel >= $minimo;
        $itens[]     = [$disponivel ? 'ok' : 'no', $r['label']];
    }
    return $itens;
}

require_once 'geral/header.php';
?>

<main class="container py-5 mt-2 flex-grow-1" style="padding-inline: var(--space-page-x); max-width: 1160px;">

    <!-- Topo -->
    <div class="text-center mb-5">
        <h1 class="fw-bold text-light mb-2">Escolha seu plano</h1>
        <p class="text-secondary" style="max-width: 520px; margin: 0 auto;">
            Comece de graça e evolua conforme suas necessidades. Sem surpresas na cobrança.
        </p>

        <?php if ($msg_upgrade): ?>
            <div class="alert mt-4 mx-auto" style="max-width:520px;background:#d4af3715;border:1px solid #d4af3740;color:#d4af37;border-radius:0.75rem;">
                <i class="bi bi-lock-fill me-2"></i> <?= $msg_upgrade ?>
            </div>
        <?php endif; ?>

        <!-- Toggle mensal / anual -->
        <div class="d-flex align-items-center justify-content-center gap-3 mt-4">
            <span class="text-secondary" style="font-size:0.9rem;">Mensal</span>
            <div class="form-check form-switch fs-4 mb-0">
                <input class="form-check-input" type="checkbox" id="toggleAnual" role="switch">
            </div>
            <span class="text-light fw-semibold" style="font-size:0.9rem;">
                Anual
                <span style="background:#16a34a22;color:#22c55e;border:1px solid #22c55e44;border-radius:999px;padding:1px 8px;font-size:0.7rem;font-weight:700;margin-left:4px;">-33%</span>
            </span>
        </div>
    </div>

    <!-- Cards -->
    <div class="row g-4 justify-content-center align-items-stretch">

        <?php
        // ── Configuração estática de cada card ─────────────────────────────
        $cards = [
            'free' => [
                'topo_bg'     => '#4b5563',
                'topo_label'  => 'PARA CONHECER O SISTEMA',
                'border'      => '#4b556366',
                'label_plano' => 'Gratuito',
                'nome'        => 'Free',
                'preco_m'     => 'R$ 0',
                'preco_a'     => null,
                'subtitulo'   => 'O essencial para começar a organizar.',
                'icone_cor'   => 'text-success',
                'icone_cor_ok' => '',
                'btn_atual'   => 'background:rgba(255,255,255,.06);color:#6b7280;',
                'btn_basico'  => 'Plano básico',
                'btn_m_href'  => null,
                'btn_a_href'  => null,
            ],
            'pro' => [
                'topo_bg'     => '#7c3aed',
                'topo_label'  => 'MAIS POPULAR',
                'border'      => '#7c3aed88',
                'label_plano' => 'PRO',
                'nome'        => 'Auralis PRO',
                'preco_m'     => 'R$ 19,90',
                'preco_a'     => 'R$ 14,99',
                'preco_a_info' => 'R$ 179,90 cobrado anualmente',
                'subtitulo'   => 'Para quem leva as finanças a sério.',
                'label_cor'   => '#a78bfa',
                'icone_cor'   => '',
                'icone_cor_ok' => 'style="color:#a78bfa;"',
                'btn_atual'   => 'background:rgba(124,58,237,.2);color:#a78bfa;border:1px solid #7c3aed66;',
                'btn_basico'  => null,
                'btn_m_text'  => 'Assinar PRO — R$ 19,90/mês',
                'btn_a_text'  => 'Assinar PRO — R$ 179,90/ano',
                'btn_m_style' => 'background:#7c3aed;color:#fff;border:none;',
                'btn_a_style' => 'background:#7c3aed;color:#fff;border:none;',
                'btn_m_href'  => 'https://www.mercadopago.com.br/subscriptions/checkout?preapproval_plan_id=9c7869b02a884962a185a44dee6c16f8',
                'btn_a_href'  => 'https://www.mercadopago.com.br/subscriptions/checkout?preapproval_plan_id=98c6343b478e4efcad77ab56fe6f5948',
            ],
            'vip' => [
                'topo_bg'     => 'linear-gradient(90deg,#AA8C2C,#d4af37)',
                'topo_label'  => '⭐ PARA FAMÍLIAS &amp; EMPREENDEDORES',
                'border'      => '#d4af3766',
                'label_plano' => 'VIP',
                'nome'        => 'Auralis VIP',
                'preco_m'     => 'R$ 29,90',
                'preco_a'     => 'R$ 19,99',
                'preco_a_info' => 'R$ 239,90 cobrado anualmente',
                'subtitulo'   => 'Para quem não aceita limites.',
                'label_cor'   => '#d4af37',
                'icone_cor'   => '',
                'icone_cor_ok' => 'style="color:#d4af37;"',
                'btn_atual'   => 'background:#d4af3720;color:#d4af37;border:1px solid #d4af3766;',
                'btn_basico'  => null,
                'btn_m_text'  => 'Assinar VIP — R$ 29,90/mês',
                'btn_a_text'  => 'Assinar VIP — R$ 239,90/ano',
                'btn_m_style' => 'background:linear-gradient(90deg,#AA8C2C,#d4af37);color:#fff;border:none;font-weight:800;',
                'btn_a_style' => 'background:linear-gradient(90deg,#AA8C2C,#d4af37);color:#fff;border:none;font-weight:800;',
                'btn_m_href'  => 'https://www.mercadopago.com.br/subscriptions/checkout?preapproval_plan_id=55856961da8d49d09b4ccded59a56810',
                'btn_a_href'  => 'https://www.mercadopago.com.br/subscriptions/checkout?preapproval_plan_id=3ed445df740c439884e8ebc71ddbdb69',
            ],
        ];

        foreach ($cards as $slug => $c):
            $row   = $limitesRaw[$slug] ?? $limitesRaw['free'];
            $itens = array_merge(_itensLimite($row), _itensRecursos($slug, $recursos));
        ?>
            <div class="col-12 col-md-4">
                <div class="card rounded-4 shadow-sm h-100 position-relative overflow-hidden"
                    style="background:var(--bg-card);border:1.5px solid <?= $c['border'] ?>;">

                    <div class="text-center py-1"
                        style="background:<?= $c['topo_bg'] ?>;font-size:0.7rem;font-weight:700;letter-spacing:0.08em;color:#fff;">
                        <?= $c['topo_label'] ?>
                    </div>

                    <div class="card-body p-4 d-flex flex-column">
                        <div class="mb-4">
                            <p class="fw-semibold mb-1 small text-uppercase tracking-wide"
                                <?= isset($c['label_cor']) ? "style=\"color:{$c['label_cor']};\"" : 'class="text-secondary"' ?>>
                                <?= htmlspecialchars($c['label_plano']) ?>
                            </p>
                            <h3 class="fw-bold text-light mb-0"><?= htmlspecialchars($c['nome']) ?></h3>
                            <div class="mt-3">
                                <span class="fw-bold text-light preco-mensal" style="font-size:2rem;"><?= $c['preco_m'] ?></span>
                                <?php if ($c['preco_a'] ?? null): ?>
                                    <span class="fw-bold text-light preco-anual d-none" style="font-size:2rem;"><?= $c['preco_a'] ?></span>
                                <?php endif; ?>
                                <span class="text-secondary">/mês</span>
                            </div>
                            <?php if ($c['preco_a'] ?? null): ?>
                                <p class="text-secondary mt-1 mb-0 preco-anual-info d-none" style="font-size:0.8rem;"><?= $c['preco_a_info'] ?></p>
                            <?php endif; ?>
                            <p class="text-secondary mt-2 mb-0" style="font-size:0.85rem;"><?= htmlspecialchars($c['subtitulo']) ?></p>
                        </div>

                        <ul class="list-unstyled flex-grow-1 mb-4" style="font-size:0.875rem;">
                            <?php foreach ($itens as [$tipo, $item]): ?>
                                <li class="d-flex align-items-center gap-2 mb-2">
                                    <i class="bi <?= $tipo === 'ok'
                                                        ? 'bi-check-circle-fill ' . $c['icone_cor']
                                                        : 'bi-x-circle text-secondary opacity-40' ?>"
                                        <?= ($tipo === 'ok' && $c['icone_cor_ok']) ? $c['icone_cor_ok'] : '' ?>></i>
                                    <span class="<?= $tipo === 'no'
                                                        ? 'text-secondary opacity-40 text-decoration-line-through'
                                                        : 'text-light' ?>">
                                        <?= htmlspecialchars($item) ?>
                                    </span>
                                </li>
                            <?php endforeach; ?>
                        </ul>

                        <?php if ($planoAtual === $slug): ?>
                            <button class="btn w-100 rounded-pill fw-semibold"
                                style="<?= $c['btn_atual'] ?>cursor:default;" disabled>
                                <?= $slug === 'vip' ? '⭐' : '✓' ?> Plano atual
                            </button>
                        <?php elseif ($c['btn_basico'] ?? null): ?>
                            <div class="btn w-100 rounded-pill fw-semibold text-secondary"
                                style="background:transparent;border:1px solid rgba(255,255,255,.1);cursor:default;">
                                <?= htmlspecialchars($c['btn_basico']) ?>
                            </div>
                        <?php else: ?>
                            <div class="d-flex flex-column gap-2">
                                <a href="<?= $c['btn_m_href'] ?>" target="_blank"
                                    class="btn w-100 rounded-pill fw-bold preco-mensal"
                                    style="<?= $c['btn_m_style'] ?>">
                                    <?= htmlspecialchars($c['btn_m_text']) ?>
                                </a>
                                <a href="<?= $c['btn_a_href'] ?>" target="_blank"
                                    class="btn w-100 rounded-pill fw-bold preco-anual d-none"
                                    style="<?= $c['btn_a_style'] ?>">
                                    <?= htmlspecialchars($c['btn_a_text']) ?>
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>

    </div>

    <!-- Garantia -->
    <div class="text-center mt-5 text-secondary" style="font-size:0.85rem;">
        <i class="bi bi-shield-check me-1"></i>
        Pagamento seguro. Cancele quando quiser. Sem fidelidade.
    </div>

</main>

<script>
    const toggle = document.getElementById('toggleAnual');
    const mensal = document.querySelectorAll('.preco-mensal');
    const anual = document.querySelectorAll('.preco-anual');
    const anualInfo = document.querySelectorAll('.preco-anual-info');

    toggle.addEventListener('change', function() {
        const isAnual = this.checked;
        mensal.forEach(el => el.classList.toggle('d-none', isAnual));
        anual.forEach(el => el.classList.toggle('d-none', !isAnual));
        anualInfo.forEach(el => el.classList.toggle('d-none', !isAnual));
    });
</script>

<?php require_once 'geral/footer.php'; ?>