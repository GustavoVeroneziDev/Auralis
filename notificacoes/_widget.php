<?php
// Notification widget — included by geral/footer.php for logged-in users
// Requires: $pdo (global), $_SESSION['usuario_id'], $_SESSION['plano']
if (!isset($_SESSION['usuario_id']) || !isset($pdo)) return;

$_nw_uid   = $_SESSION['usuario_id'];
$_nw_plano = strtolower($_SESSION['plano'] ?? 'free');

$_nw_items = [];
try {
    $stmt = $pdo->prepare("
        SELECT n.IDNotificacao, n.Titulo, n.DataCriacao, n.Conteudo,
               n.TipoInteracao, n.ItensPesquisa,
               CASE WHEN nl.FKNotificacao IS NOT NULL THEN 1 ELSE 0 END AS Lida,
               CASE WHEN nr.IDNotificacaoResposta IS NOT NULL THEN 1 ELSE 0 END AS Respondida
        FROM Notificacao n
        LEFT JOIN NotificacaoLeitura nl
               ON nl.FKNotificacao = n.IDNotificacao AND nl.FKUsuario = :uid
        LEFT JOIN NotificacaoResposta nr
               ON nr.FKNotificacao = n.IDNotificacao AND nr.FKUsuario = :uid2
        WHERE n.Ativo = 1
          AND (n.DataExpiracao IS NULL OR n.DataExpiracao >= CURDATE())
          AND (
              n.DestinatarioTipo = 'todos'
              OR n.DestinatarioTipo = :plano
              OR (n.DestinatarioTipo = 'selecionado' AND EXISTS (
                  SELECT 1 FROM NotificacaoDestinatario nd
                  WHERE nd.FKNotificacao = n.IDNotificacao AND nd.FKUsuario = :uid3
              ))
          )
        ORDER BY CASE WHEN nl.FKNotificacao IS NULL THEN 0 ELSE 1 END ASC,
                 n.DataCriacao DESC
        LIMIT 30
    ");
    $stmt->execute([':uid' => $_nw_uid, ':uid2' => $_nw_uid, ':plano' => $_nw_plano, ':uid3' => $_nw_uid]);
    $_nw_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { /* silent */ }

$_nw_unread = 0;
foreach ($_nw_items as $ni) { if (!$ni['Lida']) $_nw_unread++; }

// Relative date helper
if (!function_exists('_nw_reldate')) {
    function _nw_reldate($dt) {
        $diff = time() - strtotime($dt);
        if ($diff < 60)        return 'agora mesmo';
        if ($diff < 3600)      return 'há ' . floor($diff/60) . ' min';
        if ($diff < 86400)     return 'há ' . floor($diff/3600) . ' h';
        if ($diff < 604800)    return 'há ' . floor($diff/86400) . ' dia' . (floor($diff/86400)>1?'s':'');
        return date('d/m/Y', strtotime($dt));
    }
}
?>
<!-- ═══════════════ NOTIFICATION WIDGET ═══════════════ -->
<div id="notif-container">

    <!-- Bell FAB -->
    <button id="notif-bell" type="button" title="Notificações"
            aria-label="Notificações <?= $_nw_unread ?> não lidas">
        <i class="bi bi-bell-fill" id="notif-bell-icon"></i>
        <?php if ($_nw_unread > 0): ?>
        <span id="notif-badge"><?= min($_nw_unread, 99) ?></span>
        <?php endif; ?>
    </button>

    <!-- Panel -->
    <div id="notif-panel" role="dialog" aria-label="Notificações" hidden>
        <!-- Header -->
        <div class="notif-panel-head">
            <span class="notif-panel-title">
                <i class="bi bi-bell me-2"></i>Notificações
                <?php if ($_nw_unread > 0): ?>
                <span class="notif-count-pill"><?= $_nw_unread ?> nova<?= $_nw_unread > 1 ? 's' : '' ?></span>
                <?php endif; ?>
            </span>
            <button id="notif-mark-all" type="button" title="Marcar todas como lidas">
                <i class="bi bi-check2-all"></i>
            </button>
        </div>

        <!-- List -->
        <div id="notif-list">
        <?php if (empty($_nw_items)): ?>
            <div class="notif-empty">
                <i class="bi bi-bell-slash"></i>
                <p>Nenhuma notificação por enquanto</p>
            </div>
        <?php else: foreach ($_nw_items as $ni):
            $lida      = (bool)$ni['Lida'];
            $respondida = (bool)$ni['Respondida'];
            $itens     = (!empty($ni['ItensPesquisa']) && $ni['TipoInteracao'] === 'pesquisa')
                         ? json_decode($ni['ItensPesquisa'], true) : [];
        ?>
            <div class="notif-item <?= $lida ? 'lida' : 'nao-lida' ?>"
                 data-id="<?= htmlspecialchars($ni['IDNotificacao']) ?>"
                 data-lida="<?= $lida ? '1' : '0' ?>"
                 data-tipo="<?= htmlspecialchars($ni['TipoInteracao']) ?>">

                <button class="notif-item-header" type="button">
                    <i class="bi <?= $lida ? 'bi-envelope-open' : 'bi-envelope-fill' ?> notif-envelope"></i>
                    <div class="notif-item-meta">
                        <span class="notif-item-title"><?= htmlspecialchars($ni['Titulo']) ?></span>
                        <span class="notif-item-date"><?= _nw_reldate($ni['DataCriacao']) ?></span>
                    </div>
                    <i class="bi bi-chevron-right notif-chevron"></i>
                </button>

                <div class="notif-item-body" hidden>
                    <div class="notif-item-content">
                        <?= nl2br(htmlspecialchars($ni['Conteudo'])) ?>
                    </div>

                    <?php if (!empty($itens) && !$respondida): ?>
                    <form class="notif-survey-form" data-id="<?= htmlspecialchars($ni['IDNotificacao']) ?>">
                        <div class="notif-survey-items">
                        <?php foreach ($itens as $idx => $item):
                            $nome = 'q' . $idx;
                        ?>
                            <div class="notif-survey-item">
                                <p class="notif-survey-q"><?= htmlspecialchars($item['pergunta'] ?? '') ?></p>
                                <?php if (($item['tipo'] ?? '') === 'radio'): ?>
                                    <?php foreach (($item['opcoes'] ?? []) as $opt): ?>
                                    <label class="notif-survey-opt">
                                        <input type="radio" name="<?= $nome ?>" value="<?= htmlspecialchars($opt) ?>" required>
                                        <span><?= htmlspecialchars($opt) ?></span>
                                    </label>
                                    <?php endforeach; ?>
                                <?php elseif (($item['tipo'] ?? '') === 'checkbox'): ?>
                                    <?php foreach (($item['opcoes'] ?? []) as $opt): ?>
                                    <label class="notif-survey-opt">
                                        <input type="checkbox" name="<?= $nome ?>[]" value="<?= htmlspecialchars($opt) ?>">
                                        <span><?= htmlspecialchars($opt) ?></span>
                                    </label>
                                    <?php endforeach; ?>
                                <?php elseif (($item['tipo'] ?? '') === 'texto'): ?>
                                    <textarea class="notif-survey-text" name="<?= $nome ?>" rows="3"
                                              placeholder="Digite sua resposta..."></textarea>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                        </div>
                        <button type="submit" class="notif-survey-submit">
                            <i class="bi bi-send me-1"></i> Enviar resposta
                        </button>
                    </form>
                    <?php elseif (!empty($itens) && $respondida): ?>
                    <div class="notif-survey-done">
                        <i class="bi bi-check-circle-fill me-2"></i>Você já respondeu esta pesquisa.
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; endif; ?>
        </div>
    </div>
</div>

<script>
(function() {
    var bell      = document.getElementById('notif-bell');
    var panel     = document.getElementById('notif-panel');
    var markAll   = document.getElementById('notif-mark-all');
    var badge     = document.getElementById('notif-badge');
    var bellIcon  = document.getElementById('notif-bell-icon');
    if (!bell || !panel) return;

    // ── Open / close panel ──────────────────────────────────
    bell.addEventListener('click', function(e) {
        e.stopPropagation();
        var isHidden = panel.hidden;
        panel.hidden = !isHidden;
        bellIcon.className = panel.hidden ? 'bi bi-bell-fill' : 'bi bi-bell';
        if (!panel.hidden) panel.querySelector('#notif-list').focus && null;
    });
    document.addEventListener('click', function(e) {
        if (!panel.hidden && !bell.contains(e.target) && !panel.contains(e.target)) {
            panel.hidden = true;
            bellIcon.className = 'bi bi-bell-fill';
        }
    });
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && !panel.hidden) {
            panel.hidden = true;
            bellIcon.className = 'bi bi-bell-fill';
        }
    });

    // ── Decrease badge count ────────────────────────────────
    function decreaseBadge(n) {
        if (!badge) return;
        var cur = parseInt(badge.textContent) - n;
        if (cur <= 0) badge.remove();
        else badge.textContent = cur;
    }

    // ── Mark all read ───────────────────────────────────────
    if (markAll) {
        markAll.addEventListener('click', function() {
            var unreadItems = document.querySelectorAll('.notif-item.nao-lida');
            fetch('/notificacoes/marcar_lida.php', {
                method: 'POST',
                headers: {'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/json'},
                body: JSON.stringify({todas: true})
            }).then(function(r) { return r.json(); }).then(function(d) {
                if (!d.ok) return;
                unreadItems.forEach(function(el) {
                    el.classList.replace('nao-lida', 'lida');
                    el.dataset.lida = '1';
                    var env = el.querySelector('.notif-envelope');
                    if (env) { env.className = 'bi bi-envelope-open notif-envelope'; }
                });
                if (badge) badge.remove();
            });
        });
    }

    // ── Individual item toggle + mark read ──────────────────
    document.querySelectorAll('.notif-item').forEach(function(item) {
        var header = item.querySelector('.notif-item-header');
        var body   = item.querySelector('.notif-item-body');
        var chev   = item.querySelector('.notif-chevron');

        if (!header) return;
        header.addEventListener('click', function() {
            var expanded = !body.hidden;
            // Collapse all others
            document.querySelectorAll('.notif-item').forEach(function(other) {
                if (other !== item) {
                    var ob = other.querySelector('.notif-item-body');
                    var oc = other.querySelector('.notif-chevron');
                    if (ob) ob.hidden = true;
                    if (oc) oc.style.transform = '';
                }
            });

            body.hidden = expanded;
            if (chev) chev.style.transform = expanded ? '' : 'rotate(90deg)';

            // Mark as read on first open
            if (!expanded && item.dataset.lida === '0') {
                item.dataset.lida = '1';
                item.classList.replace('nao-lida', 'lida');
                var env = item.querySelector('.notif-envelope');
                if (env) env.className = 'bi bi-envelope-open notif-envelope';
                decreaseBadge(1);
                fetch('/notificacoes/marcar_lida.php', {
                    method: 'POST',
                    headers: {'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/json'},
                    body: JSON.stringify({id: item.dataset.id})
                });
            }
        });
    });

    // ── Survey submit ───────────────────────────────────────
    document.querySelectorAll('.notif-survey-form').forEach(function(form) {
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            var nid = form.dataset.id;
            var data = new FormData(form);
            var respostas = {};
            data.forEach(function(val, key) {
                if (key.endsWith('[]')) {
                    var k = key.slice(0, -2);
                    respostas[k] = respostas[k] || [];
                    respostas[k].push(val);
                } else {
                    respostas[key] = val;
                }
            });
            var btn = form.querySelector('.notif-survey-submit');
            btn.disabled = true;
            btn.innerHTML = '<i class="bi bi-hourglass-split me-1"></i>Enviando...';

            fetch('/notificacoes/responder_pesquisa.php', {
                method: 'POST',
                headers: {'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/json'},
                body: JSON.stringify({notificacao_id: nid, respostas: respostas})
            }).then(function(r) { return r.json(); }).then(function(d) {
                if (d.ok) {
                    form.closest('.notif-item-body').querySelector('.notif-survey-form').outerHTML =
                        '<div class="notif-survey-done"><i class="bi bi-check-circle-fill me-2"></i>Resposta enviada. Obrigado!</div>';
                    if (typeof auralisToast === 'function') auralisToast('Resposta enviada!');
                } else {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="bi bi-send me-1"></i>Enviar resposta';
                    if (typeof auralisToast === 'function') auralisToast('Erro ao enviar resposta.');
                }
            }).catch(function() {
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-send me-1"></i>Enviar resposta';
            });
        });
    });
})();
</script>
