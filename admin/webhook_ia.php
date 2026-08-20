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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'salvar_evolution_key') {
    $novaChave = trim($_POST['evolution_key'] ?? '');
    if ($novaChave !== '') {
        $stmtChkEv = $pdo->prepare("SELECT COUNT(*) FROM ConfiguracaoSistema WHERE Chave = 'evolution_api_key' AND FKUsuario IS NULL");
        $stmtChkEv->execute();
        if ($stmtChkEv->fetchColumn() > 0) {
            $pdo->prepare("UPDATE ConfiguracaoSistema SET Valor = :v WHERE Chave = 'evolution_api_key' AND FKUsuario IS NULL")
                ->execute([':v' => $novaChave]);
        } else {
            $pdo->prepare("INSERT INTO ConfiguracaoSistema (Chave, Valor, FKUsuario) VALUES ('evolution_api_key', :v, NULL)")
                ->execute([':v' => $novaChave]);
        }
        header("Location: webhook_ia.php?ok_key=1"); exit;
    }
}

$token      = waWebhookToken($pdo);
$urlWebhook = 'https://meuauralis.com/webhook_whatsapp_ia.php?token=' . $token;
$evolutionKeyAtual = evolutionApiKey($pdo);
$sucesso    = isset($_GET['ok']) ? 'Token gerado! Atualize a URL no painel da Evolution API agora — o token antigo já parou de funcionar.'
            : (isset($_GET['ok_key']) ? 'Chave da Evolution API salva.' : null);

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

    <div class="rounded-4 p-4 mb-4" style="background:#1a1d27;border:1px solid rgba(255,255,255,.08);">
        <div class="text-secondary mb-2" style="font-size:.8rem;">
            Chave de API da Evolution (autentica o Auralis DENTRO da Evolution API — diferente do token acima, que autentica a Evolution API dentro do Auralis):
        </div>
        <div class="mb-2" style="font-size:.8rem;">
            <?php if ($evolutionKeyAtual !== ''): ?>
                <span style="color:#86efac;"><i class="bi bi-check-circle-fill me-1"></i>Configurada</span>
                <code class="ms-2" style="color:#6b7280;font-size:.75rem;"><?= htmlspecialchars(substr($evolutionKeyAtual, 0, 4) . str_repeat('•', 8) . substr($evolutionKeyAtual, -4)) ?></code>
            <?php else: ?>
                <span style="color:#fca5a5;"><i class="bi bi-x-circle-fill me-1"></i>Não configurada — envio de WhatsApp não funciona até isso ser preenchido</span>
            <?php endif; ?>
        </div>
        <form method="POST" class="d-flex align-items-center gap-2 flex-wrap">
            <input type="hidden" name="action" value="salvar_evolution_key">
            <input type="text" name="evolution_key" placeholder="Cole a chave aqui pra definir/trocar"
                class="form-control form-control-sm rounded-3" style="max-width:340px;background:rgba(0,0,0,.3);color:#e5e7eb;border:1px solid rgba(255,255,255,.12);">
            <button type="submit" class="btn btn-sm rounded-pill px-3"
                style="background:rgba(212,175,55,.15);color:#d4af37;border:1px solid rgba(212,175,55,.3);">
                <i class="bi bi-save me-1"></i> Salvar
            </button>
        </form>
        <div class="text-secondary mt-2" style="font-size:.72rem;">
            Achada em Evolution Manager → Configurations dessa instância. Fica só no banco, nunca em arquivo de código.
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
