<?php
// ==============================================================================
// 1. LÓGICA PHP (Processamento de Navegação e Dados)
// ==============================================================================
session_start();
if (! isset($_SESSION['usuario_id'])) {
    header("Location: usuario/login.php");
    exit;
}
require_once 'config/conexao.php';

$usuario_id = $_SESSION['usuario_id'];

// --- LÓGICA DE NAVEGAÇÃO DE TEMPO ---
$mes_atual = isset($_GET['mes']) ? (int) $_GET['mes'] : (int) date('m');
$ano_atual = isset($_GET['ano']) ? (int) $_GET['ano'] : (int) date('Y');

$mes_ant = $mes_atual - 1;
$ano_ant = $ano_atual;
if ($mes_ant < 1) {
    $mes_ant = 12;
    $ano_ant--;
}

$mes_prox = $mes_atual + 1;
$ano_prox = $ano_atual;
if ($mes_prox > 12) {
    $mes_prox = 1;
    $ano_prox++;
}

$meses_pt = [1 => 'Janeiro', 2 => 'Fevereiro', 3 => 'Março', 4 => 'Abril', 5 => 'Maio', 6 => 'Junho', 7 => 'Julho', 8 => 'Agosto', 9 => 'Setembro', 10 => 'Outubro', 11 => 'Novembro', 12 => 'Dezembro'];
$nome_mes = $meses_pt[$mes_atual];


// ==============================================================================
// LÓGICA DO SELETOR DE CARTEIRAS (Deve vir ANTES da busca de transações)
// ==============================================================================
$carteiras = [];
try {
    $sqlCart = "SELECT IDCarteira, TipoCarteira FROM Carteira WHERE FKUsuarioDono = :usuario_id ORDER BY TipoCarteira ASC";
    $stmtCart = $pdo->prepare($sqlCart);
    $stmtCart->execute([':usuario_id' => $usuario_id]);
    $carteiras = $stmtCart->fetchAll();
} catch (PDOException $e) {
}

// Descobre qual carteira está selecionada na URL (ou pega a primeira por padrão)
if (isset($_GET['carteira'])) {
    $carteira_selecionada = $_GET['carteira'];
} else {
    $carteira_selecionada = (count($carteiras) > 0) ? $carteiras[0]['IDCarteira'] : null;
}

// Pega o nome da carteira atual para exibir no botão
$nome_carteira_atual = 'Carteira Geral';
foreach ($carteiras as $cart) {
    if ($cart['IDCarteira'] == $carteira_selecionada) {
        $nome_carteira_atual = $cart['TipoCarteira'];
        break;
    }
}

// Atualiza os links de voltar/avançar mês para não perder a carteira atual
$link_ant  = "?mes={$mes_ant}&ano={$ano_ant}" . ($carteira_selecionada ? "&carteira={$carteira_selecionada}" : "");
$link_prox = "?mes={$mes_prox}&ano={$ano_prox}" . ($carteira_selecionada ? "&carteira={$carteira_selecionada}" : "");


// --- BUSCA DE DADOS (Agora filtrando pela Carteira Selecionada!) ---
$transacoes           = [];
$totalDespesas        = 0;
$totalReceitas        = 0;
$gastosPorCategoria   = [];
$receitasPorCategoria = [];

