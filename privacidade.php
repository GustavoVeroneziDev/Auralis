<?php
session_start();
require_once 'geral/header.php';
?>

<main class="container py-5 mt-4 flex-grow-1" style="max-width: 900px; padding-inline: var(--space-page-x);">
    <div class="card bg-body-tertiary border-secondary-subtle shadow-lg p-4 p-md-5 rounded-4">

        <div class="mb-4 d-flex align-items-center gap-3 border-bottom border-secondary-subtle pb-4">
            <div class="bg-primary bg-opacity-10 p-3 rounded-circle d-flex justify-content-center align-items-center flex-shrink-0" style="width: 60px; height: 60px;">
                <i class="bi bi-shield-lock text-primary fs-2" style="color: var(--primary-gold-analysis) !important;"></i>
            </div>
            <div>
                <h1 class="fw-bold text-light mb-1 fs-3">Política de Privacidade</h1>
                <p class="text-secondary mb-0">Última atualização: <?php echo date('d/m/Y'); ?></p>
            </div>
        </div>

        <div class="text-light opacity-75 lh-lg" style="font-size: 0.95rem;">
            <p>O <strong>Auralis</strong> compromete-se a proteger a sua privacidade. Esta política explica como recolhemos, utilizamos e protegemos as suas informações pessoais e financeiras em conformidade com a Lei Geral de Proteção de Dados (LGPD).</p>

            <h4 class="text-light fw-bold mt-4 mb-3 fs-5">1. Dados Recolhidos</h4>
            <p>Recolhemos apenas as informações estritamente necessárias para o funcionamento do serviço:</p>
            <ul>
                <li><strong>Dados de Conta:</strong> Nome, endereço de e-mail e credenciais de acesso (senhas são armazenadas com criptografia de ponta a ponta). Se optar pelo login via Google (SSO), receberemos apenas o seu nome e e-mail público.</li>
                <li><strong>Dados Financeiros:</strong> Informações sobre receitas, despesas, carteiras, categorias e saldos que o utilizador insere voluntariamente no sistema.</li>
            </ul>

            <h4 class="text-light fw-bold mt-4 mb-3 fs-5">2. Uso das Informações</h4>
            <p>Os seus dados são utilizados <strong>exclusivamente</strong> para:</p>
            <ul>
                <li>Prestar o serviço de gestão financeira, gerar gráficos e projetar faturas recorrentes.</li>
                <li>Processar pagamentos de assinaturas (PRO/VIP) de forma segura.</li>
                <li>Enviar notificações essenciais sobre a sua conta (recuperação de senha, avisos de expiração).</li>
            </ul>
            <p>O Auralis <strong>não partilha, não vende e não cede</strong> os seus dados financeiros a terceiros para fins publicitários.</p>

            <h4 class="text-light fw-bold mt-4 mb-3 fs-5">3. Serviços de Terceiros</h4>
            <p>Para o funcionamento do ecossistema, utilizamos serviços de terceiros que possuem as suas próprias políticas rigorosas de segurança:</p>
            <ul>
                <li><strong>Google:</strong> Para facilidade de autenticação (Single Sign-On).</li>
                <li><strong>Mercado Pago:</strong> Para processamento de pagamentos. Nenhum dado de cartão de crédito passa ou é armazenado nos servidores do Auralis.</li>
            </ul>

            <h4 class="text-light fw-bold mt-4 mb-3 fs-5">4. Os Seus Direitos (LGPD)</h4>
            <p>O utilizador tem controlo absoluto sobre as suas informações. A qualquer momento, através do painel de <em>Configurações</em>, é possível acionar a opção de <strong>Exclusão de Conta</strong>. Esta ação é irreversível e apaga imediatamente e em cascata todos os seus registos, carteiras, transações e histórico dos nossos servidores, sem deixar vestígios.</p>

            <h4 class="text-light fw-bold mt-4 mb-3 fs-5">5. Contacto</h4>
            <p>Em caso de dúvidas sobre como tratamos os seus dados, entre em contacto com a nossa equipa de suporte.</p>
        </div>

        <div class="mt-5 text-center">
            <a href="javascript:history.back()" class="btn btn-outline-secondary rounded-pill px-4 transition-hover">
                <i class="bi bi-arrow-left me-2"></i> Voltar
            </a>
        </div>
    </div>
</main>

<?php require_once 'geral/footer.php'; ?>