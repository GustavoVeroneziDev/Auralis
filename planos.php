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
                <i class="bi bi-lock-fill me-2"></i> <?php echo $msg_upgrade ?>
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

        <!-- ── FREE ─────────────────────────────────────────────────────── -->
        <div class="col-12 col-md-4">
            <div class="card rounded-4 shadow-sm h-100 position-relative overflow-hidden"
                style="background:var(--bg-card);border:1.5px solid #4b556366;">

                <div class="text-center py-1"
                    style="background:#4b5563;font-size:0.7rem;font-weight:700;letter-spacing:0.08em;color:#fff;">
                    PARA CONHECER O SISTEMA
                </div>

                <div class="card-body p-4 d-flex flex-column">
                    <div class="mb-4">
                        <p class="text-secondary fw-semibold mb-1 small text-uppercase tracking-wide">Gratuito</p>
                        <h3 class="fw-bold text-light mb-0">Free</h3>
                        <div class="mt-3">
                            <span class="fw-bold text-light" style="font-size:2rem;">R$ 0</span>
                            <span class="text-secondary">/mês</span>
                        </div>
                        <p class="text-secondary mt-2 mb-0" style="font-size:0.85rem;">O essencial para começar a organizar.</p>
                    </div>

                    <ul class="list-unstyled flex-grow-1 mb-4" style="font-size:0.875rem;">
                        <?php foreach (
                            [
                                ['ok', '1 carteira'],
                                ['ok', 'Até 35 registros/mês'],
                                ['ok', 'Até 10 categorias'],
                                ['ok', 'Parcelamento em até 3x'],
                                ['ok', 'Dashboard com variação mensal'],
                                ['ok', 'App instalável (PWA)'],
                                ['no', 'Agenda financeira'],
                                ['no', 'Análises por categoria'],
                                ['no', 'Comprovantes e Anexos'],
                                ['no', 'Registros ilimitados'],
                                ['no', 'Parcelamento até 48x'],
                                ['no', 'Carteiras ilimitadas'],
                            ] as [$tipo, $item]
                        ): ?>
                            <li class="d-flex align-items-center gap-2 mb-2">
                                <i class="bi <?php echo $tipo === 'ok'
                                                    ? 'bi-check-circle-fill text-success'
                                                    : 'bi-x-circle text-secondary opacity-40' ?>"></i>
                                <span class="<?php echo $tipo === 'no'
                                                    ? 'text-secondary opacity-40 text-decoration-line-through'
                                                    : 'text-light' ?>">
                                    <?php echo $item ?>
                                </span>
                            </li>
                        <?php endforeach; ?>
                    </ul>

                    <?php if ($planoAtual === 'free'): ?>
                        <button class="btn w-100 rounded-pill fw-semibold"
                            style="background:rgba(255,255,255,.06);color:#6b7280;cursor:default;" disabled>
                            ✓ Plano atual
                        </button>
                    <?php else: ?>
                        <div class="btn w-100 rounded-pill fw-semibold text-secondary"
                            style="background:transparent;border:1px solid rgba(255,255,255,.1);cursor:default;">
                            Plano básico
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- ── PRO ──────────────────────────────────────────────────────── -->
        <div class="col-12 col-md-4">
            <div class="card rounded-4 shadow h-100 position-relative overflow-hidden"
                style="background:var(--bg-card);border:1.5px solid #7c3aed88;">

                <div class="text-center py-1"
                    style="background:#7c3aed;font-size:0.7rem;font-weight:700;letter-spacing:0.08em;color:#fff;">
                    MAIS POPULAR
                </div>

                <div class="card-body p-4 d-flex flex-column">
                    <div class="mb-4">
                        <p class="fw-semibold mb-1 small text-uppercase tracking-wide" style="color:#a78bfa;">PRO</p>
                        <h3 class="fw-bold text-light mb-0">Auralis PRO</h3>
                        <div class="mt-3">
                            <span class="fw-bold text-light preco-mensal" style="font-size:2rem;">R$ 19,90</span>
                            <span class="fw-bold text-light preco-anual d-none" style="font-size:2rem;">R$ 14,99</span>
                            <span class="text-secondary">/mês</span>
                        </div>
                        <p class="text-secondary mt-1 mb-0 preco-anual-info d-none" style="font-size:0.8rem;">R$ 179,90 cobrado anualmente</p>
                        <p class="text-secondary mt-2 mb-0" style="font-size:0.85rem;">Para quem leva as finanças a sério.</p>
                    </div>

                    <ul class="list-unstyled flex-grow-1 mb-4" style="font-size:0.875rem;">
                        <?php foreach (
                            [
                                ['ok', 'Até 3 carteiras'],
                                ['ok', 'Registros ilimitados'],
                                ['ok', 'Categorias ilimitadas'],
                                ['ok', 'Parcelamento em até 48x (com juros)'],
                                ['ok', 'Dashboard completo'],
                                ['ok', 'Agenda financeira'],
                                ['ok', 'Comprovantes e Anexos'],
                                ['ok', 'App instalável (PWA)'],
                                ['ok', 'Suporte prioritário'],
                                ['no', 'Carteiras ilimitadas'],
                            ] as [$tipo, $item]
                        ): ?>
                            <li class="d-flex align-items-center gap-2 mb-2">
                                <i class="bi <?php echo $tipo === 'ok'
                                                    ? 'bi-check-circle-fill'
                                                    : 'bi-x-circle text-secondary opacity-40' ?>"
                                    <?php echo $tipo === 'ok' ? 'style="color:#a78bfa;"' : '' ?>></i>
                                <span class="<?php echo $tipo === 'no'
                                                    ? 'text-secondary opacity-40 text-decoration-line-through'
                                                    : 'text-light' ?>">
                                    <?php echo $item ?>
                                </span>
                            </li>
                        <?php endforeach; ?>
                    </ul>

                    <?php if ($planoAtual === 'pro'): ?>
                        <button class="btn w-100 rounded-pill fw-semibold"
                            style="background:rgba(124,58,237,.2);color:#a78bfa;border:1px solid #7c3aed66;cursor:default;" disabled>
                            ✓ Plano atual
                        </button>
                    <?php else: ?>
                        <div class="d-flex flex-column gap-2">
                            <a href="https://www.mercadopago.com.br/subscriptions/checkout?preapproval_plan_id=9c7869b02a884962a185a44dee6c16f8"
                                target="_blank"
                                class="btn w-100 rounded-pill fw-bold preco-mensal"
                                style="background:#7c3aed;color:#fff;border:none;">
                                Assinar PRO — R$ 19,90/mês
                            </a>
                            <a href="https://www.mercadopago.com.br/subscriptions/checkout?preapproval_plan_id=98c6343b478e4efcad77ab56fe6f5948"
                                target="_blank"
                                class="btn w-100 rounded-pill fw-bold preco-anual d-none"
                                style="background:#7c3aed;color:#fff;border:none;">
                                Assinar PRO — R$ 179,90/ano
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- ── VIP ──────────────────────────────────────────────────────── -->
        <div class="col-12 col-md-4">
            <div class="card rounded-4 shadow h-100 position-relative overflow-hidden"
                style="background:var(--bg-card);border:1.5px solid #d4af3766;">

                <div class="text-center py-1"
                    style="background:linear-gradient(90deg,#AA8C2C,#d4af37);font-size:0.7rem;font-weight:700;letter-spacing:0.08em;color:#fff;">
                    ⭐ PARA FAMÍLIAS &amp; EMPREENDEDORES
                </div>

                <div class="card-body p-4 d-flex flex-column">
                    <div class="mb-4">
                        <p class="fw-semibold mb-1 small text-uppercase tracking-wide" style="color:#d4af37;">VIP</p>
                        <h3 class="fw-bold text-light mb-0">Auralis VIP</h3>
                        <div class="mt-3">
                            <span class="fw-bold text-light preco-mensal" style="font-size:2rem;">R$ 29,90</span>
                            <span class="fw-bold text-light preco-anual d-none" style="font-size:2rem;">R$ 19,99</span>
                            <span class="text-secondary">/mês</span>
                        </div>
                        <p class="text-secondary mt-1 mb-0 preco-anual-info d-none" style="font-size:0.8rem;">R$ 239,90 cobrado anualmente</p>
                        <p class="text-secondary mt-2 mb-0" style="font-size:0.85rem;">Para quem não aceita limites.</p>
                    </div>

                    <ul class="list-unstyled flex-grow-1 mb-4" style="font-size:0.875rem;">
                        <?php foreach (
                            [
                                ['ok', 'Carteiras ilimitadas'],
                                ['ok', 'Registros ilimitados'],
                                ['ok', 'Categorias ilimitadas'],
                                ['ok', 'Parcelamento em até 48x (com juros)'],
                                ['ok', 'Dashboard completo'],
                                ['ok', 'Agenda financeira'],
                                ['ok', 'Histórico ilimitado'],
                                ['ok', 'Comprovantes e Anexos'],
                                ['ok', 'App instalável (PWA)'],
                                ['ok', 'Suporte VIP dedicado'],
                            ] as [$tipo, $item]
                        ): ?>
                            <li class="d-flex align-items-center gap-2 mb-2">
                                <i class="bi bi-check-circle-fill" style="color:#d4af37;"></i>
                                <span class="text-light"><?php echo $item ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>

                    <?php if ($planoAtual === 'vip'): ?>
                        <button class="btn w-100 rounded-pill fw-semibold"
                            style="background:#d4af3720;color:#d4af37;border:1px solid #d4af3766;cursor:default;" disabled>
                            ⭐ Plano atual
                        </button>
                    <?php else: ?>
                        <div class="d-flex flex-column gap-2">
                            <a href="https://www.mercadopago.com.br/subscriptions/checkout?preapproval_plan_id=55856961da8d49d09b4ccded59a56810"
                                target="_blank"
                                class="btn w-100 rounded-pill fw-bold preco-mensal"
                                style="background:linear-gradient(90deg,#AA8C2C,#d4af37);color:#fff;border:none;font-weight:800;">
                                Assinar VIP — R$ 29,90/mês
                            </a>
                            <a href="https://www.mercadopago.com.br/subscriptions/checkout?preapproval_plan_id=3ed445df740c439884e8ebc71ddbdb69"
                                target="_blank"
                                class="btn w-100 rounded-pill fw-bold preco-anual d-none"
                                style="background:linear-gradient(90deg,#AA8C2C,#d4af37);color:#fff;border:none;font-weight:800;">
                                Assinar VIP — R$ 239,90/ano
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