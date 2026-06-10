<?php
// ==============================================================================
// 1. LÓGICA PHP (Processamento de Navegação, Carteiras e Saldos Alinhados)
// ==============================================================================
session_start();
if (!isset($_SESSION['usuario_id'])) {
    header("Location: usuario/login.php");
    exit;
}
require_once 'config/conexao.php';

$usuario_id = $_SESSION['usuario_id'];

// --- LÓGICA DE NAVEGAÇÃO DE TEMPO ---
$mes_atual = isset($_GET['mes']) ? (int) $_GET['GET']['mes'] : (int) date('m');
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

$meses_pt = [
    1 => 'Janeiro',
    2 => 'Fevereiro',
    3 => 'Março',
    4 => 'Abril',
    5 => 'Maio',
    6 => 'Junho',
    7 => 'Julho',
    8 => 'Agosto',
    9 => 'Setembro',
    10 => 'Outubro',
    11 => 'Novembro',
    12 => 'Dezembro'
];
$nome_mes = $meses_pt[$mes_atual];

// ==============================================================================
// LÓGICA DO SELETOR DE CARTEIRAS (Capacidade igual ao Dashboard + Opção "Todas")
// ==============================================================================
$carteiras = [];
try {
    $sqlCart = "SELECT IDCarteira, TipoCarteira FROM Carteira WHERE FKUsuarioDono = :usuario_id ORDER BY TipoCarteira ASC";
    $stmtCart = $pdo->prepare($sqlCart);
    $stmtCart->execute([':usuario_id' => $usuario_id]);
    $carteiras = $stmtCart->fetchAll();
} catch (PDOException $e) {
    // Tratamento de exceção silencioso para manter estabilidade da UI
}

// Descobre a carteira pela URL. Se não definida ou for 'todas', assume Visão Geral.
$carteira_selecionada = $_GET['carteira'] ?? 'todas';

$nome_carteira_atual = 'Todas as Carteiras';
if ($carteira_selecionada !== 'todas') {
    foreach ($carteiras as $cart) {
        if ($cart['IDCarteira'] == $carteira_selecionada) {
            $nome_carteira_atual = $cart['TipoCarteira'];
            break;
        }
    }
}

// Mantém os parâmetros de estado da navegação temporal e de carteira nos links
$link_ant  = "?mes={$mes_ant}&ano={$ano_ant}&carteira={$carteira_selecionada}";
$link_prox = "?mes={$mes_prox}&ano={$ano_prox}&carteira={$carteira_selecionada}";

// ==============================================================================
// MOTOR DE CÁLCULO DE SALDOS (Baseado no Dashboard - Perfeito Funcionamento)
// ==============================================================================
$transacoes = [];
$totalReceitasEfetivadas = 0;
$totalDespesasEfetivadas = 0;
$totalReceitasPendentes   = 0;
$totalDespesasPendentes   = 0;

try {
    // Montagem dinâmica da query respeitando o filtro de escopo de carteiras
    $sqlAgenda = "
        SELECT r.*, 
               COALESCE(c.NomeCategoria, 'Sem Categoria') as Categoria,
               COALESCE(c.IconeCategoria, 'bi-tag') as Icone
        FROM Registro r
        LEFT JOIN Categoria c ON r.FKCategoria = c.IDCategoria
        WHERE r.FKUsuario = :uid
          AND MONTH(r.MomentoRegistro) = :mes
          AND YEAR(r.MomentoRegistro) = :ano
    ";

    $params = [
        ':uid' => $usuario_id,
        ':mes' => $mes_atual,
        ':ano' => $ano_atual
    ];

    if ($carteira_selecionada !== 'todas') {
        $sqlAgenda .= " AND r.FKCarteira = :carteira_id";
        $params[':carteira_id'] = $carteira_selecionada;
    }

    $sqlAgenda .= " ORDER BY r.MomentoRegistro ASC";

    $stmtAgenda = $pdo->prepare($sqlAgenda);
    $stmtAgenda->execute($params);
    $transacoes = $stmtAgenda->fetchAll(PDO::FETCH_ASSOC);

    // Processamento aritmético idêntico ao motor lógico do Dashboard
    foreach ($transacoes as $t) {
        $valor = (float)$t['Valor'];
        if ($t['TipoRegistro'] === 'receita') {
            if ($t['StatusRegistro'] === 'efetivado') {
                $totalReceitasEfetivadas += $valor;
            } else {
                $totalReceitasPendentes += $valor;
            }
        } elseif ($t['TipoRegistro'] === 'despesa') {
            if ($t['StatusRegistro'] === 'efetivado') {
                $totalDespesasEfetivadas += $valor;
            } else {
                $totalDespesasPendentes += $valor;
            }
        }
    }
} catch (PDOException $e) {
    // Evita crash na interface em caso de falha de banco de dados
}

