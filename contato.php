<?php
session_start();
if (!isset($_SESSION['usuario_id'])) {
    header("Location: usuario/login.php");
    exit;
}
require_once 'geral/header.php';
?>

<main class="container-fluid py-4 mt-2 flex-grow-1" style="max-width:860px;padding-inline:var(--space-page-x);">

    <div class="mb-5">
        <div class="d-flex align-items-center gap-2 mb-1">
            <i class="bi bi-headset" style="color:var(--primary-gold-analysis);font-size:1.1rem;"></i>
            <h4 class="fw-bold text-light mb-0">Fale com a gente</h4>
        </div>
        <p class="text-secondary small mb-0">Suporte, dúvidas ou sugestões — estamos disponíveis pelos canais abaixo.</p>
    </div>

    <div class="row g-4">

        <!-- WhatsApp -->
        <div class="col-12 col-md-4">
            <a href="https://wa.me/5517920068599" target="_blank" rel="noopener"
               class="text-decoration-none d-block h-100 rounded-4 p-4 transition-hover"
               style="background:var(--bg-card);border:1px solid var(--card-border-color);">
                <div class="d-flex align-items-center gap-3 mb-3">
                    <div class="rounded-3 d-flex align-items-center justify-content-center flex-shrink-0"
                         style="width:46px;height:46px;background:rgba(37,211,102,0.12);border:1px solid rgba(37,211,102,0.25);">
                        <i class="bi bi-whatsapp" style="color:#25d366;font-size:1.3rem;"></i>
                    </div>
                    <div>
                        <div class="fw-bold text-light" style="font-size:0.95rem;">WhatsApp</div>
                        <div class="text-secondary" style="font-size:0.78rem;">Resposta rápida</div>
                    </div>
                </div>
                <div class="fw-semibold" style="color:#25d366;font-size:0.9rem;">+55 17 92006-8599</div>
                <div class="text-secondary mt-2" style="font-size:0.78rem;">Clique para abrir uma conversa</div>
            </a>
        </div>

        <!-- E-mail -->
        <div class="col-12 col-md-4">
            <a href="mailto:suporte@meuauralis.com"
               class="text-decoration-none d-block h-100 rounded-4 p-4 transition-hover"
               style="background:var(--bg-card);border:1px solid var(--card-border-color);">
                <div class="d-flex align-items-center gap-3 mb-3">
                    <div class="rounded-3 d-flex align-items-center justify-content-center flex-shrink-0"
                         style="width:46px;height:46px;background:rgba(var(--bs-primary-rgb),0.12);border:1px solid rgba(var(--bs-primary-rgb),0.25);">
                        <i class="bi bi-envelope-fill" style="color:var(--primary-gold-analysis);font-size:1.3rem;"></i>
                    </div>
                    <div>
                        <div class="fw-bold text-light" style="font-size:0.95rem;">E-mail</div>
                        <div class="text-secondary" style="font-size:0.78rem;">Dúvidas e suporte</div>
                    </div>
                </div>
                <div class="fw-semibold" style="color:var(--primary-gold-analysis);font-size:0.9rem;">suporte@meuauralis.com</div>
                <div class="text-secondary mt-2" style="font-size:0.78rem;">Clique para enviar um e-mail</div>
            </a>
        </div>

        <!-- Instagram -->
        <div class="col-12 col-md-4">
            <a href="https://instagram.com/meu_auralis" target="_blank" rel="noopener"
               class="text-decoration-none d-block h-100 rounded-4 p-4 transition-hover"
               style="background:var(--bg-card);border:1px solid var(--card-border-color);">
                <div class="d-flex align-items-center gap-3 mb-3">
                    <div class="rounded-3 d-flex align-items-center justify-content-center flex-shrink-0"
                         style="width:46px;height:46px;background:rgba(225,48,108,0.12);border:1px solid rgba(225,48,108,0.25);">
                        <i class="bi bi-instagram" style="color:#e1306c;font-size:1.3rem;"></i>
                    </div>
                    <div>
                        <div class="fw-bold text-light" style="font-size:0.95rem;">Instagram</div>
                        <div class="text-secondary" style="font-size:0.78rem;">Novidades e updates</div>
                    </div>
                </div>
                <div class="fw-semibold" style="color:#e1306c;font-size:0.9rem;">@meu_auralis</div>
                <div class="text-secondary mt-2" style="font-size:0.78rem;">Clique para visitar o perfil</div>
            </a>
        </div>

    </div>

    <!-- Bloco de contexto -->
    <div class="rounded-4 p-4 mt-5" style="background:var(--bg-card);border:1px solid var(--card-border-color);">
        <div class="d-flex gap-3 align-items-start">
            <i class="bi bi-info-circle-fill flex-shrink-0 mt-1" style="color:var(--primary-gold-analysis);font-size:1.1rem;"></i>
            <div>
                <div class="fw-semibold text-light mb-1">Como podemos ajudar?</div>
                <div class="text-secondary small" style="line-height:1.7;">
                    Dúvidas sobre como usar o sistema, sugestões de melhorias, problemas técnicos ou
                    curiosidade sobre novos recursos — pode mandar. Respondemos pelo WhatsApp ou e-mail
                    normalmente em até 24 horas úteis.
                </div>
            </div>
        </div>
    </div>

</main>

<?php require_once 'geral/footer.php'; ?>