if ($carteira_selecionada) {
    try {
        $sql = "
                SELECT
                    r.Valor, r.Descricao, r.TipoRegistro, r.MomentoRegistro,
                    COALESCE(c.NomeCategoria, 'Sem Categoria') as Categoria,
                    COALESCE(c.IconeCategoria, 'bi-tag') as Icone
                FROM Registro r
                LEFT JOIN Categoria c ON r.FKCategoria = c.IDCategoria
                WHERE r.FKUsuario = :uid
                  AND r.FKCarteira = :carteira_id
                  AND r.StatusRegistro = 'efetivado'
                  AND MONTH(r.MomentoRegistro) = :mes
                  AND YEAR(r.MomentoRegistro) = :ano
                ORDER BY r.MomentoRegistro DESC
            ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':uid' => $usuario_id,
            ':carteira_id' => $carteira_selecionada,
            ':mes' => $mes_atual,
            ':ano' => $ano_atual
        ]);
        $transacoes = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($transacoes as $t) {
            $valor = (float) $t['Valor'];
            $cat   = $t['Categoria'];

            if ($t['TipoRegistro'] === 'despesa') {
                $totalDespesas += $valor;
                if (!isset($gastosPorCategoria[$cat])) {
                    $gastosPorCategoria[$cat] = 0;
                }
                $gastosPorCategoria[$cat] += $valor;
            } else {
                $totalReceitas += $valor;
                if (!isset($receitasPorCategoria[$cat])) {
                    $receitasPorCategoria[$cat] = 0;
                }
                $receitasPorCategoria[$cat] += $valor;
            }
        }
    } catch (PDOException $e) {
    }
}

arsort($gastosPorCategoria);
$maiorGastoCat   = key($gastosPorCategoria);
$maiorGastoValor = current($gastosPorCategoria);

arsort($receitasPorCategoria);
$maiorReceitaCat   = key($receitasPorCategoria);
$maiorReceitaValor = current($receitasPorCategoria);

// ── Dados do mês ANTERIOR por categoria (para comparação) ─────────────
$gastosPorCategoriaAnt   = [];
$receitasPorCategoriaAnt = [];

if ($carteira_selecionada) {
    try {
        $sqlAnt = "
                SELECT
                    r.Valor, r.TipoRegistro,
                    COALESCE(c.NomeCategoria, 'Sem Categoria') as Categoria
                FROM Registro r
                LEFT JOIN Categoria c ON r.FKCategoria = c.IDCategoria
                WHERE r.FKUsuario   = :uid
                  AND r.FKCarteira  = :carteira_id
                  AND r.StatusRegistro = 'efetivado'
                  AND MONTH(r.MomentoRegistro) = :mes
                  AND YEAR(r.MomentoRegistro)  = :ano
            ";
        $stmtAnt = $pdo->prepare($sqlAnt);
        $stmtAnt->execute([
            ':uid'         => $usuario_id,
            ':carteira_id' => $carteira_selecionada,
            ':mes'         => $mes_ant,
            ':ano'         => $ano_ant,
        ]);
        foreach ($stmtAnt->fetchAll(PDO::FETCH_ASSOC) as $t) {
            $cat = $t['Categoria'];
            if ($t['TipoRegistro'] === 'despesa') {
                $gastosPorCategoriaAnt[$cat] = ($gastosPorCategoriaAnt[$cat] ?? 0) + (float)$t['Valor'];
            } else {
                $receitasPorCategoriaAnt[$cat] = ($receitasPorCategoriaAnt[$cat] ?? 0) + (float)$t['Valor'];
            }
        }
    } catch (PDOException $e) {
    }
}

// ── Helper: badge de variação ─────────────────────────────────────────
function analisesBadgeVar(float $atual, float $anterior, bool $invertido = false): string
{
    if ($anterior <= 0) return '';
    $delta = (($atual - $anterior) / $anterior) * 100;
    $abs   = abs(round($delta, 1));
    if ($abs < 0.5) return '';
    $subiu    = $delta > 0;
    $positivo = $invertido ? !$subiu : $subiu;
    $cor  = $positivo ? '28a745' : 'dc3545';
    $icon = $subiu ? 'bi-arrow-up-short' : 'bi-arrow-down-short';
    return "<span style='display:inline-flex;align-items:center;background:#{$cor}22;color:#{$cor};border:1px solid #{$cor}44;border-radius:999px;padding:1px 7px;font-size:0.68rem;font-weight:600;'><i class='bi {$icon}'></i>{$abs}%</span>";
}

// JSON Despesas
$dadosJsonTransacoes      = json_encode($transacoes);
$dadosJsonLabelsDespesas  = json_encode(array_keys($gastosPorCategoria));
$dadosJsonValoresDespesas = json_encode(array_values($gastosPorCategoria));

