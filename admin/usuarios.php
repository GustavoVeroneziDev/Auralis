<?php
// ==============================================================================
// ADMIN/USUARIOS.PHP — Gestão manual de usuários e planos
// ==============================================================================
session_start();

if (!isset($_SESSION['usuario_id'])) {
    header("Location: /usuario/login.php");
    exit;
}

require_once '../config/conexao.php';
require_once '../config/funcoes.php';

// Apenas admin ou supremo
$nivelSessao = strtolower($_SESSION['nivel_acesso'] ?? '');
if (!in_array($nivelSessao, ['admin', 'supremo'])) {
    header("Location: /dashboard.php?erro=sem_permissao");
    exit;
}

$adminId = $_SESSION['usuario_id'];
$sucesso = $erro = null;

// ==============================================================================
// AÇÕES POST (PRG)
// ==============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $uid    = trim($_POST['usuario_id'] ?? '');

    if (empty($uid)) {
        $erro = "ID de usuário inválido.";
    } elseif ($action === 'dar_acesso') {
        $plano = in_array($_POST['plano'] ?? '', ['pro', 'vip']) ? $_POST['plano'] : '';
        $dias  = max(1, min(3650, (int)($_POST['dias'] ?? 30)));
        $valor = max(0.0, (float)str_replace(',', '.', $_POST['valor_pago'] ?? '0'));

        if (!$plano) {
            $erro = "Selecione um plano válido.";
        } else {
            try {
                $dataInicio    = date('Y-m-d H:i:s');
                $dataExpiracao = date('Y-m-d H:i:s', strtotime("+{$dias} days"));

                $pdo->beginTransaction();

                // Cancela assinatura ativa anterior
                $pdo->prepare("UPDATE Assinatura SET Status = 'cancelada' WHERE FKUsuario = :uid AND Status = 'ativa'")
                    ->execute([':uid' => $uid]);

                // Insere nova assinatura manual
                $pdo->prepare("
                    INSERT INTO Assinatura
                        (IDAssinatura, FKUsuario, Plano, Status, Ciclo, ValorPago,
                         DataInicio, DataExpiracao, IDAssinaturaGW, EmailGateway, FontePagamento)
                    VALUES
                        (:id, :uid, :plano, 'ativa', 'manual', :valor,
                         :inicio, :exp, NULL, NULL, 'manual_admin')
                ")->execute([
                    ':id'    => gerarUuid(),
                    ':uid'   => $uid,
                    ':plano' => $plano,
                    ':valor' => $valor,
                    ':inicio' => $dataInicio,
                    ':exp'   => $dataExpiracao,
                ]);

                // Atualiza plano do usuário
                $pdo->prepare("UPDATE Usuario SET Plano = :plano WHERE IDUsuario = :uid")
                    ->execute([':plano' => $plano, ':uid' => $uid]);

                $pdo->commit();
                header("Location: usuarios.php?sucesso=acesso_dado");
                exit;
            } catch (PDOException $e) {
                $pdo->rollBack();
                $erro = "Erro ao registrar acesso.";
            }
        }
    } elseif ($action === 'revogar') {
        try {
            $pdo->beginTransaction();
            $pdo->prepare("UPDATE Assinatura SET Status = 'cancelada' WHERE FKUsuario = :uid AND Status = 'ativa'")
                ->execute([':uid' => $uid]);
            $pdo->prepare("UPDATE Usuario SET Plano = 'free' WHERE IDUsuario = :uid")
                ->execute([':uid' => $uid]);
            $pdo->commit();
            header("Location: usuarios.php?sucesso=revogado");
            exit;
        } catch (PDOException $e) {
            $pdo->rollBack();
            $erro = "Erro ao revogar acesso.";
        }
    }
}

if (isset($_GET['sucesso'])) {
    if ($_GET['sucesso'] === 'acesso_dado') $sucesso = "Acesso concedido com sucesso!";
    if ($_GET['sucesso'] === 'revogado')    $sucesso = "Plano revertido para Free.";
}

