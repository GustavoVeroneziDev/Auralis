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

<!-- Toast de sucesso global -->
<div class="position-fixed bottom-0 end-0 p-3" style="z-index:1090;pointer-events:none;">
    <div id="auralisToastEl" class="toast border-0 shadow-lg" role="status" aria-live="polite" aria-atomic="true" data-bs-delay="3000" style="background:var(--bg-card);border:1px solid var(--card-border-color) !important;pointer-events:auto;">
        <div class="d-flex align-items-center gap-2 px-3 py-2">
            <i class="bi bi-check-circle-fill flex-shrink-0" style="color:var(--accent);font-size:1rem;"></i>
            <span id="auralisToastMsg" class="fw-semibold" style="font-size:0.9rem;color:var(--text-main);"></span>
        </div>
    </div>
</div>
<script>
function auralisToast(msg) {
    var el = document.getElementById('auralisToastEl');
    var msgEl = document.getElementById('auralisToastMsg');
    if (!el || !msgEl) return;
    msgEl.textContent = msg;
    bootstrap.Toast.getOrCreateInstance(el).show();
}
if (window._pendingToast) auralisToast(window._pendingToast);

// Salvar posição de scroll antes de qualquer POST
(function() {
    var key = 'auralis_scroll_' + location.pathname;
    document.addEventListener('submit', function(e) {
        if (e.target.method && e.target.method.toLowerCase() === 'post') {
            sessionStorage.setItem(key, window.scrollY);
        }
    }, true);
})();
</script>

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
    // PWA: Service Worker + detecção de plataforma + install prompt
    // ─────────────────────────────────────────────────────────────
    (function() {
        var ua = navigator.userAgent;
        var isIOS        = /iPad|iPhone|iPod/.test(ua) && !window.MSStream;
        var isAndroid    = /Android/.test(ua);
        var isMobile     = isIOS || isAndroid || window.innerWidth < 768;
        var isStandalone = window.matchMedia('(display-mode: standalone)').matches
                        || window.navigator.standalone === true;

        // ── Atualiza o conteúdo do modal conforme o dispositivo ──────────
        window.atualizarModalInstalar = function(tipo) {
            var icon   = document.getElementById('installModalIcon');
            var title  = document.getElementById('installModalTitle');
            var desc   = document.getElementById('installModalDesc');
            var action = document.getElementById('installModalAction');
            if (!icon || !title || !desc || !action) return;

            if (tipo === 'ios') {
                icon.className  = 'bi bi-box-arrow-up';
                title.textContent = 'Instale no iPhone / iPad';
                desc.innerHTML  = 'Adicione o Auralis à tela inicial para acesso rápido, sem abrir o navegador.';
                action.innerHTML = `
                    <div class="text-start p-3 rounded-3 mb-3" style="background:rgba(255,255,255,0.05);font-size:0.87rem;line-height:1.8;">
                        <div class="d-flex align-items-center gap-2 mb-2 text-light">
                            <span class="fw-bold d-flex align-items-center justify-content-center rounded-circle text-dark flex-shrink-0"
                                  style="background:#d4af37;width:22px;height:22px;font-size:0.75rem;">1</span>
                            Toque em <i class="bi bi-box-arrow-up mx-1" style="color:#d4af37;font-size:1rem;"></i> <strong>Compartilhar</strong>
                        </div>
                        <div class="d-flex align-items-center gap-2 text-light">
                            <span class="fw-bold d-flex align-items-center justify-content-center rounded-circle text-dark flex-shrink-0"
                                  style="background:#d4af37;width:22px;height:22px;font-size:0.75rem;">2</span>
                            Toque em <strong class="ms-1">"Adicionar à Tela de Início"</strong>
                        </div>
                    </div>`;
            } else if (tipo === 'desktop') {
                icon.className  = 'bi bi-display';
                title.textContent = 'Instale o Auralis no PC';
                desc.innerHTML  = 'Abra o Auralis como aplicativo na sua área de trabalho — acesso rápido, janela dedicada, sem abas do navegador.';
                action.innerHTML = `
                    <button onclick="auralisInstalar(); bootstrap.Modal.getInstance(document.getElementById('modalInstalarApp')).hide();"
                        class="btn w-100 fw-bold text-dark rounded-pill py-3 mb-3 shadow-lg"
                        style="background:linear-gradient(135deg,#FFB800 0%,#D4AF37 100%);font-size:0.95rem;">
                        <i class="bi bi-download me-2"></i> Instalar no PC
                    </button>`;
            } else {
                // Android / padrão
                icon.className  = 'bi bi-phone';
                title.textContent = 'Instale o Auralis';
                desc.innerHTML  = 'Acesse suas finanças direto da tela inicial — sem abrir o navegador, sem digitar endereço. Rápido como um app nativo.';
                action.innerHTML = `
                    <button onclick="auralisInstalar(); bootstrap.Modal.getInstance(document.getElementById('modalInstalarApp')).hide();"
                        class="btn w-100 fw-bold text-dark rounded-pill py-3 mb-3 shadow-lg"
                        style="background:linear-gradient(135deg,#FFB800 0%,#D4AF37 100%);font-size:0.95rem;">
                        <i class="bi bi-download me-2"></i> Instalar Agora
                    </button>`;
            }
        };

        // ── Registra o Service Worker ────────────────────────────────────
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.register('/sw.js').catch(function() {});
        }

        // ── Já está instalado como PWA → esconde tudo ────────────────────
        if (isStandalone) {
            document.querySelectorAll('.btn-instalar-app').forEach(function(btn) {
                var li = btn.closest('li');
                if (li) li.style.display = 'none';
            });
            return; // nada mais a fazer
        }

        // ── iOS: beforeinstallprompt nunca dispara; mostramos instruções manuais ──
        if (isIOS) {
            document.querySelectorAll('.btn-instalar-app').forEach(function(btn) {
                btn.style.display = '';
            });
            var jaViu = localStorage.getItem('auralis_install_prompt_visto');
            var modalEl = document.getElementById('modalInstalarApp');
            if (!jaViu && modalEl) {
                setTimeout(function() {
                    window.atualizarModalInstalar('ios');
                    new bootstrap.Modal(modalEl).show();
                    localStorage.setItem('auralis_install_prompt_visto', '1');
                }, 2500);
            }
        }

        // ── Chrome / Android / Edge: captura o evento nativo ────────────
        window.auralisInstallPrompt = null;
        window.addEventListener('beforeinstallprompt', function(e) {
            e.preventDefault();
            window.auralisInstallPrompt = e;

            document.querySelectorAll('.btn-instalar-app').forEach(function(btn) {
                btn.style.display = '';
            });

            var jaViu = localStorage.getItem('auralis_install_prompt_visto');
            var modalEl = document.getElementById('modalInstalarApp');
            if (!jaViu && modalEl) {
                setTimeout(function() {
                    window.atualizarModalInstalar(isMobile ? 'mobile' : 'desktop');
                    new bootstrap.Modal(modalEl).show();
                    localStorage.setItem('auralis_install_prompt_visto', '1');
                }, 2500);
            }
        });

        // ── Função global chamada pelos botões "Instalar" ────────────────
        window.auralisInstalar = function() {
            if (isIOS) {
                // iOS não tem prompt nativo — mostra modal com instruções
                var modalEl = document.getElementById('modalInstalarApp');
                if (modalEl) {
                    window.atualizarModalInstalar('ios');
                    new bootstrap.Modal(modalEl).show();
                }
                return;
            }
            if (!window.auralisInstallPrompt) return;
            window.auralisInstallPrompt.prompt();
            window.auralisInstallPrompt.userChoice.then(function() {
                window.auralisInstallPrompt = null;
                document.querySelectorAll('.btn-instalar-app').forEach(function(btn) {
                    btn.closest('li') ? btn.closest('li').style.display = 'none' : btn.style.display = 'none';
                });
            });
        };
    })();