// JSON Receitas
$dadosJsonLabelsReceitas  = json_encode(array_keys($receitasPorCategoria));
$dadosJsonValoresReceitas = json_encode(array_values($receitasPorCategoria));

require_once 'geral/header.php';
?>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<main class="container-fluid py-4 mt-2 flex-grow-1" style="max-width: 1500px; padding-inline: var(--space-page-x); min-height: 100vh;">

    <div class="mb-4 border-bottom border-secondary-subtle pb-3">
        <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3">

            <h2 class="fw-bold text-light mb-0 d-flex align-items-center gap-2" style="font-size: clamp(1.2rem, 3vw, 1.5rem);">
                <i class="bi bi-pie-chart-fill" style="color: var(--primary-gold-analysis) !important;"></i> Análises
            </h2>

            <div class="d-flex align-items-center gap-2 w-100 w-lg-auto">

                <!-- Seletor de Carteira -->
                <div class="dropdown flex-grow-1 flex-lg-grow-0">
                    <button class="btn border-secondary-subtle text-light shadow-sm fw-semibold dropdown-toggle d-flex align-items-center justify-content-center rounded-3 transition-hover w-100"
                        type="button" data-bs-toggle="dropdown" aria-expanded="false"
                        style="font-size: 0.875rem; background-color: var(--bg-charcoal-analysis);">
                        <span class="text-truncate d-flex align-items-center">
                            <i class="bi bi-wallet2 me-2" style="color: var(--primary-gold-analysis); flex-shrink: 0;"></i>
                            <?php echo htmlspecialchars($nome_carteira_atual); ?>
                        </span>
                    </button>

                    <!-- Lista de Carteiras -->
                    <ul class="dropdown-menu dropdown-menu-dark shadow-lg border-secondary-subtle mt-2 w-100" style="background-color: #1a1d21;">
                        <li class="px-3 py-1 text-secondary small text-uppercase fw-bold tracking-wide">Alternar Carteira</li>
                        <li>
                            <hr class="dropdown-divider border-secondary-subtle">
                        </li>
                        <?php foreach ($carteiras as $cart): ?>
                            <li>
                                <a class="dropdown-item d-flex align-items-center py-2 transition-hover <?php echo $carteira_selecionada == $cart['IDCarteira'] ? 'active' : '' ?>"
                                    href="?mes=<?php echo $mes_atual ?>&ano=<?php echo $ano_atual ?>&carteira=<?php echo htmlspecialchars($cart['IDCarteira']) ?>">
                                    <?php if ($carteira_selecionada == $cart['IDCarteira']): ?>
                                        <i class="bi bi-check-circle-fill me-2" style="color: var(--primary-gold-analysis);"></i>
                                        <span class="fw-bold" style="color: var(--primary-gold-analysis);"><?php echo htmlspecialchars($cart['TipoCarteira']); ?></span>
                                    <?php else: ?>
                                        <i class="bi bi-circle me-2 text-secondary opacity-50"></i>
                                        <span class="text-light"><?php echo htmlspecialchars($cart['TipoCarteira']); ?></span>
                                    <?php endif; ?>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>

                <div class="d-flex align-items-center bg-dark border border-secondary-subtle rounded-pill shadow-sm flex-grow-1 flex-lg-grow-0 justify-content-center" style="padding: 2px 4px;">
                    <a href="<?php echo $link_ant ?>" class="btn btn-sm btn-link text-light opacity-75 transition-hover text-decoration-none d-flex align-items-center justify-content-center" style="width: 30px; height: 30px;">
                        <i class="bi bi-caret-left-fill" style="font-size: 0.65rem;"></i>
                    </a>

                    <button type="button" class="btn btn-link text-light text-decoration-none fw-semibold px-1 transition-hover d-flex align-items-center justify-content-center"
                        style="font-size: 0.875rem; white-space: nowrap;"
                        data-bs-toggle="modal" data-bs-target="#modalSeletorMes">
                        <?php echo $nome_mes ?> <span class="d-none d-sm-inline ms-1"><?php echo $ano_atual ?></span>
                        <i class="bi bi-chevron-down ms-1 opacity-75" style="font-size: 0.65rem;"></i>
                    </button>

                    <a href="<?php echo $link_prox ?>" class="btn btn-sm btn-link text-light opacity-75 transition-hover text-decoration-none d-flex align-items-center justify-content-center" style="width: 30px; height: 30px;">
                        <i class="bi bi-caret-right-fill" style="font-size: 0.65rem;"></i>
                    </a>
                </div>

            </div>
        </div>
    </div>

    <div class="row g-4 mb-5">
        <div class="col-md-6">
            <div class="card bg-body-tertiary border-secondary-subtle shadow-sm h-100 rounded-4">
                <div class="card-body p-4 d-flex align-items-center">
                    <div class="bg-danger bg-opacity-10 p-3 rounded-circle me-3 analise-icon flex-shrink-0">
                        <i class="bi bi-fire text-danger fs-1"></i>
                    </div>
                    <div>
                        <p class="text-secondary small mb-1 text-uppercase fw-bold tracking-wide">Maior Fuga de Capital (Gastos)</p>
                        <?php if ($maiorGastoCat): ?>
                            <h4 class="fw-bold text-light mb-1"><?php echo htmlspecialchars($maiorGastoCat) ?></h4>
                            <div class="d-flex align-items-center gap-2 flex-wrap">
                                <span class="text-danger fw-semibold fs-5">R$ <?php echo number_format($maiorGastoValor, 2, ',', '.') ?></span>
                                <?php echo analisesBadgeVar($maiorGastoValor, $gastosPorCategoriaAnt[$maiorGastoCat] ?? 0, true); ?>
                            </div>
                            <?php if (isset($gastosPorCategoriaAnt[$maiorGastoCat])): ?>
                                <small class="text-secondary" style="font-size:0.7rem;">Mês anterior: R$ <?php echo number_format($gastosPorCategoriaAnt[$maiorGastoCat], 2, ',', '.') ?></small>
                            <?php endif; ?>
                        <?php else: ?>
                            <h5 class="text-secondary mb-0">Nenhum gasto registrado</h5>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-6">
            <div class="card bg-body-tertiary border-secondary-subtle shadow-sm h-100 rounded-4">
                <div class="card-body p-4 d-flex align-items-center">
                    <div class="bg-success bg-opacity-10 p-3 rounded-circle me-3 analise-icon flex-shrink-0">
                        <i class="bi bi-trophy text-success fs-1"></i>
                    </div>
                    <div>
                        <p class="text-secondary small mb-1 text-uppercase fw-bold tracking-wide">Principal Motor de Renda</p>
                        <?php if ($maiorReceitaCat): ?>
                            <h4 class="fw-bold text-light mb-1"><?php echo htmlspecialchars($maiorReceitaCat) ?></h4>
                            <div class="d-flex align-items-center gap-2 flex-wrap">
                                <span class="text-success fw-semibold fs-5">R$ <?php echo number_format($maiorReceitaValor, 2, ',', '.') ?></span>
                                <?php echo analisesBadgeVar($maiorReceitaValor, $receitasPorCategoriaAnt[$maiorReceitaCat] ?? 0, false); ?>
                            </div>
                            <?php if (isset($receitasPorCategoriaAnt[$maiorReceitaCat])): ?>
                                <small class="text-secondary" style="font-size:0.7rem;">Mês anterior: R$ <?php echo number_format($receitasPorCategoriaAnt[$maiorReceitaCat], 2, ',', '.') ?></small>
                            <?php endif; ?>
                        <?php else: ?>
                            <h5 class="text-secondary mb-0">Nenhuma renda registrada</h5>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php if ($totalDespesas > 0): ?>
        <div class="row g-4 mb-5">
            <div class="col-lg-6">
                <div class="card bg-dark border-secondary-subtle shadow-sm rounded-4 h-100">
                    <div class="card-header border-bottom border-secondary-subtle bg-transparent p-4">
                        <h5 class="text-light fw-bold mb-0">Distribuição de Despesas</h5>
                    </div>
                    <div class="card-body p-4 d-flex justify-content-center align-items-center position-relative">
                        <div class="position-relative d-flex justify-content-center align-items-center w-100 donut-wrapper" style="max-width: 320px; aspect-ratio: 1;">
                            <canvas id="graficoDespesas"></canvas>
                            <div class="position-absolute text-center" style="pointer-events: none;">
                                <span class="d-block text-secondary small">Total</span>
                                <h5 class="text-light fw-bold mb-0">R$ <?php echo number_format($totalDespesas, 2, ',', '.') ?></h5>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-6">
                <div class="card bg-dark border-secondary-subtle shadow-sm rounded-4 h-100">
                    <div class="card-header border-bottom border-secondary-subtle bg-transparent p-4 d-flex justify-content-between align-items-center">
                        <h5 class="text-light fw-bold mb-0">Detalhamento</h5>
                        <span class="badge bg-secondary text-dark" id="badge-categoria-despesa">Geral</span>
                    </div>
                    <div class="card-body p-0 overflow-auto analises-list" style="max-height: 400px;" id="lista-detalhes-despesa">
                        <div class="p-5 text-center text-secondary">
                            <i class="bi bi-hand-index-thumb fs-1 mb-2 d-block opacity-50"></i>
                            Selecione uma fatia do gráfico para ver as transações.
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($totalReceitas > 0): ?>
        <div class="row g-4 mb-5">
            <div class="col-lg-6">
                <div class="card bg-dark border-secondary-subtle shadow-sm rounded-4 h-100">
                    <div class="card-header border-bottom border-secondary-subtle bg-transparent p-4">
                        <h5 class="text-light fw-bold mb-0">Distribuição de Receitas</h5>
                    </div>
                    <div class="card-body p-4 d-flex justify-content-center align-items-center position-relative">
                        <div class="position-relative d-flex justify-content-center align-items-center w-100 donut-wrapper" style="max-width: 320px; aspect-ratio: 1;">
                            <canvas id="graficoReceitas"></canvas>
                            <div class="position-absolute text-center" style="pointer-events: none;">
                                <span class="d-block text-secondary small">Total</span>
                                <h5 class="text-light fw-bold mb-0">R$ <?php echo number_format($totalReceitas, 2, ',', '.') ?></h5>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-6">
                <div class="card bg-dark border-secondary-subtle shadow-sm rounded-4 h-100">
                    <div class="card-header border-bottom border-secondary-subtle bg-transparent p-4 d-flex justify-content-between align-items-center">
                        <h5 class="text-light fw-bold mb-0">Detalhamento</h5>
                        <span class="badge bg-secondary text-dark" id="badge-categoria-receita">Geral</span>
                    </div>
                    <div class="card-body p-0 overflow-auto analises-list" style="max-height: 400px;" id="lista-detalhes-receita">
                        <div class="p-5 text-center text-secondary">
                            <i class="bi bi-hand-index-thumb fs-1 mb-2 d-block opacity-50"></i>
                            Selecione uma fatia do gráfico para ver as transações.
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($totalDespesas == 0 && $totalReceitas == 0): ?>
        <div class="text-center py-5">
            <i class="bi bi-bar-chart text-secondary opacity-50 mb-3" style="font-size: 4rem;"></i>
            <h4 class="text-light fw-bold">Nenhum dado para este período</h4>
            <p class="text-secondary">Parece que a carteira "<?php echo htmlspecialchars($nome_carteira_atual); ?>" não tem transações efetivadas em <?php echo $nome_mes ?>.</p>
        </div>
    <?php endif; ?>

