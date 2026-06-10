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
// 1. MOTOR AJAX (Retorna as Transações da Grelha + Saldos Calculados)
// ==============================================================================
if (isset($_GET['ajax']) && $_GET['acao'] === 'listar') {
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');

    $mes_str  = $_GET['mes'] ?? date('Y-m');
    $carteira = $_GET['carteira'] ?? 'todas';

    $mes_alvo = (int)date('m', strtotime($mes_str . '-01'));
    $ano_alvo = (int)date('Y', strtotime($mes_str . '-01'));

    // ─── QUERY DA GRELHA (Visual) ─────────────────────────────────────────────
    $whereTransacoes = "r.FKUsuario = :uid AND MONTH(COALESCE(r.DataVencimento, r.MomentoRegistro)) = :mes AND YEAR(COALESCE(r.DataVencimento, r.MomentoRegistro)) = :ano";
    $params = [':uid' => $usuario_id, ':mes' => $mes_alvo, ':ano' => $ano_alvo];

    if ($carteira !== 'todas' && $carteira !== '') {
        $whereTransacoes .= " AND r.FKCarteira = :cid";
        $params[':cid'] = $carteira;
    }

    try {
        $sqlGrelha = "
            SELECT r.IDRegistro as id,
                   r.TipoRegistro as tipo,
                   r.Descricao as titulo,
                   r.Valor as valor,
                   r.StatusRegistro as status,
                   r.Recorrente,
                   COALESCE(r.DataVencimento, r.MomentoRegistro) as data_evento,
                   c.NomeCategoria as categoria,
                   COALESCE(c.IconeCategoria, 'bi-tag') as icone
            FROM Registro r
            LEFT JOIN Categoria c ON r.FKCategoria = c.IDCategoria
            WHERE $whereTransacoes
            ORDER BY data_evento ASC
        ";
        $stmtGrelha = $pdo->prepare($sqlGrelha);
        $stmtGrelha->execute($params);
        $transacoesGrelha = $stmtGrelha->fetchAll(PDO::FETCH_ASSOC);

        // ─── QUERY DE SALDOS (Lógica idêntica ao Dashboard) ───────────────────
        $whereSaldos = "FKUsuario = :uid AND MONTH(MomentoRegistro) = :mes AND YEAR(MomentoRegistro) = :ano";
        if ($carteira !== 'todas' && $carteira !== '') {
            $whereSaldos .= " AND FKCarteira = :cid";
        }

        $sqlSaldos = "SELECT Valor, TipoRegistro, StatusRegistro FROM Registro WHERE $whereSaldos";
        $stmtSaldos = $pdo->prepare($sqlSaldos);
        $stmtSaldos->execute($params);
        $transacoesSoma = $stmtSaldos->fetchAll(PDO::FETCH_ASSOC);

        $totRecEfet = 0;
        $totDesEfet = 0;
        $totRecPend = 0;
        $totDesPend = 0;

        foreach ($transacoesSoma as $t) {
            $v = (float)$t['Valor'];
            if ($t['TipoRegistro'] === 'receita') {
                if ($t['StatusRegistro'] === 'efetivado') $totRecEfet += $v;
                else $totRecPend += $v;
            } else {
                if ($t['StatusRegistro'] === 'efetivado') $totDesEfet += $v;
                else $totDesPend += $v;
            }
        }

        $saldos_calculados = [
            'efetivado' => $totRecEfet - $totDesEfet,
            'pago'      => $totDesEfet,
            'esperado'  => ($totRecEfet + $totRecPend) - ($totDesEfet + $totDesPend)
        ];

        echo json_encode(['sucesso' => true, 'dados' => $transacoesGrelha, 'saldos' => $saldos_calculados]);
    } catch (PDOException $e) {
        echo json_encode(['sucesso' => false, 'erro' => $e->getMessage()]);
    }
    exit;
}

// ==============================================================================
// 2. RENDERIZAÇÃO DA PÁGINA NORMAL
// ==============================================================================

$carteiras = [];
try {
    $sqlCart = "SELECT IDCarteira, TipoCarteira FROM Carteira WHERE FKUsuarioDono = :uid ORDER BY TipoCarteira ASC";
    $stmtCart = $pdo->prepare($sqlCart);
    $stmtCart->execute([':uid' => $usuario_id]);
    $carteiras = $stmtCart->fetchAll();
} catch (PDOException $e) {
}

