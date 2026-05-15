<?php
session_start();
require_once 'config/conexao.php';
exigirAcessoMinimo(1); // Garante que apenas o usuário logado veja isso

$pageTitle = "Pagamento Confirmado — Auralis";
require_once 'geral/header.php';
?>

<main class="container py-5 mt-5 flex-grow-1 d-flex flex-column align-items-center justify-content-center" style="max-width: 600px;">
    <div class="card rounded-4 shadow-lg border-0 text-center p-5 w-100" style="background: var(--bg-card); border-top: 5px solid #22c55e !important;">
        <div class="mb-4">
            <i class="bi bi-check-circle-fill text-success" style="font-size: 5rem; filter: drop-shadow(0 0 15px rgba(34, 197, 94, 0.4));"></i>
        </div>
        <h2 class="fw-bold text-light mb-3">Pagamento Aprovado!</h2>
        <p class="text-secondary mb-4 fs-5">
            Que incrível! Seu pagamento foi processado com sucesso pelo Mercado Pago. O seu plano foi atualizado e todos os recursos premium já estão liberados na sua conta.
        </p>
        
        <div class="p-3 mb-4 rounded-3" style="background: rgba(34, 197, 94, 0.1); border: 1px solid rgba(34, 197, 94, 0.2);">
            <p class="text-success fw-semibold mb-0">
                <i class="bi bi-stars me-2"></i>Bem-vindo ao próximo nível da sua gestão financeira!
            </p>
        </div>
        
        <a href="dashboard.php" class="btn btn-lg w-100 rounded-pill fw-bold text-white shadow-sm" style="background: #7c3aed; transition: 0.3s;">
            Acessar meu Dashboard PRO
        </a>
    </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/canvas-confetti@1.6.0/dist/confetti.browser.min.js"></script>
<script>
    document.addEventListener("DOMContentLoaded", function() {
        var duration = 3 * 1000;
        var animationEnd = Date.now() + duration;
        var defaults = { startVelocity: 30, spread: 360, ticks: 60, zIndex: 0 };

        function randomInRange(min, max) {
            return Math.random() * (max - min) + min;
        }

        var interval = setInterval(function() {
            var timeLeft = animationEnd - Date.now();

            if (timeLeft <= 0) {
                return clearInterval(interval);
            }

            var particleCount = 50 * (timeLeft / duration);
            confetti(Object.assign({}, defaults, { particleCount,
                origin: { x: randomInRange(0.1, 0.3), y: Math.random() - 0.2 }
            }));
            confetti(Object.assign({}, defaults, { particleCount,
                origin: { x: randomInRange(0.7, 0.9), y: Math.random() - 0.2 }
            }));
        }, 250);
    });
</script>

<?php require_once 'geral/footer.php'; ?>