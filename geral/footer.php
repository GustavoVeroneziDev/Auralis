<?php require_once __DIR__ . '/../config/vapid_keys.php'; ?>
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

function mascaraMoeda(input) {
    var v = input.value.replace(/\D/g, '');
    if (v === '') { input.value = ''; return; }
    input.value = (parseInt(v, 10) / 100).toLocaleString('pt-BR', {style: 'currency', currency: 'BRL'});
}
function parseBRL(val) {
    return parseFloat((val || '').replace(/[^\d,]/g, '').replace(',', '.')) || 0;
}

// ── WebAuthn (login por biometria) — conversão binário <-> texto pra JSON. ──
// O servidor manda challenge/ids em base64url (padrão da lib lbuchs/webauthn);
// a resposta do navigator.credentials.* volta pro servidor em base64 comum.
function waB64urlToBuf(b64url) {
    var b64 = b64url.replace(/-/g, '+').replace(/_/g, '/');
    while (b64.length % 4) b64 += '=';
    var bin = atob(b64);
    var buf = new Uint8Array(bin.length);
    for (var i = 0; i < bin.length; i++) buf[i] = bin.charCodeAt(i);
    return buf.buffer;
}
function waBufToB64(buf) {
    var bytes = new Uint8Array(buf);
    var bin = '';
    for (var i = 0; i < bytes.length; i++) bin += String.fromCharCode(bytes[i]);
    return btoa(bin);
}

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
    // Esconde a tela de carregamento (só existe/aparece no PWA instalado —
    // ver CSS @media (display-mode: standalone) no header.php)
    // ─────────────────────────────────────────────────────────────
    (function () {
        var splash = document.getElementById('auralisSplashLoading');
        if (!splash) return;
        var escondido = false;
        function esconderSplash() {
            if (escondido) return;
            escondido = true;
            splash.style.opacity = '0';
            setTimeout(function () { splash.remove(); }, 250);
        }
        window.addEventListener('load', esconderSplash);
        setTimeout(esconderSplash, 3000); // rede fraca/trava — não deixa preso pra sempre
    })();
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

<?php if (isset($_SESSION['usuario_id'])): ?>
<script>
    // ─────────────────────────────────────────────────────────────
    // Prompt automático: "Ativar notificações" — 1x por navegador/dispositivo
    // ─────────────────────────────────────────────────────────────
    window.AURALIS_VAPID_PUBLIC_KEY = <?= json_encode($vapidPublicKey) ?>;

    function auralisUrlBase64ToUint8Array(base64String) {
        var padding = '='.repeat((4 - base64String.length % 4) % 4);
        var base64  = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
        var raw     = atob(base64);
        var arr     = new Uint8Array(raw.length);
        for (var i = 0; i < raw.length; i++) arr[i] = raw.charCodeAt(i);
        return arr;
    }

    window.auralisAtivarNotificacoes = function () {
        var modalEl = document.getElementById('modalAtivarNotificacoes');
        if (!window.Notification || !('serviceWorker' in navigator) || !('PushManager' in window)) return;

        Notification.requestPermission().then(function (permissao) {
            if (modalEl) {
                var instancia = bootstrap.Modal.getInstance(modalEl);
                if (instancia) instancia.hide();
            }
            if (permissao !== 'granted') return;

            navigator.serviceWorker.ready.then(function (reg) {
                return reg.pushManager.subscribe({
                    userVisibleOnly: true,
                    applicationServerKey: auralisUrlBase64ToUint8Array(window.AURALIS_VAPID_PUBLIC_KEY)
                });
            }).then(function (sub) {
                return fetch('/notificacoes/salvar_subscription.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(sub.toJSON())
                });
            }).then(function () {
                auralisToast('Notificações ativadas!');
            }).catch(function () {});
        });
    };

    (function () {
        if (!('serviceWorker' in navigator) || !('PushManager' in window) || !window.Notification) return;
        if (Notification.permission !== 'default') return; // já respondeu antes (aceitou ou negou) — não insiste
        if (localStorage.getItem('auralis_notif_prompt_visto')) return;

        var modalEl = document.getElementById('modalAtivarNotificacoes');
        if (!modalEl) return;

        navigator.serviceWorker.ready.then(function (reg) {
            return reg.pushManager.getSubscription();
        }).then(function (sub) {
            if (sub) return; // já ativado nesse dispositivo

            setTimeout(function () {
                if (document.querySelector('.modal.show')) return; // outro modal já aberto (ex: instalar app) — não empilha
                new bootstrap.Modal(modalEl).show();
                localStorage.setItem('auralis_notif_prompt_visto', '1');
            }, 4500);
        }).catch(function () {});
    })();
</script>
<?php endif; ?>

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

<?php if (isset($_SESSION['usuario_id']) && isset($pdo)): ?>
<?php include_once __DIR__ . '/../notificacoes/_widget.php'; ?>
<?php endif; ?>

<script>
(function () {
    var SK_URL = 'auralis_scroll_url';
    var SK_POS = 'auralis_scroll_pos';

    // Antes de qualquer POST, salva a URL atual e a posição do scroll
    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (form && form.method && form.method.toLowerCase() === 'post') {
            sessionStorage.setItem(SK_URL, window.location.pathname);
            sessionStorage.setItem(SK_POS, window.scrollY);
        }
    }, true);

    // Ao carregar, restaura o scroll se a URL bater com a que foi salva
    document.addEventListener('DOMContentLoaded', function () {
        var savedUrl = sessionStorage.getItem(SK_URL);
        var savedPos = sessionStorage.getItem(SK_POS);
        if (savedUrl && savedPos !== null && savedUrl === window.location.pathname) {
            window.scrollTo(0, parseInt(savedPos, 10));
        }
        sessionStorage.removeItem(SK_URL);
        sessionStorage.removeItem(SK_POS);
    });
})();
</script>
</body>

</html>