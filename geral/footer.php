<hr class="border-secondary-subtle my-4">

<div class="row align-items-center pb-3">
    <div class="col-md-6 text-center text-md-start m-3">
        <p class="text-light opacity-50 mb-0 small">
            &copy;
            <?php echo date('Y'); ?> Auralis. Todos os direitos reservados.
        </p>
        <p class="text-light opacity-50 mb-0 small mt-1">
            <?php
            // Procura o arquivo gerado pelo GitHub na raiz do projeto
            $arquivoVersao = __DIR__ . '/../version.txt';
            if (file_exists($arquivoVersao)) {
                $versaoAtual = trim(file_get_contents($arquivoVersao));
            } else {
                $versaoAtual = 'Modo de Desenvolvimento (Local)';
            }
            echo htmlspecialchars($versaoAtual);
            ?>
        </p>

        <a href="http://gustavoveronezi.com" target="_blank" class="text-decoration-none">
            <p class="text-light opacity-50 mb-0 small mt-1">
                Desenvolvido por <strong class="text-primary">GV Tech</strong>.
            </p>
        </a>

    </div>
    <div class="text-center mt-4 mb-2">
        <a href="termos.php" class="text-secondary small text-decoration-none me-3 hover-text-light">Termos de Uso</a>
        <a href="privacidade.php" class="text-secondary small text-decoration-none hover-text-light">Privacidade</a>
    </div>
</div>
</footer>

<script src="/geral/js/bootstrap.bundle.min.js"></script>

<script>
    document.addEventListener("DOMContentLoaded", function() {
        const observer = new IntersectionObserver((entries) => {
            entries.forEach((entry) => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('mostrar');
                }
            });
        }, {
            threshold: 0.1
        });
        const elementosOcultos = document.querySelectorAll('.card-animado');
        elementosOcultos.forEach((el) => observer.observe(el));
    });
</script>

<script>
    // ─────────────────────────────────────────────────────────────
    // PWA: Registro do Service Worker + captura do install prompt
    // ─────────────────────────────────────────────────────────────
    (function() {
        // 1. Registra o service worker
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.register('/sw.js').catch(function() {});
        }

        // 2. Captura o evento do browser antes que ele suma
        window.auralisInstallPrompt = null;
        window.addEventListener('beforeinstallprompt', function(e) {
            e.preventDefault(); // Impede o mini-infobar automático do Chrome
            window.auralisInstallPrompt = e;

            // Avisa os botões de instalação que o prompt está disponível
            document.querySelectorAll('.btn-instalar-app').forEach(function(btn) {
                btn.style.display = '';
            });

            // Mostra o modal de instalação na primeira visita (só 1x por device)
            var jaViu = localStorage.getItem('auralis_install_prompt_visto');
            var modalEl = document.getElementById('modalInstalarApp');
            if (!jaViu && modalEl) {
                // Pequeno delay para não competir com outros modais de onboarding
                setTimeout(function() {
                    var modal = new bootstrap.Modal(modalEl);
                    modal.show();
                    localStorage.setItem('auralis_install_prompt_visto', '1');
                }, 2500);
            }
        });

        // 3. Função global chamada pelos botões "Instalar"
        window.auralisInstalar = function() {
            if (!window.auralisInstallPrompt) return;
            window.auralisInstallPrompt.prompt();
            window.auralisInstallPrompt.userChoice.then(function(result) {
                window.auralisInstallPrompt = null;
                // Esconde todos os botões após a escolha (instalou ou recusou)
                document.querySelectorAll('.btn-instalar-app').forEach(function(btn) {
                    btn.closest('li') ? btn.closest('li').style.display = 'none' : btn.style.display = 'none';
                });
            });
        };

        // 4. Se já instalou como PWA, esconde tudo permanentemente
        if (window.matchMedia('(display-mode: standalone)').matches) {
            document.querySelectorAll('.btn-instalar-app').forEach(function(btn) {
                var li = btn.closest('li');
                if (li) li.style.display = 'none';
            });
        }
    })();
</script>
</body>

</html>