</main>

<style>
    .bg-card-analysis {
        background-color: #2A2A2A;
    }

    .tracking-wide {
        letter-spacing: 0.05em;
    }

    #lista-detalhes-despesa::-webkit-scrollbar,
    #lista-detalhes-receita::-webkit-scrollbar {
        width: 6px;
    }

    #lista-detalhes-despesa::-webkit-scrollbar-track,
    #lista-detalhes-receita::-webkit-scrollbar-track {
        background: transparent;
    }

    #lista-detalhes-despesa::-webkit-scrollbar-thumb,
    #lista-detalhes-receita::-webkit-scrollbar-thumb {
        background-color: #444;
        border-radius: 10px;
    }
</style>
<div class="modal fade" id="modalSeletorMes" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content bg-dark border-secondary-subtle shadow-lg rounded-4">
            <div class="modal-header border-bottom border-secondary-subtle p-3">
                <h6 class="modal-title text-light fw-bold">
                    <i class="bi bi-calendar3 me-2" style="color: var(--primary-gold-analysis);"></i> Selecionar Período
                </h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4 text-center">

                <div class="d-flex justify-content-between align-items-center mb-4 bg-charcoal-analysis rounded-pill p-2 border border-secondary-subtle mx-auto" style="max-width: 220px;">
                    <button type="button" class="btn btn-sm btn-link text-secondary shadow-none px-3" onclick="mudarAnoModal(-1)">
                        <i class="bi bi-chevron-left fs-5"></i>
                    </button>

                    <span id="anoModalDisplay" class="text-light fw-bold fs-4 m-0 tracking-wide"><?= $ano_atual ?></span>
                    <input type="hidden" id="anoModalInput" value="<?= $ano_atual ?>">

                    <button type="button" class="btn btn-sm btn-link text-secondary shadow-none px-3" onclick="mudarAnoModal(1)">
                        <i class="bi bi-chevron-right fs-5"></i>
                    </button>
                </div>

                <div class="row g-2">
                    <?php
                    $mesesAbrev = [1 => 'Jan', 2 => 'Fev', 3 => 'Mar', 4 => 'Abr', 5 => 'Mai', 6 => 'Jun', 7 => 'Jul', 8 => 'Ago', 9 => 'Set', 10 => 'Out', 11 => 'Nov', 12 => 'Dez'];
                    foreach ($mesesAbrev as $num => $nome):
                        if ($num == $mes_atual) {
                            $classeBtn = "fw-bold border-0";
                            $estiloBtn = "background: linear-gradient(135deg, #FFB800 0%, #D4AF37 100%); color: #121418; box-shadow: 0 4px 10px rgba(212, 175, 55, 0.3);";
                        } else {
                            $classeBtn = "btn-outline-secondary text-light";
                            $estiloBtn = "";
                        }
                    ?>
                        <div class="col-4">
                            <button type="button" class="btn w-100 <?= $classeBtn ?> rounded-3 py-2 transition-hover" style="<?= $estiloBtn ?>" onclick="irParaMes(<?= $num ?>)">
                                <?= $nome ?>
                            </button>
                        </div>
                    <?php endforeach; ?>
                </div>

            </div>
        </div>
    </div>
