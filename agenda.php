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

$usuario_id = $_SESSION['usuario_id'];

// ==============================================================================
// AJAX
// ==============================================================================
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
                   c.NomeCategoria as categoria, COALESCE(c.IconeCategoria, 'bi-tag') as icone
            FROM Registro r
            LEFT JOIN Categoria c ON r.FKCategoria = c.IDCategoria
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
            if ($t['TipoRegistro'] === 'receita') {
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
                   r.Valor as valor, r.DataVencimento as data_vencimento
            FROM Registro r
            WHERE r.FKUsuario = :uid AND r.StatusRegistro = 'pendente' AND r.DataVencimento IS NOT NULL
            $carteiraFilter ORDER BY r.DataVencimento ASC");
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
    $stmtCart = $pdo->prepare("SELECT IDCarteira, TipoCarteira FROM Carteira WHERE FKUsuarioDono = :uid ORDER BY TipoCarteira ASC");
    $stmtCart->execute([':uid' => $usuario_id]);
    $carteiras = $stmtCart->fetchAll();
} catch (PDOException $e) {
}

$carteira_selecionada = $_GET['carteira'] ?? 'todas';
$nome_carteira_atual  = 'Todas as Carteiras';
if ($carteira_selecionada !== 'todas') {
    foreach ($carteiras as $c) {
        if ($c['IDCarteira'] == $carteira_selecionada) {
            $nome_carteira_atual = $c['TipoCarteira'];
            break;
        }
    }
}

require_once 'geral/header.php';
?>

<main class="container-fluid py-4 mt-2 flex-grow-1" style="max-width: 1500px; padding-inline: var(--space-page-x); min-height: 100vh;">

    <!-- ── Cabeçalho ─────────────────────────────────────────────────────── -->
    <div class="mb-4 border-bottom border-secondary-subtle pb-3">
        <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3">

            <h2 class="fw-bold text-light mb-0 d-flex align-items-center gap-2" style="font-size: clamp(1.2rem, 3vw, 1.5rem);">
                <i class="bi bi-calendar3" style="color:#AA8C2C;"></i> Agenda Financeira
            </h2>

            <div class="d-flex align-items-center gap-2 gap-md-3 w-100 w-lg-auto flex-wrap">

                <!-- Filtro de carteira -->
                <div class="dropdown" style="min-width: 0;">
                    <button class="btn border-secondary-subtle text-light shadow-sm fw-semibold dropdown-toggle d-flex align-items-center rounded-3 transition-hover"
                        type="button" data-bs-toggle="dropdown"
                        style="font-size: 0.875rem; background-color: #222222; padding: 0.45rem 1rem; white-space: nowrap;">
                        <i class="bi bi-wallet2 me-2 flex-shrink-0" style="color: #AA8C2C;"></i>
                        <span class="text-truncate" style="max-width: 140px;"><?= htmlspecialchars($nome_carteira_atual) ?></span>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-dark shadow-lg border-secondary-subtle mt-2" style="background-color: #1a1d21; min-width: 220px;">
                        <li class="px-3 py-1 text-secondary small text-uppercase fw-bold">Filtrar Escopo</li>
                        <li>
                            <hr class="dropdown-divider border-secondary-subtle">
                        </li>
                        <li>
                            <a class="dropdown-item d-flex align-items-center py-2 <?= $carteira_selecionada === 'todas' ? 'active' : '' ?>" href="?carteira=todas">
                                <i class="bi <?= $carteira_selecionada === 'todas' ? 'bi-check-circle-fill' : 'bi-circle' ?> me-2 flex-shrink-0"></i>
                                <span style="color:<?= $carteira_selecionada === 'todas' ? '#AA8C2C' : 'inherit' ?>">Todas as Carteiras</span>
                            </a>
                        </li>
                        <?php foreach ($carteiras as $cart): ?>
                            <li>
                                <a class="dropdown-item d-flex align-items-center py-2 <?= $carteira_selecionada == $cart['IDCarteira'] ? 'active' : '' ?>" href="?carteira=<?= htmlspecialchars($cart['IDCarteira']) ?>">
                                    <i class="bi <?= $carteira_selecionada == $cart['IDCarteira'] ? 'bi-check-circle-fill' : 'bi-circle' ?> me-2 flex-shrink-0"></i>
                                    <span class="text-truncate" style="max-width:170px;"><?= htmlspecialchars($cart['TipoCarteira']) ?></span>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>

                <!-- Navegação de mês -->
                <div class="d-flex align-items-center bg-dark border border-secondary-subtle rounded-pill shadow-sm px-2 py-1 gap-1">
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
                <a href="nova_transacao.php" class="btn btn-gold fw-bold text-dark rounded-pill px-3 d-flex align-items-center gap-2 flex-shrink-0" style="white-space:nowrap;">
                    <i class="bi bi-plus-lg"></i> Nova transação
                </a>

            </div>
        </div>
    </div>

    <!-- ── Cards de resumo ───────────────────────────────────────────────── -->
    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="card border-secondary-subtle shadow-sm rounded-4" style="background-color:#1c1f24;">
                <div class="card-body p-3">
                    <div class="d-flex align-items-center gap-2 mb-2" style="font-size:0.8rem; color:#888;">
                        <i class="bi bi-check-circle text-success"></i> Efetivado no mês
                    </div>
                    <h4 id="saldoEfetivado" class="fw-bold mb-0 text-secondary">—</h4>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-secondary-subtle shadow-sm rounded-4" style="background-color:#1c1f24;">
                <div class="card-body p-3">
                    <div class="d-flex align-items-center gap-2 mb-2" style="font-size:0.8rem; color:#888;">
                        <i class="bi bi-hourglass-split" style="color:#E63946;"></i> A pagar no mês
                    </div>
                    <h4 id="saldoPago" class="fw-bold mb-0 text-danger">—</h4>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-secondary-subtle shadow-sm rounded-4" style="background-color:#1c1f24;">
                <div class="card-body p-3">
                    <div class="d-flex align-items-center gap-2 mb-2" style="font-size:0.8rem; color:#888;">
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
            <div class="card border-secondary-subtle shadow-sm rounded-4 overflow-hidden" style="background-color:#1c1f24;">
                <div class="card-body p-2 p-md-3">
                    <div id="agenda-grid" class="calendar-grid"></div>
                </div>
            </div>
        </div>

        <!-- Sidebar -->
        <div class="col-xl-4 col-lg-5">

            <!-- Atrasadas -->
            <div id="panel-atrasadas" class="rounded-4 mb-3 d-none overflow-hidden"
                style="background-color:#1e1214; border:1px solid rgba(230,57,70,0.35);">
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
                style="background-color:#1e1a10; border:1px solid rgba(255,184,0,0.35);">
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
                style="background-color:#10141e; border:1px solid rgba(59,130,246,0.35);">
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
        background-color: #2a2d33;
        border-radius: 10px;
        overflow: hidden;
    }

    .calendar-day-header {
        background-color: #1a1d21;
        color: #AA8C2C;
        text-align: center;
        padding: 10px 4px;
        font-weight: 700;
        font-size: 0.75rem;
        text-transform: uppercase;
        letter-spacing: 0.05em;
    }

    .calendar-day {
        background-color: #1c1f24;
        min-height: 110px;
        padding: 6px;
        display: flex;
        flex-direction: column;
        position: relative;
        transition: background-color 0.15s ease;
    }

    .calendar-day:hover:not(.empty) {
        background-color: #21252b;
    }

    .calendar-day.empty {
        background-color: #15171a;
        opacity: 0.4;
    }

    .calendar-day.today {
        background-color: rgba(170, 140, 44, 0.05);
        box-shadow: inset 0 0 0 1px #AA8C2C;
    }

    .calendar-day.today .day-number {
        background-color: #AA8C2C;
        color: #121418;
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
        color: #666;
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
        background-color: #444;
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
    }

    .calendar-event:hover {
        filter: brightness(1.25);
        transform: translateY(-1px);
    }

    .calendar-event.evento-pago {
        background-color: rgba(6, 214, 160, 0.18);
        color: #b8f5e8;
    }

    .calendar-event.evento-atrasado {
        background-color: rgba(230, 57, 70, 0.22);
        color: #f8b4b9;
    }

    .calendar-event.evento-hoje {
        background-color: rgba(255, 184, 0, 0.22);
        color: #f5e2a0;
    }

    .calendar-event.evento-pendente {
        background-color: rgba(59, 130, 246, 0.22);
        color: #a0c4f8;
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
        border-bottom: 1px solid rgba(255, 255, 255, 0.05);
        cursor: pointer;
        transition: background-color 0.15s ease;
    }

    .sidebar-item:last-child {
        border-bottom: none;
    }

    .sidebar-item:hover {
        background-color: rgba(255, 255, 255, 0.04);
    }
