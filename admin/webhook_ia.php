<?php
require_once '../config/sessao.php';
if (!isset($_SESSION['usuario_id'])) { header("Location: /usuario/login.php"); exit; }
require_once '../config/conexao.php';
require_once '../config/funcoes.php';
require_once '../config/permissoes.php';

exigirAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'gerar_novo') {
    regenerarWaWebhookToken($pdo);
    header("Location: webhook_ia.php?ok=1"); exit;
}

$token      = waWebhookToken($pdo);
$urlWebhook = 'https://meuauralis.com/webhook_whatsapp_ia.php?token=' . $token;
$sucesso    = isset($_GET['ok']) ? 'Token gerado! Atualize a URL no painel da Evolution API agora — o token antigo já parou de funcionar.' : null;

$pageTitle = 'Webhook da IA — Admin Auralis';
require_once '../geral/header.php';
?>
<main class="container py-4 mt-2 flex-grow-1" style="max-width:720px;padding-inline:var(--space-page-x);">

    <div class="d-flex justify-content-between align-items-center mb-4 border-bottom border-secondary-subtle pb-3 gap-3 flex-wrap">
        <a href="/dashboard.php" class="btn btn-outline-secondary btn-sm rounded-pill px-3 flex-shrink-0">
            <i class="bi bi-arrow-left me-1"></i> Voltar
        </a>
        <h1 class="h5 mb-0 text-light"><i class="bi bi-shield-lock-fill me-2" style="color:#d4af37;"></i>Webhook da IA</h1>
    </div>

    <?php if ($sucesso): ?>
    <div class="alert alert-success rounded-3 border-0 mb-4"><?= htmlspecialchars($sucesso) ?></div>
    <?php endif; ?>

    <div class="rounded-4 p-4 mb-4" style="background:#1a1d27;border:1px solid rgba(255,255,255,.08);">
        <div class="text-secondary mb-2" style="font-size:.8rem;">
            URL completa a configurar no painel da Evolution API (campo de webhook da instância):
        </div>
        <div class="d-flex align-items-center gap-2 flex-wrap">
            <code id="webhookUrl" class="px-3 py-2 rounded-3 flex-grow-1"
                style="background:rgba(0,0,0,.3);color:#d4af37;font-size:.8rem;word-break:break-all;display:block;">
                <?= htmlspecialchars($urlWebhook) ?>
            </code>
            <button onclick="copiarUrl()" id="btnCopiar"
                class="btn btn-sm rounded-pill px-3 flex-shrink-0"
                style="background:rgba(212,175,55,.15);color:#d4af37;border:1px solid rgba(212,175,55,.3);">
                <i class="bi bi-clipboard me-1"></i> Copiar
            </button>
        </div>
        <div class="text-secondary mt-3" style="font-size:.75rem;">
            Sem essa URL exata configurada lá (com o <code>?token=</code> certo), nenhuma mensagem de cliente
            é processada pela IA — o webhook rejeita com 403 antes de tocar em qualquer coisa.
        </div>
    </div>

    <div class="rounded-4 p-4" style="background:rgba(239,68,68,.05);border:1px solid rgba(239,68,68,.18);">
        <div class="fw-semibold text-light mb-1"><i class="bi bi-arrow-repeat me-2" style="color:#fca5a5;"></i>Gerar novo token</div>
        <div class="text-secondary mb-3" style="font-size:.8rem;">
            Só use se suspeitar que esse token vazou (ex: apareceu em algum lugar público sem querer).
            O token atual para de funcionar na hora — você vai precisar atualizar a URL na Evolution API de novo.
        </div>
        <form method="POST" onsubmit="return confirm('Isso invalida o token atual imediatamente. A IA para de responder até você atualizar a URL na Evolution API. Continuar?');">
            <input type="hidden" name="action" value="gerar_novo">
            <button type="submit" class="btn btn-sm rounded-pill px-3"
                style="background:rgba(239,68,68,.12);color:#fca5a5;border:1px solid rgba(239,68,68,.3);">
                <i class="bi bi-arrow-repeat me-1"></i> Gerar novo token
            </button>
        </form>
    </div>

</main>

<script>
function copiarUrl() {
    const texto = document.getElementById('webhookUrl').textContent.trim();
    navigator.clipboard.writeText(texto).then(() => {
        const btn = document.getElementById('btnCopiar');
        const original = btn.innerHTML;
        btn.innerHTML = '<i class="bi bi-check2 me-1"></i> Copiado!';
        setTimeout(() => { btn.innerHTML = original; }, 2000);
    });
}
</script>

<?php require_once '../geral/footer.php'; ?>
