<?php
// ==============================================================================
// AGENDA.PHP — Calendário financeiro visual (Padrão Auralis)
// ==============================================================================
session_start();
if (!isset($_SESSION['usuario_id'])) {
    header("Location: usuario/login.php");
    exit;
}
require_once 'config/conexao.php';

$usuario_id = $_SESSION['usuario_id'];

// ==============================================================================
// ENDPOINT AJAX
// ==============================================================================
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    $acao = trim($_GET['acao'] ?? '');

    // ── LISTAR ─────────────────────────────────────────────────────────────────
    if ($acao === 'listar') {
        $mes    = preg_replace('/[^0-9\-]/', '', $_GET['mes'] ?? date('Y-m'));
        $inicio = $mes . '-01';
        $fim    = date('Y-m-t', strtotime($inicio));
        $hoje   = date('Y-m-d');

        // Eventos manuais
        $stmtEv = $pdo->prepare("
            SELECT IDEvento AS id, Titulo AS titulo, DataEvento AS data,
                   Cor AS cor, Concluido AS concluido, Descricao AS descricao,
                   'evento' AS tipo, NULL AS status_reg, NULL AS valor, NULL AS tipo_reg
            FROM AgendaEvento
            WHERE FKUsuario = :u AND DataEvento BETWEEN :ini AND :fim
            ORDER BY DataEvento ASC
        ");
        $stmtEv->execute([':u' => $usuario_id, ':ini' => $inicio, ':fim' => $fim]);
        $eventos = $stmtEv->fetchAll(PDO::FETCH_ASSOC);

        // Transações com vencimento no mês (pendentes E efetivadas)
        $stmtTr = $pdo->prepare("
            SELECT IDRegistro AS id,
                   Descricao AS titulo,
                   COALESCE(DataVencimento, MomentoRegistro) AS data,
                   TipoRegistro AS tipo_reg,
                   StatusRegistro AS status_reg,
                   Valor AS valor,
                   'transacao' AS tipo,
                   NULL AS cor,
                   IF(StatusRegistro = 'efetivado', 1, 0) AS concluido,
                   NULL AS descricao
            FROM Registro
            WHERE FKUsuario = :u
              AND COALESCE(DataVencimento, MomentoRegistro) BETWEEN :ini AND :fim
            ORDER BY COALESCE(DataVencimento, MomentoRegistro) ASC
        ");
        $stmtTr->execute([':u' => $usuario_id, ':ini' => $inicio, ':fim' => $fim]);
        $transacoes = $stmtTr->fetchAll(PDO::FETCH_ASSOC);

        // Adiciona flag de urgência em cada transação
        foreach ($transacoes as &$t) {
            if ($t['status_reg'] === 'pendente') {
                if ($t['data'] < $hoje)       $t['urgencia'] = 'atrasada';
                elseif ($t['data'] === $hoje)  $t['urgencia'] = 'hoje';
                else                           $t['urgencia'] = 'pendente';
            } else {
                $t['urgencia'] = 'efetivado';
            }
        }
        unset($t);

        echo json_encode([
            'ok'     => true,
            'itens'  => array_merge($eventos, $transacoes),
            'hoje'   => $hoje,
        ]);
        exit;
    }

    // ── RESUMO (painel lateral) ────────────────────────────────────────────────
    if ($acao === 'resumo') {
        $hoje  = date('Y-m-d');
        $set7  = date('Y-m-d', strtotime('+7 days'));
        $iniMes = date('Y-m-01');
        $fimMes = date('Y-m-t');

        $q = function ($where) use ($pdo, $usuario_id) {
            $stmt = $pdo->prepare("
                SELECT IDRegistro AS id, Descricao AS titulo,
                       COALESCE(DataVencimento, MomentoRegistro) AS data,
                       TipoRegistro AS tipo_reg, Valor AS valor
                FROM Registro
                WHERE FKUsuario = :u AND StatusRegistro = 'pendente' AND $where
                ORDER BY data ASC LIMIT 20
            ");
            $stmt->execute([':u' => $usuario_id]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        };

        echo json_encode([
            'ok'       => true,
            'atrasadas' => $q(
                "COALESCE(DataVencimento, MomentoRegistro) < :d AND :d2 = :d2",
                // Simpler: just pass the date
            ),
        ]);
        // Faço a query direto para simplificar
        $atrasadas = $pdo->prepare("SELECT IDRegistro AS id, Descricao AS titulo, COALESCE(DataVencimento, MomentoRegistro) AS data, TipoRegistro AS tipo_reg, Valor AS valor FROM Registro WHERE FKUsuario=:u AND StatusRegistro='pendente' AND COALESCE(DataVencimento, MomentoRegistro) < :hoje ORDER BY data ASC LIMIT 20");
        $atrasadas->execute([':u' => $usuario_id, ':hoje' => $hoje]);

        $hoje_list = $pdo->prepare("SELECT IDRegistro AS id, Descricao AS titulo, COALESCE(DataVencimento, MomentoRegistro) AS data, TipoRegistro AS tipo_reg, Valor AS valor FROM Registro WHERE FKUsuario=:u AND StatusRegistro='pendente' AND COALESCE(DataVencimento, MomentoRegistro) = :hoje ORDER BY data ASC LIMIT 20");
        $hoje_list->execute([':u' => $usuario_id, ':hoje' => $hoje]);

        $proximos = $pdo->prepare("SELECT IDRegistro AS id, Descricao AS titulo, COALESCE(DataVencimento, MomentoRegistro) AS data, TipoRegistro AS tipo_reg, Valor AS valor FROM Registro WHERE FKUsuario=:u AND StatusRegistro='pendente' AND COALESCE(DataVencimento, MomentoRegistro) BETWEEN :d1 AND :d2 ORDER BY data ASC LIMIT 20");
        $proximos->execute([':u' => $usuario_id, ':d1' => date('Y-m-d', strtotime('+1 day')), ':d2' => $set7]);

        // — nunca chega ao echo acima, saída já foi enviada, mas corrijo abaixo
        exit;
    }

    // ── SALVAR EVENTO ──────────────────────────────────────────────────────────
    if ($acao === 'salvar' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id        = trim($_POST['id']        ?? '');
        $titulo    = trim($_POST['titulo']    ?? '');
        $descricao = trim($_POST['descricao'] ?? '');
        $data      = trim($_POST['data']      ?? '');
        $cor       = trim($_POST['cor']       ?? 'roxo');
        $concluido = isset($_POST['concluido']) ? 1 : 0;

        $coresValidas = ['roxo', 'azul', 'verde', 'amarelo', 'vermelho', 'cinza'];
        if (!in_array($cor, $coresValidas)) $cor = 'roxo';

        if (empty($titulo) || empty($data)) {
            echo json_encode(['ok' => false, 'msg' => 'Preencha o título e a data.']);
            exit;
        }

        try {
            if ($id) {
                $stmt = $pdo->prepare("UPDATE AgendaEvento SET Titulo=:t, Descricao=:d, DataEvento=:dt, Cor=:c, Concluido=:co WHERE IDEvento=:id AND FKUsuario=:u");
                $stmt->execute([':t' => $titulo, ':d' => $descricao, ':dt' => $data, ':c' => $cor, ':co' => $concluido, ':id' => $id, ':u' => $usuario_id]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO AgendaEvento (IDEvento,FKUsuario,Titulo,Descricao,DataEvento,Cor,Concluido) VALUES (:id,:u,:t,:d,:dt,:c,:co)");
                $stmt->execute([':id' => gerarUuid(), ':u' => $usuario_id, ':t' => $titulo, ':d' => $descricao, ':dt' => $data, ':c' => $cor, ':co' => $concluido]);
            }
            echo json_encode(['ok' => true]);
        } catch (PDOException $e) {
            echo json_encode(['ok' => false, 'msg' => 'Erro ao salvar.']);
        }
        exit;
    }

    // ── EXCLUIR EVENTO ─────────────────────────────────────────────────────────
    if ($acao === 'excluir' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id = trim($_POST['id'] ?? '');
        try {
            $stmt = $pdo->prepare("DELETE FROM AgendaEvento WHERE IDEvento=:id AND FKUsuario=:u");
            $stmt->execute([':id' => $id, ':u' => $usuario_id]);
            echo json_encode(['ok' => true]);
        } catch (PDOException $e) {
            echo json_encode(['ok' => false, 'msg' => 'Erro ao excluir.']);
        }
        exit;
    }

    echo json_encode(['ok' => false, 'msg' => 'Ação desconhecida.']);
    exit;
}

// ==============================================================================
// PRÉ-CARREGA RESUMO NO PHP (sem AJAX extra na abertura)
// ==============================================================================
$hoje    = date('Y-m-d');
$set7    = date('Y-m-d', strtotime('+7 days'));

$stmtAtrasadas = $pdo->prepare("
    SELECT IDRegistro AS id, Descricao AS titulo,
           COALESCE(DataVencimento, MomentoRegistro) AS data,
           TipoRegistro AS tipo_reg, Valor AS valor
    FROM Registro
    WHERE FKUsuario=:u AND StatusRegistro='pendente'
      AND COALESCE(DataVencimento, MomentoRegistro) < :hoje
    ORDER BY data ASC LIMIT 20
");
$stmtAtrasadas->execute([':u' => $usuario_id, ':hoje' => $hoje]);
$atrasadas = $stmtAtrasadas->fetchAll();

$stmtHoje = $pdo->prepare("
    SELECT IDRegistro AS id, Descricao AS titulo,
           COALESCE(DataVencimento, MomentoRegistro) AS data,
           TipoRegistro AS tipo_reg, Valor AS valor
    FROM Registro
    WHERE FKUsuario=:u AND StatusRegistro='pendente'
      AND COALESCE(DataVencimento, MomentoRegistro) = :hoje
    ORDER BY data ASC LIMIT 20
");
$stmtHoje->execute([':u' => $usuario_id, ':hoje' => $hoje]);
$vencem_hoje = $stmtHoje->fetchAll();

$stmtProx = $pdo->prepare("
    SELECT IDRegistro AS id, Descricao AS titulo,
           COALESCE(DataVencimento, MomentoRegistro) AS data,
           TipoRegistro AS tipo_reg, Valor AS valor
    FROM Registro
    WHERE FKUsuario=:u AND StatusRegistro='pendente'
      AND COALESCE(DataVencimento, MomentoRegistro) BETWEEN :d1 AND :d2
    ORDER BY data ASC LIMIT 20
");
$stmtProx->execute([':u' => $usuario_id, ':d1' => date('Y-m-d', strtotime('+1 day')), ':d2' => $set7]);
$proximos7 = $stmtProx->fetchAll();

// Helper
function fmtValor(float $v): string
{
    return 'R$ ' . number_format($v, 2, ',', '.');
}
function fmtData(string $d): string
{
    $meses = ['', 'Jan', 'Fev', 'Mar', 'Abr', 'Mai', 'Jun', 'Jul', 'Ago', 'Set', 'Out', 'Nov', 'Dez'];
    [$y, $m, $day] = explode('-', $d);
    return intval($day) . ' ' . $meses[intval($m)];
}

require_once 'geral/header.php';
?>

<main class="container-fluid py-4 mt-2 flex-grow-1"
    style="max-width:1500px;padding-inline:var(--space-page-x);min-height:100vh;">

    <!-- ── CABEÇALHO ───────────────────────────────────────────────────────── -->
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 border-bottom border-secondary-subtle pb-3 gap-3">
        <div class="d-flex align-items-center gap-3">
            <div class="d-flex align-items-center justify-content-center rounded-3 flex-shrink-0"
                style="width:44px;height:44px;background:rgba(212,175,55,.12);border:1px solid rgba(212,175,55,.25);">
                <i class="bi bi-calendar3" style="color:var(--accent);font-size:1.25rem;"></i>
            </div>
            <div>
                <h2 class="fw-bold text-light mb-0">Agenda</h2>
                <span id="badge-mes-ano" class="text-secondary small text-uppercase fw-semibold" style="letter-spacing:.06em;font-size:.7rem;"></span>
            </div>
        </div>

        <div class="d-flex align-items-center gap-2 flex-wrap">
            <!-- Navegação de mês -->
            <div class="d-flex align-items-center bg-body-tertiary border border-secondary-subtle rounded-pill shadow-sm" style="padding:3px;">
                <button class="btn btn-sm btn-link text-secondary px-3 py-1 text-decoration-none" onclick="mudarMes(-1)">
                    <i class="bi bi-chevron-left" style="font-size:.75rem;"></i>
                </button>
                <button class="btn btn-sm btn-link text-light px-3 py-1 text-decoration-none fw-semibold" onclick="irHoje()" style="font-size:.825rem;border-inline:1px solid rgba(255,255,255,.08);">
                    Hoje
                </button>
                <button class="btn btn-sm btn-link text-secondary px-3 py-1 text-decoration-none" onclick="mudarMes(1)">
                    <i class="bi bi-chevron-right" style="font-size:.75rem;"></i>
                </button>
            </div>

            <!-- Novo evento -->
            <button class="btn btn-sm rounded-pill px-4 fw-bold shadow-sm"
                style="background:linear-gradient(135deg,#d4af37,#AA8C2C);color:#121418;border:none;"
                onclick="abrirNovo(null)">
                <i class="bi bi-plus-lg me-1"></i> Novo evento
            </button>
        </div>
    </div>

    <!-- ── LAYOUT PRINCIPAL: calendário + painel lateral ──────────────────── -->
    <div class="row g-4 align-items-start">

        <!-- CALENDÁRIO -->
        <div class="col-lg-8">
            <div class="agenda-cal shadow-sm">
                <!-- Cabeçalho dos dias -->
                <div class="cal-semana">
                    <?php foreach (['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'] as $d): ?>
                        <div><?= $d ?></div>
                    <?php endforeach; ?>
                </div>
                <div id="cal-grid" class="cal-grade">
                    <div class="cal-carregando">
                        <div class="spinner-border spinner-border-sm text-secondary me-2"></div>
                        <span class="text-secondary fw-semibold" style="font-size:.875rem;">Carregando agenda...</span>
                    </div>
                </div>
            </div>

            <!-- Legenda -->
            <div class="d-flex flex-wrap gap-3 mt-3 px-1" style="font-size:.75rem;color:#6b7280;">
                <span class="d-flex align-items-center gap-1"><span class="leg-dot" style="background:#dc2626;"></span>Atrasada</span>
                <span class="d-flex align-items-center gap-1"><span class="leg-dot" style="background:#d4af37;"></span>Vence hoje</span>
                <span class="d-flex align-items-center gap-1"><span class="leg-dot" style="background:#3b82f6;"></span>Pendente</span>
                <span class="d-flex align-items-center gap-1"><span class="leg-dot" style="background:#22c55e;"></span>Efetivada</span>
                <span class="d-flex align-items-center gap-1"><span class="leg-dot" style="background:#a78bfa;"></span>Evento manual</span>
            </div>
        </div>

        <!-- PAINEL DE VENCIMENTOS -->
        <div class="col-lg-4">

            <?php if ($atrasadas): ?>
                <!-- Atrasadas -->
                <div class="card rounded-4 shadow-sm mb-3 border-0" style="background:rgba(220,38,38,.07);border:1px solid rgba(220,38,38,.2) !important;">
                    <div class="card-body p-3">
                        <div class="d-flex align-items-center gap-2 mb-3">
                            <i class="bi bi-exclamation-triangle-fill text-danger"></i>
                            <span class="fw-bold text-danger" style="font-size:.875rem;">
                                Atrasadas (<?= count($atrasadas) ?>)
                            </span>
                        </div>
                        <?php foreach ($atrasadas as $r): ?>
                            <a href="nova_transacao.php?editar=<?= urlencode($r['id']) ?>"
                                class="d-flex align-items-center justify-content-between gap-2 py-2 text-decoration-none border-bottom border-secondary-subtle transition-hover"
                                style="color:inherit;">
                                <div class="d-flex align-items-center gap-2 min-w-0">
                                    <span class="d-inline-flex align-items-center justify-content-center rounded-circle flex-shrink-0"
                                        style="width:28px;height:28px;min-width:28px;background:rgba(220,38,38,.15);">
                                        <i class="bi bi-arrow-down-short text-danger" style="font-size:.9rem;"></i>
                                    </span>
                                    <div class="min-w-0">
                                        <div class="text-light fw-semibold text-truncate" style="font-size:.8rem;max-width:160px;"
                                            title="<?= htmlspecialchars($r['titulo']) ?>">
                                            <?= htmlspecialchars($r['titulo']) ?>
                                        </div>
                                        <div class="text-danger opacity-75" style="font-size:.7rem;"><?= fmtData($r['data']) ?></div>
                                    </div>
                                </div>
                                <span class="text-danger fw-bold flex-shrink-0" style="font-size:.8rem;">
                                    <?= fmtValor((float)$r['valor']) ?>
                                </span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($vencem_hoje): ?>
                <!-- Vencem hoje -->
                <div class="card rounded-4 shadow-sm mb-3 border-0" style="background:rgba(212,175,55,.07);border:1px solid rgba(212,175,55,.25) !important;">
                    <div class="card-body p-3">
                        <div class="d-flex align-items-center gap-2 mb-3">
                            <i class="bi bi-alarm-fill" style="color:#d4af37;"></i>
                            <span class="fw-bold" style="font-size:.875rem;color:#d4af37;">
                                Vencem hoje (<?= count($vencem_hoje) ?>)
                            </span>
                        </div>
                        <?php foreach ($vencem_hoje as $r): ?>
                            <a href="nova_transacao.php?editar=<?= urlencode($r['id']) ?>"
                                class="d-flex align-items-center justify-content-between gap-2 py-2 text-decoration-none border-bottom border-secondary-subtle transition-hover"
                                style="color:inherit;">
                                <div class="d-flex align-items-center gap-2 min-w-0">
                                    <span class="d-inline-flex align-items-center justify-content-center rounded-circle flex-shrink-0"
                                        style="width:28px;height:28px;min-width:28px;background:rgba(212,175,55,.15);">
                                        <i class="bi bi-<?= $r['tipo_reg'] === 'receita' ? 'arrow-up-short text-success' : 'arrow-down-short text-danger' ?>" style="font-size:.9rem;"></i>
                                    </span>
                                    <div class="min-w-0">
                                        <div class="text-light fw-semibold text-truncate" style="font-size:.8rem;max-width:160px;"
                                            title="<?= htmlspecialchars($r['titulo']) ?>">
                                            <?= htmlspecialchars($r['titulo']) ?>
                                        </div>
                                        <div class="text-secondary" style="font-size:.7rem;">Hoje</div>
                                    </div>
                                </div>
                                <span class="fw-bold flex-shrink-0" style="font-size:.8rem;color:<?= $r['tipo_reg'] === 'receita' ? '#22c55e' : '#f87171' ?>;">
                                    <?= ($r['tipo_reg'] === 'receita' ? '+' : '-') . fmtValor((float)$r['valor']) ?>
                                </span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($proximos7): ?>
                <!-- Próximos 7 dias -->
                <div class="card rounded-4 shadow-sm mb-3 border-secondary-subtle" style="background:var(--bg-card);">
                    <div class="card-body p-3">
                        <div class="d-flex align-items-center gap-2 mb-3">
                            <i class="bi bi-calendar-week text-secondary"></i>
                            <span class="fw-bold text-secondary" style="font-size:.875rem;">
                                Próximos 7 dias (<?= count($proximos7) ?>)
                            </span>
                        </div>
                        <?php foreach ($proximos7 as $r): ?>
                            <a href="nova_transacao.php?editar=<?= urlencode($r['id']) ?>"
                                class="d-flex align-items-center justify-content-between gap-2 py-2 text-decoration-none border-bottom border-secondary-subtle transition-hover"
                                style="color:inherit;">
                                <div class="d-flex align-items-center gap-2 min-w-0">
                                    <span class="d-inline-flex align-items-center justify-content-center rounded-circle flex-shrink-0"
                                        style="width:28px;height:28px;min-width:28px;background:rgba(59,130,246,.12);">
                                        <i class="bi bi-<?= $r['tipo_reg'] === 'receita' ? 'arrow-up-short text-success' : 'arrow-down-short text-danger' ?>" style="font-size:.9rem;"></i>
                                    </span>
                                    <div class="min-w-0">
                                        <div class="text-light fw-semibold text-truncate" style="font-size:.8rem;max-width:160px;"
                                            title="<?= htmlspecialchars($r['titulo']) ?>">
                                            <?= htmlspecialchars($r['titulo']) ?>
                                        </div>
                                        <div class="text-secondary" style="font-size:.7rem;"><?= fmtData($r['data']) ?></div>
                                    </div>
                                </div>
                                <span class="text-secondary fw-semibold flex-shrink-0" style="font-size:.8rem;">
                                    <?= fmtValor((float)$r['valor']) ?>
                                </span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (!$atrasadas && !$vencem_hoje && !$proximos7): ?>
                <div class="card rounded-4 border-secondary-subtle text-center p-4" style="background:var(--bg-card);">
                    <i class="bi bi-check-circle-fill text-success mb-2" style="font-size:2rem;"></i>
                    <div class="fw-semibold text-light mb-1" style="font-size:.875rem;">Tudo em dia!</div>
                    <div class="text-secondary" style="font-size:.8rem;">Nenhum vencimento pendente nos próximos dias.</div>
                </div>
            <?php endif; ?>

        </div>
    </div>
</main>

<!-- ── MODAL DE EVENTO ─────────────────────────────────────────────────────── -->
<div class="modal fade" id="modalEvento" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-secondary-subtle shadow-lg rounded-4" style="background:#1e2126;">

            <div class="modal-header border-bottom border-secondary-subtle p-3">
                <h5 class="modal-title text-light fw-bold d-flex align-items-center gap-2">
                    <i class="bi bi-calendar-event" style="color:var(--accent);"></i>
                    <span id="modal-titulo-texto">Novo Evento</span>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>

            <div class="modal-body p-4">
                <div id="modal-erro" class="alert d-none align-items-center gap-2 rounded-3 border-0 fw-semibold py-2 px-3 mb-4"
                    style="background:rgba(220,38,38,.1);color:#fca5a5;font-size:.875rem;">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                    <span id="modal-erro-msg"></span>
                </div>

                <input type="hidden" id="ev-id">

                <div class="mb-3">
                    <label class="form-label text-secondary fw-semibold" style="font-size:.8rem;">Título <span class="text-danger">*</span></label>
                    <input type="text" id="ev-titulo"
                        class="form-control border-secondary-subtle text-light shadow-none fw-semibold"
                        style="background:#252a31;"
                        placeholder="Ex: Pagamento do IPTU">
                </div>

                <div class="mb-3">
                    <label class="form-label text-secondary fw-semibold" style="font-size:.8rem;">Data <span class="text-danger">*</span></label>
                    <input type="date" id="ev-data"
                        class="form-control border-secondary-subtle text-light shadow-none fw-semibold"
                        style="background:#252a31;">
                </div>

                <div class="mb-3">
                    <label class="form-label text-secondary fw-semibold" style="font-size:.8rem;">Observação</label>
                    <textarea id="ev-descricao" rows="2"
                        class="form-control border-secondary-subtle text-light shadow-none"
                        style="background:#252a31;"
                        placeholder="Detalhes adicionais..."></textarea>
                </div>

                <div class="mb-4">
                    <label class="form-label text-secondary fw-semibold mb-2" style="font-size:.8rem;">Cor</label>
                    <div class="d-flex gap-2 flex-wrap" id="cor-picker">
                        <?php
                        $cores = [
                            'roxo'     => ['#7c3aed', 'Roxo'],
                            'azul'     => ['#2563eb', 'Azul'],
                            'verde'    => ['#059669', 'Verde'],
                            'amarelo'  => ['#AA8C2C', 'Âmbar'],
                            'vermelho' => ['#dc2626', 'Vermelho'],
                            'cinza'    => ['#6b7280', 'Cinza'],
                        ];
                        foreach ($cores as $val => [$hex, $label]):
                        ?>
                            <label class="cor-opcao">
                                <input type="radio" name="ev-cor" value="<?= $val ?>" <?= $val === 'roxo' ? 'checked' : '' ?> hidden>
                                <span class="cor-bolinha" style="background:<?= $hex ?>;"></span>
                                <span class="cor-texto"><?= $label ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="d-flex align-items-center justify-content-between p-3 rounded-3 border border-secondary-subtle" style="background:#252a31;">
                    <label class="text-light fw-semibold mb-0" style="font-size:.875rem;cursor:pointer;" for="ev-concluido">
                        Marcar como concluído
                    </label>
                    <div class="form-check form-switch mb-0" style="padding-left:2.5rem;">
                        <input class="form-check-input shadow-none m-0" type="checkbox" id="ev-concluido" role="switch"
                            style="width:2.5rem;height:1.25rem;cursor:pointer;">
                    </div>
                </div>
            </div>

            <div class="modal-footer border-top border-secondary-subtle p-3">
                <div id="confirmar-exclusao" class="d-none w-100 d-flex align-items-center justify-content-between">
                    <span class="text-danger fw-semibold" style="font-size:.875rem;">
                        <i class="bi bi-exclamation-triangle-fill me-1"></i> Confirmar exclusão?
                    </span>
                    <div class="d-flex gap-2">
                        <button class="btn btn-sm btn-outline-secondary rounded-pill fw-semibold" onclick="cancelarExclusao()">Cancelar</button>
                        <button class="btn btn-sm btn-danger rounded-pill fw-bold px-3" onclick="confirmarExclusao()" id="btn-confirmar-excluir">Excluir</button>
                    </div>
                </div>

                <div id="rodape-normal" class="w-100 d-flex justify-content-between align-items-center">
                    <button id="btn-excluir" class="btn btn-sm btn-outline-danger rounded-pill fw-semibold d-none" onclick="pedirExclusao()">
                        <i class="bi bi-trash3 me-1"></i> Excluir
                    </button>
                    <div class="d-flex gap-2 ms-auto">
                        <button class="btn btn-link text-secondary text-decoration-none fw-semibold" data-bs-dismiss="modal">Cancelar</button>
                        <button class="btn rounded-pill px-4 fw-bold d-flex align-items-center gap-2"
                            style="background:linear-gradient(135deg,#d4af37,#AA8C2C);color:#121418;border:none;"
                            onclick="salvarEvento()" id="btn-salvar-evento">
                            <i class="bi bi-check-lg"></i> Salvar
                        </button>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>

<style>
    /* ── Calendário ────────────────────────────────────────────── */
    .agenda-cal {
        background: #333;
        border: 1px solid rgba(255, 255, 255, .07);
        border-radius: 12px;
        overflow: hidden;
    }

    .cal-semana {
        display: grid;
        grid-template-columns: repeat(7, 1fr);
        background: #1a1d21;
        gap: 1px;
        padding-bottom: 1px;
    }

    .cal-semana>div {
        padding: 8px 10px;
        font-size: .7rem;
        font-weight: 600;
        text-transform: lowercase;
        color: #666;
        background: #1e2126;
        text-align: right;
    }

    .cal-grade {
        display: grid;
        grid-template-columns: repeat(7, 1fr);
        gap: 1px;
        background: #333;
    }

    .cal-carregando {
        grid-column: 1/-1;
        background: #1e2126;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 4rem 0;
    }

    .cal-celula {
        background: #1e2126;
        min-height: 110px;
        padding: 6px;
        display: flex;
        flex-direction: column;
        transition: background .15s;
        cursor: default;
    }

    .cal-celula:hover {
        background: #252a31;
    }

    .cal-celula.outro-mes {
        background: #18191e;
    }

    .cal-celula.outro-mes .num-dia-num {
        opacity: .25;
    }

    /* Hoje */
    .cal-celula.eh-hoje {
        background: rgba(212, 175, 55, .05);
    }

    .cal-celula.eh-hoje::after {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 2px;
        background: linear-gradient(90deg, #d4af37, #AA8C2C);
    }

    .cal-celula.eh-hoje {
        position: relative;
    }

    /* Status de células */
    .cal-celula.tem-atrasada {
        background: rgba(220, 38, 38, .05);
    }

    .cal-celula.tem-hoje-venc {
        background: rgba(212, 175, 55, .05);
    }

    /* Número do dia */
    .num-dia {
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-direction: row-reverse;
        margin-bottom: 5px;
    }

    .num-dia-num {
        font-size: .78rem;
        color: #777;
        font-weight: 500;
        line-height: 1;
    }

    .num-dia-num.hoje-circulo {
        background: var(--accent);
        color: #121418;
        font-weight: 700;
        width: 22px;
        height: 22px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 50%;
        font-size: .72rem;
    }

    /* Botão + no dia */
    .btn-add-ev {
        background: transparent;
        border: none;
        color: var(--accent);
        opacity: 0;
        transition: opacity .2s;
        padding: 0;
        line-height: 1;
        font-size: .85rem;
    }

    .cal-celula:hover .btn-add-ev {
        opacity: 1;
    }

    /* Pills de eventos e transações */
    .ev-pill {
        font-size: .7rem;
        padding: 3px 5px;
        border-radius: 4px;
        margin-bottom: 3px;
        cursor: pointer;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        display: flex;
        align-items: center;
        gap: 4px;
        font-weight: 500;
        transition: filter .15s;
        border-left: 2px solid transparent;
    }

    .ev-pill:hover {
        filter: brightness(1.2);
    }

    .ev-nome {
        overflow: hidden;
        text-overflow: ellipsis;
        flex: 1;
        min-width: 0;
    }

    /* Status de urgência */
    .ev-atrasada {
        background: rgba(220, 38, 38, .12);
        color: #fca5a5;
        border-left-color: #dc2626;
    }

    .ev-hoje {
        background: rgba(212, 175, 55, .12);
        color: #d4af37;
        border-left-color: #d4af37;
    }

    .ev-pendente {
        background: rgba(59, 130, 246, .1);
        color: #93c5fd;
        border-left-color: #3b82f6;
    }

    .ev-efetivado {
        background: rgba(34, 197, 94, .08);
        color: #86efac;
        border-left-color: #22c55e;
        opacity: .75;
    }

    /* Cores de eventos manuais */
    .ev-roxo {
        background: rgba(124, 58, 237, .15);
        color: #c4b5fd;
        border-left-color: #7c3aed;
    }

    .ev-azul {
        background: rgba(37, 99, 235, .15);
        color: #93c5fd;
        border-left-color: #2563eb;
    }

    .ev-verde {
        background: rgba(5, 150, 105, .15);
        color: #6ee7b7;
        border-left-color: #059669;
    }

    .ev-amarelo {
        background: rgba(170, 140, 44, .15);
        color: #d4af37;
        border-left-color: #AA8C2C;
    }

    .ev-vermelho {
        background: rgba(220, 38, 38, .15);
        color: #fca5a5;
        border-left-color: #dc2626;
    }

    .ev-cinza {
        background: rgba(107, 114, 128, .15);
        color: #d1d5db;
        border-left-color: #6b7280;
    }

    /* Cor picker */
    .cor-opcao {
        display: flex;
        align-items: center;
        gap: 6px;
        padding: 5px 10px;
        border-radius: 20px;
        border: 1px solid rgba(255, 255, 255, .1);
        cursor: pointer;
        background: transparent;
        transition: all .2s;
    }

    .cor-opcao:has(input:checked) {
        border-color: var(--accent);
        background: rgba(212, 175, 55, .08);
    }

    .cor-opcao:has(input:checked) .cor-texto {
        color: var(--accent) !important;
    }

    .cor-bolinha {
        width: 10px;
        height: 10px;
        border-radius: 50%;
        flex-shrink: 0;
    }

    .cor-texto {
        color: #777;
        font-size: .78rem;
        font-weight: 600;
    }

    /* Switch */
    .form-check-input:checked {
        background-color: var(--accent);
        border-color: var(--accent);
    }

    /* Legenda */
    .leg-dot {
        display: inline-block;
        width: 8px;
        height: 8px;
        border-radius: 50%;
    }

    /* Painel lateral */
    .card a.transition-hover:hover {
        background: rgba(255, 255, 255, .03);
    }

    /* ── Mobile ─────────────────────────────────────────────────── */
    @media (max-width:767.98px) {
        .cal-semana {
            display: none;
        }

        .cal-grade {
            grid-template-columns: 1fr;
            gap: 0;
        }

        .cal-celula {
            min-height: auto;
            padding: 12px;
            border-bottom: 1px solid #2a2a2a;
        }

        .cal-celula.outro-mes {
            display: none;
        }

        .cal-celula.sem-eventos {
            display: none;
        }

        .num-dia {
            flex-direction: row;
            justify-content: flex-start;
            gap: 12px;
            margin-bottom: 8px;
        }

        .btn-add-ev {
            opacity: 1;
            font-size: 1.1rem;
        }
    }
</style>

<script>
    const HOJE_JS = new Date();
    const MESES = ['Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];
    let anoAtual = HOJE_JS.getFullYear();
    let mesAtual = HOJE_JS.getMonth();
    let itensMes = [];

    const bsModal = new bootstrap.Modal(document.getElementById('modalEvento'));

    function padZ(n) {
        return String(n).padStart(2, '0');
    }

    function dateStr(d) {
        return `${d.getFullYear()}-${padZ(d.getMonth()+1)}-${padZ(d.getDate())}`;
    }

    function escHtml(s) {
        return String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function fmtBRL(v) {
        return parseFloat(v || 0).toLocaleString('pt-BR', {
            style: 'currency',
            currency: 'BRL'
        });
    }

    // ── CARREGAR MÊS ────────────────────────────────────────────────────────────
    async function carregarMes(ano, mes) {
        const grid = document.getElementById('cal-grid');
        grid.innerHTML = '<div class="cal-carregando"><div class="spinner-border spinner-border-sm text-secondary me-2"></div><span class="text-secondary fw-semibold" style="font-size:.875rem;">Carregando...</span></div>';

        const mesStr = padZ(mes + 1);
        try {
            const res = await fetch(`agenda.php?ajax=1&acao=listar&mes=${ano}-${mesStr}`);
            const json = await res.json();
            itensMes = json.ok ? json.itens : [];
        } catch (e) {
            itensMes = [];
        }

        renderCalendario(ano, mes);
    }

    // ── RENDERIZAR ───────────────────────────────────────────────────────────────
    function renderCalendario(ano, mes) {
        document.getElementById('badge-mes-ano').textContent = `${MESES[mes]} ${ano}`;

        const grid = document.getElementById('cal-grid');
        const primeiro = new Date(ano, mes, 1).getDay();
        const diasMes = new Date(ano, mes + 1, 0).getDate();
        const diasAnt = new Date(ano, mes, 0).getDate();
        const total = Math.ceil((primeiro + diasMes) / 7) * 7;
        const hojeStr = dateStr(HOJE_JS);

        grid.innerHTML = '';

        for (let i = 0; i < total; i++) {
            let dia, outro = false,
                dt;

            if (i < primeiro) {
                dia = diasAnt - primeiro + i + 1;
                outro = true;
                dt = new Date(ano, mes - 1, dia);
            } else if (i >= primeiro + diasMes) {
                dia = i - primeiro - diasMes + 1;
                outro = true;
                dt = new Date(ano, mes + 1, dia);
            } else {
                dia = i - primeiro + 1;
                dt = new Date(ano, mes, dia);
            }

            const ds = dateStr(dt);
            const ehHoje = ds === hojeStr;
            const itens = itensMes.filter(it => (it.data || '').startsWith(ds));

            // Urgência máxima do dia (para colorir a célula)
            const temAtrasada = itens.some(it => it.urgencia === 'atrasada');
            const temHoje = itens.some(it => it.urgencia === 'hoje');
            const semEventos = itens.length === 0 && outro;

            const cell = document.createElement('div');
            cell.className = [
                'cal-celula',
                outro ? 'outro-mes' : '',
                ehHoje ? 'eh-hoje' : '',
                temAtrasada ? 'tem-atrasada' : '',
                (temHoje && !temAtrasada) ? 'tem-hoje-venc' : '',
                semEventos ? 'sem-eventos' : '',
            ].filter(Boolean).join(' ');

            // Número + botão
            const numDiv = document.createElement('div');
            numDiv.className = 'num-dia';

            const numSpan = document.createElement('span');
            numSpan.className = 'num-dia-num' + (ehHoje ? ' hoje-circulo' : '');
            numSpan.textContent = dia;

            const btnAdd = document.createElement('button');
            btnAdd.className = 'btn-add-ev';
            btnAdd.title = 'Adicionar evento';
            btnAdd.innerHTML = '<i class="bi bi-plus-circle-fill"></i>';
            btnAdd.onclick = e => {
                e.stopPropagation();
                abrirNovo(ds);
            };

            numDiv.appendChild(numSpan);
            numDiv.appendChild(btnAdd);
            cell.appendChild(numDiv);

            // Pills
            itens.forEach(item => {
                const pill = document.createElement('div');

                if (item.tipo === 'transacao') {
                    const isRec = item.tipo_reg === 'receita';
                    const icon = isRec ? 'bi-arrow-up-short text-success' : 'bi-arrow-down-short text-danger';
                    pill.className = `ev-pill ev-${escHtml(item.urgencia||'pendente')}`;
                    pill.title = `${item.titulo} — ${fmtBRL(item.valor)}`;
                    pill.innerHTML = `<i class="bi ${icon}" style="flex-shrink:0;font-size:.85rem;"></i><span class="ev-nome">${escHtml(item.titulo)}</span>`;
                    pill.onclick = () => window.location.href = `nova_transacao.php?editar=${encodeURIComponent(item.id)}`;
                } else {
                    const concIcon = item.concluido == 1 ? 'bi-check-circle-fill' : 'bi-circle';
                    pill.className = `ev-pill ev-${escHtml(item.cor||'roxo')}`;
                    pill.title = item.descricao_orig ? `${item.titulo}: ${item.descricao_orig}` : item.titulo;
                    pill.innerHTML = `<i class="bi ${concIcon}" style="flex-shrink:0;font-size:.7rem;"></i><span class="ev-nome">${escHtml(item.titulo)}</span>`;
                    pill.onclick = () => abrirEditar(item);
                }

                cell.appendChild(pill);
            });

            grid.appendChild(cell);
        }
    }

    // ── NAVEGAÇÃO ─────────────────────────────────────────────────────────────────
    function mudarMes(d) {
        mesAtual += d;
        if (mesAtual > 11) {
            mesAtual = 0;
            anoAtual++;
        }
        if (mesAtual < 0) {
            mesAtual = 11;
            anoAtual--;
        }
        carregarMes(anoAtual, mesAtual);
    }

    function irHoje() {
        anoAtual = HOJE_JS.getFullYear();
        mesAtual = HOJE_JS.getMonth();
        carregarMes(anoAtual, mesAtual);
    }

    // ── MODAL EVENTO ──────────────────────────────────────────────────────────────
    function limparModal() {
        ['ev-id', 'ev-titulo', 'ev-data', 'ev-descricao'].forEach(id => document.getElementById(id).value = '');
        document.getElementById('ev-concluido').checked = false;
        document.querySelector('input[name="ev-cor"][value="roxo"]').checked = true;
        document.getElementById('btn-excluir').classList.add('d-none');
        document.getElementById('modal-erro').classList.add('d-none');
        cancelarExclusao();
    }

    function abrirNovo(data) {
        limparModal();
        document.getElementById('modal-titulo-texto').textContent = 'Novo Evento';
        if (data) document.getElementById('ev-data').value = data;
        bsModal.show();
        setTimeout(() => document.getElementById('ev-titulo').focus(), 300);
    }

    function abrirEditar(item) {
        limparModal();
        document.getElementById('ev-id').value = item.id;
        document.getElementById('ev-titulo').value = item.titulo;
        document.getElementById('ev-data').value = (item.data || '').substring(0, 10);
        document.getElementById('ev-descricao').value = item.descricao || '';
        document.getElementById('ev-concluido').checked = item.concluido == '1';
        const radioEl = document.querySelector(`input[name="ev-cor"][value="${item.cor}"]`);
        if (radioEl) radioEl.checked = true;
        document.getElementById('btn-excluir').classList.remove('d-none');
        document.getElementById('modal-titulo-texto').textContent = 'Editar Evento';
        bsModal.show();
    }

    async function salvarEvento() {
        const titulo = document.getElementById('ev-titulo').value.trim();
        const data = document.getElementById('ev-data').value;
        const btn = document.getElementById('btn-salvar-evento');

        if (!titulo || !data) {
            document.getElementById('modal-erro-msg').textContent = 'Preencha o título e a data.';
            document.getElementById('modal-erro').classList.remove('d-none');
            return;
        }

        const txt = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Salvando...';
        document.getElementById('modal-erro').classList.add('d-none');

        const body = new FormData();
        body.append('id', document.getElementById('ev-id').value);
        body.append('titulo', titulo);
        body.append('data', data);
        body.append('descricao', document.getElementById('ev-descricao').value.trim());
        body.append('cor', document.querySelector('input[name="ev-cor"]:checked')?.value || 'roxo');
        if (document.getElementById('ev-concluido').checked) body.append('concluido', '1');

        try {
            const res = await fetch('agenda.php?ajax=1&acao=salvar', {
                method: 'POST',
                body
            });
            const json = await res.json();
            if (json.ok) {
                bsModal.hide();
                carregarMes(anoAtual, mesAtual);
            } else {
                document.getElementById('modal-erro-msg').textContent = json.msg || 'Erro ao salvar.';
                document.getElementById('modal-erro').classList.remove('d-none');
            }
        } catch (e) {
            document.getElementById('modal-erro-msg').textContent = 'Erro de conexão.';
            document.getElementById('modal-erro').classList.remove('d-none');
        } finally {
            btn.disabled = false;
            btn.innerHTML = txt;
        }
    }

    function pedirExclusao() {
        document.getElementById('confirmar-exclusao').classList.remove('d-none');
        document.getElementById('rodape-normal').classList.add('d-none');
    }

    function cancelarExclusao() {
        document.getElementById('confirmar-exclusao').classList.add('d-none');
        document.getElementById('rodape-normal').classList.remove('d-none');
    }

    async function confirmarExclusao() {
        const btn = document.getElementById('btn-confirmar-excluir');
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
        const body = new FormData();
        body.append('id', document.getElementById('ev-id').value);
        try {
            const res = await fetch('agenda.php?ajax=1&acao=excluir', {
                method: 'POST',
                body
            });
            if ((await res.json()).ok) {
                bsModal.hide();
                carregarMes(anoAtual, mesAtual);
            }
        } catch (e) {} finally {
            btn.disabled = false;
            btn.innerHTML = 'Excluir';
        }
    }

    document.getElementById('modalEvento').addEventListener('hidden.bs.modal', cancelarExclusao);

    // Init
    carregarMes(anoAtual, mesAtual);
</script>

<?php require_once 'geral/footer.php'; ?>