// ==============================================================================
// BUSCA DE USUÁRIOS COM ASSINATURA ATIVA
// ==============================================================================
$usuarios = [];
try {
    $usuarios = $pdo->query("
        SELECT
            u.IDUsuario, u.Nome, u.Email, u.Plano, u.StatusConta,
            u.NivelAcesso, u.MomentoCriacao,
            a.DataExpiracao, a.DataInicio, a.ValorPago, a.Ciclo, a.FontePagamento
        FROM Usuario u
        LEFT JOIN Assinatura a ON a.IDAssinatura = (
            SELECT IDAssinatura FROM Assinatura
            WHERE FKUsuario = u.IDUsuario AND Status = 'ativa'
            ORDER BY DataExpiracao DESC LIMIT 1
        )
        ORDER BY
            CASE u.Plano WHEN 'vip' THEN 1 WHEN 'pro' THEN 2 ELSE 3 END,
            u.MomentoCriacao DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $erro = "Erro ao buscar usuários.";
}

// Contadores por plano
$stats = ['total' => count($usuarios), 'free' => 0, 'pro' => 0, 'vip' => 0];
foreach ($usuarios as $u) {
    $p = strtolower($u['Plano'] ?? 'free');
    if (isset($stats[$p])) $stats[$p]++;
}

require_once '../geral/header.php';
?>

<main class="container-fluid py-4 mt-2 flex-grow-1" style="max-width: 1400px; padding-inline: var(--space-page-x); min-height: 100vh;">

    <!-- Cabeçalho -->
    <div class="d-flex justify-content-between align-items-center mb-4 border-bottom border-secondary-subtle pb-3 gap-3 flex-wrap">
        <h2 class="fw-bold text-light mb-0 d-flex align-items-center gap-2" style="font-size: clamp(1.1rem, 3vw, 1.4rem);">
            <i class="bi bi-shield-fill-check" style="color:#E63946;"></i>
            Painel Administrativo
            <span style="font-size:0.65rem; background:rgba(230,57,70,0.15); color:#f87171; border:1px solid rgba(230,57,70,0.3); border-radius:999px; padding:2px 10px; font-weight:700; letter-spacing:0.06em; vertical-align:middle;">
                <?= strtoupper($nivelSessao) ?>
            </span>
        </h2>
        <a href="/dashboard.php" class="btn btn-outline-secondary btn-sm rounded-pill px-3 flex-shrink-0">
            <i class="bi bi-arrow-left me-1"></i> Voltar
        </a>
    </div>

    <!-- Alertas -->
    <?php if ($sucesso): ?>
        <div class="alert alert-success d-flex align-items-center gap-2 rounded-3 border-0 bg-success bg-opacity-10 text-success fw-semibold mb-4">
            <i class="bi bi-check-circle-fill"></i> <?= htmlspecialchars($sucesso) ?>
        </div>
    <?php endif; ?>
    <?php if ($erro): ?>
        <div class="alert alert-danger d-flex align-items-center gap-2 rounded-3 border-0 bg-danger bg-opacity-10 text-danger fw-semibold mb-4">
            <i class="bi bi-exclamation-triangle-fill"></i> <?= htmlspecialchars($erro) ?>
        </div>
    <?php endif; ?>

    <!-- Cards de resumo -->
    <div class="row g-3 mb-4">
        <?php
        $cardStats = [
            ['label' => 'Total de usuários', 'valor' => $stats['total'], 'icon' => 'bi-people-fill',     'cor' => '#AA8C2C'],
            ['label' => 'Free',               'valor' => $stats['free'],  'icon' => 'bi-person',           'cor' => '#888888'],
            ['label' => 'PRO',                'valor' => $stats['pro'],   'icon' => 'fi fi-br-crown',      'cor' => '#a78bfa'],
            ['label' => 'VIP',                'valor' => $stats['vip'],   'icon' => 'fi fi-ss-gem',        'cor' => '#d4af37'],
        ];
        foreach ($cardStats as $cs): ?>
            <div class="col-6 col-md-3">
                <div class="card border-secondary-subtle rounded-4 p-3 text-center" style="background:#1c1f24;">
                    <div class="small mb-1 fw-semibold" style="color:<?= $cs['cor'] ?>; font-size:0.78rem;">
                        <i class="<?= $cs['icon'] ?> me-1"></i><?= $cs['label'] ?>
                    </div>
                    <div class="fw-bold fs-3" style="color:<?= $cs['cor'] ?>;"><?= $cs['valor'] ?></div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- Busca -->
    <div class="mb-3">
        <input type="text" id="buscaUsuario"
            class="form-control bg-dark border-secondary-subtle text-light rounded-pill shadow-sm"
            placeholder="Buscar por nome ou e-mail..."
            style="max-width: 380px; padding-left: 1.1rem;">
    </div>

    <!-- Tabela -->
    <div class="card border-secondary-subtle shadow-sm rounded-4 overflow-hidden" style="background:#1c1f24;">
        <div class="table-responsive">
            <table class="table table-dark table-hover align-middle mb-0" id="tabelaUsuarios">
                <thead>
                    <tr style="background:#15171b; font-size:0.72rem; text-transform:uppercase; letter-spacing:0.06em; color:#555;">
                        <th class="py-3 ps-4" style="font-weight:700;">Usuário</th>
                        <th style="font-weight:700;">Plano</th>
                        <th style="font-weight:700;">Expira em</th>
                        <th style="font-weight:700;">Fonte</th>
                        <th style="font-weight:700;">Status</th>
                        <th style="font-weight:700;">Nível</th>
                        <th style="font-weight:700;">Cadastro</th>
                        <th class="pe-4 text-end" style="font-weight:700;">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($usuarios as $u):
                        $plano         = strtolower($u['Plano'] ?? 'free');
                        $nivel         = strtolower($u['NivelAcesso'] ?? 'titular');
                        $exp           = !empty($u['DataExpiracao']) ? new DateTime($u['DataExpiracao']) : null;
                        $hoje          = new DateTime();
                        $diasRestantes = $exp ? (int)$hoje->diff($exp)->format('%r%a') : null;
                        $criacao       = new DateTime($u['MomentoCriacao']);
                        $inicial       = mb_strtoupper(mb_substr($u['Nome'] ?? '?', 0, 1, 'UTF-8'), 'UTF-8');
                    ?>
                        <tr class="usuario-row"
                            data-nome="<?= htmlspecialchars(strtolower($u['Nome'] ?? '')) ?>"
                            data-email="<?= htmlspecialchars(strtolower($u['Email'] ?? '')) ?>">

                            <!-- Usuário -->
                            <td class="ps-4 py-3">
                                <div class="d-flex align-items-center gap-3">
                                    <div class="d-flex align-items-center justify-content-center rounded-circle fw-bold flex-shrink-0"
                                        style="width:36px;height:36px;background:rgba(170,140,44,0.15);color:#AA8C2C;font-size:0.88rem;">
                                        <?= $inicial ?>
                                    </div>
                                    <div style="min-width:0;">
                                        <div class="text-light fw-semibold text-truncate" style="font-size:0.88rem; max-width:180px;">
                                            <?= htmlspecialchars($u['Nome'] ?? '—') ?>
                                        </div>
                                        <div class="text-secondary text-truncate" style="font-size:0.73rem; max-width:180px;">
                                            <?= htmlspecialchars($u['Email'] ?? '') ?>
                                        </div>
                                    </div>
                                </div>
                            </td>

                            <!-- Plano -->
                            <td>
                                <?php if ($plano === 'vip'): ?>
                                    <span class="badge rounded-pill" style="background:rgba(212,175,55,0.15);color:#d4af37;border:1px solid rgba(212,175,55,0.4);font-size:0.7rem;padding:4px 9px;">⭐ VIP</span>
                                <?php elseif ($plano === 'pro'): ?>
                                    <span class="badge rounded-pill" style="background:rgba(124,58,237,0.15);color:#a78bfa;border:1px solid rgba(124,58,237,0.4);font-size:0.7rem;padding:4px 9px;">👑 PRO</span>
                                <?php else: ?>
                                    <span class="text-secondary" style="font-size:0.8rem;">Free</span>
                                <?php endif; ?>
                            </td>

                            <!-- Expiração -->
                            <td style="font-size:0.8rem;">
                                <?php if ($exp && $plano !== 'free'): ?>
                                    <?php if ($diasRestantes !== null && $diasRestantes >= 0): ?>
                                        <div class="<?= $diasRestantes <= 7 ? 'text-warning' : 'text-secondary' ?>">
                                            <?= $exp->format('d/m/Y') ?>
                                            <div style="font-size:0.7rem; opacity:0.7;"><?= $diasRestantes ?>d restantes</div>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-danger" style="font-size:0.78rem;">Expirado</span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="text-secondary opacity-40">—</span>
                                <?php endif; ?>
                            </td>

                            <!-- Fonte -->
                            <td style="font-size:0.73rem; color:#666;">
                                <?php
                                $fonte = $u['FontePagamento'] ?? '';
                                $fonteLabel = match ($fonte) {
                                    'mercadopago'  => 'MercadoPago',
                                    'manual_admin' => '<span style="color:#AA8C2C;">Manual</span>',
                                    ''             => '—',
                                    default        => htmlspecialchars($fonte),
                                };
                                echo $fonteLabel;
                                ?>
                            </td>

                            <!-- Status conta -->
                            <td>
                                <?php if (($u['StatusConta'] ?? '') === 'ativo'): ?>
                                    <span class="badge rounded-pill" style="background:rgba(6,214,160,0.12);color:#6ee7c7;border:1px solid rgba(6,214,160,0.3);font-size:0.68rem;padding:3px 8px;">ativo</span>
                                <?php else: ?>
                                    <span class="badge rounded-pill" style="background:rgba(230,57,70,0.12);color:#f87171;border:1px solid rgba(230,57,70,0.3);font-size:0.68rem;padding:3px 8px;"><?= htmlspecialchars($u['StatusConta'] ?? '?') ?></span>
                                <?php endif; ?>
                            </td>

                            <!-- Nível de acesso -->
                            <td style="font-size:0.78rem;">
                                <?php
                                $nivelCor = match ($nivel) {
                                    'supremo' => '#E63946',
                                    'admin'   => '#f87171',
                                    default   => '#666',
                                };
                                ?>
                                <span style="color:<?= $nivelCor ?>;"><?= ucfirst($nivel) ?></span>
                            </td>

                            <!-- Data de cadastro -->
                            <td style="font-size:0.75rem; color:#666;">
                                <?= $criacao->format('d/m/Y') ?>
                            </td>

                            <!-- Ações -->
                            <td class="pe-4">
                                <div class="d-flex gap-2 justify-content-end align-items-center">
                                    <button type="button"
                                        class="btn btn-sm rounded-pill px-3 fw-semibold btn-dar-acesso"
                                        style="background:rgba(212,175,55,0.12);color:#d4af37;border:1px solid rgba(212,175,55,0.35);font-size:0.73rem;white-space:nowrap;"
                                        data-bs-toggle="modal" data-bs-target="#modalDarAcesso"
                                        data-id="<?= htmlspecialchars($u['IDUsuario']) ?>"
                                        data-nome="<?= htmlspecialchars($u['Nome'] ?? '') ?>"
                                        data-email="<?= htmlspecialchars($u['Email'] ?? '') ?>"
                                        data-plano="<?= $plano ?>">
                                        <i class="bi bi-plus-circle me-1"></i> Dar acesso
                                    </button>
                                    <?php if ($plano !== 'free'): ?>
                                        <button type="button"
                                            class="btn btn-sm rounded-pill btn-revogar d-flex align-items-center justify-content-center"
                                            style="width:30px;height:30px;padding:0;background:rgba(230,57,70,0.1);color:#f87171;border:1px solid rgba(230,57,70,0.3);"
                                            title="Revogar plano"
                                            data-bs-toggle="modal" data-bs-target="#modalRevogar"
                                            data-id="<?= htmlspecialchars($u['IDUsuario']) ?>"
                                            data-nome="<?= htmlspecialchars($u['Nome'] ?? '') ?>"
                                            data-plano="<?= $plano ?>">
                                            <i class="bi bi-x-lg" style="font-size:0.7rem;"></i>
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </td>

                        </tr>
                    <?php endforeach; ?>

                    <?php if (empty($usuarios)): ?>
                        <tr>
                            <td colspan="8" class="text-center text-secondary py-5">Nenhum usuário encontrado.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</main>

<!-- ═══════════════════════════════════════════════════════════════════════════
     MODAL: DAR ACESSO
     ═══════════════════════════════════════════════════════════════════════ -->
<div class="modal fade" id="modalDarAcesso" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" style="max-width: 430px;">
        <div class="modal-content border-secondary-subtle rounded-4" style="background:#1a1d21;">
            <div class="modal-header border-bottom border-secondary-subtle px-4">
                <h5 class="modal-title text-light fw-bold d-flex align-items-center gap-2">
                    <i class="bi bi-star-fill" style="color:#d4af37;"></i> Conceder Acesso
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="">
                <div class="modal-body p-4">
                    <input type="hidden" name="action" value="dar_acesso">
                    <input type="hidden" name="usuario_id" id="dar_usuario_id">

                    <!-- Info do usuário alvo -->
                    <div class="rounded-3 p-3 mb-4" style="background:rgba(255,255,255,0.04);">
                        <div class="text-light fw-semibold" id="dar_nome" style="font-size:0.92rem;"></div>
                        <div class="text-secondary" id="dar_email" style="font-size:0.78rem;"></div>
                    </div>

                    <!-- Seleção de plano -->
                    <div class="mb-4">
                        <label class="form-label text-secondary small mb-2 d-block">Plano</label>
                        <div class="d-flex gap-3">
                            <div class="flex-grow-1">
                                <input type="radio" class="btn-check" name="plano" id="plano_pro" value="pro" required>
                                <label class="btn w-100 fw-bold rounded-3 py-2" for="plano_pro"
                                    style="background:rgba(124,58,237,0.1);color:#a78bfa;border:1px solid rgba(124,58,237,0.4);">
                                    👑 PRO
                                </label>
                            </div>
                            <div class="flex-grow-1">
                                <input type="radio" class="btn-check" name="plano" id="plano_vip" value="vip" required>
                                <label class="btn w-100 fw-bold rounded-3 py-2" for="plano_vip"
                                    style="background:rgba(212,175,55,0.1);color:#d4af37;border:1px solid rgba(212,175,55,0.4);">
                                    ⭐ VIP
                                </label>
                            </div>
                        </div>
                    </div>

                    <!-- Duração -->
                    <div class="mb-4">
                        <label class="form-label text-secondary small mb-2 d-block">Duração</label>
                        <div class="d-flex gap-2 mb-2">
                            <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill px-3" onclick="setDias(30)">30d</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill px-3" onclick="setDias(60)">60d</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill px-3" onclick="setDias(180)">6m</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill px-3" onclick="setDias(365)">1 ano</button>
                        </div>
                        <div class="input-group">
                            <input type="number" name="dias" id="campoDias"
                                class="form-control bg-dark border-secondary-subtle text-light rounded-start-3"
                                value="30" min="1" max="3650" required>
                            <span class="input-group-text bg-dark border-secondary-subtle text-secondary">dias</span>
                        </div>
                    </div>

                    <!-- Valor cobrado (opcional) -->
                    <div class="mb-1">
                        <label class="form-label text-secondary small mb-2 d-block">
                            Valor cobrado <span class="opacity-50">(R$) — opcional, só para registro</span>
                        </label>
                        <div class="input-group">
                            <span class="input-group-text bg-dark border-secondary-subtle text-secondary">R$</span>
                            <input type="text" name="valor_pago"
                                class="form-control bg-dark border-secondary-subtle text-light"
                                placeholder="0,00" autocomplete="off">
                        </div>
                    </div>

                </div>
                <div class="modal-footer border-top border-secondary-subtle px-4 d-flex justify-content-between">
                    <button type="button" class="btn btn-link text-secondary text-decoration-none" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn fw-bold px-4 rounded-pill"
                        style="background:linear-gradient(135deg,#FFB800,#D4AF37);color:#000;">
                        <i class="bi bi-check-lg me-1"></i> Confirmar
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════
     MODAL: REVOGAR ACESSO
     ═══════════════════════════════════════════════════════════════════════ -->
<div class="modal fade" id="modalRevogar" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" style="max-width: 380px;">
        <div class="modal-content border-secondary-subtle rounded-4" style="background:#1a1d21;">
            <div class="modal-header border-bottom border-secondary-subtle px-4">
                <h5 class="modal-title text-light fw-bold d-flex align-items-center gap-2">
                    <i class="bi bi-x-circle text-danger"></i> Revogar Acesso
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="">
                <div class="modal-body p-4 text-center">
                    <input type="hidden" name="action" value="revogar">
                    <input type="hidden" name="usuario_id" id="revogar_usuario_id">
                    <p class="text-secondary mb-1">
                        Rebaixar <strong class="text-light" id="revogar_nome"></strong> para o plano <strong class="text-secondary">Free</strong>?
                    </p>
                    <p class="text-secondary small mb-0">A assinatura ativa será cancelada imediatamente.</p>
                </div>
                <div class="modal-footer border-top border-secondary-subtle px-4 d-flex justify-content-between">
                    <button type="button" class="btn btn-link text-secondary text-decoration-none" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-danger fw-bold px-4 rounded-pill">
                        Confirmar
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
    #tabelaUsuarios tbody tr:hover td {
        background-color: rgba(255, 255, 255, 0.025) !important;
    }

    .opacity-40 {
        opacity: 0.4;
    }