</script>
<!-- Sidebar JS -->
<?php if (isset($_useSidebar) && $_useSidebar): ?>
<script>
(function(){
    var sidebar  = document.getElementById('auralis-sidebar');
    var overlay  = document.getElementById('sidebarOverlay');
    var toggle   = document.getElementById('sidebarToggle');
    var mToggle  = document.getElementById('sidebarMobileToggle');
    var KEY      = 'auralis_sidebar';

    if (!sidebar) return;

    // Mobile: open/close
    function openSidebar() {
        sidebar.classList.add('mobile-open');
        overlay.classList.add('active');
    }
    function closeSidebar() {
        sidebar.classList.remove('mobile-open');
        overlay.classList.remove('active');
    }
    if (mToggle) mToggle.addEventListener('click', openSidebar);
    if (overlay) overlay.addEventListener('click', closeSidebar);

    // Desktop: collapse/expand — no mobile fecha a sidebar em vez de colapsar
    function isMobile() { return window.innerWidth < 992; }

    if (toggle) {
        // Adapta ícone para mobile ao carregar
        if (isMobile()) {
            var mIcon = toggle.querySelector('i');
            if (mIcon) mIcon.className = 'bi bi-chevron-left';
        }

        toggle.addEventListener('click', function() {
            if (isMobile()) {
                closeSidebar();
                return;
            }
            var collapsed = sidebar.classList.toggle('sidebar-collapsed');
            localStorage.setItem(KEY, collapsed ? 'collapsed' : 'expanded');
            toggle.querySelector('i').className = collapsed
                ? 'bi bi-layout-sidebar'
                : 'bi bi-layout-sidebar-reverse';
        });
        // Sync icon with initial state (desktop)
        if (!isMobile() && sidebar.classList.contains('sidebar-collapsed')) {
            var icon = toggle.querySelector('i');
            if (icon) icon.className = 'bi bi-layout-sidebar';
        }
    }

    // Fecha sidebar ao redimensionar para desktop (evita ficar preso em mobile-open)
    window.addEventListener('resize', function() {
        if (!isMobile() && toggle) {
            var dIcon = toggle.querySelector('i');
            if (dIcon && dIcon.className === 'bi bi-chevron-left') {
                dIcon.className = sidebar.classList.contains('sidebar-collapsed')
                    ? 'bi bi-layout-sidebar'
                    : 'bi bi-layout-sidebar-reverse';
            }
        }
        if (isMobile() && toggle) {
            var mIcon2 = toggle.querySelector('i');
            if (mIcon2) mIcon2.className = 'bi bi-chevron-left';
        }
    });
})();
</script>
<?php endif; ?>

<?php if (isset($_useSidebar) && $_useSidebar): ?>
    </div><!-- /auralis-content -->
<?php endif; ?>
</body>

</html>