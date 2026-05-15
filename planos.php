<?php
session_start();
require_once 'config/conexao.php';
exigirAcessoMinimo(1);

$planoAtual  = obterPlanoAtual();
$upgrade     = $_GET['upgrade'] ?? '';
$pageTitle   = "Planos — Auralis";
$msg_upgrade = match ($upgrade) {
    'pro'  => 'Este recurso é exclusivo do <strong>Auralis PRO</strong>. Faça upgrade para desbloquear.',
    'vip'  => 'Este recurso é exclusivo do <strong>Auralis VIP</strong>. Faça upgrade para desbloquear.',
    default => '',
};

require_once 'geral/header.php';
?>
<main class="container py-5 mt-2 flex-grow-1" style="padding-inline: var(--space-page-x); max-width: 1100px;">

    <div class="text-center mb-5">
        <h1 class="fw-bold text-light mb-2">Escolha seu plano</h1>
        <p class="text-secondary" style="max-width: 520px; margin: 0 auto;">
            Novas contas ganham <strong>50 horas de Acesso Total</strong>. Aproveite para testar todos os recursos!
        </p>

        <?php if ($msg_upgrade): ?>
            <div class="alert mt-4 mx-auto" style="max-width:520px; background:#f59e0b15; border:1px solid #f59e0b40; color:#f59e0b; border-radius:0.75rem;">
                <i class="bi bi-lock-fill me-2"></i> <?php echo $msg_upgrade ?>
            </div>
        <?php endif; ?>

        <div class="d-flex align-items-center justify-content-center gap-3 mt-4">
            <span class="text-secondary" style="font-size:0.9rem;">Mensal</span>
            <div class="form-check form-switch fs-4 mb-0">
                <input class="form-check-input" type="checkbox" id="toggleAnual" role="switch">
            </div>
            <span class="text-light fw-semibold" style="font-size:0.9rem;">
                Anual <span style="background:#16a34a22;color:#22c55e;border:1px solid #22c55e44;border-radius:999px;padding:1px 8px;font-size:0.7rem;font-weight:700;margin-left:4px;">-33%</span>
            </span>
        </div>
    </div>

    <div class="row g-4 justify-content-center">

        <div class="col-12 col-md-4">
            <div class="card rounded-4 shadow-sm h-100 position-relative overflow-hidden" style="background: var(--bg-card); border: 1.5px solid #4b556366;">
                <div class="text-center py-1" style="background:#4b5563;font-size:0.7rem;font-weight:700;letter-spacing:0.08em;color:#fff;">PARA CONHECER O SISTEMA</div>
                <div class="card-body p-4 d-flex flex-column">
                    <div class="mb-4">
                        <p class="text-secondary fw-semibold mb-1 small text-uppercase">Gratuito</p>
                        <h3 class="fw-bold text-light mb-0">Free</h3>
                        <div class="mt-3">
                            <span class="fw-bold text-light" style="font-size:2rem;">R$ 0</span>
                            <span class="text-secondary">/mês</span>
                        </div>
                    </div>

                    <ul class="list-unstyled flex-grow-1 mb-4" style="font-size:0.875rem;">
                        <?php foreach (
                            [
                                ['ok', '1 carteira'],
                                ['ok', 'Até 35 registros/mês'],
                                ['ok', '13 categorias fixas'],
                                ['ok', 'Parcelamento em até 3x'],
                                ['ok', 'Dashboard básico'],
                                ['no', 'Histórico comparativo'],
                                ['no', 'Exportação PDF/Excel'],
                                ['no', 'Unificação de carteiras'],
                                ['no', 'Módulo de Cartão de Crédito'],
                                ['no', 'Compartilhamento familiar'],
                                ['no', 'Metas financeiras'],
                                ['no', 'Suporte Prioritário'],
                            ] as [$tipo, $item]
                        ): ?>
                            <li class="d-flex align-items-center gap-2 mb-2">
                                <i class="bi <?php echo $tipo === 'ok' ? 'bi-check-circle-fill text-success' : 'bi-x-circle text-secondary opacity-50' ?>"></i>
                                <span class="<?php echo $tipo === 'no' ? 'text-secondary opacity-50 text-decoration-line-through' : 'text-light' ?>"><?php echo $item ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <button class="btn w-100 rounded-pill fw-semibold" style="background:rgba(255,255,255,.06);color:#6b7280;" disabled>✓ Plano atual</button>
                </div>
            </div>
        </div>

        <div class="col-12 col-md-4">
            <div class="card rounded-4 shadow h-100 position-relative overflow-hidden" style="background: var(--bg-card); border: 1.5px solid #7c3aed88;">
                <div class="text-center py-1" style="background:#7c3aed;font-size:0.7rem;font-weight:700;letter-spacing:0.08em;color:#fff;">MAIS POPULAR</div>
                <div class="card-body p-4 d-flex flex-column">
                    <div class="mb-4">
                        <p class="fw-semibold mb-1 small text-uppercase" style="color:#a78bfa;">PRO</p>
                        <h3 class="fw-bold text-light mb-0">Auralis PRO</h3>
                        <div class="mt-3">
                            <span class="fw-bold text-light preco-mensal" style="font-size:2rem;">R$ 19,90</span>
                            <span class="fw-bold text-light preco-anual d-none" style="font-size:2rem;">R$ 14,99</span>
                            <span class="text-secondary">/mês</span>
                        </div>
                    </div>

                    <ul class="list-unstyled flex-grow-1 mb-4" style="font-size:0.875rem;">
                        <?php foreach (
                            [
                                ['ok', 'Até 3 carteiras'],
                                ['ok', 'Registros ilimitados'],
                                ['ok', 'Categorias ilimitadas'],
                                ['ok', 'Parcelamento em até 48x'],
                                ['ok', 'Dashboard completo'],
                                ['ok', 'Histórico comparativo (12m)'],
                                ['ok', 'Exportação PDF/Excel'],
                                ['ok', 'Unificação de carteiras'],
                                ['no', 'Módulo de Cartão de Crédito'],
                                ['no', 'Compartilhamento familiar'],
                                ['no', 'Metas financeiras'],
                                ['ok', 'Suporte prioritário'],
                            ] as [$tipo, $item]
                        ): ?>
                            <li class="d-flex align-items-center gap-2 mb-2">
                                <i class="bi <?php echo $tipo === 'ok' ? 'bi-check-circle-fill' : 'bi-x-circle text-secondary opacity-50' ?>" <?php echo $tipo === 'ok' ? 'style="color:#a78bfa;"' : '' ?>></i>
                                <span class="<?php echo $tipo === 'no' ? 'text-secondary opacity-50 text-decoration-line-through' : 'text-light' ?>"><?php echo $item ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <a href="URL_MERCADO_PAGO" class="btn w-100 rounded-pill fw-bold" style="background:#7c3aed;color:#fff;border:none;">Assinar PRO</a>
                </div>
            </div>
        </div>

        <div class="col-12 col-md-4">
            <div class="card rounded-4 shadow h-100 position-relative overflow-hidden" style="background: var(--bg-card); border: 1.5px solid #ca8a0466;">
                <div class="text-center py-1" style="background: linear-gradient(90deg, #ca8a04, #eab308); font-size:0.7rem;font-weight:700;letter-spacing:0.08em;color:#fff;">⭐ PARA FAMÍLIAS & EMPRESAS</div>
                <div class="card-body p-4 d-flex flex-column">
                    <div class="mb-4">
                        <p class="fw-semibold mb-1 small text-uppercase" style="color:#eab308;">VIP</p>
                        <h3 class="fw-bold text-light mb-0">Auralis VIP</h3>
                        <div class="mt-3">
                            <span class="fw-bold text-light preco-mensal" style="font-size:2rem;">R$ 29,90</span>
                            <span class="fw-bold text-light preco-anual d-none" style="font-size:2rem;">R$ 19,99</span>
                            <span class="text-secondary">/mês</span>
                        </div>
                    </div>

                    <ul class="list-unstyled flex-grow-1 mb-4" style="font-size:0.875rem;">
                        <?php foreach (
                            [
                                ['ok', 'Carteiras ilimitadas'],
                                ['ok', 'Registros ilimitados'],
                                ['ok', 'Categorias ilimitadas'],
                                ['ok', 'Parcelamento em até 48x'],
                                ['ok', 'Dashboard completo'],
                                ['ok', 'Histórico ilimitado'],
                                ['ok', 'Exportação PDF/Excel'],
                                ['ok', 'Unificação de carteiras'],
                                ['ok', 'Módulo de Cartão de Crédito'],
                                ['ok', 'Compartilhamento familiar'],
                                ['ok', 'Metas financeiras'],
                                ['ok', 'Suporte VIP dedicado'],
                            ] as [$item]
                        ): ?>
                            <li class="d-flex align-items-center gap-2 mb-2">
                                <i class="bi bi-check-circle-fill" style="color:#eab308;"></i>
                                <span class="text-light"><?php echo $item ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <a href="URL_MERCADO_PAGO" class="btn w-100 rounded-pill fw-bold" style="background:linear-gradient(90deg, #ca8a04, #eab308);color:#fff;border:none;">Assinar VIP</a>
                </div>
            </div>
        </div>

    </div>
</main>

<script>
    const toggle = document.getElementById('toggleAnual');
    const mensal = document.querySelectorAll('.preco-mensal');
    const anual = document.querySelectorAll('.preco-anual');

    toggle.addEventListener('change', function() {
        mensal.forEach(el => el.classList.toggle('d-none', this.checked));
        anual.forEach(el => el.classList.toggle('d-none', !this.checked));
    });
</script>

<?php require_once 'geral/footer.php'; ?>