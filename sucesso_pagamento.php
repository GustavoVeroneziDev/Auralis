<?php
/**
 * AURALIS — Página de sucesso após pagamento no Mercado Pago
 *
 * Dois fluxos possíveis de retorno:
 * 1. Cartão/assinatura (Checkout Pro): ?preapproval_id=XXX&status=authorized
 * 2. Link de pagamento fixo (Pix avulso, planos.php): ?payment_id=XXX&external_reference=XXX
 *    (o external_reference é o código de referência configurado no próprio link do MP,
 *    igual ao plan_id de MP_PLANOS)
 *
 * Em ambos os casos, consulta a API do MP de forma ativa (saída do servidor) e ativa
 * o plano imediatamente — sem depender só do webhook.
 */
session_start();
require_once 'config/conexao.php';
exigirAcessoMinimo(1);

$pageTitle     = "Pagamento Confirmado — Auralis";
$planoAtivado  = '';
$erro          = '';

$preapprovalId = $_GET['preapproval_id'] ?? '';
$status        = $_GET['status']         ?? '';
$paymentId     = $_GET['payment_id']     ?? $_GET['collection_id'] ?? '';
$externalRef   = $_GET['external_reference'] ?? '';

// ── Fluxo 1: assinatura via cartão (consulta direta à API do MP) ─────────
if ($preapprovalId && $status === 'authorized') {

    list($httpCode, $info) = mpConsultarApi(
        "https://api.mercadopago.com/preapproval/{$preapprovalId}"
    );

    if ($httpCode === 200 && !empty($info)) {
        $mpStatus  = $info['status']               ?? '';
        $planId    = $info['preapproval_plan_id']   ?? '';
        $email     = $info['payer_email']           ?? '';
        $valor     = ($info['auto_recurring']['transaction_amount'] ?? 0);

        if (in_array($mpStatus, ['authorized', 'active'])) {
            $resultado = mpAtivarPlano($pdo, $email, $planId, $preapprovalId, $valor);
            if ($resultado) {
                $planoAtivado = $resultado;
                // Atualiza a sessão imediatamente — sem precisar de novo login
                $_SESSION['plano'] = $resultado;
                unset($_SESSION['expiracao_verificada']);
                // Processa comissão do revendedor ou recompensa por indicação
                processarIndicacaoConversao($pdo, $email, (float)$valor, $resultado);
            } else {
                $erro = 'plano_nao_mapeado';
            }
        } else {
            $erro = 'status_pendente';
        }
    } else {
        $erro = 'api_falhou';
    }

// ── Fluxo 2: link de pagamento fixo — Pix avulso (sem preapproval) ───────
} elseif ($paymentId && $externalRef && isset(MP_PLANOS[$externalRef])) {

    list($httpCode, $pagamento) = mpConsultarApi(
        "https://api.mercadopago.com/v1/payments/{$paymentId}"
    );

    if ($httpCode === 200 && !empty($pagamento)) {
        $mpStatus = $pagamento['status']            ?? '';
        $email    = $pagamento['payer']['email']    ?? '';
        $valor    = $pagamento['transaction_amount'] ?? 0;

        if ($mpStatus === 'approved') {
            $resultado = mpAtivarPlano($pdo, $email, $externalRef, "pix_{$paymentId}", $valor);
            if ($resultado) {
                $planoAtivado = $resultado;
                $_SESSION['plano'] = $resultado;
                unset($_SESSION['expiracao_verificada']);
                processarIndicacaoConversao($pdo, $email, (float)$valor, $resultado);
            } else {
                $erro = 'plano_nao_mapeado';
            }
        } else {
            $erro = 'status_pendente';
        }
    } else {
        $erro = 'api_falhou';
    }
}

require_once 'geral/header.php';
?>