</div>

<script>
    function mudarAnoModal(delta) {
        const inputAno = document.getElementById('anoModalInput');
        const displayAno = document.getElementById('anoModalDisplay');

        let novoAno = parseInt(inputAno.value) + delta;

        inputAno.value = novoAno;
        displayAno.innerText = novoAno;
    }

    function irParaMes(mes) {
        const ano = document.getElementById('anoModalInput').value;
        const urlParams = new URLSearchParams(window.location.search);

        urlParams.set('mes', mes);
        urlParams.set('ano', ano);
        // O URLSearchParams mantém a ?carteira=... intacta, não precisa se preocupar!

        window.location.search = urlParams.toString();
    }
</script>
<script>
    const transacoesBrutas = <?php echo $dadosJsonTransacoes ?>;
    const gastosCatAnt = <?php echo json_encode($gastosPorCategoriaAnt) ?>;
    const receitasCatAnt = <?php echo json_encode($receitasPorCategoriaAnt) ?>;
    const gastosCatAtual = <?php echo json_encode($gastosPorCategoria) ?>;
    const receitasCatAtual = <?php echo json_encode($receitasPorCategoria) ?>;
    const nomeMesAnterior = "<?php echo $meses_pt[$mes_ant] ?>";

    // Badge de variação JS-side
    function badgeVarJS(atual, anterior, invertido = false) {
        if (!anterior || anterior <= 0) return '';
        const delta = ((atual - anterior) / anterior) * 100;
        const abs = Math.abs(delta).toFixed(1);
        if (abs < 0.5) return '';
        const subiu = delta > 0;
        const positivo = invertido ? !subiu : subiu;
        const cor = positivo ? '28a745' : 'dc3545';
        const icon = subiu ? 'bi-arrow-up-short' : 'bi-arrow-down-short';
        return `<span style="display:inline-flex;align-items:center;background:#${cor}22;color:#${cor};border:1px solid #${cor}44;border-radius:999px;padding:1px 7px;font-size:0.68rem;font-weight:600;"><i class="bi ${icon}"></i> ${abs}%</span>`;
    }

    const coresDespesas = ['#AA8C2C', '#D4AF37', '#E7C665', '#E63946', '#F4A261', '#E9C46A', '#9C6644'];
    const coresReceitas = ['#06D6A0', '#118AB2', '#2A9D8F', '#264653', '#457B9D', '#1D3557', '#0077B6'];

    // ── Plugin: labels nas fatias do gráfico ──────────────────────────────────
    const pluginLabelsFatias = {
        id: 'labelsFatias',
        afterDatasetsDraw(chart) {
            const {
                ctx,
                data
            } = chart;
            const total = data.datasets[0].data.reduce((a, b) => a + b, 0);
            if (total === 0) return;

            ctx.save();
            data.datasets[0].data.forEach((valor, i) => {
                const pct = (valor / total) * 100;
                // Fatias muito pequenas (< 6%) não recebem label para não sujar
                if (pct < 6) return;

                const meta = chart.getDatasetMeta(0);
                const arc = meta.data[i];
                const midAngle = arc.startAngle + (arc.endAngle - arc.startAngle) / 2;

                // Posição: 82% do raio externo (dentro da fatia, mas perto da borda)
                const outerRadius = arc.outerRadius;
                const innerRadius = arc.innerRadius;
                const midRadius = innerRadius + (outerRadius - innerRadius) * 0.6;

                const x = arc.x + Math.cos(midAngle) * midRadius;
                const y = arc.y + Math.sin(midAngle) * midRadius;

                // Nome da categoria (truncado em 10 chars)
                const label = data.labels[i].length > 10 ?
                    data.labels[i].substring(0, 9) + '…' :
                    data.labels[i];

                ctx.textAlign = 'center';
                ctx.textBaseline = 'middle';

                // Percentagem em cima — maior e em negrito
                ctx.font = 'bold 11px Inter, sans-serif';
                ctx.fillStyle = 'rgba(255,255,255,0.95)';
                ctx.fillText(Math.round(pct) + '%', x, y - 7);

                // Nome embaixo — menor
                ctx.font = '9px Inter, sans-serif';
                ctx.fillStyle = 'rgba(255,255,255,0.75)';
                ctx.fillText(label, x, y + 6);
            });
            ctx.restore();
        }
    };

    function criarGrafico(canvasId, labels, valores, cores, tipo) {
        const canvas = document.getElementById(canvasId);
        if (!canvas) return null;

        const chart = new Chart(canvas.getContext('2d'), {
            type: 'doughnut',
            data: {
                labels,
                datasets: [{
                    data: valores,
                    backgroundColor: cores,
                    borderWidth: 3,
                    borderColor: '#1a1d21',
                    hoverBorderWidth: 0,
                    hoverOffset: 6,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '65%',
                animation: {
                    animateRotate: true,
                    duration: 600
                },
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        callbacks: {
                            label(ctx) {
                                const total = ctx.dataset.data.reduce((a, b) => a + b, 0);
                                const pct = ((ctx.parsed / total) * 100).toFixed(1);
                                const val = new Intl.NumberFormat('pt-BR', {
                                    style: 'currency',
                                    currency: 'BRL'
                                }).format(ctx.parsed);
                                return ` ${val} (${pct}%)`;
                            }
                        },
                        backgroundColor: '#1e2126',
                        borderColor: 'rgba(212,175,55,0.3)',
                        borderWidth: 1,
                        titleColor: '#f8fafc',
                        bodyColor: '#a1a1aa',
                        padding: 10,
                    }
                },
                onClick: (event, elements) => {
                    if (elements.length > 0) {
                        const categoriaClicada = chart.data.labels[elements[0].index];
                        atualizarListaDetalhes(categoriaClicada, tipo);
                    }
                }
            },
            plugins: [pluginLabelsFatias]
        });
        return chart;
    }

    const chartDespesas = criarGrafico(
        'graficoDespesas',
        <?php echo $dadosJsonLabelsDespesas ?>,
        <?php echo $dadosJsonValoresDespesas ?>,
        coresDespesas,
        'despesa'
    );

    const chartReceitas = criarGrafico(
        'graficoReceitas',
        <?php echo $dadosJsonLabelsReceitas ?>,
        <?php echo $dadosJsonValoresReceitas ?>,
        coresReceitas,
        'receita'
    );

    function atualizarListaDetalhes(categoriaFiltro, tipo) {
        const containerLista = document.getElementById(`lista-detalhes-${tipo}`);
        const badgeCategoria = document.getElementById(`badge-categoria-${tipo}`);

        badgeCategoria.innerText = categoriaFiltro;
        badgeCategoria.className = tipo === 'despesa' ? 'badge bg-warning text-dark' : 'badge bg-info text-dark';

        const transacoesFiltradas = transacoesBrutas.filter(t =>
            t.TipoRegistro === tipo && t.Categoria === categoriaFiltro
        );

        const totalAtual = tipo === 'despesa' ?
            (gastosCatAtual[categoriaFiltro] || 0) :
            (receitasCatAtual[categoriaFiltro] || 0);
        const totalAnt = tipo === 'despesa' ?
            (gastosCatAnt[categoriaFiltro] || 0) :
            (receitasCatAnt[categoriaFiltro] || 0);

        const fmt = v => new Intl.NumberFormat('pt-BR', {
            style: 'currency',
            currency: 'BRL'
        }).format(v);
        const corTotal = tipo === 'despesa' ? 'text-danger' : 'text-success';

        // Cabeçalho de comparação da categoria
        let htmlLista = `
            <div class="px-4 py-3 border-bottom border-secondary-subtle d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div>
                    <span class="text-secondary small">Total em ${categoriaFiltro}</span><br>
                    <span class="${corTotal} fw-bold fs-5">${fmt(totalAtual)}</span>
                    ${badgeVarJS(totalAtual, totalAnt, tipo === 'despesa')}
                </div>
                ${totalAnt > 0 ? `<span class="text-secondary small">${nomeMesAnterior}: ${fmt(totalAnt)}</span>` : ''}
            </div>
            <div class="list-group list-group-flush">`;

        transacoesFiltradas.forEach(t => {
            const [ano, mes, dia] = t.MomentoRegistro.split(' ')[0].split('-');
            const dataStr = `${dia}/${mes}/${ano}`;
            const valorFormatado = fmt(t.Valor);
            const corValor = tipo === 'despesa' ? 'text-danger' : 'text-success';
            const sinalValor = tipo === 'despesa' ? '-' : '+';

            htmlLista += `
                <div class="list-group-item bg-transparent border-secondary-subtle px-4 py-3 d-flex justify-content-between align-items-center">
                    <div class="d-flex align-items-center">
                        <i class="bi ${t.Icone} text-secondary fs-4 me-3"></i>
                        <div>
                            <h6 class="text-light fw-semibold mb-0">${t.Descricao}</h6>
                            <small class="text-secondary">${dataStr}</small>
                        </div>
                    </div>
                    <span class="${corValor} fw-bold">${sinalValor}${valorFormatado}</span>
                </div>`;
        });
        htmlLista += '</div>';
        containerLista.innerHTML = htmlLista;
    }
</script>

<?php require_once 'geral/footer.php'; ?>