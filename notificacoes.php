<?php
session_start();
if (!isset($_SESSION['usuario_id'])) {
    header("Location: usuario/login.php");
    exit;
}
require_once 'config/conexao.php';
require_once 'config/vapid_keys.php';

$usuario_id = $_SESSION['usuario_id'];

// Já tem alguma subscription de push ativa? (usado só pro estado inicial do botão)
$_temPushAtivo = false;
try {
    $stmtPush = $pdo->prepare("SELECT 1 FROM PushSubscription WHERE FKUsuario = :uid LIMIT 1");
    $stmtPush->execute([':uid' => $usuario_id]);
    $_temPushAtivo = (bool)$stmtPush->fetchColumn();
} catch (PDOException $e) {
    // Tabela ainda não migrada nesse ambiente — trata como "sem push"
}

require_once 'geral/header.php';
?>

<main class="container-fluid py-4 mt-2 flex-grow-1" style="max-width:860px;padding-inline:var(--space-page-x);">

    <div class="mb-5">
        <div class="d-flex align-items-center gap-2 mb-1">
            <i class="bi bi-bell-fill" style="color:var(--primary-gold-analysis);font-size:1.1rem;"></i>
            <h4 class="fw-bold text-light mb-0">Notificações</h4>
        </div>
        <p class="text-secondary small mb-0">Central de notificações do Auralis.</p>
    </div>

    <div class="row g-4">

        <!-- NOTIFICAÇÕES NO NAVEGADOR / CELULAR -->
        <div class="col-12">
            <div class="card border-secondary-subtle shadow-sm rounded-4" style="background:var(--bg-card);">
                <div class="card-header border-bottom border-secondary-subtle bg-transparent p-4">
                    <h5 class="fw-bold mb-0">
                        <i class="bi bi-bell-fill me-2" style="color:var(--accent);"></i> Notificações no Navegador
                    </h5>
                </div>
                <div class="card-body p-4">
                    <div class="text-secondary small mb-3">
                        Receba avisos de contas a vencer direto na tela de notificações do celular ou computador — mesmo com o Auralis fechado.
                    </div>

                    <div id="pushNaoSuportado" style="display:none;">
                        <div class="d-flex align-items-center gap-2 text-secondary small">
                            <i class="bi bi-exclamation-circle"></i> Seu navegador não suporta esse recurso.
                        </div>
                    </div>

                    <div id="pushPrecisaInstalar" style="display:none;">
                        <div class="d-flex align-items-center gap-2 text-secondary small">
                            <i class="bi bi-info-circle"></i> No iPhone, primeiro instale o Auralis na tela de início (Configurações → Instalar como Aplicativo) — depois volte aqui pra ativar.
                        </div>
                    </div>

                    <div id="pushAtivo" style="display:<?= $_temPushAtivo ? '' : 'none' ?>;">
                        <div class="d-flex align-items-center gap-3 flex-wrap">
                            <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0"
                                 style="width:48px;height:48px;background:rgba(16,185,129,0.12);border:1px solid rgba(16,185,129,0.3);">
                                <i class="bi bi-check-circle-fill" style="color:#10b981;font-size:1.3rem;"></i>
                            </div>
                            <div class="flex-grow-1">
                                <div class="fw-semibold text-light">Notificações ativadas neste dispositivo</div>
                                <div class="text-secondary small">Você vai receber avisos de vencimento por aqui.</div>
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill" id="btnDesativarPush">Desativar</button>
                        </div>
                    </div>

                    <div id="pushInativo" style="display:<?= $_temPushAtivo ? 'none' : '' ?>;">
                        <button type="button" class="btn fw-bold text-dark rounded-pill px-4 py-2" id="btnAtivarPush"
                                style="background:linear-gradient(135deg,#FFB800,#D4AF37);font-size:0.9rem;">
                            <i class="bi bi-bell me-2"></i> Ativar Notificações
                        </button>
                    </div>

                </div>
            </div>
        </div>

    </div>

</main>

