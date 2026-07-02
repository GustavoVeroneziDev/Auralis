<?php
// ==============================================================================
// AGENDA.PHP — Calendário financeiro e Resumos Sincronizados
// ==============================================================================
ob_start();
session_start();

if (!isset($_SESSION['usuario_id'])) {
    if (isset($_GET['ajax'])) {
        echo json_encode(['sucesso' => false, 'erro' => 'Não autenticado']);
        exit;
    }
    header("Location: usuario/login.php");
    exit;
}

require_once 'config/conexao.php';

// Gate: acesso configurável via /admin/configuracoes_planos.php
$_testeAgenda = function_exists('obterHorasRestantesTeste') && obterHorasRestantesTeste() > 0;
if (!$_testeAgenda && !recursoDisponivelParaPlano('agenda')) {
    if (isset($_GET['ajax'])) {
        ob_clean();
        header('Content-Type: application/json');
        echo json_encode(['sucesso' => false, 'erro' => 'Plano insuficiente']);
        exit;
    }
    header("Location: /planos.php?upgrade=" . urlencode(nivelMinimoRecurso('agenda')));
    exit;
}

$usuario_id = $_SESSION['usuario_id'];

// ==============================================================================
// AJAX
// ==============================================================================

// Exclusão rápida via context menu
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'excluir_rapido') {
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    $id = trim($_POST['registro_id'] ?? '');
    if ($id) {
        try {
            $pdo->prepare("DELETE FROM Registro WHERE IDRegistro = :id AND FKUsuario = :uid")
                ->execute([':id' => $id, ':uid' => $usuario_id]);
            echo json_encode(['ok' => true]);
        } catch (PDOException $e) {
            echo json_encode(['ok' => false, 'erro' => $e->getMessage()]);
        }
    } else {
        echo json_encode(['ok' => false, 'erro' => 'ID inválido']);
    }
    exit;
}

// Exclusão múltipla via seleção
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'excluir_multiplos') {
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    $ids = json_decode($_POST['ids'] ?? '[]', true);
    if (!is_array($ids) || empty($ids)) {
        echo json_encode(['ok' => false, 'erro' => 'Nenhum ID fornecido']);
        exit;
    }
    $ids = array_values(array_filter($ids, fn($id) => preg_match('/^[0-9a-f\-]{36}$/i', trim($id))));
    if (empty($ids)) {
        echo json_encode(['ok' => false, 'erro' => 'IDs inválidos']);
        exit;
    }
    try {
        $ph   = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("DELETE FROM Registro WHERE IDRegistro IN ($ph) AND FKUsuario = ?");
        $stmt->execute(array_merge($ids, [$usuario_id]));
        echo json_encode(['ok' => true, 'deletados' => $stmt->rowCount()]);
    } catch (PDOException $e) {
        echo json_encode(['ok' => false, 'erro' => $e->getMessage()]);
    }
    exit;
}

// Mover transação para outro dia (drag-and-drop)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'mover_dia') {
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    $id       = trim($_POST['registro_id'] ?? '');
    $novaData = trim($_POST['nova_data'] ?? '');
    if (!$id || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $novaData)) {
        echo json_encode(['ok' => false, 'erro' => 'Dados inválidos']); exit;
    }
    try {
        $pdo->prepare("
            UPDATE Registro
            SET DataVencimento  = CASE WHEN DataVencimento IS NOT NULL THEN :nd1 ELSE NULL END,
                MomentoRegistro = CASE WHEN DataVencimento IS NULL
                                       THEN CONCAT(:nd2, ' ', TIME(MomentoRegistro))
                                       ELSE MomentoRegistro END
            WHERE IDRegistro = :id AND FKUsuario = :uid
        ")->execute([':nd1' => $novaData, ':nd2' => $novaData, ':id' => $id, ':uid' => $usuario_id]);
        echo json_encode(['ok' => true]);
    } catch (PDOException $e) {
        echo json_encode(['ok' => false, 'erro' => $e->getMessage()]);
    }
    exit;
}