</style>

<script>
    // ── Modal: Dar acesso ────────────────────────────────────────────────────
    document.getElementById('modalDarAcesso').addEventListener('show.bs.modal', function(e) {
        const btn = e.relatedTarget;
        document.getElementById('dar_usuario_id').value = btn.dataset.id;
        document.getElementById('dar_nome').textContent = btn.dataset.nome;
        document.getElementById('dar_email').textContent = btn.dataset.email;
        // Pré-seleciona o plano atual ou PRO como padrão
        const planoAtual = btn.dataset.plano;
        if (planoAtual === 'vip') {
            document.getElementById('plano_vip').checked = true;
        } else {
            document.getElementById('plano_pro').checked = true;
        }
        document.getElementById('campoDias').value = 30;
    });

    // ── Modal: Revogar ───────────────────────────────────────────────────────
    document.getElementById('modalRevogar').addEventListener('show.bs.modal', function(e) {
        const btn = e.relatedTarget;
        document.getElementById('revogar_usuario_id').value = btn.dataset.id;
        document.getElementById('revogar_nome').textContent = btn.dataset.nome;
    });

    // ── Atalhos de duração ───────────────────────────────────────────────────
    function setDias(n) {
        document.getElementById('campoDias').value = n;
    }

    // ── Busca client-side ────────────────────────────────────────────────────
    document.getElementById('buscaUsuario').addEventListener('input', function() {
        const q = this.value.toLowerCase().trim();
        document.querySelectorAll('.usuario-row').forEach(row => {
            const match = !q || row.dataset.nome.includes(q) || row.dataset.email.includes(q);
            row.style.display = match ? '' : 'none';
        });
    });
</script>

<?php require_once '../geral/footer.php'; ?>