// Computação final das métricas de exibição
$saldo_efetivado = $totalReceitasEfetivadas - $totalDespesasEfetivadas;
$total_pago      = $totalDespesasEfetivadas;
$saldo_esperado  = ($totalReceitasEfetivadas + $totalReceitasPendentes) - ($totalDespesasEfetivadas + $totalDespesasPendentes);

require_once 'geral/header.php';
?>

<main class="container-fluid py-4 mt-2 flex-grow-1" style="max-width: 1500px; padding-inline: var(--space-page-x); min-height: 100vh;">

    <div class="mb-4 border-bottom border-secondary-subtle pb-3">
        <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3">

            <h2 class="fw-bold text-light mb-0 d-flex align-items-center gap-2" style="font-size: clamp(1.2rem, 3vw, 1.5rem);">
                <i class="bi bi-calendar3" style="color: #AA8C2C !important;"></i> Agenda Financeira
            </h2>

            <div class="d-flex align-items-center gap-2 w-100 w-lg-auto">

                <div class="dropdown flex-grow-1 flex-lg-grow-0" style="min-width: 0;">
                    <button class="btn border-secondary-subtle text-light shadow-sm fw-semibold dropdown-toggle d-flex align-items-center justify-content-between rounded-3 transition-hover w-100"
                        type="button" data-bs-toggle="dropdown" aria-expanded="false"
                        style="font-size: 0.875rem; background-color: #222222;">

                        <div class="d-flex align-items-center text-start" style="min-width: 0;">
                            <i class="bi bi-wallet2 me-2 flex-shrink-0" style="color: #AA8C2C;"></i>
                            <span class="text-truncate" style="max-width: 140px;" title="<?php echo htmlspecialchars($nome_carteira_atual); ?>">
                                <?php echo htmlspecialchars($nome_carteira_atual); ?>
                            </span>
                        </div>
                    </button>

                    <ul class="dropdown-menu dropdown-menu-dark shadow-lg border-secondary-subtle mt-2 w-100" style="background-color: #1a1d21; min-width: 220px;">
                        <li class="px-3 py-1 text-secondary small text-uppercase fw-bold tracking-wide">Filtrar Escopo</li>
                        <li>
                            <hr class="dropdown-divider border-secondary-subtle">
                        </li>
                        <li>
                            <a class="dropdown-item d-flex align-items-center py-2 transition-hover <?php echo $carteira_selecionada === 'todas' ? 'active' : '' ?>"
                                href="?mes=<?php echo $mes_atual ?>&ano=<?php echo $ano_atual ?>&carteira=todas">
                                <?php if ($carteira_selecionada === 'todas'): ?>
                                    <i class="bi bi-check-circle-fill me-2 flex-shrink-0" style="color: #AA8C2C;"></i>
                                    <span class="fw-bold text-truncate" style="color: #AA8C2C;">Todas as Carteiras</span>
                                <?php else: ?>
                                    <i class="bi bi-circle me-2 flex-shrink-0 text-secondary opacity-50"></i>
                                    <span class="text-light text-truncate">Todas as Carteiras</span>
                                <?php endif; ?>
                            </a>
                        </li>
                        <?php foreach ($carteiras as $cart): ?>
                            <li>
                                <a class="dropdown-item d-flex align-items-center py-2 transition-hover <?php echo $carteira_selecionada == $cart['IDCarteira'] ? 'active' : '' ?>"
                                    href="?mes=<?php echo $mes_atual ?>&ano=<?php echo $ano_atual ?>&carteira=<?php echo htmlspecialchars($cart['IDCarteira']) ?>"
                                    title="<?php echo htmlspecialchars($cart['TipoCarteira']); ?>">

                                    <?php if ($carteira_selecionada == $cart['IDCarteira']): ?>
                                        <i class="bi bi-check-circle-fill me-2 flex-shrink-0" style="color: #AA8C2C;"></i>
                                        <span class="fw-bold text-truncate" style="color: #AA8C2C; max-width: 170px;">
                                            <?php echo htmlspecialchars($cart['TipoCarteira']); ?>
                                        </span>
                                    <?php else: ?>
                                        <i class="bi bi-circle me-2 flex-shrink-0 text-secondary opacity-50"></i>
                                        <span class="text-light text-truncate" style="max-width: 170px;">
                                            <?php echo htmlspecialchars($cart['TipoCarteira']); ?>
                                        </span>
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

        <div class="col-md-4">
            <div class="card bg-body-tertiary border-secondary-subtle shadow-sm rounded-4">
                <div class="card-body p-4 d-flex align-items-center">
                    <div class="rounded-circle me-3 d-flex justify-content-center align-items-center flex-shrink-0"
                        style="width: 60px; height: 60px; background-color: rgba(6, 214, 160, 0.1);">
                        <i class="bi bi-cash-coin text-success fs-2"></i>
                    </div>
                    <div>
                        <p class="text-secondary small mb-1 text-uppercase fw-bold" style="letter-spacing: 0.05em;">Saldo Efetivado</p>
                        <h3 class="fw-bold mb-0 <?php echo $saldo_efetivado >= 0 ? 'text-success' : 'text-danger'; ?>">
                            R$ <?php echo number_format($saldo_efetivado, 2, ',', '.'); ?>
                        </h3>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card bg-body-tertiary border-secondary-subtle shadow-sm rounded-4">
                <div class="card-body p-4 d-flex align-items-center">
                    <div class="rounded-circle me-3 d-flex justify-content-center align-items-center flex-shrink-0"
                        style="width: 60px; height: 60px; background-color: rgba(230, 57, 70, 0.1);">
                        <i class="bi bi-credit-card text-danger fs-2"></i>
                    </div>
                    <div>
                        <p class="text-secondary small mb-1 text-uppercase fw-bold" style="letter-spacing: 0.05em;">Total Pago</p>
                        <h3 class="fw-bold text-light mb-0">
                            R$ <?php echo number_format($total_pago, 2, ',', '.'); ?>
                        </h3>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card bg-body-tertiary border-secondary-subtle shadow-sm rounded-4">
                <div class="card-body p-4 d-flex align-items-center">
                    <div class="rounded-circle me-3 d-flex justify-content-center align-items-center flex-shrink-0"
                        style="width: 60px; height: 60px; background-color: rgba(212, 175, 55, 0.1); border: 1px solid rgba(212, 175, 55, 0.25);">
                        <i class="bi bi-hourglass-split text-warning fs-2" style="color: #D4AF37 !important;"></i>
                    </div>
                    <div>
                        <p class="text-secondary small mb-1 text-uppercase fw-bold" style="letter-spacing: 0.05em;">Saldo Esperado</p>
                        <h3 class="fw-bold mb-0" style="color: #FFB800;">
                            R$ <?php echo number_format($saldo_esperado, 2, ',', '.'); ?>
                        </h3>
                    </div>
                </div>
            </div>
        </div>

    </div>

    <div class="card bg-dark border-secondary-subtle shadow-sm rounded-4 p-4">
        <h5 class="text-light fw-bold mb-4"><i class="bi bi-list-stars text-secondary me-2"></i> Fluxo de Lançamentos para o Período</h5>

        <?php if (empty($transacoes)): ?>
            <div class="text-center py-5 text-secondary">
                <i class="bi bi-calendar-x fs-1 mb-3 d-block opacity-50"></i>
                Nenhum lançamento registrado ou agendado para este período.
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-dark table-hover align-middle mb-0">
                    <thead>
                        <tr class="text-secondary small text-uppercase" style="font-size: 0.75rem;">
                            <th class="ps-4">Data</th>
                            <th>Descrição</th>
                            <th>Categoria</th>
                            <th>Status</th>
                            <th class="text-end pe-4">Valor</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($transacoes as $t):
                            $dateObj = new DateTime($t['MomentoRegistro']);
                            $isReceita = $t['TipoRegistro'] === 'receita';
                            $isEfetivado = $t['StatusRegistro'] === 'efetivado';
                        ?>
                            <tr>
                                <td class="ps-4 fw-semibold text-secondary" style="font-size: 0.875rem;">
                                    <?php echo $dateObj->format('d/m/Y'); ?>
                                </td>
                                <td>
                                    <span class="text-light fw-bold" style="font-size: 0.9rem;"><?php echo htmlspecialchars($t['Descricao']); ?></span>
                                </td>
                                <td>
                                    <div class="d-flex align-items-center gap-2">
                                        <i class="bi <?php echo htmlspecialchars($t['Icone']); ?> text-secondary"></i>
                                        <span class="text-light-analysis small"><?php echo htmlspecialchars($t['Categoria']); ?></span>
                                    </div>
                                </td>
                                <td>
                                    <?php if ($isEfetivado): ?>
                                        <span class="badge bg-success bg-opacity-10 text-success rounded-pill px-2 py-1" style="font-size: 0.7rem;">Efetivado</span>
                                    <?php else: ?>
                                        <span class="badge bg-warning bg-opacity-10 text-warning rounded-pill px-2 py-1" style="font-size: 0.7rem;">Pendente</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end pe-4 fw-bold <?php echo $isReceita ? 'text-success' : 'text-danger'; ?>" style="font-size: 0.95rem;">
                                    <?php echo $isReceita ? '+' : '-'; ?> R$ <?php echo number_format($t['Valor'], 2, ',', '.'); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

</main>

<div class="modal fade" id="modalSeletorMes" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content bg-dark border-secondary-subtle shadow-lg rounded-4">
            <div class="modal-header border-bottom border-secondary-subtle p-3">
                <h6 class="modal-title text-light fw-bold">
                    <i class="bi bi-calendar3 me-2" style="color: #D4AF37;"></i> Selecionar Período
                </h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4 text-center">

                <div class="d-flex justify-content-between align-items-center mb-4 rounded-pill p-2 border border-secondary-subtle mx-auto" style="max-width: 220px; background-color: #222222;">
                    <button type="button" class="btn btn-sm btn-link text-secondary shadow-none px-3" onclick="mudarAnoModal(-1)">
                        <i class="bi bi-chevron-left fs-5"></i>
                    </button>
                    <span id="anoModalDisplay" class="text-light fw-bold fs-4 m-0"><?= $ano_atual ?></span>
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
                            <button type="button" class="btn w-100 <?= $classeBtn ?> rounded-3 py-2" style="<?= $estiloBtn ?>" onclick="irParaMes(<?= $num ?>)">
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
        window.location.search = urlParams.toString();
    }
</script>

<?php require_once 'geral/footer.php'; ?>