// Detalhe de fatura de cartão para o modal da agenda
if (isset($_GET['ajax']) && $_GET['acao'] === 'fatura_detalhe') {
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    $faturaId = trim($_GET['fatura_id'] ?? '');
    if (!$faturaId) {
        echo json_encode(['ok' => false]);
        exit;
    }
    try {
        $stmtF = $pdo->prepare("
            SELECT f.IDFatura, f.DataFechamento, f.DataVencimento, f.Status, f.FKCartao,
                   c.Nome AS NomeCartao, c.Bandeira, c.Cor
            FROM FaturaCartao f
            JOIN CartaoCredito c ON f.FKCartao = c.IDCartao
            WHERE f.IDFatura = :fid AND f.FKUsuario = :uid
        ");
        $stmtF->execute([':fid' => $faturaId, ':uid' => $usuario_id]);
        $fatura = $stmtF->fetch(PDO::FETCH_ASSOC);
        if (!$fatura) {
            echo json_encode(['ok' => false]);
            exit;
        }

        $stmtL = $pdo->prepare("
            SELECT l.Descricao, l.Valor, l.DataCompra, l.ParcelaAtual, l.TotalParcelas,
                   cat.NomeCategoria, cat.IconeCategoria
            FROM LancamentoCartao l
            LEFT JOIN Categoria cat ON l.FKCategoria = cat.IDCategoria
            WHERE l.FKFatura = :fid AND l.FKUsuario = :uid
            ORDER BY l.DataCompra ASC, l.IDLancamento ASC
        ");
        $stmtL->execute([':fid' => $faturaId, ':uid' => $usuario_id]);
        echo json_encode(['ok' => true, 'fatura' => $fatura, 'lancamentos' => $stmtL->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (PDOException $e) {
        echo json_encode(['ok' => false]);
    }
    exit;
}

if (isset($_GET['ajax']) && $_GET['acao'] === 'listar') {
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');

    $mes_str  = $_GET['mes'] ?? date('Y-m');
    $carteira = $_GET['carteira'] ?? 'todas';
    $mes_alvo = (int)date('m', strtotime($mes_str . '-01'));
    $ano_alvo = (int)date('Y', strtotime($mes_str . '-01'));

    $whereGrelha   = "r.FKUsuario = :uid AND MONTH(COALESCE(r.DataVencimento, r.MomentoRegistro)) = :mes AND YEAR(COALESCE(r.DataVencimento, r.MomentoRegistro)) = :ano";
    $carteiraFilter = '';
    $params = [':uid' => $usuario_id, ':mes' => $mes_alvo, ':ano' => $ano_alvo];

    if ($carteira !== 'todas' && $carteira !== '') {
        $whereGrelha   .= " AND r.FKCarteira = :cid";
        $carteiraFilter = " AND r.FKCarteira = :cid";
        $params[':cid'] = $carteira;
    }

    try {
        // ─── Grelha ──────────────────────────────────────────────────────────
        $stmtG = $pdo->prepare("
            SELECT r.IDRegistro as id, r.TipoRegistro as tipo, r.Descricao as titulo,
                   r.Valor as valor, r.StatusRegistro as status, r.Recorrente,
                   COALESCE(r.DataVencimento, r.MomentoRegistro) as data_evento,
                   c.NomeCategoria as categoria, COALESCE(c.IconeCategoria, 'bi-tag') as icone,
                   (SELECT COUNT(*) FROM Comprovante WHERE FKRegistro = r.IDRegistro AND FKUsuario = r.FKUsuario) AS tem_comprovante,
                   COALESCE(fp.IDFatura, fp2.IDFatura) as fatura_id,
                   COALESCE(fp.FKCartao, fp2.FKCartao) as cartao_id
            FROM Registro r
            LEFT JOIN Categoria c ON r.FKCategoria = c.IDCategoria
            LEFT JOIN FaturaCartao fp  ON fp.FKRegistroPagamento = r.IDRegistro AND fp.FKUsuario  = r.FKUsuario
            LEFT JOIN FaturaCartao fp2 ON fp2.FKRegistroPreview  = r.IDRegistro AND fp2.FKUsuario = r.FKUsuario
            WHERE $whereGrelha ORDER BY data_evento ASC");
        $stmtG->execute($params);
        $transacoesGrelha = $stmtG->fetchAll(PDO::FETCH_ASSOC);

        // ─── Saldos ───────────────────────────────────────────────────────────
        $whereSaldos = "FKUsuario = :uid AND MONTH(MomentoRegistro) = :mes AND YEAR(MomentoRegistro) = :ano" . ($carteira !== 'todas' ? " AND FKCarteira = :cid" : "");
        $stmtS = $pdo->prepare("SELECT Valor, TipoRegistro, StatusRegistro FROM Registro WHERE $whereSaldos");
        $stmtS->execute($params);

        $totRecEfet = $totDesEfet = $totRecPend = $totDesPend = 0;
        foreach ($stmtS->fetchAll(PDO::FETCH_ASSOC) as $t) {
            $v = (float)$t['Valor'];
            if ($t['TipoRegistro'] === 'receita' || $t['TipoRegistro'] === 'transferencia_entrada') {
                $t['StatusRegistro'] === 'efetivado' ? $totRecEfet += $v : $totRecPend += $v;
            } else {
                $t['StatusRegistro'] === 'efetivado' ? $totDesEfet += $v : $totDesPend += $v;
            }
        }

        // ─── Sidebar (global — relativo a hoje) ───────────────────────────────
        $sidebarParams = [':uid' => $usuario_id];
        if ($carteira !== 'todas' && $carteira !== '') $sidebarParams[':cid'] = $carteira;

        $stmtSb = $pdo->prepare("
            SELECT r.IDRegistro as id, r.TipoRegistro as tipo, r.Descricao as titulo,
                   r.Valor as valor,
                   COALESCE(r.DataVencimento, r.MomentoRegistro) as data_vencimento,
                   COALESCE(fp.IDFatura, fp2.IDFatura) as fatura_id,
                   COALESCE(fp.FKCartao, fp2.FKCartao) as cartao_id
            FROM Registro r
            LEFT JOIN FaturaCartao fp  ON fp.FKRegistroPagamento = r.IDRegistro AND fp.FKUsuario  = r.FKUsuario
            LEFT JOIN FaturaCartao fp2 ON fp2.FKRegistroPreview  = r.IDRegistro AND fp2.FKUsuario = r.FKUsuario
            WHERE r.FKUsuario = :uid AND r.StatusRegistro = 'pendente'
            $carteiraFilter ORDER BY COALESCE(r.DataVencimento, r.MomentoRegistro) ASC");
        $stmtSb->execute($sidebarParams);

        $hoje    = date('Y-m-d');
        $em7dias = date('Y-m-d', strtotime('+7 days'));
        $atrasadas = $vencem_hoje = $proximos_7 = [];

        foreach ($stmtSb->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $dv = substr($p['data_vencimento'], 0, 10);
            if ($dv < $hoje)         $atrasadas[]   = $p;
            elseif ($dv === $hoje)   $vencem_hoje[] = $p;
            elseif ($dv <= $em7dias) $proximos_7[]  = $p;
        }

        echo json_encode([
            'sucesso' => true,
            'dados'   => $transacoesGrelha,
            'saldos'  => [
                'efetivado' => $totRecEfet - $totDesEfet,
                'a_pagar'   => $totDesPend,
                'a_receber' => $totRecPend,
            ],
            'sidebar' => [
                'atrasadas'   => $atrasadas,
                'vencem_hoje' => $vencem_hoje,
                'proximos_7'  => $proximos_7,
            ],
        ]);
    } catch (PDOException $e) {
        echo json_encode(['sucesso' => false, 'erro' => $e->getMessage()]);
    }
    exit;
}

// ==============================================================================
// RENDERIZAÇÃO DA PÁGINA
// ==============================================================================
$carteiras = [];
try {
    $stmtCart = $pdo->prepare("SELECT IDCarteira, TipoCarteira, Principal FROM Carteira WHERE FKUsuarioDono = :uid ORDER BY Principal DESC, TipoCarteira ASC");
    $stmtCart->execute([':uid' => $usuario_id]);
    $carteiras = $stmtCart->fetchAll();
} catch (PDOException $e) {
}

$_agendaTemAcessoComp = function_exists('recursoDisponivelParaPlano') ? recursoDisponivelParaPlano('comprovantes') : false;
$_carteiraIdsAg = array_column($carteiras, 'IDCarteira');
if (isset($_GET['carteira'])) {
    $carteira_selecionada = $_GET['carteira'];
    // Persiste apenas se for uma carteira específica (não "todas")
    if ($carteira_selecionada !== 'todas' && in_array($carteira_selecionada, $_carteiraIdsAg)) {
        $_SESSION['ultima_carteira'] = $carteira_selecionada;
    }
} else {
    // Restaura da sessão; padrão é "todas" se não houver histórico
    $fromSession = $_SESSION['ultima_carteira'] ?? null;
    $carteira_selecionada = ($fromSession && in_array($fromSession, $_carteiraIdsAg)) ? $fromSession : 'todas';
}
$nome_carteira_atual  = 'Todas as Carteiras';
if ($carteira_selecionada !== 'todas') {
    foreach ($carteiras as $c) {
        if ($c['IDCarteira'] == $carteira_selecionada) {
            $nome_carteira_atual = $c['TipoCarteira'];
            break;
        }
    }
}

// Carteira usada nos atalhos de "+ Receita"/"+ Despesa": se um filtro específico estiver
// ativo, usa ele; se estiver em "Todas", cai na carteira Principal (ou a primeira, se
// nenhuma for principal) — em vez de deixar o formulário sem carteira pré-selecionada.
$_carteiraNovoLancamento = ($carteira_selecionada !== 'todas')
    ? $carteira_selecionada
    : ($carteiras[0]['IDCarteira'] ?? '');

require_once 'geral/header.php';
?>

<main class="container-fluid py-4 mt-2 flex-grow-1" style="max-width: 1500px; padding-inline: var(--space-page-x); min-height: 100vh;">

    <!-- ── Cabeçalho ─────────────────────────────────────────────────────── -->
    <div class="mb-4 border-bottom border-secondary-subtle pb-3">
        <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3">

            <div class="d-flex align-items-center gap-2 gap-md-3 w-100 w-lg-auto flex-wrap">

                <!-- Filtro de carteira -->
                <div class="dropdown flex-shrink-0">
                    <button class="btn border-secondary-subtle text-light shadow-sm fw-semibold dropdown-toggle d-flex align-items-center rounded-3 transition-hover px-2 px-sm-3"
                        type="button" data-bs-toggle="dropdown" aria-expanded="false"
                        style="font-size: 0.875rem; background-color: var(--bg-charcoal-analysis); max-width: 200px;">
                        <span class="text-truncate d-flex align-items-center">
                            <i class="bi bi-wallet2 me-1 me-sm-2 flex-shrink-0" style="color: var(--primary-gold-analysis);"></i>
                            <?= htmlspecialchars($nome_carteira_atual) ?>
                        </span>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-dark shadow-lg border-secondary-subtle mt-2" style="background-color:var(--bg-card-analysis); min-width:220px;">
                        <li class="px-3 pt-2 pb-1 text-secondary small text-uppercase fw-bold tracking-wide">Alternar Carteira</li>
                        <li>
                            <hr class="dropdown-divider border-secondary-subtle my-1">
                        </li>
                        <li>
                            <a class="dropdown-item d-flex align-items-center gap-2 py-2 transition-hover" href="?carteira=todas" style="font-size:0.9rem;">
                                <?php if ($carteira_selecionada === 'todas'): ?>
                                    <i class="bi bi-check-circle-fill flex-shrink-0" style="color:var(--primary-gold-analysis);"></i>
                                    <span class="fw-bold text-truncate" style="color:var(--primary-gold-analysis); max-width:170px;">Todas as Carteiras</span>
                                <?php else: ?>
                                    <i class="bi bi-circle flex-shrink-0 text-secondary opacity-50"></i>
                                    <span class="text-light text-truncate" style="max-width:170px;">Todas as Carteiras</span>
                                <?php endif; ?>
                            </a>
                        </li>
                        <?php foreach ($carteiras as $cart): ?>
                            <li>
                                <a class="dropdown-item d-flex align-items-center gap-2 py-2 transition-hover" href="?carteira=<?= htmlspecialchars($cart['IDCarteira']) ?>" style="font-size:0.9rem;">
                                    <?php if ($carteira_selecionada == $cart['IDCarteira']): ?>
                                        <i class="bi bi-check-circle-fill flex-shrink-0" style="color:var(--primary-gold-analysis);"></i>
                                        <span class="fw-bold text-truncate" style="color:var(--primary-gold-analysis); max-width:170px;" title="<?= htmlspecialchars($cart['TipoCarteira']) ?>"><?= htmlspecialchars($cart['TipoCarteira']) ?></span>
                                    <?php else: ?>
                                        <i class="bi bi-circle flex-shrink-0 text-secondary opacity-50"></i>
                                        <span class="text-light text-truncate" style="max-width:170px;" title="<?= htmlspecialchars($cart['TipoCarteira']) ?>"><?= htmlspecialchars($cart['TipoCarteira']) ?></span>
                                    <?php endif; ?>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>

                <!-- Navegação de mês -->
                <div class="d-flex align-items-center border border-secondary-subtle rounded-pill shadow-sm px-2 py-1 gap-1" style="background:var(--bg-card);">
                    <button type="button" class="btn btn-sm btn-link text-secondary shadow-none" onclick="window.mudarMes(-1)">
                        <i class="bi bi-chevron-left fs-6"></i>
                    </button>
                    <span id="mesAnoTitulo" class="text-light fw-bold fs-6 mx-2" style="min-width: 140px; text-align: center;">Carregando...</span>
                    <button type="button" class="btn btn-sm btn-link text-secondary shadow-none" onclick="window.mudarMes(1)">
                        <i class="bi bi-chevron-right fs-6"></i>
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill ms-1 px-3" style="font-size: 0.75rem;" onclick="window.irHoje()">Hoje</button>
                </div>

                <!-- Nova transação -->
                <a href="nova_transacao.php?voltar=agenda.php" class="btn btn-gold fw-bold text-dark rounded-pill px-3 d-flex align-items-center gap-2 flex-shrink-0" style="white-space:nowrap;">
                    <i class="bi bi-plus-lg"></i> Nova transação
                </a>

            </div>
        </div>
    </div>

    <!-- ── Cards de resumo ───────────────────────────────────────────────── -->
    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="card border-secondary-subtle shadow-sm rounded-4" style="background-color:var(--bg-card-analysis);">
                <div class="card-body p-3">
                    <div class="d-flex align-items-center gap-2 mb-2" style="font-size:0.8rem; color:var(--text-muted);">
                        <i class="bi bi-check-circle text-success"></i>Balanço efetivado no mês
                    </div>
                    <h4 id="saldoEfetivado" class="fw-bold mb-0 text-secondary">—</h4>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-secondary-subtle shadow-sm rounded-4" style="background-color:var(--bg-card-analysis);">
                <div class="card-body p-3">
                    <div class="d-flex align-items-center gap-2 mb-2" style="font-size:0.8rem; color:var(--text-muted);">
                        <i class="bi bi-hourglass-split" style="color:#E63946;"></i> A pagar no mês
                    </div>
                    <h4 id="saldoPago" class="fw-bold mb-0 text-danger">—</h4>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-secondary-subtle shadow-sm rounded-4" style="background-color:var(--bg-card-analysis);">
                <div class="card-body p-3">
                    <div class="d-flex align-items-center gap-2 mb-2" style="font-size:0.8rem; color:var(--text-muted);">
                        <i class="bi bi-clock" style="color:#06D6A0;"></i> A receber no mês
                    </div>
                    <h4 id="saldoEsperado" class="fw-bold mb-0 text-success">—</h4>
                </div>
            </div>
        </div>
    </div>

    <!-- ── Calendário + Sidebar ──────────────────────────────────────────── -->
    <div class="row g-4 align-items-start">

        <!-- Calendário -->
        <div class="col-xl-8 col-lg-7">
            <div class="card border-secondary-subtle shadow-sm rounded-4 overflow-hidden" style="background-color:var(--bg-card-analysis);">
                <div class="card-body p-2 p-md-3">
                    <div id="agenda-grid" class="calendar-grid"></div>
                </div>
            </div>
        </div>

        <!-- Sidebar -->
        <div class="col-xl-4 col-lg-5">

            <!-- Atrasadas -->
            <div id="panel-atrasadas" class="rounded-4 mb-3 d-none overflow-hidden"
                style="background-color:var(--bg-card-analysis); border:1px solid rgba(230,57,70,0.35);">
                <div class="d-flex align-items-center gap-2 px-4 py-3"
                    style="background-color:rgba(230,57,70,0.1); border-bottom:1px solid rgba(230,57,70,0.2);">
                    <i class="bi bi-exclamation-triangle-fill text-danger"></i>
                    <span class="fw-bold text-danger">Atrasadas</span>
                    <span id="count-atrasadas" class="ms-1 px-2 py-0 rounded-pill fw-bold"
                        style="background:rgba(230,57,70,0.2); color:#f87171; font-size:0.75rem;">0</span>
                </div>
                <div id="list-atrasadas"></div>
            </div>

            <!-- Vencem hoje -->
            <div id="panel-hoje" class="rounded-4 mb-3 d-none overflow-hidden"
                style="background-color:var(--bg-card-analysis); border:1px solid rgba(255,184,0,0.35);">
                <div class="d-flex align-items-center gap-2 px-4 py-3"
                    style="background-color:rgba(255,184,0,0.08); border-bottom:1px solid rgba(255,184,0,0.2);">
                    <i class="bi bi-bell-fill" style="color:#FFB800;"></i>
                    <span class="fw-bold" style="color:#FFB800;">Vencem hoje</span>
                    <span id="count-hoje" class="ms-1 px-2 py-0 rounded-pill fw-bold"
                        style="background:rgba(255,184,0,0.2); color:#f5e0a0; font-size:0.75rem;">0</span>
                </div>
                <div id="list-hoje"></div>
            </div>

            <!-- Próximos 7 dias -->
            <div id="panel-proximos" class="rounded-4 d-none overflow-hidden"
                style="background-color:var(--bg-card-analysis); border:1px solid rgba(59,130,246,0.35);">
                <div class="d-flex align-items-center gap-2 px-4 py-3"
                    style="background-color:rgba(59,130,246,0.08); border-bottom:1px solid rgba(59,130,246,0.2);">
                    <i class="bi bi-calendar-event" style="color:#60a5fa;"></i>
                    <span class="fw-bold" style="color:#60a5fa;">Próximos 7 dias</span>
                    <span id="count-proximos" class="ms-1 px-2 py-0 rounded-pill fw-bold"
                        style="background:rgba(59,130,246,0.2); color:#a0c4f8; font-size:0.75rem;">0</span>
                </div>
                <div id="list-proximos"></div>
            </div>

        </div>
    </div>

</main>

<style>
    .btn-gold {
        background: linear-gradient(135deg, #FFB800 0%, #D4AF37 100%);
        border: none;
    }

    .btn-gold:hover {
        background: linear-gradient(135deg, #FFD04F 0%, #E7C665 100%);
        color: #000;
        box-shadow: 0 4px 15px rgba(212, 175, 55, 0.4) !important;
    }

    /* ── Calendário ── */
    .calendar-grid {
        display: grid;
        grid-template-columns: repeat(7, 1fr);
        gap: 1px;
        background-color: var(--border-color-analysis);
        border-radius: 10px;
        overflow: hidden;
    }

    .calendar-day-header {
        background-color: var(--bg-card-analysis);
        color: var(--primary-gold-analysis);
        text-align: center;
        padding: 10px 4px;
        font-weight: 700;
        font-size: 0.75rem;
        text-transform: uppercase;
        letter-spacing: 0.05em;
    }

    .calendar-day {
        background-color: var(--bg-card-analysis);
        min-height: 110px;
        max-height: 200px;
        padding: 6px;
        display: flex;
        flex-direction: column;
        position: relative;
        overflow: hidden;
        transition: background-color 0.15s ease;
    }

    .calendar-day:hover:not(.empty) {
        background-color: var(--bg-hover);
    }

    .calendar-day.empty {
        background-color: var(--bg-main-analysis);
        opacity: 0.4;
    }

    .calendar-day.today {
        background-color: rgba(170, 140, 44, 0.05);
        box-shadow: inset 0 0 0 1px var(--primary-gold-analysis);
    }

    .calendar-day.today .day-number {
        background-color: var(--primary-gold-analysis);
        color: #1a1d21;
        border-radius: 50%;
        width: 22px;
        height: 22px;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .day-number {
        font-weight: 700;
        font-size: 0.8rem;
        color: var(--text-muted-analysis);
        margin-bottom: 5px;
        line-height: 1;
    }

    .day-events {
        display: flex;
        flex-direction: column;
        gap: 3px;
        overflow-y: auto;
        flex-grow: 1;
        max-height: 95px;
    }

    .day-events::-webkit-scrollbar {
        width: 3px;
    }

    .day-events::-webkit-scrollbar-thumb {
        background-color: var(--border-color-analysis);
        border-radius: 10px;
    }

    /* ── Pílulas de evento ── */
    .calendar-event {
        font-size: 0.7rem;
        padding: 3px 5px;
        border-radius: 5px;
        display: flex;
        align-items: center;
        gap: 2px;
        font-weight: 600;
        cursor: pointer;
        transition: filter 0.1s ease, transform 0.1s ease;
        overflow: hidden;
        white-space: nowrap;
        max-width: 100%;
        min-width: 0;
    }

    .calendar-event:hover {
        filter: brightness(1.25);
        transform: translateY(-1px);
    }

    /* ── Drag-and-drop ─────────────────────── */
    .calendar-event[draggable="true"] { cursor: grab; }
    .calendar-event[draggable="true"]:active { cursor: grabbing; }
    .calendar-event.drag-ghost { opacity: 0.35; }
    .calendar-day.drag-over {
        background: rgba(212,175,55,0.12) !important;
        outline: 2px dashed var(--accent);
        outline-offset: -2px;
    }

    .calendar-event.evento-pago {
        background-color: var(--color-income-bg);
        color: var(--color-income-text);
    }

    .calendar-event.evento-atrasado {
        background-color: var(--color-expense-bg);
        color: var(--color-expense-text);
    }

    .calendar-event.evento-hoje {
        background-color: var(--color-today-bg);
        color: var(--color-today-text);
    }

    .calendar-event.evento-pendente {
        background-color: var(--color-pending-bg);
        color: var(--color-pending-text);
    }

    .event-desc {
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        flex: 1;
        min-width: 0;
    }

    /* ── Sidebar items ── */
    .sidebar-item {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 10px 16px;
        border-bottom: 1px solid var(--bs-border-color);
        cursor: pointer;
        transition: background-color 0.15s ease;
    }

    .sidebar-item:last-child {
        border-bottom: none;
    }

    .sidebar-item:hover {
        background-color: var(--bg-hover);
    }

    /* ── Botões de ação na célula ── */
    .day-actions {
        position: absolute;
        top: 4px;
        right: 4px;
        display: flex;
        gap: 3px;
        opacity: 0;
        transition: opacity 0.15s ease;
        z-index: 2;
    }

    .calendar-day:hover:not(.empty) .day-actions {
        opacity: 1;
    }

    .day-action-btn {
        width: 20px;
        height: 20px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 0.65rem;
        text-decoration: none;
        transition: transform 0.12s ease;
        line-height: 1;
    }

    .day-action-btn.receita-btn {
        background: rgba(6, 214, 160, 0.2);
        color: #6ee7c7;
        border: 1px solid rgba(6, 214, 160, 0.45);
    }

    .day-action-btn.despesa-btn {
        background: rgba(230, 57, 70, 0.2);
        color: #f87171;
        border: 1px solid rgba(230, 57, 70, 0.45);
    }

    .day-action-btn:hover {
        transform: scale(1.25);
    }

    .calendar-day:not(.empty) {
        cursor: pointer;
    }

    /* ── Responsividade ──────────────────────────────────────────────── */

    /* Tablet (< 900px) */
    @media (max-width: 899px) {
        .calendar-day {
            min-height: 85px;
            padding: 4px;
        }

        .calendar-event {
            font-size: 0.65rem;
            padding: 2px 4px;
        }

        .day-events {
            max-height: 72px;
            gap: 2px;
        }
    }

    /* Mobile grande (< 640px) */
    @media (max-width: 639px) {
        .calendar-day {
            min-height: 66px;
            max-height: 120px;
            padding: 3px;
        }

        .calendar-day-header {
            padding: 7px 2px;
            font-size: 0.6rem;
            letter-spacing: 0;
        }

        .day-number {
            font-size: 0.68rem;
        }

        .calendar-day.today .day-number {
            width: 19px;
            height: 19px;
            font-size: 0.62rem;
        }

        .calendar-event {
            font-size: 0.6rem;
            padding: 2px 3px;
            border-radius: 4px;
        }

        .day-events {
            max-height: 52px;
            gap: 2px;
        }

        .day-actions {
            display: none !important;
        }
    }

    /* Mobile pequeno (< 480px): eventos viram barras coloridas, sem texto */
    @media (max-width: 479px) {
        .calendar-day {
            min-height: 52px;
            max-height: 90px;
            padding: 3px 2px;
        }

        .calendar-day-header {
            padding: 5px 1px;
            font-size: 0.55rem;
        }

        .day-number {
            font-size: 0.6rem;
            margin-bottom: 2px;
        }

        .calendar-day.today .day-number {
            width: 17px;
            height: 17px;
            font-size: 0.55rem;
        }

        .day-events {
            flex-direction: row;
            flex-wrap: wrap;
            max-height: 28px;
            gap: 2px;
            overflow: hidden;
        }

        .calendar-event {
            width: 8px;
            height: 8px;
            min-width: 8px;
            min-height: 8px;
            padding: 0;
            border-radius: 2px;
            flex-shrink: 0;
        }

        .calendar-event i,
        .event-desc {
            display: none !important;
        }

        .calendar-event.evento-pago {
            background-color: rgba(6, 214, 160, 0.55);
        }

        .calendar-event.evento-atrasado {
            background-color: rgba(230, 57, 70, 0.65);
        }

        .calendar-event.evento-hoje {
            background-color: rgba(255, 184, 0, 0.65);
        }

        .calendar-event.evento-pendente {
            background-color: rgba(59, 130, 246, 0.55);
        }
    }
</style>

<script>
    const carteiraAtual = "<?= htmlspecialchars($carteira_selecionada, ENT_QUOTES, 'UTF-8') ?>";
    const carteiraNovoLancamento = "<?= htmlspecialchars($_carteiraNovoLancamento, ENT_QUOTES, 'UTF-8') ?>";
    const _temAcessoCompAgenda = <?= $_agendaTemAcessoComp ? 'true' : 'false' ?>;
    let anoAtual = new Date().getFullYear();
    let mesAtual = new Date().getMonth();
    const HOJE_JS = new Date();

    const mesesNomes = ['Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];
    const diasSemana = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'];

    function cssVar(name) {
        return getComputedStyle(document.documentElement).getPropertyValue(name).trim();
    }

    function formatarMoeda(v) {
        return new Intl.NumberFormat('pt-BR', {
            style: 'currency',
            currency: 'BRL'
        }).format(v);
    }

    function esc(str) {
        const d = document.createElement('div');
        d.innerText = str ?? '';
        return d.innerHTML;
    }

    function hojeStr() {
        return HOJE_JS.getFullYear() + '-' +
            String(HOJE_JS.getMonth() + 1).padStart(2, '0') + '-' +
            String(HOJE_JS.getDate()).padStart(2, '0');
    }

    function formatarDataCurta(dateStr) {
        if (!dateStr) return '';
        const p = dateStr.split(' ')[0].split('-');
        return `${p[2]}/${p[1]}`;
    }

    // ── Classificação da pílula ────────────────────────────────────────────
    function classEvento(t, dataStr) {
        if (t.status === 'efetivado') return 'evento-pago';
        const data = t.data_evento ? t.data_evento.split(' ')[0] : dataStr;
        const hj = hojeStr();
        if (data < hj) return 'evento-atrasado';
        if (data === hj) return 'evento-hoje';
        return 'evento-pendente';
    }

    // ── Navegação ─────────────────────────────────────────────────────────
    window.mudarMes = function(delta) {
        mesAtual += delta;
        if (mesAtual > 11) {
            mesAtual = 0;
            anoAtual++;
        } else if (mesAtual < 0) {
            mesAtual = 11;
            anoAtual--;
        }
        window.carregarMes(anoAtual, mesAtual);
    };

    window.irHoje = function() {
        anoAtual = HOJE_JS.getFullYear();
        mesAtual = HOJE_JS.getMonth();
        window.carregarMes(anoAtual, mesAtual);
    };

    // ── Carga principal ───────────────────────────────────────────────────
    window.carregarMes = async function(ano, mes) {
        document.getElementById('mesAnoTitulo').innerText = `${mesesNomes[mes]} ${ano}`;
        const mesStr = ano + '-' + String(mes + 1).padStart(2, '0');
        try {
            const r = await fetch(`agenda.php?ajax=1&acao=listar&mes=${mesStr}&carteira=${carteiraAtual}`);
            const json = await r.json();
            if (json.sucesso) {
                renderizarGrelha(ano, mes, json.dados);
                atualizarSaldos(json.saldos);
                renderizarSidebar(json.sidebar);
            } else {
                console.error("Erro do servidor:", json.erro);
            }
        } catch (e) {
            console.error("Erro AJAX", e);
        }
    };

    // ── Cards de resumo ───────────────────────────────────────────────────
    function atualizarSaldos(saldos) {
        const elEf = document.getElementById('saldoEfetivado');
        const elPg = document.getElementById('saldoPago');
        const elRc = document.getElementById('saldoEsperado');
        if (!elEf || !elPg || !elRc) return;

        elEf.innerHTML = (saldos.efetivado >= 0 ? '+' : '') + formatarMoeda(saldos.efetivado);
        elEf.className = 'fw-bold mb-0 ' + (saldos.efetivado >= 0 ? 'text-success' : 'text-danger');

        elPg.innerHTML = '- ' + formatarMoeda(saldos.a_pagar);
        elPg.className = 'fw-bold mb-0 text-danger';

        elRc.innerHTML = '+ ' + formatarMoeda(saldos.a_receber);
        elRc.className = 'fw-bold mb-0 text-success';
    }

    // ── Sidebar ───────────────────────────────────────────────────────────
    function itemSidebar(item) {
        const isRec = item.tipo === 'receita' || item.tipo === 'transferencia_entrada';
        const incomeColor = cssVar('--color-income-text') || '#6ee7c7';
        const expenseColor = cssVar('--color-expense-text') || '#f87171';
        const corValor = isRec ? incomeColor : expenseColor;
        const sinal = isRec ? '+' : '-';
        const arrow = isRec ?
            `<i class="bi bi-arrow-up-short" style="color:${incomeColor};font-size:1.15rem;flex-shrink:0;"></i>` :
            `<i class="bi bi-arrow-down-short" style="color:${expenseColor};font-size:1.15rem;flex-shrink:0;"></i>`;

        const clickSb = item.fatura_id ?
            `abrirModalFaturaCC('${item.fatura_id}','${item.cartao_id}')` :
            `window.location.href='nova_transacao.php?voltar=agenda.php&editar=${encodeURIComponent(item.id)}'`;
        return `<div class="sidebar-item" onclick="${clickSb}">
            <div class="d-flex align-items-center gap-2" style="min-width:0;">
                ${arrow}
                <div style="min-width:0;">
                    <div class="text-light fw-semibold text-truncate" style="font-size:0.83rem;max-width:180px;">${esc(item.titulo)}</div>
                    <div class="text-secondary" style="font-size:0.7rem;">${formatarDataCurta(item.data_vencimento)}</div>
                </div>
            </div>
            <span class="fw-bold flex-shrink-0 ms-3" style="font-size:0.83rem;color:${corValor};">${sinal}${formatarMoeda(item.valor)}</span>
        </div>`;
    }

    function renderizarSidebar(sidebar) {
        const pares = [{
                panelId: 'panel-atrasadas',
                listId: 'list-atrasadas',
                countId: 'count-atrasadas',
                items: sidebar.atrasadas
            },
            {
                panelId: 'panel-hoje',
                listId: 'list-hoje',
                countId: 'count-hoje',
                items: sidebar.vencem_hoje
            },
            {
                panelId: 'panel-proximos',
                listId: 'list-proximos',
                countId: 'count-proximos',
                items: sidebar.proximos_7
            },
        ];
        pares.forEach(({
            panelId,
            listId,
            countId,
            items
        }) => {
            const panel = document.getElementById(panelId);
            const list = document.getElementById(listId);
            const count = document.getElementById(countId);
            if (items && items.length > 0) {
                count.textContent = items.length;
                list.innerHTML = items.map(itemSidebar).join('');
                panel.classList.remove('d-none');
            } else {
                panel.classList.add('d-none');
            }
        });
    }

    // ── Grelha do calendário ──────────────────────────────────────────────
    let transacoesMes = [];

    function abrirModalDia(dataStr, transacoesDoDia) {
        const parts = dataStr.split('-');
        const dt = new Date(parseInt(parts[0]), parseInt(parts[1]) - 1, parseInt(parts[2]));
        const diasLong = ['Domingo', 'Segunda-feira', 'Terça-feira', 'Quarta-feira', 'Quinta-feira', 'Sexta-feira', 'Sábado'];
        const mesesLong = ['Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];

        document.getElementById('modalDiaTitulo').textContent =
            `${diasLong[dt.getDay()]}, ${dt.getDate()} de ${mesesLong[dt.getMonth()]}`;
        document.getElementById('modalDiaSubtitulo').textContent = dt.getFullYear();
        const _carteiraQS = carteiraNovoLancamento ? `&carteira_id=${encodeURIComponent(carteiraNovoLancamento)}` : '';
        document.getElementById('modalDiaBtnReceita').href = `nova_transacao.php?tipo=receita&data=${dataStr}&voltar=agenda.php${_carteiraQS}`;
        document.getElementById('modalDiaBtnDespesa').href = `nova_transacao.php?tipo=despesa&data=${dataStr}&voltar=agenda.php${_carteiraQS}`;

        // Reset de seleção ao abrir novo dia
        _agendaSelIds.clear();
        _agendaSelMode = false;
        const _fNorm = document.getElementById('modalDiaFooterNormal');
        const _fSel  = document.getElementById('modalDiaFooterSel');
        if (_fNorm) _fNorm.style.display = '';
        if (_fSel)  _fSel.style.display  = 'none';

        const body = document.getElementById('modalDiaBody');
        if (!transacoesDoDia || transacoesDoDia.length === 0) {
            body.innerHTML = `<div class="text-center text-secondary py-5" style="font-size:0.88rem;">Nenhuma transação registrada neste dia.</div>`;
            body.onclick = null; body.oncontextmenu = null;
        } else {
            const incomeColor = cssVar('--color-income-text') || '#6ee7c7';
            const expenseColor = cssVar('--color-expense-text') || '#f87171';
            const cardColor = cssVar('--color-card-text') || '#a78bfa';
            const pendingColor = cssVar('--color-pending-text') || '#f5e2a0';
            body.innerHTML = transacoesDoDia.map(t => {
                const isRec = t.tipo === 'receita' || t.tipo === 'transferencia_entrada';
                const corVal = isRec ? incomeColor : expenseColor;
                const sinal = isRec ? '+' : '-';
                const statusBadge = t.status === 'efetivado' ?
                    `<span class="badge rounded-pill" style="background:var(--color-income-bg);color:${incomeColor};border:1px solid var(--color-income-border);font-size:0.65rem;white-space:nowrap;">Efetivado</span>` :
                    `<span class="badge rounded-pill" style="background:var(--color-pending-bg);color:${pendingColor};border:1px solid var(--color-today-bg);font-size:0.65rem;white-space:nowrap;">Pendente</span>`;
                const btnComp = (_temAcessoCompAgenda && t.tem_comprovante > 0) ?
                    `<button onclick="event.stopPropagation();abrirComprovantes('${t.id}')" class="btn btn-sm btn-outline-info rounded-pill px-2 py-0 mt-1" title="Ver comprovante"><i class="bi bi-eye"></i></button>` : '';
                const isCC = !!t.fatura_id;
                const iconeEsq = isCC ?
                    `<i class="bi bi-credit-card-2-front" style="color:${cardColor};font-size:1.3rem;flex-shrink:0;"></i>` :
                    `<i class="bi bi-arrow-${isRec ? 'up' : 'down'}-short" style="color:${corVal};font-size:1.5rem;flex-shrink:0;"></i>`;
                const tituloAttr = t.titulo.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
                return `<div class="dia-item d-flex align-items-center gap-3 px-4 py-3 border-bottom border-secondary-subtle"
                             data-id="${t.id}" data-is-cc="${isCC?'1':'0'}"
                             data-fatura="${t.fatura_id||''}" data-cartao="${t.cartao_id||''}"
                             data-titulo="${tituloAttr}"
                             style="cursor:pointer;transition:background .12s ease;user-select:none;">
                    <div class="dia-sel-chk flex-shrink-0" style="display:none;width:22px;text-align:center;">
                        <i class="bi bi-circle" style="font-size:0.92rem;color:#888;"></i>
                    </div>
                    ${iconeEsq}
                    <div style="min-width:0;flex:1;">
                        <div class="text-light fw-semibold text-truncate" style="font-size:0.88rem;">${esc(t.titulo)}</div>
                        <div class="text-secondary" style="font-size:0.72rem;">${isCC ? 'Fatura do cartão' : esc(t.categoria ?? 'Sem categoria')}</div>
                    </div>
                    <div class="text-end flex-shrink-0 d-flex flex-column align-items-end gap-1">
                        <span class="fw-bold" style="color:${corVal};font-size:0.88rem;">${sinal}${formatarMoeda(t.valor)}</span>
                        ${statusBadge}
                        ${btnComp}
                    </div>
                </div>`;
            }).join('');

            // Delegação de eventos nos itens
            body.onclick = function(e) {
                const item = e.target.closest('.dia-item');
                if (!item) return;
                if (e.target.closest('button')) return; // comprovante btn
                e.stopPropagation();
                const isCC = item.dataset.isCC === '1';
                if (_agendaSelMode) {
                    if (!isCC) _agendaToggleSel(item.dataset.id, item);
                    return;
                }
                if (isCC) abrirModalDiaCC(item.dataset.fatura, item.dataset.cartao);
                else window.location.href = `nova_transacao.php?voltar=agenda.php&editar=${encodeURIComponent(item.dataset.id)}`;
            };
            body.oncontextmenu = function(e) {
                const item = e.target.closest('.dia-item');
                if (!item || item.dataset.isCC === '1') return;
                e.preventDefault(); e.stopPropagation();
                window._mostrarMenuModalDia(e.clientX, e.clientY, item);
            };
            body.onmouseover = function(e) {
                const item = e.target.closest('.dia-item');
                if (item && !item.classList.contains('dia-item-sel')) item.style.background = 'rgba(255,255,255,0.03)';
            };
            body.onmouseout = function(e) {
                const item = e.target.closest('.dia-item');
                if (item && !item.classList.contains('dia-item-sel')) item.style.background = '';
            };
        }

        new bootstrap.Modal(document.getElementById('modalDia')).show();
    }

    function renderizarGrelha(ano, mes, transacoes) {
        const grid = document.getElementById('agenda-grid');
        grid.innerHTML = '';

        diasSemana.forEach(d => {
            const hdr = document.createElement('div');
            hdr.className = 'calendar-day-header';
            hdr.innerText = d;
            grid.appendChild(hdr);
        });

        const primeiroDia = new Date(ano, mes, 1).getDay();
        const totalDias = new Date(ano, mes + 1, 0).getDate();

        for (let i = 0; i < primeiroDia; i++) {
            const el = document.createElement('div');
            el.className = 'calendar-day empty';
            grid.appendChild(el);
        }

        for (let dia = 1; dia <= totalDias; dia++) {
            const dataStr = `${ano}-${String(mes+1).padStart(2,'0')}-${String(dia).padStart(2,'0')}`;
            const isHoje = (dia === HOJE_JS.getDate() && mes === HOJE_JS.getMonth() && ano === HOJE_JS.getFullYear());

            const cel = document.createElement('div');
            cel.className = `calendar-day${isHoje ? ' today' : ''}`;

            const num = document.createElement('div');
            num.className = 'day-number';
            num.innerText = dia;
            cel.appendChild(num);

            // Botões de ação (aparecem no hover)
            const actionsDiv = document.createElement('div');
            actionsDiv.className = 'day-actions';
            const _carteiraQSDia = carteiraNovoLancamento ? `&carteira_id=${encodeURIComponent(carteiraNovoLancamento)}` : '';
            actionsDiv.innerHTML = `
                <a href="nova_transacao.php?tipo=receita&data=${dataStr}&voltar=agenda.php${_carteiraQSDia}"
                   class="day-action-btn receita-btn" title="Nova Receita"
                   onclick="event.stopPropagation()"><i class="bi bi-plus-lg"></i></a>
                <a href="nova_transacao.php?tipo=despesa&data=${dataStr}&voltar=agenda.php${_carteiraQSDia}"
                   class="day-action-btn despesa-btn" title="Nova Despesa"
                   onclick="event.stopPropagation()"><i class="bi bi-plus-lg"></i></a>`;
            cel.appendChild(actionsDiv);

            const eventsDiv = document.createElement('div');
            eventsDiv.className = 'day-events';

            const transacoesDoDia = transacoes.filter(t =>
                (t.data_evento ? t.data_evento.split(' ')[0] : '') === dataStr
            );

            transacoesDoDia.forEach(t => {
                const isRec = t.tipo === 'receita' || t.tipo === 'transferencia_entrada';
                const cls = classEvento(t, dataStr);

                const pill = document.createElement('div');
                pill.className = `calendar-event ${cls}`;
                pill.title = `${t.titulo} — ${formatarMoeda(t.valor)}`;
                if (t.fatura_id) {
                    pill.onclick = (e) => {
                        e.stopPropagation();
                        abrirModalFaturaCC(t.fatura_id, t.cartao_id);
                    };
                    pill.innerHTML = `<i class="bi bi-credit-card-2-front" style="color:#a78bfa;font-size:0.8rem;flex-shrink:0;line-height:1;"></i><span class="event-desc">${esc(t.titulo)}</span>`;
                } else {
                    pill.onclick = (e) => {
                        e.stopPropagation();
                        window.location.href = `nova_transacao.php?voltar=agenda.php&editar=${encodeURIComponent(t.id)}`;
                    };
                    pill.addEventListener('contextmenu', (e) => {
                        e.preventDefault();
                        e.stopPropagation();
                        window._mostrarMenuPill(e.clientX, e.clientY, t);
                    });
                    // Drag-and-drop
                    pill.draggable = true;
                    pill.addEventListener('dragstart', (e) => {
                        e.stopPropagation();
                        e.dataTransfer.effectAllowed = 'move';
                        e.dataTransfer.setData('text/plain', t.id);
                        pill.classList.add('drag-arrastando');
                        setTimeout(() => pill.classList.add('drag-ghost'), 0);
                    });
                    pill.addEventListener('dragend', () => {
                        pill.classList.remove('drag-arrastando', 'drag-ghost');
                        document.querySelectorAll('.calendar-day.drag-over').forEach(c => c.classList.remove('drag-over'));
                    });
                    const arrow = isRec ?
                        `<i class="bi bi-arrow-up-short" style="color:#6ee7c7;font-size:0.95rem;flex-shrink:0;line-height:1;"></i>` :
                        `<i class="bi bi-arrow-down-short" style="color:#f87171;font-size:0.95rem;flex-shrink:0;line-height:1;"></i>`;
                    const rep = (t.Recorrente == 1) ?
                        `<i class="bi bi-arrow-repeat ms-1" style="opacity:0.55;font-size:0.6rem;flex-shrink:0;"></i>` : '';
                    pill.innerHTML = `${arrow}<span class="event-desc">${esc(t.titulo)}</span>${rep}`;
                }
                eventsDiv.appendChild(pill);
            });

            cel.appendChild(eventsDiv);

            // Drop zone — recebe pills arrastados
            cel.addEventListener('dragover', (e) => { e.preventDefault(); e.dataTransfer.dropEffect = 'move'; });
            cel.addEventListener('dragenter', (e) => { e.preventDefault(); cel.classList.add('drag-over'); });
            cel.addEventListener('dragleave', (e) => { if (!cel.contains(e.relatedTarget)) cel.classList.remove('drag-over'); });
            cel.addEventListener('drop', async (e) => {
                e.preventDefault();
                e.stopPropagation();
                cel.classList.remove('drag-over');
                const id = e.dataTransfer.getData('text/plain');
                if (!id) return;
                const fd = new FormData();
                fd.append('action', 'mover_dia');
                fd.append('registro_id', id);
                fd.append('nova_data', dataStr);
                try {
                    const res = await fetch('agenda.php', { method: 'POST', body: fd });
                    const json = await res.json();
                    if (json.ok) window.carregarMes(anoAtual, mesAtual);
                    else alert('Erro ao mover transação.');
                } catch { alert('Erro de conexão.'); }
            });

            // Clique no fundo do dia → modal de detalhes
            cel.addEventListener('click', () => abrirModalDia(dataStr, transacoesDoDia));

            grid.appendChild(cel);
        }
    }

    // ── Context menu dos pills do calendário ────────────────────────────────
    (function() {
        const menu = document.createElement('div');
        menu.id = 'ctx-pill';
        menu.style.cssText = 'position:fixed;z-index:9999;display:none;background:var(--bg-card-analysis);border:1px solid var(--border-color-analysis);border-radius:10px;box-shadow:0 8px 28px rgba(0,0,0,.25);min-width:168px;overflow:hidden;';
        menu.innerHTML = `
            <div id="ctx-editar"  class="ctx-item"><i class="bi bi-pencil-square" style="color:#f5c542;"></i> Editar</div>
            <div id="ctx-comp"    class="ctx-item" style="display:none;"><i class="bi bi-eye" style="color:#38bdf8;"></i> Ver comprovante</div>
            <div class="ctx-sep"></div>
            <div id="ctx-excluir" class="ctx-item ctx-danger"><i class="bi bi-trash3"></i> Excluir</div>`;
        document.body.appendChild(menu);

        const style = document.createElement('style');
        style.textContent = `.ctx-item{padding:9px 16px;cursor:pointer;font-size:.855rem;color:var(--text-light-analysis);display:flex;align-items:center;gap:9px;transition:background .1s}.ctx-item:hover{background:var(--bg-hover)}.ctx-danger{color:#f87171!important}.ctx-sep{height:1px;background:var(--border-color-analysis);margin:3px 0}`;
        document.head.appendChild(style);

        let _transacao = null;

        function fechar() {
            menu.style.display = 'none';
            _transacao = null;
        }
        document.addEventListener('click', fechar);
        document.addEventListener('keydown', e => e.key === 'Escape' && fechar());
        menu.addEventListener('click', e => e.stopPropagation());

        window._mostrarMenuPill = function(x, y, t) {
            _transacao = t;
            document.getElementById('ctx-editar').onclick = () => {
                fechar();
                window.location.href = `nova_transacao.php?voltar=agenda.php&editar=${encodeURIComponent(t.id)}`;
            };
            const btnComp = document.getElementById('ctx-comp');
            if (_temAcessoCompAgenda && t.tem_comprovante > 0) {
                btnComp.style.display = 'flex';
                btnComp.onclick = () => {
                    fechar();
                    abrirComprovantes(t.id);
                };
            } else {
                btnComp.style.display = 'none';
            }
            document.getElementById('ctx-excluir').onclick = () => {
                fechar();
                const desc = t.titulo.length > 40 ? t.titulo.slice(0, 40) + '…' : t.titulo;
                if (!confirm(`Excluir "${desc}"?\n\nEsta ação é irreversível.`)) return;
                fetch('agenda.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded'
                        },
                        body: `action=excluir_rapido&registro_id=${encodeURIComponent(t.id)}`
                    })
                    .then(r => r.json())
                    .then(d => {
                        if (d.ok) window.carregarMes(anoAtual, mesAtual);
                        else alert('Erro ao excluir.');
                    })
                    .catch(() => alert('Erro de conexão.'));
            };

            // Posiciona sem sair da viewport
            menu.style.display = 'block';
            const mw = menu.offsetWidth,
                mh = menu.offsetHeight;
            const vw = window.innerWidth,
                vh = window.innerHeight;
            menu.style.left = (x + mw > vw ? x - mw : x) + 'px';
            menu.style.top = (y + mh > vh ? y - mh : y) + 'px';
        };
    })();

    // ── Seleção múltipla de itens no modal de dia ─────────────────────────
    let _agendaSelMode = false;
    const _agendaSelIds = new Set();

    function _agendaEntrarSel() {
        _agendaSelMode = true;
        document.querySelectorAll('#modalDiaBody .dia-sel-chk').forEach(el => el.style.display = '');
        document.getElementById('modalDiaFooterNormal').style.display = 'none';
        document.getElementById('modalDiaFooterSel').style.display = 'flex';
        _agendaAtualizarBarra();
    }

    function _agendaSairSel() {
        _agendaSelMode = false;
        _agendaSelIds.clear();
        document.querySelectorAll('#modalDiaBody .dia-sel-chk').forEach(el => el.style.display = 'none');
        document.querySelectorAll('#modalDiaBody .dia-item-sel').forEach(el => {
            el.classList.remove('dia-item-sel');
            el.style.background = '';
            const chk = el.querySelector('.dia-sel-chk i');
            if (chk) { chk.className = 'bi bi-circle'; chk.style.color = '#888'; }
        });
        const fNorm = document.getElementById('modalDiaFooterNormal');
        const fSel  = document.getElementById('modalDiaFooterSel');
        if (fNorm) fNorm.style.display = '';
        if (fSel)  fSel.style.display  = 'none';
    }

    function _agendaToggleSel(id, item) {
        if (_agendaSelIds.has(id)) {
            _agendaSelIds.delete(id);
            item.classList.remove('dia-item-sel');
            item.style.background = '';
            const chk = item.querySelector('.dia-sel-chk i');
            if (chk) { chk.className = 'bi bi-circle'; chk.style.color = '#888'; }
        } else {
            _agendaSelIds.add(id);
            item.classList.add('dia-item-sel');
            item.style.background = 'rgba(245,158,11,0.08)';
            const chk = item.querySelector('.dia-sel-chk i');
            if (chk) { chk.className = 'bi bi-check-circle-fill'; chk.style.color = '#f59e0b'; }
        }
        _agendaAtualizarBarra();
    }

    function _agendaAtualizarBarra() {
        const n = _agendaSelIds.size;
        const countEl = document.getElementById('agendaSelCount');
        const btnEl   = document.getElementById('agendaSelExcluir');
        if (countEl) countEl.textContent = `${n} selecionado${n !== 1 ? 's' : ''}`;
        if (btnEl)   btnEl.disabled = n === 0;
    }

    function _agendaExcluirSelecionados() {
        const ids = [..._agendaSelIds];
        if (!ids.length) return;
        if (!confirm(`Excluir ${ids.length} transaç${ids.length > 1 ? 'ões' : 'ão'}?\n\nEsta ação é irreversível.`)) return;
        fetch('agenda.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `action=excluir_multiplos&ids=${encodeURIComponent(JSON.stringify(ids))}`
        }).then(r => r.json()).then(d => {
            if (d.ok) {
                _agendaSairSel();
                bootstrap.Modal.getInstance(document.getElementById('modalDia'))?.hide();
                window.carregarMes(anoAtual, mesAtual);
            } else alert('Erro ao excluir: ' + (d.erro || ''));
        }).catch(() => alert('Erro de conexão.'));
    }

    // Reset ao fechar o modal (DOMContentLoaded pois o HTML do modal vem após o script)
    document.addEventListener('DOMContentLoaded', function() {
        const el = document.getElementById('modalDia');
        if (el) el.addEventListener('hidden.bs.modal', _agendaSairSel);
    });

    // ── Context menu dos itens do modal de dia ────────────────────────────
    (function() {
        const menuM = document.createElement('div');
        menuM.id = 'ctx-modal-dia';
        menuM.style.cssText = 'position:fixed;z-index:10000;display:none;background:var(--bg-card-analysis);border:1px solid var(--border-color-analysis);border-radius:10px;box-shadow:0 8px 28px rgba(0,0,0,.35);min-width:185px;overflow:hidden;';
        menuM.innerHTML = `
            <div id="ctx-m-sel"    class="ctx-item"><i class="bi bi-check-circle" style="color:#f59e0b;"></i> Selecionar</div>
            <div id="ctx-m-edit"   class="ctx-item"><i class="bi bi-pencil-square" style="color:#f5c542;"></i> Editar</div>
            <div class="ctx-sep"></div>
            <div id="ctx-m-del"    class="ctx-item ctx-danger"><i class="bi bi-trash3"></i> Excluir</div>
            <div id="ctx-m-delsel" class="ctx-item ctx-danger" style="display:none;">
                <i class="bi bi-trash3-fill"></i> <span id="ctx-m-delsel-lbl">Excluir selecionados</span>
            </div>`;
        document.body.appendChild(menuM);

        function fecharM() { menuM.style.display = 'none'; }
        document.addEventListener('click', fecharM);
        document.addEventListener('keydown', e => e.key === 'Escape' && fecharM());
        menuM.addEventListener('click', e => e.stopPropagation());

        window._mostrarMenuModalDia = function(x, y, item) {
            const id = item.dataset.id;
            const titulo = item.dataset.titulo;

            document.getElementById('ctx-m-sel').onclick = () => {
                fecharM();
                if (!_agendaSelMode) _agendaEntrarSel();
                _agendaToggleSel(id, item);
            };
            document.getElementById('ctx-m-edit').onclick = () => {
                fecharM();
                window.location.href = `nova_transacao.php?voltar=agenda.php&editar=${encodeURIComponent(id)}`;
            };
            document.getElementById('ctx-m-del').onclick = () => {
                fecharM();
                const desc = titulo.length > 40 ? titulo.slice(0, 40) + '…' : titulo;
                if (!confirm(`Excluir "${desc}"?\n\nEsta ação é irreversível.`)) return;
                fetch('agenda.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `action=excluir_rapido&registro_id=${encodeURIComponent(id)}`
                }).then(r => r.json()).then(d => {
                    if (d.ok) { _agendaSairSel(); window.carregarMes(anoAtual, mesAtual); bootstrap.Modal.getInstance(document.getElementById('modalDia'))?.hide(); }
                    else alert('Erro ao excluir.');
                }).catch(() => alert('Erro de conexão.'));
            };

            const delSelBtn = document.getElementById('ctx-m-delsel');
            const n = _agendaSelIds.size;
            if (n > 0) {
                delSelBtn.style.display = '';
                document.getElementById('ctx-m-delsel-lbl').textContent = `Excluir ${n} selecionado${n > 1 ? 's' : ''}`;
                delSelBtn.onclick = () => { fecharM(); _agendaExcluirSelecionados(); };
            } else {
                delSelBtn.style.display = 'none';
            }

            menuM.style.display = 'block';
            const mw = menuM.offsetWidth, mh = menuM.offsetHeight;
            const vw = window.innerWidth, vh = window.innerHeight;
            menuM.style.left = (x + mw > vw ? x - mw : x) + 'px';
            menuM.style.top  = (y + mh > vh ? y - mh : y) + 'px';
        };
    })();

    // ── Modal de Fatura de Cartão ─────────────────────────────────────────
    async function abrirModalFaturaCC(faturaId, cartaoId) {
        const modalEl = document.getElementById('modalFaturaCC');
        const modal = new bootstrap.Modal(modalEl);
        document.getElementById('modalFaturaCCBody').innerHTML =
            '<div class="text-center py-5 text-secondary"><i class="bi bi-hourglass-split me-2"></i>Carregando...</div>';
        document.getElementById('modalFaturaCCLink').href =
            '/cartao_credito/fatura.php?cartao=' + encodeURIComponent(cartaoId);
        modal.show();
        try {
            const r = await fetch(`agenda.php?ajax=1&acao=fatura_detalhe&fatura_id=${encodeURIComponent(faturaId)}`);
            const data = await r.json();
            if (!data.ok) {
                document.getElementById('modalFaturaCCBody').innerHTML = '<p class="text-danger text-center py-4 px-4">Erro ao carregar fatura.</p>';
                return;
            }

            const f = data.fatura;
            const cor = f.Cor || '#7c3aed';
            const stMap = {
                aberta: ['#22c55e', 'ABERTA'],
                fechada: ['#FFB800', 'FECHADA'],
                paga: ['#6ee7c7', 'PAGA']
            };
            const [stCor, stLabel] = stMap[f.Status] || ['#888', '—'];
            const total = data.lancamentos.reduce((s, l) => s + parseFloat(l.Valor), 0);
            const fmtD = d => d ? d.slice(8, 10) + '/' + d.slice(5, 7) + '/' + d.slice(0, 4) : '—';
            const fmtV = v => parseFloat(v).toLocaleString('pt-BR', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });

            let html = `<div class="px-4 pt-3 pb-3" style="border-bottom:1px solid var(--border-color-analysis);">
                <div class="d-flex align-items-center gap-3">
                    <div class="rounded-3 d-flex align-items-center justify-content-center flex-shrink-0"
                         style="width:42px;height:42px;background:${cor}22;">
                        <i class="bi bi-credit-card-2-front" style="color:${cor};font-size:1.15rem;"></i>
                    </div>
                    <div>
                        <div class="fw-bold text-light" style="font-size:1rem;">${esc(f.NomeCartao)}</div>
                        <span style="display:inline-flex;align-items:center;background:${stCor}18;color:${stCor};border:1px solid ${stCor}33;border-radius:999px;padding:1px 8px;font-size:0.62rem;font-weight:700;margin-top:3px;">● ${stLabel}</span>
                    </div>
                    <div class="ms-auto text-end">
                        <div class="text-secondary" style="font-size:0.68rem;">Fecha ${fmtD(f.DataFechamento)}</div>
                        <div class="text-secondary" style="font-size:0.68rem;">Vence ${fmtD(f.DataVencimento)}</div>
                        <div class="fw-bold text-danger mt-1" style="font-size:1.1rem;">R$ ${fmtV(total)}</div>
                    </div>
                </div>
            </div>`;

            if (!data.lancamentos.length) {
                html += '<div class="text-center text-secondary py-5" style="font-size:0.85rem;">Nenhum lançamento nesta fatura.</div>';
            } else {
                data.lancamentos.forEach(l => {
                    const dt = l.DataCompra ? l.DataCompra.slice(8, 10) + '/' + l.DataCompra.slice(5, 7) : '—';
                    const parc = parseInt(l.TotalParcelas) > 1 ?
                        `<span style="display:inline-flex;align-items:center;background:rgba(124,58,237,.18);color:#a78bfa;border:1px solid rgba(124,58,237,.3);border-radius:999px;padding:0 5px;font-size:0.58rem;font-weight:700;margin-left:4px;">${l.ParcelaAtual}/${l.TotalParcelas}x</span>` : '';
                    html += `<div class="d-flex align-items-center gap-3 px-4 py-2" style="border-bottom:1px solid var(--bs-border-color);">
                        <div style="min-width:0;flex:1;">
                            <div class="text-light d-flex align-items-center flex-wrap" style="font-size:0.83rem;">${esc(l.Descricao)}${parc}</div>
                            <div class="text-secondary" style="font-size:0.7rem;">${l.NomeCategoria ? esc(l.NomeCategoria) : '—'} · ${dt}</div>
                        </div>
                        <span class="fw-bold text-danger flex-shrink-0" style="font-size:0.85rem;">R$ ${fmtV(l.Valor)}</span>
                    </div>`;
                });
                html += `<div class="d-flex justify-content-between px-4 py-3" style="border-top:1px solid var(--border-color-analysis);">
                    <span class="text-secondary fw-semibold" style="font-size:0.83rem;">Total</span>
                    <span class="fw-bold text-danger" style="font-size:0.9rem;">R$ ${fmtV(total)}</span>
                </div>`;
            }
            document.getElementById('modalFaturaCCBody').innerHTML = html;
        } catch (e) {
            document.getElementById('modalFaturaCCBody').innerHTML = '<p class="text-danger text-center py-4 px-4">Erro ao carregar fatura.</p>';
        }
    }

    function abrirModalDiaCC(faturaId, cartaoId) {
        const diaModal = bootstrap.Modal.getInstance(document.getElementById('modalDia'));
        if (diaModal) {
            document.getElementById('modalDia').addEventListener('hidden.bs.modal', function once() {
                this.removeEventListener('hidden.bs.modal', once);
                abrirModalFaturaCC(faturaId, cartaoId);
            });
            diaModal.hide();
        } else {
            abrirModalFaturaCC(faturaId, cartaoId);
        }
    }

    document.addEventListener("DOMContentLoaded", () => window.carregarMes(anoAtual, mesAtual));

    function abrirComprovantes(registroId) {
        const modal = new bootstrap.Modal(document.getElementById('modalComprovantesAgenda'));
        const body = document.getElementById('modalComprovantesAgendaBody');
        body.innerHTML = '<div class="text-center text-secondary py-4"><i class="bi bi-hourglass-split me-2"></i>Carregando...</div>';
        modal.show();
        fetch('/comprovante/listar_ajax.php?registro=' + encodeURIComponent(registroId))
            .then(r => r.json())
            .then(data => {
                if (data.erro) {
                    body.innerHTML = '<p class="text-danger text-center py-3">' + data.erro + '</p>';
                    return;
                }
                if (!data.arquivos.length) {
                    body.innerHTML = '<p class="text-secondary text-center py-3">Nenhum comprovante encontrado.</p>';
                    return;
                }
                let html = '<div class="d-flex flex-column gap-3">';
                data.arquivos.forEach(a => {
                    const isImg = a.TipoMime.startsWith('image/');
                    const url = '/comprovante/ver.php?id=' + encodeURIComponent(a.IDComprovante);
                    if (isImg) {
                        html += `<div class="text-center"><img src="${url}" class="img-fluid rounded-3" style="max-height:380px;object-fit:contain;" alt="${a.NomeOriginal}">
                                 <p class="text-secondary small mt-2">${a.NomeOriginal}</p></div>`;
                    } else {
                        html += `<div class="d-flex align-items-center gap-3 p-3 rounded-3" style="background:var(--bg-hover);border:1px solid var(--border-color-analysis);">
                                     <i class="bi bi-file-earmark-pdf fs-2 text-danger"></i>
                                     <div class="flex-grow-1"><p class="text-light mb-0 fw-semibold">${a.NomeOriginal}</p></div>
                                     <a href="${url}" target="_blank" class="btn btn-sm btn-outline-secondary rounded-pill">Abrir</a>
                                     <a href="${url}?download=1" class="btn btn-sm btn-outline-primary rounded-pill">Baixar</a>
                                 </div>`;
                    }
                });
                html += '</div>';
                body.innerHTML = html;
            })
            .catch(() => {
                body.innerHTML = '<p class="text-danger text-center py-3">Erro ao carregar comprovantes.</p>';
            });
    }
</script>

<!-- ═══════════════════════════════════════════════════════════════════════
     MODAL: DETALHES DO DIA
     ═══════════════════════════════════════════════════════════════════ -->
<div class="modal fade" id="modalDia" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable" style="max-width: 500px;">
        <div class="modal-content border-secondary-subtle rounded-4" style="background:var(--bg-card-analysis);">
            <div class="modal-header border-bottom border-secondary-subtle px-4 py-3 d-flex align-items-start gap-3">
                <div>
                    <h5 class="modal-title text-light fw-bold mb-0" id="modalDiaTitulo">—</h5>
                    <div class="text-secondary" id="modalDiaSubtitulo" style="font-size:0.8rem;">—</div>
                </div>
                <button type="button" class="btn-close ms-auto flex-shrink-0 mt-1" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0" id="modalDiaBody"></div>
            <div class="modal-footer border-top border-secondary-subtle px-4 py-3">
                <div id="modalDiaFooterNormal" class="d-flex gap-2 flex-wrap w-100 align-items-center">
                    <a id="modalDiaBtnReceita" href="#" class="btn btn-sm rounded-pill fw-semibold"
                        style="background:rgba(6,214,160,0.15);color:#6ee7c7;border:1px solid rgba(6,214,160,0.4);">
                        <i class="bi bi-arrow-up-short me-1"></i> Nova Receita
                    </a>
                    <a id="modalDiaBtnDespesa" href="#" class="btn btn-sm rounded-pill fw-semibold"
                        style="background:rgba(230,57,70,0.15);color:#f87171;border:1px solid rgba(230,57,70,0.4);">
                        <i class="bi bi-arrow-down-short me-1"></i> Nova Despesa
                    </a>
                    <button type="button" class="btn btn-link text-secondary text-decoration-none ms-auto p-0"
                        data-bs-dismiss="modal" style="font-size:0.82rem;">Fechar</button>
                </div>
                <div id="modalDiaFooterSel" class="w-100 align-items-center gap-2" style="display:none;">
                    <span id="agendaSelCount" class="text-secondary small fw-semibold">0 selecionados</span>
                    <button id="agendaSelExcluir" onclick="_agendaExcluirSelecionados()"
                            class="btn btn-sm btn-danger rounded-pill ms-auto" disabled>
                        <i class="bi bi-trash3 me-1"></i> Excluir selecionados
                    </button>
                    <button onclick="_agendaSairSel()"
                            class="btn btn-sm btn-outline-secondary rounded-pill">
                        Cancelar
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- MODAL: FATURA DE CARTÃO DE CRÉDITO -->
<div class="modal fade" id="modalFaturaCC" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable" style="max-width:480px;">
        <div class="modal-content border-secondary-subtle rounded-4" style="background:var(--bg-card-analysis);">
            <div class="modal-header border-secondary-subtle px-4 py-3">
                <h6 class="modal-title fw-bold text-light mb-0">
                    <i class="bi bi-credit-card-2-front me-2" style="color:#a78bfa;"></i>Fatura do Cartão
                </h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0" id="modalFaturaCCBody"></div>
            <div class="modal-footer border-secondary-subtle px-4 py-3 d-flex justify-content-between">
                <a id="modalFaturaCCLink" href="#" class="btn btn-sm fw-semibold rounded-pill px-3"
                    style="background:rgba(124,58,237,.18);color:#a78bfa;border:1px solid rgba(124,58,237,.3);">
                    <i class="bi bi-arrow-right-circle me-1"></i> Ver fatura completa
                </a>
                <button type="button" class="btn btn-sm btn-link text-secondary text-decoration-none"
                    data-bs-dismiss="modal">Fechar</button>
            </div>
        </div>
    </div>
</div>

<!-- MODAL: VISUALIZAR COMPROVANTES -->
<div class="modal fade" id="modalComprovantesAgenda" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-secondary-subtle" style="background:var(--bg-card);">
            <div class="modal-header border-secondary-subtle px-4 py-3">
                <h6 class="modal-title fw-bold text-light mb-0"><i class="bi bi-paperclip me-2"></i>Comprovantes</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4" id="modalComprovantesAgendaBody">
                <div class="text-center text-secondary py-4"><i class="bi bi-hourglass-split me-2"></i>Carregando...</div>
            </div>
        </div>
    </div>
</div>

<?php require_once 'geral/footer.php'; ?>