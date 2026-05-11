<?php
session_start();
require_once 'config/conexao.php';
exigirAcessoMinimo(1);

$planoAtual  = obterPlanoAtual();
$upgrade     = $_GET['upgrade'] ?? '';
$pageTitle   = "Planos — Auralis";
$msg_upgrade = match($upgrade) {
    'pro'  => 'Este recurso é exclusivo do <strong>Auralis PRO</strong>. Faça upgrade para desbloquear.',
    'vip'  => 'Este recurso é exclusivo do <strong>Auralis VIP</strong>. Faça upgrade para desbloquear.',
    default => '',
};

require_once 'geral/header.php';
?>

<main class="container py-5 mt-2 flex-grow-1" style="padding-inline: var(--space-page-x); max-width: 1100px;">

    <!-- Topo -->
    <div class="text-center mb-5">
        <h1 class="fw-bold text-light mb-2">Escolha seu plano</h1>
        <p class="text-secondary" style="max-width: 520px; margin: 0 auto;">
            Comece de graça e evolua conforme suas necessidades. Sem surpresas na cobrança.
        </p>

        <?php if ($msg_upgrade): ?>
        <div class="alert mt-4 mx-auto" style="max-width:520px; background:#d4af3715; border:1px solid #d4af3740; color:#d4af37; border-radius:0.75rem;">
            <i class="bi bi-lock-fill me-2"></i> <?php echo $msg_upgrade ?>
        </div>
        <?php endif; ?>

        <!-- Toggle mensal / anual -->
        <div class="d-flex align-items-center justify-content-center gap-3 mt-4">
            <span class="text-secondary" id="labelMensal" style="font-size:0.9rem;">Mensal</span>
            <div class="form-check form-switch fs-4 mb-0">
                <input class="form-check-input" type="checkbox" id="toggleAnual" role="switch">
            </div>
            <span class="text-light fw-semibold" id="labelAnual" style="font-size:0.9rem;">
                Anual
                <span style="background:#16a34a22;color:#22c55e;border:1px solid #22c55e44;border-radius:999px;padding:1px 8px;font-size:0.7rem;font-weight:700;margin-left:4px;">-33%</span>
            </span>
        </div>
    </div>

    <!-- Cards de planos -->
    <div class="row g-4 justify-content-center">

        <!-- FREE -->
        <div class="col-12 col-md-4">
            <div class="card rounded-4 shadow-sm h-100 border-secondary-subtle" style="background: var(--bg-card);">
                <div class="card-body p-4 d-flex flex-column">
                    <div class="mb-4">
                        <p class="text-secondary fw-semibold mb-1 small text-uppercase tracking-wide">Gratuito</p>
                        <h3 class="fw-bold text-light mb-0">Free</h3>
                        <div class="mt-3">
                            <span class="fw-bold text-light" style="font-size:2rem;">R$ 0</span>
                            <span class="text-secondary">/mês</span>
                        </div>
                        <p class="text-secondary mt-2 mb-0" style="font-size:0.85rem;">Para quem quer começar a organizar.</p>
                    </div>

                    <ul class="list-unstyled flex-grow-1 mb-4" style="font-size:0.875rem;">
                        <?php foreach ([
                            ['ok', '1 carteira'],
                            ['ok', 'Até 35 transações/mês'],
                            ['ok', 'Categorias (até 10)'],
                            ['ok', 'Parcelamento em até 3x'],
                            ['ok', 'Dashboard com análises básicas'],
                            ['no', 'Histórico comparativo'],
                            ['no', 'Exportação de extrato'],
                            ['no', 'Carteiras ilimitadas'],
                        ] as [$tipo, $item]): ?>
                        <li class="d-flex align-items-center gap-2 mb-2">
                            <i class="bi <?php echo $tipo === 'ok' ? 'bi-check-circle-fill text-success' : 'bi-x-circle text-secondary opacity-50' ?>"></i>
                            <span class="<?php echo $tipo === 'no' ? 'text-secondary opacity-50' : 'text-light' ?>"><?php echo $item ?></span>
                        </li>
                        <?php endforeach; ?>
                    </ul>

                    <?php if ($planoAtual === 'free'): ?>
                        <button class="btn w-100 rounded-pill fw-semibold" style="background:rgba(255,255,255,.06);color:#6b7280;cursor:default;" disabled>
                            Plano atual
                        </button>
                    <?php else: ?>
                        <div class="btn w-100 rounded-pill fw-semibold text-secondary" style="background:transparent;border:1px solid rgba(255,255,255,.1);">
                            Plano básico
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- PRO -->
        <div class="col-12 col-md-4">
            <div class="card rounded-4 shadow h-100 position-relative overflow-hidden"
                style="background: var(--bg-card); border: 1.5px solid #7c3aed88;">
                <!-- Destaque -->
                <div class="text-center py-1" style="background:#7c3aed;font-size:0.7rem;font-weight:700;letter-spacing:0.08em;color:#fff;">
                    MAIS POPULAR
                </div>
                <div class="card-body p-4 d-flex flex-column">
                    <div class="mb-4">
                        <p class="fw-semibold mb-1 small text-uppercase tracking-wide" style="color:#a78bfa;">PRO</p>
                        <h3 class="fw-bold text-light mb-0">Auralis PRO</h3>
                        <div class="mt-3">
                            <span class="fw-bold text-light preco-mensal" style="font-size:2rem;">R$ 14,90</span>
                            <span class="fw-bold text-light preco-anual d-none" style="font-size:2rem;">R$ 9,92</span>
                            <span class="text-secondary">/mês</span>
                        </div>
                        <p class="text-secondary mt-1 mb-0 preco-anual-info d-none" style="font-size:0.8rem;">R$ 119,00 cobrado anualmente</p>
                        <p class="text-secondary mt-2 mb-0" style="font-size:0.85rem;">Para quem leva as finanças a sério.</p>
                    </div>

                    <ul class="list-unstyled flex-grow-1 mb-4" style="font-size:0.875rem;">
                        <?php foreach ([
                            ['ok', 'Carteiras ilimitadas'],
                            ['ok', 'Transações ilimitadas'],
                            ['ok', 'Categorias ilimitadas'],
                            ['ok', 'Parcelamento em até 48x'],
                            ['ok', 'Histórico comparativo (12 meses)'],
                            ['ok', 'Exportação de extrato PDF/Excel'],
                            ['ok', 'Suporte prioritário'],
                            ['no', 'Compartilhamento familiar'],
                            ['no', 'Módulo de cartão de crédito'],
                        ] as [$tipo, $item]): ?>
                        <li class="d-flex align-items-center gap-2 mb-2">
                            <i class="bi <?php echo $tipo === 'ok' ? 'bi-check-circle-fill' : 'bi-x-circle text-secondary opacity-50' ?>"
                               <?php echo $tipo === 'ok' ? 'style="color:#a78bfa;"' : '' ?>></i>
                            <span class="<?php echo $tipo === 'no' ? 'text-secondary opacity-50' : 'text-light' ?>"><?php echo $item ?></span>
                        </li>
                        <?php endforeach; ?>
                    </ul>

                    <?php if ($planoAtual === 'pro'): ?>
                        <button class="btn w-100 rounded-pill fw-semibold" style="background:rgba(124,58,237,.2);color:#a78bfa;border:1px solid #7c3aed66;cursor:default;" disabled>
                            ✓ Plano atual
                        </button>
                    <?php else: ?>
                        <div class="d-flex flex-column gap-2">
                            <a href="https://pay.kiwify.com.br/SEU-LINK-PRO-MENSAL"
                               target="_blank"
                               class="btn w-100 rounded-pill fw-bold preco-mensal"
                               style="background:#7c3aed;color:#fff;border:none;">
                                Assinar PRO — R$ 14,90/mês
                            </a>
                            <a href="https://pay.kiwify.com.br/SEU-LINK-PRO-ANUAL"
                               target="_blank"
                               class="btn w-100 rounded-pill fw-bold preco-anual d-none"
                               style="background:#7c3aed;color:#fff;border:none;">
                                Assinar PRO — R$ 119,00/ano
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- VIP -->
        <div class="col-12 col-md-4">
            <div class="card rounded-4 shadow h-100 position-relative overflow-hidden"
                style="background: var(--bg-card); border: 1.5px solid #d4af3766;">
                <div class="text-center py-1" style="background: linear-gradient(90deg,#AA8C2C,#d4af37); font-size:0.7rem;font-weight:700;letter-spacing:0.08em;color:#121418;">
                    ⭐ PARA FAMÍLIAS & EMPREENDEDORES
                </div>
                <div class="card-body p-4 d-flex flex-column">
                    <div class="mb-4">
                        <p class="fw-semibold mb-1 small text-uppercase tracking-wide" style="color:#d4af37;">VIP</p>
                        <h3 class="fw-bold text-light mb-0">Auralis VIP</h3>
                        <div class="mt-3">
                            <span class="fw-bold text-light preco-mensal" style="font-size:2rem;">R$ 29,90</span>
                            <span class="fw-bold text-light preco-anual d-none" style="font-size:2rem;">R$ 19,92</span>
                            <span class="text-secondary">/mês</span>
                        </div>
                        <p class="text-secondary mt-1 mb-0 preco-anual-info d-none" style="font-size:0.8rem;">R$ 239,00 cobrado anualmente</p>
                        <p class="text-secondary mt-2 mb-0" style="font-size:0.85rem;">Tudo do PRO + gestão familiar completa.</p>
                    </div>

                    <ul class="list-unstyled flex-grow-1 mb-4" style="font-size:0.875rem;">
                        <?php foreach ([
                            ['ok', 'Tudo do plano PRO'],
                            ['ok', 'Compartilhamento familiar (4 membros)'],
                            ['ok', 'Módulo de cartão de crédito'],
                            ['ok', 'Histórico ilimitado'],
                            ['ok', 'Dashboard familiar com balanço'],
                            ['ok', 'Metas financeiras'],
                            ['ok', 'Relatórios para IR (em breve)'],
                            ['ok', 'Suporte VIP dedicado'],
                        ] as [$tipo, $item]): ?>
                        <li class="d-flex align-items-center gap-2 mb-2">
                            <i class="bi bi-check-circle-fill" style="color:#d4af37;"></i>
                            <span class="text-light"><?php echo $item ?></span>
                        </li>
                        <?php endforeach; ?>
                    </ul>

                    <?php if ($planoAtual === 'vip'): ?>
                        <button class="btn w-100 rounded-pill fw-semibold" style="background:#d4af3720;color:#d4af37;border:1px solid #d4af3766;cursor:default;" disabled>
                            ⭐ Plano atual
                        </button>
                    <?php else: ?>
                        <div class="d-flex flex-column gap-2">
                            <a href="https://pay.kiwify.com.br/SEU-LINK-VIP-MENSAL"
                               target="_blank"
                               class="btn w-100 rounded-pill fw-bold preco-mensal"
                               style="background:linear-gradient(90deg,#AA8C2C,#d4af37);color:#121418;border:none;font-weight:800;">
                                Assinar VIP — R$ 29,90/mês
                            </a>
                            <a href="https://pay.kiwify.com.br/SEU-LINK-VIP-ANUAL"
                               target="_blank"
                               class="btn w-100 rounded-pill fw-bold preco-anual d-none"
                               style="background:linear-gradient(90deg,#AA8C2C,#d4af37);color:#121418;border:none;font-weight:800;">
                                Assinar VIP — R$ 239,00/ano
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

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
const anual  = document.querySelectorAll('.preco-anual');
const anualInfo = document.querySelectorAll('.preco-anual-info');

toggle.addEventListener('change', function () {
    const isAnual = this.checked;
    mensal.forEach(el => el.classList.toggle('d-none', isAnual));
    anual.forEach(el => el.classList.toggle('d-none', !isAnual));
    anualInfo.forEach(el => el.classList.toggle('d-none', !isAnual));
});
</script>

<?php require_once 'geral/footer.php'; ?>