</style>

<script>
    const carteiraAtual = "<?= htmlspecialchars($carteira_selecionada, ENT_QUOTES, 'UTF-8') ?>";
    let anoAtual = new Date().getFullYear();
    let mesAtual = new Date().getMonth();
    const HOJE_JS = new Date();

    const mesesNomes = ['Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];
    const diasSemana = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'];

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
        const isRec = item.tipo === 'receita';
        const corValor = isRec ? '#6ee7c7' : '#f87171';
        const sinal = isRec ? '+' : '-';
        const arrow = isRec ?
            `<i class="bi bi-arrow-up-short" style="color:#6ee7c7;font-size:1.15rem;flex-shrink:0;"></i>` :
            `<i class="bi bi-arrow-down-short" style="color:#f87171;font-size:1.15rem;flex-shrink:0;"></i>`;

        return `<div class="sidebar-item"
                     onclick="window.location.href='nova_transacao.php?editar=${encodeURIComponent(item.id)}'">
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

            const eventsDiv = document.createElement('div');
            eventsDiv.className = 'day-events';

            transacoes
                .filter(t => (t.data_evento ? t.data_evento.split(' ')[0] : '') === dataStr)
                .forEach(t => {
                    const isRec = t.tipo === 'receita';
                    const cls = classEvento(t, dataStr);

                    const pill = document.createElement('div');
                    pill.className = `calendar-event ${cls}`;
                    pill.title = `${t.titulo} — ${formatarMoeda(t.valor)}`;
                    pill.onclick = () => window.location.href = `nova_transacao.php?editar=${encodeURIComponent(t.id)}`;

                    const arrow = isRec ?
                        `<i class="bi bi-arrow-up-short" style="color:#6ee7c7;font-size:0.95rem;flex-shrink:0;line-height:1;"></i>` :
                        `<i class="bi bi-arrow-down-short" style="color:#f87171;font-size:0.95rem;flex-shrink:0;line-height:1;"></i>`;
                    const rep = (t.Recorrente == 1) ?
                        `<i class="bi bi-arrow-repeat ms-1" style="opacity:0.55;font-size:0.6rem;flex-shrink:0;"></i>` :
                        '';

                    pill.innerHTML = `${arrow}<span class="event-desc">${esc(t.titulo)}</span>${rep}`;
                    eventsDiv.appendChild(pill);
                });

            cel.appendChild(eventsDiv);
            grid.appendChild(cel);
        }
    }

    document.addEventListener("DOMContentLoaded", () => window.carregarMes(anoAtual, mesAtual));
</script>

<?php require_once 'geral/footer.php'; ?>