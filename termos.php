<?php
session_start();
require_once 'geral/header.php';
?>

<main class="container py-5 mt-4 flex-grow-1" style="max-width: 900px; padding-inline: var(--space-page-x);">
    <div class="card bg-body-tertiary border-secondary-subtle shadow-lg p-4 p-md-5 rounded-4">

        <div class="mb-4 d-flex align-items-center gap-3 border-bottom border-secondary-subtle pb-4">
            <div class="bg-primary bg-opacity-10 p-3 rounded-circle d-flex justify-content-center align-items-center flex-shrink-0" style="width: 60px; height: 60px;">
                <i class="bi bi-file-earmark-text text-primary fs-2" style="color: var(--primary-gold-analysis) !important;"></i>
            </div>
            <div>
                <h1 class="fw-bold text-light mb-1 fs-3">Termos de Uso</h1>
                <p class="text-secondary mb-0">Última atualização: <?php echo date('d/m/Y'); ?></p>
            </div>
        </div>

        <div class="text-light opacity-75 lh-lg" style="font-size: 0.95rem;">
            <p>Ao criar uma conta e utilizar o <strong>Auralis</strong>, o utilizador concorda com os termos e condições descritos abaixo. Leia atentamente.</p>

            <h4 class="text-light fw-bold mt-4 mb-3 fs-5">1. Natureza do Serviço</h4>
            <p>O Auralis é um Software como Serviço (SaaS) concebido para organização e gestão financeira pessoal e empresarial. O sistema atua apenas como uma ferramenta de anotação, projeção e cálculo.</p>
            <p><strong>Isenção:</strong> O Auralis não é uma instituição financeira, não realiza investimentos, e não oferece aconselhamento financeiro ou contabilístico. As decisões baseadas nos dados exibidos no sistema são da exclusiva responsabilidade do utilizador.</p>

            <h4 class="text-light fw-bold mt-4 mb-3 fs-5">2. Assinaturas e Pagamentos</h4>
            <p>O Auralis oferece um modelo <em>Freemium</em> com acesso básico gratuito e planos pagos (PRO e VIP) que desbloqueiam funcionalidades avançadas, processados através do gateway Mercado Pago.</p>
            <ul>
                <li>A ativação dos recursos premium ocorre automaticamente após a confirmação do pagamento.</li>
                <li>O cancelamento da assinatura pode ser feito a qualquer momento. O utilizador manterá os benefícios até ao final do ciclo já pago, ocorrendo posteriormente a transição automática para o plano Free.</li>
                <li>Não oferecemos reembolsos proporcionais para períodos parcialmente utilizados.</li>
            </ul>

            <h4 class="text-light fw-bold mt-4 mb-3 fs-5">3. Limitação de Responsabilidade (Garantias)</h4>
            <p>Trabalhamos arduamente para manter o sistema online 24/7 e os seus dados seguros. No entanto, serviços de tecnologia estão sujeitos a falhas externas. Portanto:</p>
            <ul>
                <li>O Auralis é fornecido "tal como está" (<em>as is</em>). Não nos responsabilizamos por multas, juros, perda de prazos de vencimento ou lucros cessantes decorrentes de eventuais interrupções no servidor (downtime), falhas na automação de contas recorrentes ou erros de digitação do próprio utilizador.</li>
                <li>É da responsabilidade do utilizador garantir a precisão dos dados inseridos para o correto funcionamento do motor de juros e parcelamentos.</li>
            </ul>

            <h4 class="text-light fw-bold mt-4 mb-3 fs-5">4. Responsabilidades do Utilizador</h4>
            <p>O utilizador concorda em:</p>
            <ul>
                <li>Manter a sua senha de acesso segura e não a partilhar com terceiros.</li>
                <li>Não utilizar o sistema para fins ilícitos, fraude ou branqueamento de capitais.</li>
            </ul>
            <p>O Auralis reserva-se o direito de suspender ou banir, sem aviso prévio, contas que violem de forma deliberada estes termos de utilização ou que tentem explorar vulnerabilidades do sistema.</p>
        </div>

        <div class="mt-5 text-center">
            <a href="javascript:history.back()" class="btn btn-outline-secondary rounded-pill px-4 transition-hover">
                <i class="bi bi-arrow-left me-2"></i> Voltar
            </a>
        </div>
    </div>
</main>

<?php require_once 'geral/footer.php'; ?>