$carteira_selecionada = $_GET['carteira'] ?? 'todas';
$nome_carteira_atual = 'Todas as Carteiras';

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

    <div class="mb-4 border-bottom border-secondary-subtle pb-3">
        <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3">

            <h2 class="fw-bold text-light mb-0 d-flex align-items-center gap-2" style="font-size: clamp(1.2rem, 3vw, 1.5rem);">
                Agenda Financeira
            </h2>

            <div class="d-flex align-items-center gap-3 w-100 w-lg-auto">

                <div class="dropdown flex-grow-1 flex-lg-grow-0" style="min-width: 0;">
                    <button class="btn border-secondary-subtle text-light shadow-sm fw-semibold dropdown-toggle d-flex align-items-center justify-content-between rounded-3 transition-hover w-100"
                        type="button" data-bs-toggle="dropdown" style="font-size: 0.875rem; background-color: #222222; padding: 0.45rem 1rem;">
                        <div class="d-flex align-items-center text-start" style="min-width: 0;">
                            <i class="bi bi-wallet2 me-2 flex-shrink-0" style="color: #AA8C2C;"></i>
                            <span class="text-truncate" style="max-width: 140px;"><?php echo htmlspecialchars($nome_carteira_atual); ?></span>
                        </div>
                    </button>

                    <ul class="dropdown-menu dropdown-menu-dark shadow-lg border-secondary-subtle mt-2 w-100" style="background-color: #1a1d21; min-width: 220px;">
                        <li class="px-3 py-1 text-secondary small text-uppercase fw-bold tracking-wide">Filtrar Escopo</li>
                        <li>
                            <hr class="dropdown-divider border-secondary-subtle">
                        </li>

                        <li>
                            <a class="dropdown-item d-flex align-items-center py-2 transition-hover <?php echo $carteira_selecionada === 'todas' ? 'active' : '' ?>" href="?carteira=todas">
                                <i class="bi <?php echo $carteira_selecionada === 'todas' ? 'bi-check-circle-fill' : 'bi-circle'; ?> me-2 flex-shrink-0" style="color: <?php echo $carteira_selecionada === 'todas' ? '#ffffff' : 'rgba(255,255,255,0.5)'; ?>"></i>
                                <span class="<?php echo $carteira_selecionada === 'todas' ? 'fw-bold' : ''; ?>" style="color: <?php echo $carteira_selecionada === 'todas' ? '#AA8C2C' : 'inherit'; ?>">Todas as Carteiras</span>
                            </a>
                        </li>

                        <?php foreach ($carteiras as $cart): ?>
                            <li>
                                <a class="dropdown-item d-flex align-items-center py-2 transition-hover <?php echo $carteira_selecionada == $cart['IDCarteira'] ? 'active' : '' ?>" href="?carteira=<?php echo htmlspecialchars($cart['IDCarteira']); ?>">
                                    <i class="bi <?php echo $carteira_selecionada == $cart['IDCarteira'] ? 'bi-check-circle-fill' : 'bi-circle'; ?> me-2 flex-shrink-0" style="color: <?php echo $carteira_selecionada == $cart['IDCarteira'] ? '#ffffff' : 'rgba(255,255,255,0.5)'; ?>"></i>
                                    <span class="text-truncate <?php echo $carteira_selecionada == $cart['IDCarteira'] ? 'fw-bold' : ''; ?>" style="max-width: 170px; color: <?php echo $carteira_selecionada == $cart['IDCarteira'] ? '#ffffff' : 'inherit'; ?>">
                                        <?php echo htmlspecialchars($cart['TipoCarteira']); ?>
                                    </span>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>

                <div class="d-flex align-items-center bg-dark border border-secondary-subtle rounded-pill shadow-sm justify-content-center px-2 py-1 gap-1">
                    <button type="button" class="btn btn-sm btn-link text-secondary shadow-none" onclick="window.mudarMes(-1)">
                        <i class="bi bi-chevron-left fs-6"></i>
                    </button>
                    <span id="mesAnoTitulo" class="text-light fw-bold fs-6 mx-2" style="min-width: 140px; text-align: center;">Carregando...</span>
                    <button type="button" class="btn btn-sm btn-link text-secondary shadow-none" onclick="window.mudarMes(1)">
                        <i class="bi bi-chevron-right fs-6"></i>
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill ms-1 px-3" style="font-size: 0.75rem;" onclick="window.irHoje()">Hoje</button>
                </div>

            </div>
        </div>
    </div>

    <div class="card bg-dark border-secondary-subtle shadow-sm rounded-4 p-4 mb-5">
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
            <h5 class="text-light fw-bold mb-0">
                <i class="bi bi-grid-3x3-gap text-secondary me-2"></i> Fluxo Mensal Detalhado
            </h5>
            <div class="d-flex gap-3 text-secondary small align-items-center fw-semibold">
                <div><span class="badge rounded-circle p-1 me-1" style="background-color: rgba(6, 214, 160, 0.2);"></span> Receita</div>
                <div><span class="badge rounded-circle p-1 me-1" style="background-color: rgba(230, 57, 70, 0.2);"></span> Despesa</div>
                <div><span class="badge rounded-circle p-1 me-1" style="border: 2px solid #FFB800;"></span> Pendente</div>
            </div>
        </div>

        <div id="agenda-grid" class="calendar-grid">
        </div>
    </div>