<script>
// ─────────────────────────────────────────────────────────────
// Notificações Web Push — ativar/desativar neste dispositivo
// ─────────────────────────────────────────────────────────────
(function () {
    var VAPID_PUBLIC_KEY = <?= json_encode($vapidPublicKey) ?>;

    var elNaoSuportado    = document.getElementById('pushNaoSuportado');
    var elPrecisaInstalar = document.getElementById('pushPrecisaInstalar');
    var elAtivo           = document.getElementById('pushAtivo');
    var elInativo         = document.getElementById('pushInativo');
    var btnAtivar         = document.getElementById('btnAtivarPush');
    var btnDesativar      = document.getElementById('btnDesativarPush');
    if (!elAtivo) return;

    function mostrarEstado(estado) {
        elNaoSuportado.style.display    = estado === 'nao-suportado'    ? '' : 'none';
        elPrecisaInstalar.style.display = estado === 'precisa-instalar' ? '' : 'none';
        elAtivo.style.display           = estado === 'ativo'            ? '' : 'none';
        elInativo.style.display         = estado === 'inativo'          ? '' : 'none';
    }

    function urlBase64ToUint8Array(base64String) {
        var padding = '='.repeat((4 - base64String.length % 4) % 4);
        var base64  = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
        var raw     = atob(base64);
        var arr     = new Uint8Array(raw.length);
        for (var i = 0; i < raw.length; i++) arr[i] = raw.charCodeAt(i);
        return arr;
    }

    var isIOS        = /iPad|iPhone|iPod/.test(navigator.userAgent) && !window.MSStream;
    var isStandalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;

    if (!('serviceWorker' in navigator) || !('PushManager' in window) || !window.Notification) {
        mostrarEstado(isIOS && !isStandalone ? 'precisa-instalar' : 'nao-suportado');
        return;
    }

    navigator.serviceWorker.ready.then(function (reg) {
        return reg.pushManager.getSubscription();
    }).then(function (sub) {
        mostrarEstado(sub ? 'ativo' : 'inativo');
    }).catch(function () {
        mostrarEstado('inativo');
    });

    if (btnAtivar) {
        btnAtivar.addEventListener('click', function () {
            btnAtivar.disabled = true;
            Notification.requestPermission().then(function (permissao) {
                if (permissao !== 'granted') {
                    auralisToast('Permissão de notificação negada.');
                    btnAtivar.disabled = false;
                    return;
                }
                navigator.serviceWorker.ready.then(function (reg) {
                    return reg.pushManager.subscribe({
                        userVisibleOnly: true,
                        applicationServerKey: urlBase64ToUint8Array(VAPID_PUBLIC_KEY)
                    });
                }).then(function (sub) {
                    return fetch('/notificacoes/salvar_subscription.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify(sub.toJSON())
                    });
                }).then(function (r) { return r.json(); })
                .then(function (data) {
                    btnAtivar.disabled = false;
                    if (data && data.ok) {
                        mostrarEstado('ativo');
                        auralisToast('Notificações ativadas!');
                    } else {
                        auralisToast('Não deu pra ativar agora. Tenta de novo.');
                    }
                }).catch(function () {
                    btnAtivar.disabled = false;
                    auralisToast('Não deu pra ativar agora. Tenta de novo.');
                });
            });
        });
    }

    if (btnDesativar) {
        btnDesativar.addEventListener('click', function () {
            btnDesativar.disabled = true;
            navigator.serviceWorker.ready.then(function (reg) {
                return reg.pushManager.getSubscription();
            }).then(function (sub) {
                if (!sub) return null;
                var endpoint = sub.endpoint;
                return sub.unsubscribe().then(function () {
                    return fetch('/notificacoes/remover_subscription.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ endpoint: endpoint })
                    });
                });
            }).then(function () {
                btnDesativar.disabled = false;
                mostrarEstado('inativo');
                auralisToast('Notificações desativadas.');
            }).catch(function () {
                btnDesativar.disabled = false;
            });
        });
    }
})();
</script>

<?php require_once 'geral/footer.php'; ?>