<main class="container py-5 mt-4 flex-grow-1 d-flex flex-column align-items-center justify-content-center" style="max-width: 640px;">

    <?php if ($planoAtivado): ?>
    <!-- ── SUCESSO ─────────────────────────────────────────────────────── -->
    <div class="card rounded-4 shadow-lg border-0 text-center p-5 w-100"
         style="background: var(--bg-card); border-top: 4px solid #22c55e !important;">
        <div class="mb-4">
            <i class="bi bi-check-circle-fill text-success"
               style="font-size: 5rem; filter: drop-shadow(0 0 18px rgba(34,197,94,.4));"></i>
        </div>
        <h2 class="fw-bold text-light mb-3">Pagamento Aprovado!</h2>
        <p class="text-secondary mb-4" style="font-size:1.05rem; line-height:1.7;">
            Seu plano <strong class="text-light">Auralis <?php echo strtoupper($planoAtivado) ?></strong>
            foi ativado agora mesmo. Todos os recursos premium já estão liberados.
        </p>

        <div class="p-3 mb-4 rounded-3" style="background:rgba(34,197,94,.08);border:1px solid rgba(34,197,94,.2);">
            <p class="text-success fw-semibold mb-0 d-flex align-items-center justify-content-center gap-2">
                <i class="bi bi-stars"></i>
                Bem-vindo ao próximo nível da sua gestão financeira!
            </p>
        </div>

        <a href="dashboard.php" class="btn btn-lg w-100 rounded-pill fw-bold"
           style="background:#7c3aed;color:#fff;border:none;padding:.875rem;">
            <i class="bi bi-speedometer2 me-2"></i>
            Acessar meu Dashboard <?php echo strtoupper($planoAtivado) ?>
        </a>
    </div>

    <?php elseif ($erro === 'status_pendente'): ?>
    <!-- ── PENDENTE ────────────────────────────────────────────────────── -->
    <div class="card rounded-4 shadow-lg border-0 text-center p-5 w-100"
         style="background: var(--bg-card); border-top: 4px solid #f59e0b !important;">
        <i class="bi bi-hourglass-split text-warning mb-4" style="font-size:4rem;"></i>
        <h2 class="fw-bold text-light mb-3">Pagamento em processamento</h2>
        <p class="text-secondary mb-4">
            Seu pagamento está sendo confirmado pelo Mercado Pago.
            Isso pode levar alguns minutos. Assim que confirmado, seu plano será ativado automaticamente.
        </p>
        <a href="dashboard.php" class="btn w-100 rounded-pill fw-bold"
           style="background:rgba(255,255,255,.08);color:#f8fafc;border:1px solid rgba(255,255,255,.1);">
            Ir para o Dashboard
        </a>
    </div>

    <?php else: ?>
    <!-- ── FALLBACK: sem preapproval_id (acesso direto à página) ──────── -->
    <div class="card rounded-4 shadow-lg border-0 text-center p-5 w-100"
         style="background: var(--bg-card); border-top: 4px solid #d4af37 !important;">
        <i class="bi bi-credit-card-2-front mb-4" style="font-size:4rem;color:#d4af37;"></i>
        <h2 class="fw-bold text-light mb-3">Assinatura recebida!</h2>
        <p class="text-secondary mb-4">
            Seu pagamento foi registrado no Mercado Pago.
            Seu plano será ativado em até <strong class="text-light">2 minutos</strong>.
            Se demorar mais, entre em contato pelo suporte.
        </p>
        <div class="d-flex flex-column gap-2">
            <a href="dashboard.php" class="btn btn-lg w-100 rounded-pill fw-bold"
               style="background:#7c3aed;color:#fff;border:none;">
                Ir para o Dashboard
            </a>
            <a href="planos.php" class="btn w-100 rounded-pill"
               style="background:transparent;color:#a1a1aa;border:1px solid rgba(255,255,255,.1);">
                Ver meus planos
            </a>
        </div>
    </div>
    <?php endif; ?>

</main>

<?php if ($planoAtivado): ?>
<script src="https://cdn.jsdelivr.net/npm/canvas-confetti@1.6.0/dist/confetti.browser.min.js"></script>
<script>
document.addEventListener("DOMContentLoaded", function () {
    var end = Date.now() + 3500;
    var colors = ['#d4af37', '#a78bfa', '#22c55e', '#f8fafc'];

    (function frame() {
        confetti({ particleCount: 3, angle: 60,  spread: 55, origin: { x: 0 }, colors });
        confetti({ particleCount: 3, angle: 120, spread: 55, origin: { x: 1 }, colors });
        if (Date.now() < end) requestAnimationFrame(frame);
    })();
});
</script>
<?php endif; ?>

<?php require_once 'geral/footer.php'; ?>