</main>

<style>
    .calendar-grid {
        display: grid;
        grid-template-columns: repeat(7, 1fr);
        gap: 1px;
        background-color: #333333;
        border: 1px solid #333333;
        border-radius: 12px;
        overflow: hidden;
    }

    .calendar-day-header {
        background-color: #1a1d21;
        color: #AA8C2C;
        text-align: center;
        padding: 12px 8px;
        font-weight: 700;
        font-size: 0.8rem;
        text-transform: uppercase;
        letter-spacing: 0.05em;
    }

    .calendar-day {
        background-color: #1c1f24;
        min-height: 125px;
        padding: 8px;
        display: flex;
        flex-direction: column;
        position: relative;
        transition: background-color 0.2s ease;
    }

    .calendar-day:hover:not(.empty) {
        background-color: #21252b;
    }

    .calendar-day.empty {
        background-color: #15171a;
        opacity: 0.4;
    }

    .calendar-day.today {
        background-color: rgba(170, 140, 44, 0.04);
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
        font-size: 0.85rem;
        color: #888888;
        margin-bottom: 6px;
    }

    .day-events {
        display: flex;
        flex-direction: column;
        gap: 4px;
        overflow-y: auto;
        flex-grow: 1;
        max-height: 100px;
        padding-right: 2px;
    }

    .day-events::-webkit-scrollbar {
        width: 4px;
    }

    .day-events::-webkit-scrollbar-thumb {
        background-color: #444;
        border-radius: 10px;
    }

    .calendar-event {
        font-size: 0.72rem;
        padding: 4px 6px;
        border-radius: 6px;
        display: flex;
        flex-direction: column;
        line-height: 1.3;
        font-weight: 600;
        cursor: pointer;
        transition: transform 0.1s ease, filter 0.1s ease;
    }

    .calendar-event:hover {
        transform: translateY(-1px);
        filter: brightness(1.2);
    }

    .calendar-event.receita {
        background-color: rgba(6, 214, 160, 0.12);
        color: #06D6A0;
    }

    .calendar-event.despesa {
        background-color: rgba(230, 57, 70, 0.12);
        color: #E63946;
    }

    .calendar-event.pendente {
        border-left: 3px solid #FFB800;
    }

    .event-value {
        font-family: 'Inter', sans-serif;
        font-weight: 700;
    }

    .event-desc {
        opacity: 0.8;
        font-weight: 500;
    }
</style>

<script>
    // ==========================================================================
    // LÓGICA DO CALENDÁRIO AJAX (Blindado Globalmente)
    // ==========================================================================
    console.log("[Auralis] Motor de Agenda Iniciado.");

    const carteiraAtual = "<?php echo htmlspecialchars($carteira_selecionada, ENT_QUOTES, 'UTF-8'); ?>";
    let anoAtual = new Date().getFullYear();
    let mesAtual = new Date().getMonth(); // 0 a 11
    const HOJE_JS = new Date();

    const mesesNomes = ['Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];
    const diasSemana = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'];

    function formatarMoeda(valor) {
        return new Intl.NumberFormat('pt-BR', {
            style: 'currency',
            currency: 'BRL'
        }).format(valor);
    }

    function esc(str) {
        const div = document.createElement('div');
        div.innerText = str;
        return div.innerHTML;
    }

    // Exportação explícita para o Window (Evita erros de "is not defined" no onclick do HTML)
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

    window.carregarMes = async function(ano, mes) {
        document.getElementById('mesAnoTitulo').innerText = `${mesesNomes[mes]} ${ano}`;
        const mesStr = ano + '-' + String(mes + 1).padStart(2, '0');

        try {
            const response = await fetch(`agenda.php?ajax=1&acao=listar&mes=${mesStr}&carteira=${carteiraAtual}`);
            const json = await response.json();

            if (json.sucesso) {
                renderizarGrelha(ano, mes, json.dados);
                atualizarSaldos(json.saldos);
            } else {
                console.error("Erro do servidor:", json.erro);
                alert("Erro ao carregar dados do calendário.");
            }
        } catch (e) {
            console.error("Erro na requisição AJAX", e);
        }
    };

    function atualizarSaldos(saldos) {
        const elEfetivado = document.getElementById('saldoEfetivado');
        const elPago = document.getElementById('saldoPago');
        const elEsperado = document.getElementById('saldoEsperado');

        elEfetivado.classList.remove('placeholder-glow');
        elPago.classList.remove('placeholder-glow');
        elEsperado.classList.remove('placeholder-glow');

        elEfetivado.innerHTML = formatarMoeda(saldos.efetivado);
        elEfetivado.className = `fw-bold mb-0 ${saldos.efetivado >= 0 ? 'text-success' : 'text-danger'}`;

        elPago.innerHTML = formatarMoeda(saldos.pago);
        elEsperado.innerHTML = formatarMoeda(saldos.esperado);
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
            const empty = document.createElement('div');
            empty.className = 'calendar-day empty';
            grid.appendChild(empty);
        }

        for (let dia = 1; dia <= totalDias; dia++) {
            const dataAtualStr = `${ano}-${String(mes+1).padStart(2,'0')}-${String(dia).padStart(2,'0')}`;
            const isHoje = (dia === HOJE_JS.getDate() && mes === HOJE_JS.getMonth() && ano === HOJE_JS.getFullYear());

            const cel = document.createElement('div');
            cel.className = `calendar-day ${isHoje ? 'today' : ''}`;

            const num = document.createElement('div');
            num.className = 'day-number';
            num.innerText = dia;
            cel.appendChild(num);

            const eventsDiv = document.createElement('div');
            eventsDiv.className = 'day-events';

            const tDia = transacoes.filter(t => {
                const dataLimpa = t.data_evento ? t.data_evento.split(' ')[0] : '';
                return dataLimpa === dataAtualStr;
            });

            tDia.forEach(t => {
                const isReceita = t.tipo === 'receita';
                const isPendente = t.status === 'pendente';

                const pill = document.createElement('div');
                pill.className = `calendar-event ${isReceita ? 'receita' : 'despesa'} ${isPendente ? 'pendente' : ''}`;
                pill.title = `${t.titulo} — ${formatarMoeda(t.valor)}`;
                pill.onclick = () => window.location.href = `nova_transacao.php?editar=${encodeURIComponent(t.id)}`;

                const iconHtml = `<i class="bi ${esc(t.icone)} flex-shrink-0 me-1" style="font-size:0.75rem;"></i>`;
                const repHtml = (t.Recorrente == 1) ? `<i class="bi bi-arrow-repeat ms-1" style="opacity:0.6;font-size:0.7rem;"></i>` : '';
                const valHtml = `<span class="event-value">${isReceita ? '+' : '-'} ${formatarMoeda(t.valor)}</span>`;
                const descHtml = `<span class="event-desc text-truncate w-100 d-inline-block">${esc(t.titulo)} ${repHtml}</span>`;

                pill.innerHTML = `<div class="d-flex align-items-center mb-1">${iconHtml} ${valHtml}</div>${descHtml}`;
                eventsDiv.appendChild(pill);
            });

            cel.appendChild(eventsDiv);
            grid.appendChild(cel);
        }
    }

    document.addEventListener("DOMContentLoaded", () => {
        window.carregarMes(anoAtual, mesAtual);
    });
</script>

<?php require_once 'geral/footer.php'; ?>