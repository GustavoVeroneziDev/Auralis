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
require_once 'config/funcoes.php';

// Gate: acesso configurável via /admin/configuracoes_planos.php
$_testeAnalises = function_exists('obterHorasRestantesTeste') && obterHorasRestantesTeste() > 0;
if (!$_testeAnalises && !recursoDisponivelParaPlano('analises')) {
    header("Location: /planos.php?upgrade=" . urlencode(nivelMinimoRecurso('analises')));
    exit;
}

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
    // Principal primeiro — usada como fallback quando não há carteira escolhida na sessão/URL
    $sqlCart = "SELECT IDCarteira, TipoCarteira, Principal FROM Carteira WHERE FKUsuarioDono = :usuario_id ORDER BY Principal DESC, TipoCarteira ASC";
    $stmtCart = $pdo->prepare($sqlCart);
    $stmtCart->execute([':usuario_id' => $usuario_id]);
    $carteiras = $stmtCart->fetchAll();
} catch (PDOException $e) {
}

// Descobre qual carteira está selecionada na URL (ou restaura da sessão)
$_carteiraIdsAn = array_column($carteiras, 'IDCarteira');
if (isset($_GET['carteira'])) {
    $carteira_selecionada = $_GET['carteira'];
    if (in_array($carteira_selecionada, $_carteiraIdsAn)) {
        $_SESSION['ultima_carteira'] = $carteira_selecionada;
    } else {
        $carteira_selecionada = (count($carteiras) > 0) ? $carteiras[0]['IDCarteira'] : null;
    }
} else {
    $fromSession = $_SESSION['ultima_carteira'] ?? null;
    if ($fromSession && in_array($fromSession, $_carteiraIdsAn)) {
        $carteira_selecionada = $fromSession;
    } else {
        $carteira_selecionada = (count($carteiras) > 0) ? $carteiras[0]['IDCarteira'] : null;
        if ($carteira_selecionada) $_SESSION['ultima_carteira'] = $carteira_selecionada;
    }
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
                    r.IDRegistro, r.Valor, r.Descricao, r.TipoRegistro,
                    r.MomentoRegistro, r.StatusRegistro,
                    COALESCE(c.NomeCategoria, 'Sem Categoria') as Categoria,
                    COALESCE(c.IconeCategoria, 'bi-tag') as Icone
                FROM Registro r
                LEFT JOIN Categoria c ON r.FKCategoria = c.IDCategoria
                WHERE r.FKUsuario = :uid
                  AND r.FKCarteira = :carteira_id
                  AND r.StatusRegistro = 'efetivado'
                  AND r.TipoRegistro IN ('receita','despesa')
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
                  AND r.TipoRegistro IN ('receita','despesa')
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

// ── Taxa de poupança (mês atual x mês anterior) ────────────────────────
$totalDespesasAnt = array_sum($gastosPorCategoriaAnt);
$totalReceitasAnt = array_sum($receitasPorCategoriaAnt);

$taxaPoupancaAtual = $totalReceitas > 0 ? round((($totalReceitas - $totalDespesas) / $totalReceitas) * 100, 1) : null;
$taxaPoupancaAnt    = $totalReceitasAnt > 0 ? round((($totalReceitasAnt - $totalDespesasAnt) / $totalReceitasAnt) * 100, 1) : null;

// Mês em andamento: receitas (ex: salário) costumam cair só no fim do mês, então a taxa
// de poupança fica enganosa/alarmante enquanto o mês não fecha — melhor avisar em vez de mostrar.
$_mesEmAndamento   = ($mes_atual == (int) date('m') && $ano_atual == (int) date('Y'));
$_diasRestantesMes = $_mesEmAndamento ? ((int) date('t') - (int) date('d')) : 0;

// ── Evolução histórica (últimos 6 ou 12 meses) ──────────────────────────
$_qtdMesesHist = (isset($_GET['hist']) && (int)$_GET['hist'] === 12) ? 12 : 6;
$historicoMensal = [];

if ($carteira_selecionada) {
    try {
        $dtInicioHist = new DateTime(sprintf('%04d-%02d-01', $ano_atual, $mes_atual));
        $dtInicioHist->modify('-' . ($_qtdMesesHist - 1) . ' months');

        $sqlHist = "
            SELECT YEAR(r.MomentoRegistro) as ano, MONTH(r.MomentoRegistro) as mes,
                   r.TipoRegistro, SUM(r.Valor) as total
            FROM Registro r
            WHERE r.FKUsuario = :uid
              AND r.FKCarteira = :carteira_id
              AND r.StatusRegistro = 'efetivado'
              AND r.TipoRegistro IN ('receita','despesa')
              AND r.MomentoRegistro >= :data_inicio
            GROUP BY ano, mes, r.TipoRegistro
        ";
        $stmtHist = $pdo->prepare($sqlHist);
        $stmtHist->execute([
            ':uid'         => $usuario_id,
            ':carteira_id' => $carteira_selecionada,
            ':data_inicio' => $dtInicioHist->format('Y-m-01'),
        ]);

        $brutoHist = [];
        foreach ($stmtHist->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $chave = $row['ano'] . '-' . str_pad($row['mes'], 2, '0', STR_PAD_LEFT);
            $brutoHist[$chave][$row['TipoRegistro']] = (float)$row['total'];
        }

        $cursorHist = clone $dtInicioHist;
        for ($i = 0; $i < $_qtdMesesHist; $i++) {
            $chave = $cursorHist->format('Y-m');
            $rec   = $brutoHist[$chave]['receita'] ?? 0;
            $desp  = $brutoHist[$chave]['despesa'] ?? 0;
            $historicoMensal[] = [
                'label'    => mb_substr($meses_pt[(int)$cursorHist->format('n')], 0, 3) . '/' . $cursorHist->format('y'),
                'receita'  => $rec,
                'despesa'  => $desp,
                'saldo'    => $rec - $desp,
            ];
            $cursorHist->modify('+1 month');
        }
    } catch (PDOException $e) {
    }
}

// ── Categorias do usuário + metas/orçamento definidos ───────────────────
garantirTabelaMetaCategoria($pdo);

$categoriasUsuario = [];
try {
    $stmtCatU = $pdo->prepare("SELECT IDCategoria, NomeCategoria, TipoCategoria, IconeCategoria FROM Categoria WHERE FKUsuario = :uid ORDER BY NomeCategoria ASC");
    $stmtCatU->execute([':uid' => $usuario_id]);
    $categoriasUsuario = $stmtCatU->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
}

$metasPorCategoria = [];
try {
    $stmtMetas = $pdo->prepare("SELECT FKCategoria, ValorMeta FROM MetaCategoria WHERE FKUsuario = :uid");
    $stmtMetas->execute([':uid' => $usuario_id]);
    foreach ($stmtMetas->fetchAll(PDO::FETCH_ASSOC) as $m) {
        $metasPorCategoria[$m['FKCategoria']] = (float)$m['ValorMeta'];
    }
} catch (PDOException $e) {
}

// ── Cofrinhos do usuário ──────────────────────────────────────────────
$cofrinhos = [];
try {
    $stmtCof = $pdo->prepare("
        SELECT co.IDCofrinho, co.Nome, co.Icone, co.Cor, co.ValorMeta, co.DataLimite, co.DataCriacao,
               co.FKCarteira,
               ca.TipoCarteira as NomeCarteira,
               COALESCE(SUM(CASE WHEN r.TipoRegistro='cofrinho'          THEN  r.Valor
                                 WHEN r.TipoRegistro='cofrinho_retirada' THEN -r.Valor
                                 ELSE 0 END), 0) as ValorAtual
        FROM Cofrinho co
        LEFT JOIN Carteira ca ON ca.IDCarteira = co.FKCarteira
        LEFT JOIN Registro r  ON r.FKCofrinho  = co.IDCofrinho
                              AND r.TipoRegistro IN ('cofrinho','cofrinho_retirada')
        WHERE co.FKUsuario = :uid AND co.Ativo = 1
        GROUP BY co.IDCofrinho, co.Nome, co.Icone, co.Cor, co.ValorMeta, co.DataLimite, co.DataCriacao,
                 co.FKCarteira, ca.TipoCarteira
        ORDER BY co.DataCriacao ASC
    ");
    $stmtCof->execute([':uid' => $usuario_id]);
    $cofrinhos = $stmtCof->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
}

// ── Histórico de transações por cofrinho ──────────────────────────────
$historicoCofrinhos = [];
if (!empty($cofrinhos)) {
    try {
        $ids = implode(',', array_fill(0, count($cofrinhos), '?'));
        $stmtHist = $pdo->prepare("
            SELECT FKCofrinho, TipoRegistro, Valor, Descricao, MomentoRegistro
            FROM Registro
            WHERE FKCofrinho IN ({$ids})
              AND FKUsuario = ?
              AND TipoRegistro IN ('cofrinho','cofrinho_retirada')
            ORDER BY MomentoRegistro DESC
            LIMIT 200
        ");
        $params = array_column($cofrinhos, 'IDCofrinho');
        $params[] = $usuario_id;
        $stmtHist->execute($params);
        foreach ($stmtHist->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $historicoCofrinhos[$row['FKCofrinho']][] = $row;
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
    $bg     = $positivo ? 'var(--color-income-bg)'    : 'var(--color-expense-bg)';
    $color  = $positivo ? 'var(--color-income-text)'  : 'var(--color-expense-text)';
    $border = $positivo ? 'var(--color-income-border)' : 'var(--color-expense-border)';
    $icon   = $subiu ? 'bi-arrow-up-short' : 'bi-arrow-down-short';
    return "<span style='display:inline-flex;align-items:center;background:{$bg};color:{$color};border:1px solid {$border};border-radius:999px;padding:1px 7px;font-size:0.68rem;font-weight:600;'><i class='bi {$icon}'></i>{$abs}%</span>";
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

<div class="print-header" style="display:none;">
    <div class="print-header-logo">Auralis</div>
    <div class="print-header-meta">Análises de <?= $nome_mes . ' ' . $ano_atual ?> &mdash; <?= htmlspecialchars($nome_carteira_atual ?? 'Todas as carteiras') ?></div>
</div>

<main class="container-fluid py-4 mt-2 flex-grow-1" style="max-width: 1500px; padding-inline: var(--space-page-x); min-height: 100vh;">

    <div class="mb-4 border-bottom border-secondary-subtle pb-3">
        <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3">

            <div class="d-flex align-items-center gap-2 w-100 w-lg-auto">

                <!-- Seletor de Carteira -->
                <div class="dropdown flex-grow-1 flex-lg-grow-0" style="min-width: 0;">
                    <button class="btn shadow-sm fw-semibold dropdown-toggle d-flex align-items-center justify-content-between rounded-3 transition-hover w-100"
                        type="button" data-bs-toggle="dropdown" aria-expanded="false"
                        style="font-size:0.875rem;background:var(--bg-card);border:1px solid var(--card-border-color);color:var(--text-main);">

                        <!-- A BASE (Botão): Truncamento aplicado aqui -->
                        <div class="d-flex align-items-center text-start" style="min-width: 0;">
                            <i class="bi bi-wallet2 me-2 flex-shrink-0" style="color:var(--accent);"></i>
                            <span class="text-truncate" style="max-width: 130px;" title="<?php echo htmlspecialchars($nome_carteira_atual); ?>">
                                <?php echo htmlspecialchars($nome_carteira_atual); ?>
                            </span>
                        </div>
                    </button>

                    <!-- Lista de Carteiras -->
                    <ul class="dropdown-menu shadow-lg mt-2 w-100" style="background-color:var(--bg-card);border-color:var(--card-border-color);min-width:220px;">
                        <li class="px-3 py-1 text-secondary small text-uppercase fw-bold tracking-wide">Alternar Carteira</li>
                        <li>
                            <hr class="dropdown-divider border-secondary-subtle">
                        </li>
                        <?php foreach ($carteiras as $cart): ?>
                            <li>
                                <a class="dropdown-item d-flex align-items-center py-2 transition-hover <?php echo $carteira_selecionada == $cart['IDCarteira'] ? 'active' : '' ?>"
                                    href="?mes=<?php echo $mes_atual ?>&ano=<?php echo $ano_atual ?>&carteira=<?php echo htmlspecialchars($cart['IDCarteira']) ?>"
                                    title="<?php echo htmlspecialchars($cart['TipoCarteira']); ?>">

                                    <?php if ($carteira_selecionada == $cart['IDCarteira']): ?>
                                        <i class="bi bi-check-circle-fill me-2 flex-shrink-0" style="color: var(--primary-gold-analysis);"></i>
                                        <span class="fw-bold text-truncate" style="color: var(--primary-gold-analysis); max-width: 170px;">
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

                <div class="d-flex align-items-center rounded-pill shadow-sm flex-grow-1 flex-lg-grow-0 justify-content-center" style="padding:2px 4px;background:var(--bg-card);border:1px solid var(--card-border-color);">
                    <a href="<?php echo $link_ant ?>" class="btn btn-sm btn-link transition-hover text-decoration-none d-flex align-items-center justify-content-center" style="width:30px;height:30px;color:var(--accent);">
                        <i class="bi bi-caret-left-fill" style="font-size: 0.65rem;"></i>
                    </a>

                    <button type="button" class="btn btn-link text-decoration-none fw-semibold px-1 transition-hover d-flex align-items-center justify-content-center"
                        style="font-size:0.875rem;white-space:nowrap;color:var(--text-main);"
                        data-bs-toggle="modal" data-bs-target="#modalSeletorMes">
                        <?php echo $nome_mes ?> <span class="d-none d-sm-inline ms-1"><?php echo $ano_atual ?></span>
                        <i class="bi bi-chevron-down ms-1 opacity-75" style="font-size: 0.65rem;"></i>
                    </button>

                    <a href="<?php echo $link_prox ?>" class="btn btn-sm btn-link transition-hover text-decoration-none d-flex align-items-center justify-content-center" style="width:30px;height:30px;color:var(--accent);">
                        <i class="bi bi-caret-right-fill" style="font-size: 0.65rem;"></i>
                    </a>
                </div>

                <!-- Botões de exportação -->
                <a href="/exportar.php?tipo=analises&mes=<?= $mes_atual ?>&ano=<?= $ano_atual ?>&carteira=<?= urlencode($carteira_selecionada ?? '') ?>"
                    class="btn btn-sm d-flex align-items-center gap-1 rounded-3 flex-shrink-0 no-print"
                    style="background:var(--bg-card);border:1px solid var(--card-border-color);color:var(--text-main);font-size:0.78rem;"
                    title="Exportar análises em CSV">
                    <i class="bi bi-filetype-csv" style="color:var(--accent);font-size:0.9rem;"></i>
                    <span class="d-none d-sm-inline">CSV</span>
                </a>
                <button onclick="window.print()"
                    class="btn btn-sm d-flex align-items-center gap-1 rounded-3 flex-shrink-0 no-print"
                    style="background:var(--bg-card);border:1px solid var(--card-border-color);color:var(--text-main);font-size:0.78rem;"
                    title="Exportar análises em PDF">
                    <i class="bi bi-printer" style="color:var(--accent);font-size:0.9rem;"></i>
                    <span class="d-none d-sm-inline">PDF</span>
                </button>

            </div>
        </div>
    </div>

    <div class="row g-4 mb-5">
        <div class="col-md-6">
            <div class="card h-100 rounded-4 shadow-sm" style="background:var(--color-expense-bg);border:1px solid var(--color-expense-border);">
                <div class="card-body p-4 d-flex align-items-center">
                    <div class="rounded-circle me-3 analise-icon flex-shrink-0 d-flex justify-content-center align-items-center" style="width:70px;height:70px;background:rgba(230,57,70,0.18);">
                        <i class="bi bi-fire fs-1 mb-0" style="color:var(--color-expense-text);"></i>
                    </div>
                    <div>
                        <p class="small mb-1 text-uppercase fw-bold tracking-wide" style="color:var(--color-expense-text);opacity:0.75;">Maior Fuga de Capital (Gastos)</p>
                        <?php if ($maiorGastoCat): ?>
                            <h4 class="fw-bold text-light mb-1"><?php echo htmlspecialchars($maiorGastoCat) ?></h4>
                            <div class="d-flex align-items-center gap-2 flex-wrap">
                                <span class="fw-semibold fs-5" style="color:var(--color-expense-text);">R$ <?php echo number_format($maiorGastoValor, 2, ',', '.') ?></span>
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
            <div class="card h-100 rounded-4 shadow-sm" style="background:var(--color-income-bg);border:1px solid var(--color-income-border);">
                <div class="card-body p-4 d-flex align-items-center">
                    <div class="rounded-circle me-3 analise-icon flex-shrink-0 d-flex justify-content-center align-items-center" style="width:70px;height:70px;background:rgba(6,214,160,0.18);">
                        <i class="bi bi-trophy fs-1 mb-0" style="color:var(--color-income-text);"></i>
                    </div>
                    <div>
                        <p class="small mb-1 text-uppercase fw-bold tracking-wide" style="color:var(--color-income-text);opacity:0.75;">Principal Motor de Renda</p>
                        <?php if ($maiorReceitaCat): ?>
                            <h4 class="fw-bold text-light mb-1"><?php echo htmlspecialchars($maiorReceitaCat) ?></h4>
                            <div class="d-flex align-items-center gap-2 flex-wrap">
                                <span class="fw-semibold fs-5" style="color:var(--color-income-text);">R$ <?php echo number_format($maiorReceitaValor, 2, ',', '.') ?></span>
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

    <!-- ── Taxa de Poupança ────────────────────────────────────────────── -->
    <div class="row g-4 mb-5">
        <div class="col-12">
            <div class="card rounded-4 shadow-sm" style="background:var(--bg-card);border:1px solid var(--card-border-color);">
                <div class="card-body p-4 d-flex align-items-center flex-wrap gap-4">
                    <div class="rounded-circle flex-shrink-0 d-flex justify-content-center align-items-center" style="width:70px;height:70px;background:rgba(96,165,250,0.18);">
                        <i class="bi bi-piggy-bank fs-1 mb-0" style="color:#60a5fa;"></i>
                    </div>
                    <div class="flex-grow-1">
                        <p class="small mb-1 text-uppercase fw-bold tracking-wide" style="color:#60a5fa;opacity:0.85;">Taxa de Poupança do Mês</p>
                        <?php if ($_mesEmAndamento): ?>
                            <h5 class="text-secondary mb-1"><i class="bi bi-hourglass-split me-2"></i>Mês em andamento</h5>
                            <p class="text-secondary small mb-0">
                                Esse indicador fica mais confiável quando <?php echo $nome_mes ?> fechar — receitas como salário costumam cair só no fim do mês.
                                <?php if ($_diasRestantesMes > 0): ?>
                                    Faltam <?php echo $_diasRestantesMes ?> dia<?php echo $_diasRestantesMes > 1 ? 's' : '' ?>.
                                <?php endif; ?>
                            </p>
                        <?php elseif ($taxaPoupancaAtual !== null): ?>
                            <div class="d-flex align-items-center gap-2 flex-wrap">
                                <h3 class="fw-bold mb-0" style="color:<?php echo $taxaPoupancaAtual >= 0 ? '#60a5fa' : 'var(--color-expense-text)'; ?>;">
                                    <?php echo number_format($taxaPoupancaAtual, 1, ',', '.') ?>%
                                </h3>
                                <?php if ($taxaPoupancaAnt !== null):
                                    $deltaPoup = $taxaPoupancaAtual - $taxaPoupancaAnt;
                                    $absPoup   = abs(round($deltaPoup, 1));
                                    if ($absPoup >= 0.5):
                                        $bgPoup = $deltaPoup > 0 ? 'var(--color-income-bg)' : 'var(--color-expense-bg)';
                                        $colPoup = $deltaPoup > 0 ? 'var(--color-income-text)' : 'var(--color-expense-text)';
                                        $bordPoup = $deltaPoup > 0 ? 'var(--color-income-border)' : 'var(--color-expense-border)';
                                        $iconPoup = $deltaPoup > 0 ? 'bi-arrow-up-short' : 'bi-arrow-down-short';
                                ?>
                                    <span style="display:inline-flex;align-items:center;background:<?php echo $bgPoup; ?>;color:<?php echo $colPoup; ?>;border:1px solid <?php echo $bordPoup; ?>;border-radius:999px;padding:1px 7px;font-size:0.68rem;font-weight:600;">
                                        <i class="bi <?php echo $iconPoup; ?>"></i><?php echo $absPoup; ?> p.p.
                                    </span>
                                <?php endif; endif; ?>
                            </div>
                            <p class="text-secondary small mb-0 mt-1">
                                <?php if ($taxaPoupancaAtual >= 0): ?>
                                    Você guardou <?php echo number_format($taxaPoupancaAtual, 1, ',', '.') ?>% da sua renda em <?php echo $nome_mes ?>.
                                <?php else: ?>
                                    Você gastou <?php echo number_format(abs($taxaPoupancaAtual), 1, ',', '.') ?>% a mais do que recebeu em <?php echo $nome_mes ?>.
                                <?php endif; ?>
                            </p>
                            <?php if ($taxaPoupancaAnt !== null): ?>
                                <small class="text-secondary" style="font-size:0.7rem;">Mês anterior: <?php echo number_format($taxaPoupancaAnt, 1, ',', '.') ?>%</small>
                            <?php endif; ?>
                        <?php else: ?>
                            <h5 class="text-secondary mb-0">Sem receita registrada este mês</h5>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ── Evolução Histórica ──────────────────────────────────────────── -->
    <div class="row g-4 mb-5" id="evolucao-historica">
        <div class="col-12">
            <div class="card shadow-sm rounded-4" style="background:var(--bg-card);border:1px solid var(--card-border-color);">
                <div class="card-header border-bottom border-secondary-subtle bg-transparent p-4 d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <h5 class="text-light fw-bold mb-0">Evolução Histórica</h5>
                    <?php
                        $_qsBase = "mes={$mes_atual}&ano={$ano_atual}" . ($carteira_selecionada ? "&carteira=" . urlencode($carteira_selecionada) : "");
                    ?>
                    <div class="btn-group btn-group-sm" role="group" aria-label="Período do histórico">
                        <a href="?<?php echo $_qsBase ?>&hist=6#evolucao-historica"
                           class="btn visual-toggle-btn <?php echo $_qtdMesesHist === 6 ? 'active' : '' ?>">6 meses</a>
                        <a href="?<?php echo $_qsBase ?>&hist=12#evolucao-historica"
                           class="btn visual-toggle-btn <?php echo $_qtdMesesHist === 12 ? 'active' : '' ?>">12 meses</a>
                    </div>
                </div>
                <div class="card-body p-4">
                    <?php if (!empty($historicoMensal)): ?>
                        <div style="height:320px;">
                            <canvas id="graficoHistorico"></canvas>
                        </div>
                    <?php else: ?>
                        <div class="text-center text-secondary py-4">
                            <i class="bi bi-graph-up fs-1 mb-2 d-block opacity-50"></i>
                            Ainda não há dados suficientes pra mostrar a evolução.
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <?php if ($totalDespesas > 0): ?>
        <div class="row g-4 mb-5">
            <div class="col-lg-6">
                <div class="card shadow-sm rounded-4 h-100" style="background:var(--bg-card);border:1px solid var(--card-border-color);">
                    <div class="card-header border-bottom border-secondary-subtle bg-transparent p-4 d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <h5 class="text-light fw-bold mb-0">Distribuição de Despesas</h5>
                        <div class="btn-group btn-group-sm visual-toggle" role="group" aria-label="Tipo de gráfico" data-tipo="despesa">
                            <button type="button" class="btn visual-toggle-btn" data-visual="pizza" title="Gráfico de pizza">
                                <i class="bi bi-pie-chart-fill"></i>
                            </button>
                            <button type="button" class="btn visual-toggle-btn" data-visual="barra" title="Gráfico de barras">
                                <i class="bi bi-bar-chart-line-fill"></i>
                            </button>
                        </div>
                    </div>
                    <div class="card-body p-4 d-flex justify-content-center align-items-center position-relative">
                        <div class="position-relative d-flex justify-content-center align-items-center w-100 donut-wrapper" id="wrapper-grafico-despesa" style="max-width: 320px; aspect-ratio: 1;">
                            <canvas id="graficoDespesas"></canvas>
                            <div class="position-absolute text-center" id="total-overlay-despesa" style="pointer-events: none;">
                                <span class="d-block text-secondary small">Total</span>
                                <h5 class="text-light fw-bold mb-0">R$ <?php echo number_format($totalDespesas, 2, ',', '.') ?></h5>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-6">
                <div class="card shadow-sm rounded-4 h-100" style="background:var(--bg-card);border:1px solid var(--card-border-color);">
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
                <div class="card shadow-sm rounded-4 h-100" style="background:var(--bg-card);border:1px solid var(--card-border-color);">
                    <div class="card-header border-bottom border-secondary-subtle bg-transparent p-4 d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <h5 class="text-light fw-bold mb-0">Distribuição de Receitas</h5>
                        <div class="btn-group btn-group-sm visual-toggle" role="group" aria-label="Tipo de gráfico" data-tipo="receita">
                            <button type="button" class="btn visual-toggle-btn" data-visual="pizza" title="Gráfico de pizza">
                                <i class="bi bi-pie-chart-fill"></i>
                            </button>
                            <button type="button" class="btn visual-toggle-btn" data-visual="barra" title="Gráfico de barras">
                                <i class="bi bi-bar-chart-line-fill"></i>
                            </button>
                        </div>
                    </div>
                    <div class="card-body p-4 d-flex justify-content-center align-items-center position-relative">
                        <div class="position-relative d-flex justify-content-center align-items-center w-100 donut-wrapper" id="wrapper-grafico-receita" style="max-width: 320px; aspect-ratio: 1;">
                            <canvas id="graficoReceitas"></canvas>
                            <div class="position-absolute text-center" id="total-overlay-receita" style="pointer-events: none;">
                                <span class="d-block text-secondary small">Total</span>
                                <h5 class="text-light fw-bold mb-0">R$ <?php echo number_format($totalReceitas, 2, ',', '.') ?></h5>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-6">
                <div class="card shadow-sm rounded-4 h-100" style="background:var(--bg-card);border:1px solid var(--card-border-color);">
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

    <!-- ══════════════════════════════════════════════════════════════════════ -->
    <!-- ── Metas por Categoria (orçamento de despesa / meta de receita) ──── -->
    <!-- ══════════════════════════════════════════════════════════════════════ -->
    <div class="row g-4 mb-5" id="metas-categoria">
        <?php
            $_msgsMeta = [
                'meta_salva'    => 'Meta salva com sucesso!',
                'meta_removida' => 'Meta removida.',
            ];
            $_errosMeta = [
                'categoria_invalida' => 'Categoria inválida.',
                'valor_invalido'     => 'Informe um valor numérico maior que zero.',
                'banco'              => 'Erro ao salvar no banco de dados.',
            ];
        ?>
        <?php if (isset($_GET['sucesso_meta']) && isset($_msgsMeta[$_GET['sucesso_meta']])): ?>
            <div class="col-12">
                <div class="alert alert-success rounded-4 border-0 mb-0 py-2 px-3 small fw-semibold">
                    <i class="bi bi-check-circle me-2"></i><?php echo htmlspecialchars($_msgsMeta[$_GET['sucesso_meta']]) ?>
                </div>
            </div>
        <?php endif; ?>
        <?php if (isset($_GET['erro_meta']) && isset($_errosMeta[$_GET['erro_meta']])): ?>
            <div class="col-12">
                <div class="alert rounded-4 border-0 mb-0 py-2 px-3 small fw-semibold" style="background:rgba(220,38,38,0.15);color:#fca5a5;">
                    <i class="bi bi-exclamation-triangle me-2"></i><?php echo htmlspecialchars($_errosMeta[$_GET['erro_meta']]) ?>
                </div>
            </div>
        <?php endif; ?>

        <div class="col-lg-6">
            <div class="card shadow-sm rounded-4 h-100" style="background:var(--bg-card);border:1px solid var(--card-border-color);">
                <div class="card-header border-bottom border-secondary-subtle bg-transparent p-4">
                    <h5 class="text-light fw-bold mb-0"><i class="bi bi-piggy-bank me-2 text-danger"></i>Orçamento por Categoria (Despesas)</h5>
                    <p class="text-secondary small mb-0 mt-1">Defina um limite mensal e acompanhe se está estourando.</p>
                </div>
                <div class="card-body p-0">
                    <?php
                        $_catsDespesa = array_filter($categoriasUsuario, fn($c) => $c['TipoCategoria'] === 'despesa');
                    ?>
                    <?php if (empty($_catsDespesa)): ?>
                        <div class="p-4 text-center text-secondary small">Nenhuma categoria de despesa cadastrada.</div>
                    <?php else: ?>
                        <?php foreach ($_catsDespesa as $cat):
                            $gastoAtualCat = $gastosPorCategoria[$cat['NomeCategoria']] ?? 0;
                            $metaCat       = $metasPorCategoria[$cat['IDCategoria']] ?? null;
                            $pctCat        = ($metaCat && $metaCat > 0) ? round(($gastoAtualCat / $metaCat) * 100, 1) : null;
                        ?>
                        <div class="d-flex align-items-center justify-content-between px-4 py-3 border-bottom border-secondary-subtle">
                            <div class="d-flex align-items-center gap-3 flex-grow-1 min-w-0">
                                <i class="bi <?php echo htmlspecialchars($cat['IconeCategoria'] ?: 'bi-tag') ?> text-secondary fs-5 flex-shrink-0"></i>
                                <div class="flex-grow-1 min-w-0">
                                    <div class="text-light fw-semibold text-truncate"><?php echo htmlspecialchars($cat['NomeCategoria']) ?></div>
                                    <?php if ($pctCat !== null): ?>
                                        <div class="progress rounded-pill mt-1" style="height:6px;background:rgba(255,255,255,0.07);">
                                            <div class="progress-bar rounded-pill"
                                                 style="width:<?php echo min(100, $pctCat) ?>%;background:<?php echo $pctCat >= 100 ? 'var(--color-expense-text)' : '#60a5fa'; ?>;"></div>
                                        </div>
                                        <div class="d-flex justify-content-between mt-1">
                                            <span class="text-secondary" style="font-size:0.72rem;">R$ <?php echo number_format($gastoAtualCat, 2, ',', '.') ?> / R$ <?php echo number_format($metaCat, 2, ',', '.') ?></span>
                                            <?php if ($pctCat >= 100): ?>
                                                <span class="fw-semibold text-danger" style="font-size:0.72rem;"><i class="bi bi-exclamation-triangle-fill me-1"></i>Estourou!</span>
                                            <?php endif; ?>
                                        </div>
                                    <?php else: ?>
                                        <span class="badge rounded-pill text-secondary border border-secondary-subtle mt-1" style="font-size:0.7rem;">Sem orçamento definido</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill flex-shrink-0 ms-2"
                                    onclick="abrirModalMeta('<?php echo htmlspecialchars($cat['IDCategoria']) ?>','<?php echo htmlspecialchars(addslashes($cat['NomeCategoria'])) ?>','despesa',<?php echo $metaCat !== null ? $metaCat : 'null' ?>)">
                                <i class="bi bi-pencil"></i>
                            </button>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card shadow-sm rounded-4 h-100" style="background:var(--bg-card);border:1px solid var(--card-border-color);">
                <div class="card-header border-bottom border-secondary-subtle bg-transparent p-4">
                    <h5 class="text-light fw-bold mb-0"><i class="bi bi-flag-fill me-2 text-success"></i>Meta por Categoria (Receitas)</h5>
                    <p class="text-secondary small mb-0 mt-1">Defina uma meta mensal e acompanhe se já bateu.</p>
                </div>
                <div class="card-body p-0">
                    <?php
                        $_catsReceita = array_filter($categoriasUsuario, fn($c) => $c['TipoCategoria'] === 'receita');
                    ?>
                    <?php if (empty($_catsReceita)): ?>
                        <div class="p-4 text-center text-secondary small">Nenhuma categoria de receita cadastrada.</div>
                    <?php else: ?>
                        <?php foreach ($_catsReceita as $cat):
                            $receitaAtualCat = $receitasPorCategoria[$cat['NomeCategoria']] ?? 0;
                            $metaCatR        = $metasPorCategoria[$cat['IDCategoria']] ?? null;
                            $pctCatR         = ($metaCatR && $metaCatR > 0) ? round(($receitaAtualCat / $metaCatR) * 100, 1) : null;
                        ?>
                        <div class="d-flex align-items-center justify-content-between px-4 py-3 border-bottom border-secondary-subtle">
                            <div class="d-flex align-items-center gap-3 flex-grow-1 min-w-0">
                                <i class="bi <?php echo htmlspecialchars($cat['IconeCategoria'] ?: 'bi-tag') ?> text-secondary fs-5 flex-shrink-0"></i>
                                <div class="flex-grow-1 min-w-0">
                                    <div class="text-light fw-semibold text-truncate"><?php echo htmlspecialchars($cat['NomeCategoria']) ?></div>
                                    <?php if ($pctCatR !== null): ?>
                                        <div class="progress rounded-pill mt-1" style="height:6px;background:rgba(255,255,255,0.07);">
                                            <div class="progress-bar rounded-pill"
                                                 style="width:<?php echo min(100, $pctCatR) ?>%;background:#06D6A0;"></div>
                                        </div>
                                        <div class="d-flex justify-content-between mt-1">
                                            <span class="text-secondary" style="font-size:0.72rem;">R$ <?php echo number_format($receitaAtualCat, 2, ',', '.') ?> / R$ <?php echo number_format($metaCatR, 2, ',', '.') ?></span>
                                            <?php if ($pctCatR >= 100): ?>
                                                <span class="fw-semibold" style="font-size:0.72rem;color:#06D6A0;"><i class="bi bi-check-circle-fill me-1"></i>Meta atingida!</span>
                                            <?php endif; ?>
                                        </div>
                                    <?php else: ?>
                                        <span class="badge rounded-pill text-secondary border border-secondary-subtle mt-1" style="font-size:0.7rem;">Sem meta definida</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill flex-shrink-0 ms-2"
                                    onclick="abrirModalMeta('<?php echo htmlspecialchars($cat['IDCategoria']) ?>','<?php echo htmlspecialchars(addslashes($cat['NomeCategoria'])) ?>','receita',<?php echo $metaCatR !== null ? $metaCatR : 'null' ?>)">
                                <i class="bi bi-pencil"></i>
                            </button>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- ══════════════════════════════════════════════════════════════════════ -->
    <!-- ── Seção Cofrinhos & Metas ─────────────────────────────────────── -->
    <!-- ══════════════════════════════════════════════════════════════════════ -->
    <div id="cofrinhos" class="mt-5 pt-2">
        <div class="d-flex align-items-center justify-content-between mb-4">
            <div class="d-flex align-items-center gap-2">
                <i class="bi bi-piggy-bank fs-4" style="color:#f59e0b;"></i>
                <h5 class="text-light fw-bold mb-0">Cofrinhos &amp; Metas</h5>
            </div>
            <button class="btn btn-sm rounded-pill fw-semibold"
                    style="background:rgba(245,158,11,0.12);color:#f59e0b;border:1px solid rgba(245,158,11,0.3);"
                    data-bs-toggle="modal" data-bs-target="#modalCriarCofrinho">
                <i class="bi bi-plus-lg me-1"></i> Novo cofrinho
            </button>
        </div>

        <?php
        $sucessosValidos = ['cofrinho_criado','cofrinho_editado','deposito_realizado','retirada_realizada','reajuste_feito','cofrinho_excluido'];
        $msgsSucesso = [
            'cofrinho_criado'    => 'Cofrinho criado com sucesso!',
            'cofrinho_editado'   => 'Cofrinho atualizado!',
            'deposito_realizado' => 'Depósito realizado com sucesso!',
            'retirada_realizada' => 'Retirada realizada com sucesso!',
            'reajuste_feito'     => 'Saldo reajustado com sucesso!',
            'cofrinho_excluido'  => 'Cofrinho excluído.',
        ];
        if (isset($_GET['sucesso']) && isset($msgsSucesso[$_GET['sucesso']])): ?>
            <div class="alert alert-success rounded-4 border-0 mb-3 py-2 px-3 small fw-semibold">
                <i class="bi bi-check-circle me-2"></i><?= htmlspecialchars($msgsSucesso[$_GET['sucesso']]) ?>
            </div>
        <?php endif; ?>

        <?php if (isset($_GET['erro'])): ?>
            <div class="alert rounded-4 border-0 mb-3 py-2 px-3 small fw-semibold" style="background:rgba(220,38,38,0.15);color:#fca5a5;">
                <i class="bi bi-exclamation-triangle me-2"></i>Erro ao processar:
                <?= htmlspecialchars($_GET['erro']) ?>
                <?php if (!empty($_GET['detail'])): ?>
                    <br><code style="font-size:0.78rem;opacity:0.8;"><?= htmlspecialchars($_GET['detail']) ?></code>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if (empty($cofrinhos)): ?>
            <div class="card bg-body-tertiary border-secondary-subtle rounded-4 shadow-sm">
                <div class="card-body text-center py-5">
                    <i class="bi bi-piggy-bank text-secondary opacity-25 mb-3" style="font-size:3.5rem;"></i>
                    <h6 class="text-light fw-bold mb-1">Nenhum cofrinho ainda</h6>
                    <p class="text-secondary small mb-3">Crie seu primeiro cofrinho para guardar dinheiro com metas.</p>
                    <button class="btn btn-sm rounded-pill fw-semibold px-4"
                            style="background:rgba(245,158,11,0.12);color:#f59e0b;border:1px solid rgba(245,158,11,0.3);"
                            data-bs-toggle="modal" data-bs-target="#modalCriarCofrinho">
                        <i class="bi bi-plus-lg me-1"></i> Criar primeiro cofrinho
                    </button>
                </div>
            </div>
        <?php else: ?>
            <div class="row row-cols-1 row-cols-md-2 row-cols-lg-3 g-3">
                <?php foreach ($cofrinhos as $cof):
                    $valAtual = (float) $cof['ValorAtual'];
                    $valMeta  = $cof['ValorMeta'] !== null ? (float) $cof['ValorMeta'] : null;
                    $pct      = ($valMeta && $valMeta > 0) ? min(100, round(($valAtual / $valMeta) * 100, 1)) : null;
                    $diasRestantes = null;
                    if ($cof['DataLimite']) {
                        $hoje2 = new DateTime();
                        $fim2  = new DateTime($cof['DataLimite']);
                        $diasRestantes = (int) $hoje2->diff($fim2)->days * ($fim2 >= $hoje2 ? 1 : -1);
                    }
                    $cofId   = htmlspecialchars($cof['IDCofrinho']);
                    $cofNome = htmlspecialchars(addslashes($cof['Nome']));
                    $cofCor  = htmlspecialchars($cof['Cor']);
                    $histJSON = htmlspecialchars(json_encode($historicoCofrinhos[$cof['IDCofrinho']] ?? []), ENT_QUOTES);
                    $metaJSON = $valMeta !== null ? number_format($valMeta, 2, '.', '') : 'null';
                ?>
                <div class="col">
                    <div class="card bg-body-tertiary border-secondary-subtle shadow-sm rounded-4 h-100 overflow-hidden"
                         style="transition:border-color .15s;">
                        <!-- Header clicável → histórico -->
                        <div class="d-flex align-items-center gap-3 px-4 py-3 cofrinho-header-click"
                             style="background:linear-gradient(135deg,<?= $cofCor ?>22,<?= $cofCor ?>44);
                                    border-bottom:1px solid <?= $cofCor ?>33;cursor:pointer;"
                             onclick="abrirHistorico('<?= $cofId ?>','<?= $cofNome ?>','<?= $cofCor ?>',<?= $valAtual ?>,<?= $metaJSON ?>,'<?= htmlspecialchars($cof['Icone']) ?>','<?= $histJSON ?>','<?= htmlspecialchars($cof['DataLimite'] ?? '') ?>')">
                            <div class="rounded-3 d-flex align-items-center justify-content-center flex-shrink-0"
                                 style="width:44px;height:44px;background:<?= $cofCor ?>33;">
                                <i class="bi <?= htmlspecialchars($cof['Icone']) ?>" style="color:<?= $cofCor ?>;font-size:1.4rem;"></i>
                            </div>
                            <div class="flex-grow-1 min-w-0">
                                <div class="fw-bold text-light text-truncate" style="font-size:1rem;"><?= htmlspecialchars($cof['Nome']) ?></div>
                                <div class="text-secondary small text-truncate">
                                    <i class="bi bi-wallet2 me-1" style="font-size:0.7rem;"></i><?= htmlspecialchars($cof['NomeCarteira'] ?? '—') ?>
                                </div>
                            </div>
                            <i class="bi bi-chevron-right text-secondary opacity-50 flex-shrink-0" style="font-size:0.8rem;"></i>
                        </div>

                        <div class="card-body px-4 pb-4 pt-3">
                            <!-- Valor -->
                            <div class="d-flex align-items-baseline gap-1 mb-2">
                                <span class="fw-bold text-light" style="font-size:1.35rem;">R$ <?= number_format($valAtual, 2, ',', '.') ?></span>
                                <?php if ($valMeta !== null): ?>
                                    <span class="text-secondary small">/ R$ <?= number_format($valMeta, 2, ',', '.') ?></span>
                                <?php else: ?>
                                    <span class="text-secondary small">guardado</span>
                                <?php endif; ?>
                            </div>

                            <!-- Barra de progresso -->
                            <?php if ($pct !== null): ?>
                                <div class="mb-2">
                                    <div class="progress rounded-pill" style="height:8px;background:rgba(255,255,255,0.07);">
                                        <div class="progress-bar rounded-pill"
                                             style="width:<?= $pct ?>%;background:<?= $cofCor ?>;"
                                             role="progressbar" aria-valuenow="<?= $pct ?>" aria-valuemin="0" aria-valuemax="100"></div>
                                    </div>
                                    <div class="d-flex justify-content-between mt-1">
                                        <span class="text-secondary" style="font-size:0.72rem;"><?= $pct ?>% concluído</span>
                                        <?php if ($pct >= 100): ?>
                                            <span class="fw-semibold" style="font-size:0.72rem;color:<?= $cofCor ?>;"><i class="bi bi-check-circle-fill me-1"></i>Meta atingida!</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php else: ?>
                                <div class="mb-2">
                                    <span class="badge rounded-pill text-secondary border border-secondary-subtle" style="font-size:0.7rem;">Sem meta definida</span>
                                </div>
                            <?php endif; ?>

                            <!-- Data limite -->
                            <?php if ($diasRestantes !== null): ?>
                                <div class="small mb-3 <?= $diasRestantes < 0 ? 'text-danger' : 'text-secondary' ?>">
                                    <i class="bi bi-calendar3 me-1"></i>
                                    <?php if ($diasRestantes < 0): ?>
                                        Prazo encerrado há <?= abs($diasRestantes) ?> dia<?= abs($diasRestantes) > 1 ? 's' : '' ?>
                                    <?php elseif ($diasRestantes === 0): ?>
                                        Prazo: hoje!
                                    <?php else: ?>
                                        <?= $diasRestantes ?> dia<?= $diasRestantes > 1 ? 's' : '' ?> restante<?= $diasRestantes > 1 ? 's' : '' ?>
                                        <span class="opacity-50 ms-1">(<?= date('d/m/Y', strtotime($cof['DataLimite'])) ?>)</span>
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <div class="mb-3"></div>
                            <?php endif; ?>

                            <!-- Ações -->
                            <div class="d-flex gap-2">
                                <button class="btn btn-sm flex-grow-1 fw-semibold rounded-pill"
                                        style="background:<?= $cofCor ?>22;color:<?= $cofCor ?>;border:1px solid <?= $cofCor ?>44;"
                                        onclick="abrirDepositar('<?= $cofId ?>','<?= $cofNome ?>')">
                                    <i class="bi bi-plus-lg me-1"></i> Depositar
                                </button>
                                <!-- Dropdown de ações -->
                                <div class="dropdown">
                                    <button class="btn btn-sm btn-outline-secondary rounded-pill px-2"
                                            type="button" data-bs-toggle="dropdown" title="Mais ações">
                                        <i class="bi bi-three-dots"></i>
                                    </button>
                                    <ul class="dropdown-menu dropdown-menu-end shadow" style="background:var(--bg-card);border-color:var(--bs-border-color);min-width:175px;">
                                        <li><button class="dropdown-item d-flex align-items-center gap-2 py-2"
                                                    onclick="abrirRetirar('<?= $cofId ?>','<?= $cofNome ?>')">
                                            <i class="bi bi-arrow-up-circle text-warning"></i> Retirar valor
                                        </button></li>
                                        <li><button class="dropdown-item d-flex align-items-center gap-2 py-2"
                                                    onclick="abrirReajustar('<?= $cofId ?>','<?= $cofNome ?>',<?= $valAtual ?>)">
                                            <i class="bi bi-sliders text-info"></i> Reajustar saldo
                                        </button></li>
                                        <li><hr class="dropdown-divider border-secondary-subtle my-1"></li>
                                        <li><button class="dropdown-item d-flex align-items-center gap-2 py-2"
                                                    onclick="abrirEditar('<?= $cofId ?>','<?= $cofNome ?>','<?= $cofCor ?>','<?= htmlspecialchars($cof['Icone']) ?>',<?= $metaJSON ?>,'<?= htmlspecialchars($cof['DataLimite'] ?? '') ?>')">
                                            <i class="bi bi-pencil text-secondary"></i> Editar cofrinho
                                        </button></li>
                                        <li><button class="dropdown-item d-flex align-items-center gap-2 py-2 text-danger"
                                                    onclick="abrirExcluir('<?= $cofId ?>','<?= $cofNome ?>')">
                                            <i class="bi bi-trash3"></i> Excluir cofrinho
                                        </button></li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>

                <!-- Card fantasma: novo cofrinho -->
                <div class="col">
                    <div class="card rounded-4 h-100 d-flex align-items-center justify-content-center"
                         style="background:transparent;border:2px dashed var(--bs-border-color);cursor:pointer;min-height:200px;"
                         data-bs-toggle="modal" data-bs-target="#modalCriarCofrinho">
                        <div class="text-center py-4 px-3">
                            <div class="rounded-circle d-flex align-items-center justify-content-center mx-auto mb-3"
                                 style="width:52px;height:52px;background:rgba(245,158,11,0.1);border:2px dashed rgba(245,158,11,0.35);">
                                <i class="bi bi-plus-lg" style="color:#f59e0b;font-size:1.3rem;"></i>
                            </div>
                            <div class="fw-semibold text-secondary small">Novo cofrinho</div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Forms ocultos -->
    <form id="formAcaoCofrinho" method="POST" action="cofrinho/processa_cofrinho.php" style="display:none;">
        <input type="hidden" name="acao"        id="form_acao">
        <input type="hidden" name="id_cofrinho" id="form_id">
    </form>

</main>

<!-- Context menu dos itens do Detalhamento -->
<div id="analiseContextMenu"
     style="display:none;position:fixed;z-index:9999;min-width:170px;
            background:var(--bg-card);border:1px solid var(--card-border-color);
            border-radius:10px;box-shadow:0 8px 24px rgba(0,0,0,.4);overflow:hidden;">
    <div onclick="ctxEditar()"
         style="padding:10px 16px;cursor:pointer;display:flex;align-items:center;gap:10px;font-size:.875rem;color:var(--text-main);"
         onmouseenter="this.style.background='rgba(255,255,255,.07)'" onmouseleave="this.style.background=''">
        <i class="bi bi-pencil-square" style="color:var(--accent);"></i> Editar
    </div>
    <div onclick="ctxNaoEfetivar()"
         style="padding:10px 16px;cursor:pointer;display:flex;align-items:center;gap:10px;font-size:.875rem;color:var(--text-main);"
         onmouseenter="this.style.background='rgba(255,255,255,.07)'" onmouseleave="this.style.background=''">
        <i class="bi bi-clock-history" style="color:#f59e0b;"></i> Não efetivar
    </div>
    <?php if (count($carteiras) > 1): ?>
    <div onclick="ctxToggleTransferirMenu(event)"
         style="padding:10px 16px;cursor:pointer;display:flex;align-items:center;justify-content:space-between;font-size:.875rem;color:var(--text-main);"
         onmouseenter="this.style.background='rgba(255,255,255,.07)'" onmouseleave="this.style.background=''">
        <span style="display:flex;align-items:center;gap:10px;">
            <i class="bi bi-arrow-left-right" style="color:#60a5fa;"></i> Transferir para
        </span>
        <i class="bi bi-chevron-down text-secondary" id="ctxTransferirChevron" style="font-size:.7rem;transition:transform .15s;"></i>
    </div>
    <div id="ctxTransferirSubmenu" style="display:none;border-top:1px solid var(--card-border-color);">
        <?php foreach ($carteiras as $cart): ?>
            <?php if ($cart['IDCarteira'] != $carteira_selecionada): ?>
                <div onclick="ctxTransferir('<?= htmlspecialchars($cart['IDCarteira'], ENT_QUOTES) ?>', '<?= htmlspecialchars($cart['TipoCarteira'], ENT_QUOTES) ?>')"
                     style="padding:9px 16px 9px 28px;cursor:pointer;display:flex;align-items:center;gap:10px;font-size:.85rem;color:var(--text-main);"
                     onmouseenter="this.style.background='rgba(96,165,250,.08)'" onmouseleave="this.style.background=''">
                    <i class="bi bi-wallet2" style="color:#60a5fa;font-size:.85rem;"></i>
                    <?= htmlspecialchars($cart['TipoCarteira']) ?>
                </div>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <div style="height:1px;background:var(--card-border-color);margin:2px 0;"></div>
    <div onclick="ctxExcluir()"
         style="padding:10px 16px;cursor:pointer;display:flex;align-items:center;gap:10px;font-size:.875rem;color:#f87171;"
         onmouseenter="this.style.background='rgba(239,68,68,.08)'" onmouseleave="this.style.background=''">
        <i class="bi bi-trash3"></i> Excluir
    </div>
</div>

<style>
    .bg-card-analysis {
        background-color: var(--bg-card-analysis);
    }

    .analise-item {
        transition: background .15s, border-left-color .15s;
        border-left: 3px solid transparent !important;
    }
    .analise-item:hover {
        background: rgba(255,255,255,.05) !important;
        border-left-color: var(--accent) !important;
    }

    .tracking-wide {
        letter-spacing: 0.05em;
    }

    .visual-toggle-btn {
        background: transparent;
        border: 1px solid var(--card-border-color);
        color: var(--text-muted);
        line-height: 1;
    }
    .visual-toggle-btn:hover:not(.active) {
        background: rgba(255,255,255,0.06);
        color: var(--text-main);
    }
    .visual-toggle-btn.active {
        background: rgba(212,175,55,0.15);
        border-color: rgba(212,175,55,0.5);
        color: var(--accent, #D4AF37);
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
        background-color: var(--bs-border-color);
        border-radius: 10px;
    }
</style>
<div class="modal fade" id="modalSeletorMes" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content border-secondary-subtle shadow-lg rounded-4" style="background:var(--bg-card);">
            <div class="modal-header border-bottom border-secondary-subtle p-3">
                <h6 class="modal-title text-light fw-bold">
                    <i class="bi bi-calendar3 me-2" style="color: var(--primary-gold-analysis);"></i> Selecionar Período
                </h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
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
        const bg = positivo ? 'var(--color-income-bg)' : 'var(--color-expense-bg)';
        const color = positivo ? 'var(--color-income-text)' : 'var(--color-expense-text)';
        const border = positivo ? 'var(--color-income-border)' : 'var(--color-expense-border)';
        const icon = subiu ? 'bi-arrow-up-short' : 'bi-arrow-down-short';
        return `<span style="display:inline-flex;align-items:center;background:${bg};color:${color};border:1px solid ${border};border-radius:999px;padding:1px 7px;font-size:0.68rem;font-weight:600;"><i class="bi ${icon}"></i> ${abs}%</span>`;
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

                // Nome da categoria (truncado em 12 chars)
                const label = data.labels[i].length > 12 ?
                    data.labels[i].substring(0, 11) + '…' :
                    data.labels[i];

                ctx.textAlign = 'center';
                ctx.textBaseline = 'middle';

                // Sombra escura para garantir contraste sobre qualquer cor de fatia
                ctx.shadowColor = 'rgba(0,0,0,0.75)';
                ctx.shadowBlur = 5;
                ctx.shadowOffsetX = 0;
                ctx.shadowOffsetY = 1;

                // Percentagem em cima — branco puro, negrito
                ctx.font = 'bold 12px Inter, sans-serif';
                ctx.fillStyle = '#ffffff';
                ctx.fillText(Math.round(pct) + '%', x, y - 8);

                // Nome embaixo — branco levemente suavizado, negrito
                ctx.font = 'bold 10px Inter, sans-serif';
                ctx.fillStyle = 'rgba(255,255,255,0.90)';
                ctx.fillText(label, x, y + 7);

                // Reset shadow
                ctx.shadowColor = 'transparent';
                ctx.shadowBlur = 0;
            });
            ctx.restore();
        }
    };

    // ── Plugin: labels no fim de cada barra (valor + %) ────────────────────────
    const pluginLabelsBarras = {
        id: 'labelsBarras',
        afterDatasetsDraw(chart) {
            const { ctx, data } = chart;
            const total = data.datasets[0].data.reduce((a, b) => a + b, 0);
            if (total === 0) return;
            const meta = chart.getDatasetMeta(0);
            const textMain = getComputedStyle(document.documentElement).getPropertyValue('--text-main').trim() || '#f8fafc';

            ctx.save();
            ctx.font = 'bold 11px Inter, sans-serif';
            ctx.textAlign = 'left';
            ctx.textBaseline = 'middle';
            ctx.fillStyle = textMain;
            data.datasets[0].data.forEach((valor, i) => {
                const bar = meta.data[i];
                if (!bar) return;
                const pct = ((valor / total) * 100).toFixed(0);
                const val = new Intl.NumberFormat('pt-BR', {
                    style: 'currency',
                    currency: 'BRL',
                    maximumFractionDigits: 0
                }).format(valor);
                ctx.fillText(`${val} (${pct}%)`, bar.x + 8, bar.y);
            });
            ctx.restore();
        }
    };

    function montarGraficoPizza(canvasId, labels, valores, cores, tipo) {
        const canvas = document.getElementById(canvasId);
        if (!canvas) return null;

        const style = getComputedStyle(document.documentElement);
        const bgCard = style.getPropertyValue('--bg-card').trim() || '#1e2126';
        const bgDark = style.getPropertyValue('--bg-dark').trim() || '#1a1d21';
        const textMain = style.getPropertyValue('--text-main').trim() || '#f8fafc';
        const textMuted = style.getPropertyValue('--text-muted').trim() || '#a1a1aa';
        const accentRgb = style.getPropertyValue('--bs-primary-rgb').trim() || '212,175,55';

        const chart = new Chart(canvas.getContext('2d'), {
            type: 'doughnut',
            data: {
                labels,
                datasets: [{
                    data: valores,
                    backgroundColor: cores,
                    borderWidth: 3,
                    borderColor: bgDark,
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
                        backgroundColor: bgCard,
                        borderColor: `rgba(${accentRgb},0.3)`,
                        borderWidth: 1,
                        titleColor: textMain,
                        bodyColor: textMuted,
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

    function montarGraficoBarra(canvasId, labels, valores, cores, tipo) {
        const canvas = document.getElementById(canvasId);
        if (!canvas) return null;

        // Ordena por valor decrescente (efeito "ranking"), preservando a cor original de cada categoria
        const indices = valores.map((_, i) => i).sort((a, b) => valores[b] - valores[a]);
        const labelsOrd = indices.map(i => labels[i]);
        const valoresOrd = indices.map(i => valores[i]);
        const coresOrd = indices.map(i => cores[i % cores.length]);

        const style = getComputedStyle(document.documentElement);
        const bgCard = style.getPropertyValue('--bg-card').trim() || '#1e2126';
        const textMain = style.getPropertyValue('--text-main').trim() || '#f8fafc';
        const textMuted = style.getPropertyValue('--text-muted').trim() || '#a1a1aa';
        const accentRgb = style.getPropertyValue('--bs-primary-rgb').trim() || '212,175,55';

        const chart = new Chart(canvas.getContext('2d'), {
            type: 'bar',
            data: {
                labels: labelsOrd,
                datasets: [{
                    data: valoresOrd,
                    backgroundColor: coresOrd,
                    borderRadius: 6,
                    borderSkipped: false,
                    maxBarThickness: 28,
                }]
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                animation: {
                    duration: 600
                },
                layout: {
                    padding: { right: 90 }
                },
                scales: {
                    x: {
                        grid: { color: 'rgba(255,255,255,0.06)' },
                        ticks: {
                            color: textMuted,
                            callback: v => new Intl.NumberFormat('pt-BR', {
                                style: 'currency',
                                currency: 'BRL',
                                maximumFractionDigits: 0
                            }).format(v)
                        }
                    },
                    y: {
                        grid: { display: false },
                        ticks: { color: textMain, font: { weight: '600' } }
                    }
                },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label(ctx) {
                                const total = ctx.dataset.data.reduce((a, b) => a + b, 0);
                                const pct = ((ctx.parsed.x / total) * 100).toFixed(1);
                                const val = new Intl.NumberFormat('pt-BR', {
                                    style: 'currency',
                                    currency: 'BRL'
                                }).format(ctx.parsed.x);
                                return ` ${val} (${pct}%)`;
                            }
                        },
                        backgroundColor: bgCard,
                        borderColor: `rgba(${accentRgb},0.3)`,
                        borderWidth: 1,
                        titleColor: textMain,
                        bodyColor: textMuted,
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
            plugins: [pluginLabelsBarras]
        });
        return chart;
    }

    // ── Central de opções: alterna entre pizza e barra, lembrando a escolha ───
    const chartsState = {};
    const visualAtual = {
        despesa: localStorage.getItem('analises_visual_despesa') || 'pizza',
        receita: localStorage.getItem('analises_visual_receita') || 'pizza',
    };

    function ajustarWrapper(tipo, visual, numCategorias) {
        const wrapper = document.getElementById(`wrapper-grafico-${tipo}`);
        const totalOverlay = document.getElementById(`total-overlay-${tipo}`);
        if (!wrapper) return;
        if (visual === 'barra') {
            wrapper.style.maxWidth = '100%';
            wrapper.style.aspectRatio = 'auto';
            wrapper.style.height = Math.max(220, numCategorias * 46) + 'px';
            if (totalOverlay) totalOverlay.style.display = 'none';
        } else {
            wrapper.style.maxWidth = '320px';
            wrapper.style.aspectRatio = '1';
            wrapper.style.height = '';
            if (totalOverlay) totalOverlay.style.display = '';
        }
    }

    function renderGrafico(tipo) {
        const info = chartsState[tipo];
        if (!info) return;
        const visual = visualAtual[tipo];
        if (info.instance) {
            info.instance.destroy();
            info.instance = null;
        }
        ajustarWrapper(tipo, visual, info.labels.length);
        info.instance = visual === 'barra' ?
            montarGraficoBarra(info.canvasId, info.labels, info.valores, info.cores, tipo) :
            montarGraficoPizza(info.canvasId, info.labels, info.valores, info.cores, tipo);
    }

    function registrarGrafico(tipo, canvasId, labels, valores, cores) {
        if (!document.getElementById(canvasId)) return;
        chartsState[tipo] = { canvasId, labels, valores, cores, instance: null };
        renderGrafico(tipo);
    }

    registrarGrafico(
        'despesa', 'graficoDespesas',
        <?php echo $dadosJsonLabelsDespesas ?>,
        <?php echo $dadosJsonValoresDespesas ?>,
        coresDespesas
    );

    registrarGrafico(
        'receita', 'graficoReceitas',
        <?php echo $dadosJsonLabelsReceitas ?>,
        <?php echo $dadosJsonValoresReceitas ?>,
        coresReceitas
    );

    document.querySelectorAll('.visual-toggle').forEach(group => {
        const tipo = group.dataset.tipo;
        group.querySelectorAll('.visual-toggle-btn').forEach(btn => {
            btn.classList.toggle('active', btn.dataset.visual === visualAtual[tipo]);
            btn.addEventListener('click', () => {
                if (visualAtual[tipo] === btn.dataset.visual) return;
                visualAtual[tipo] = btn.dataset.visual;
                localStorage.setItem(`analises_visual_${tipo}`, btn.dataset.visual);
                group.querySelectorAll('.visual-toggle-btn').forEach(b => b.classList.toggle('active', b === btn));
                renderGrafico(tipo);
            });
        });
    });

    // ── Gráfico de evolução histórica (linha) ──────────────────────────────
    (function() {
        const canvas = document.getElementById('graficoHistorico');
        if (!canvas) return;

        const historico = <?php echo json_encode($historicoMensal) ?>;
        const style = getComputedStyle(document.documentElement);
        const textMain = style.getPropertyValue('--text-main').trim() || '#f8fafc';
        const textMuted = style.getPropertyValue('--text-muted').trim() || '#a1a1aa';
        const bgCard = style.getPropertyValue('--bg-card').trim() || '#1e2126';
        const accentRgb = style.getPropertyValue('--bs-primary-rgb').trim() || '212,175,55';

        new Chart(canvas.getContext('2d'), {
            type: 'line',
            data: {
                labels: historico.map(h => h.label),
                datasets: [{
                        label: 'Receita',
                        data: historico.map(h => h.receita),
                        borderColor: '#06D6A0',
                        backgroundColor: 'rgba(6,214,160,0.12)',
                        tension: 0.3,
                        fill: true,
                        pointRadius: 3,
                        pointBackgroundColor: '#06D6A0',
                    },
                    {
                        label: 'Despesa',
                        data: historico.map(h => h.despesa),
                        borderColor: '#E63946',
                        backgroundColor: 'rgba(230,57,70,0.12)',
                        tension: 0.3,
                        fill: true,
                        pointRadius: 3,
                        pointBackgroundColor: '#E63946',
                    },
                    {
                        label: 'Saldo',
                        data: historico.map(h => h.saldo),
                        borderColor: '#60a5fa',
                        borderDash: [5, 4],
                        backgroundColor: 'transparent',
                        tension: 0.3,
                        fill: false,
                        pointRadius: 3,
                        pointBackgroundColor: '#60a5fa',
                    },
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    mode: 'index',
                    intersect: false
                },
                scales: {
                    x: {
                        grid: {
                            display: false
                        },
                        ticks: {
                            color: textMuted
                        }
                    },
                    y: {
                        grid: {
                            color: 'rgba(255,255,255,0.06)'
                        },
                        ticks: {
                            color: textMuted,
                            callback: v => new Intl.NumberFormat('pt-BR', {
                                style: 'currency',
                                currency: 'BRL',
                                maximumFractionDigits: 0
                            }).format(v)
                        }
                    }
                },
                plugins: {
                    legend: {
                        labels: {
                            color: textMain,
                            usePointStyle: true,
                            pointStyle: 'circle'
                        }
                    },
                    tooltip: {
                        callbacks: {
                            label(ctx) {
                                const val = new Intl.NumberFormat('pt-BR', {
                                    style: 'currency',
                                    currency: 'BRL'
                                }).format(ctx.parsed.y);
                                return ` ${ctx.dataset.label}: ${val}`;
                            }
                        },
                        backgroundColor: bgCard,
                        borderColor: `rgba(${accentRgb},0.3)`,
                        borderWidth: 1,
                        titleColor: textMain,
                        bodyColor: textMuted,
                        padding: 10,
                    }
                }
            }
        });
    })();

    // ── Modal: Definir Meta/Orçamento por Categoria ─────────────────────────
    function abrirModalMeta(categoriaId, nome, tipo, metaAtual) {
        document.getElementById('metaCategoriaId').value = categoriaId;
        document.getElementById('metaAcao').value = 'salvar';
        document.getElementById('modalDefinirMetaTitulo').textContent = (tipo === 'despesa' ? 'Orçamento — ' : 'Meta — ') + nome;
        document.getElementById('modalDefinirMetaLabel').textContent = tipo === 'despesa' ? 'Limite mensal para esta categoria' : 'Meta mensal para esta categoria';

        const input = document.getElementById('metaValorInput');
        input.value = metaAtual ? Number(metaAtual).toLocaleString('pt-BR', {
            style: 'currency',
            currency: 'BRL'
        }) : '';

        const btnRemover = document.getElementById('btnRemoverMeta');
        if (metaAtual) {
            btnRemover.style.display = '';
            btnRemover.onclick = function() {
                document.getElementById('metaAcao').value = 'remover';
                document.getElementById('formDefinirMeta').submit();
            };
        } else {
            btnRemover.style.display = 'none';
        }

        bootstrap.Modal.getOrCreateInstance(document.getElementById('modalDefinirMeta')).show();
    }

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
            const editUrl = `nova_transacao.php?editar=${encodeURIComponent(t.IDRegistro)}&voltar=${encodeURIComponent(location.href)}`;

            htmlLista += `
                <div class="analise-item list-group-item bg-transparent border-secondary-subtle px-4 py-3 d-flex justify-content-between align-items-center"
                     data-id="${t.IDRegistro}"
                     data-edit-url="${editUrl}"
                     style="cursor:pointer;"
                     onclick="location.href=this.dataset.editUrl"
                     oncontextmenu="abrirContextMenu(event, this)">
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

    // ── Context menu dos itens do detalhamento ────────────────────────────
    let _ctxTarget = null;

    function abrirContextMenu(e, el) {
        e.preventDefault();
        e.stopPropagation();
        _ctxTarget = el;
        const menu = document.getElementById('analiseContextMenu');
        menu.style.display = 'block';
        const x = Math.min(e.clientX, window.innerWidth  - menu.offsetWidth  - 8);
        const y = Math.min(e.clientY, window.innerHeight - menu.offsetHeight - 8);
        menu.style.left = x + 'px';
        menu.style.top  = y + 'px';
    }

    document.addEventListener('click', () => {
        const menu = document.getElementById('analiseContextMenu');
        if (menu) menu.style.display = 'none';
        const sub = document.getElementById('ctxTransferirSubmenu');
        if (sub) sub.style.display = 'none';
        const chev = document.getElementById('ctxTransferirChevron');
        if (chev) chev.style.transform = '';
        _ctxTarget = null;
    });

    function ctxEditar() {
        if (_ctxTarget) location.href = _ctxTarget.dataset.editUrl;
    }

    function ctxExcluir() {
        if (!_ctxTarget) return;
        const id = _ctxTarget.dataset.id;
        if (!confirm('Excluir este lançamento? Esta ação não pode ser desfeita.')) return;
        fetch('/geral/acao_registro.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: `acao=excluir&id=${encodeURIComponent(id)}`
        }).then(r => r.json()).then(d => {
            if (d.ok) { _ctxTarget.remove(); auralisToast('Lançamento excluído.'); }
        });
    }

    function ctxToggleTransferirMenu(e) {
        e.stopPropagation();
        const sub  = document.getElementById('ctxTransferirSubmenu');
        const chev = document.getElementById('ctxTransferirChevron');
        const open = sub.style.display !== 'none';
        sub.style.display  = open ? 'none' : 'block';
        if (chev) chev.style.transform = open ? '' : 'rotate(180deg)';
        const menu = document.getElementById('analiseContextMenu');
        const x = parseFloat(menu.style.left);
        const newH = menu.offsetHeight;
        menu.style.top = Math.min(parseFloat(menu.style.top), window.innerHeight - newH - 8) + 'px';
    }

    function ctxTransferir(destId, destNome) {
        if (!_ctxTarget) return;
        const id = _ctxTarget.dataset.id;
        const el = _ctxTarget;
        fetch('/geral/acao_registro.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: `acao=transferir&id=${encodeURIComponent(id)}&carteira_destino=${encodeURIComponent(destId)}`
        }).then(r => r.json()).then(d => {
            if (d.ok) {
                el.remove();
                document.getElementById('analiseContextMenu').style.display = 'none';
                auralisToast('Transferido para ' + destNome + '!');
            }
        });
    }

    function ctxNaoEfetivar() {
        if (!_ctxTarget) return;
        const id = _ctxTarget.dataset.id;
        fetch('/geral/acao_registro.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: `acao=nao_efetivar&id=${encodeURIComponent(id)}`
        }).then(r => r.json()).then(d => {
            if (d.ok) { _ctxTarget.remove(); auralisToast('Lançamento marcado como pendente.'); }
        });
    }
</script>

<!-- ══════════════════════════════════════════════════════════════════════ -->
<!-- ── Modais de Cofrinho ──────────────────────────────────────────── -->
<!-- ══════════════════════════════════════════════════════════════════════ -->

<!-- ── Modal: Histórico ──────────────────────────────────────────────── -->
<div class="modal fade" id="modalHistorico" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable" style="max-width:500px;">
        <div class="modal-content border-secondary-subtle shadow-lg rounded-4" style="background:var(--bg-card);">
            <div class="modal-header border-bottom border-secondary-subtle p-0" id="modalHistoricoHeader" style="border-radius:inherit inherit 0 0;">
                <div class="d-flex align-items-center gap-3 p-4 w-100">
                    <div class="rounded-3 d-flex align-items-center justify-content-center flex-shrink-0" id="histIconeBox" style="width:44px;height:44px;">
                        <i class="bi bi-piggy-bank fs-4" id="histIcone"></i>
                    </div>
                    <div class="flex-grow-1 min-w-0">
                        <h6 class="modal-title text-light fw-bold mb-0" id="histNome">Cofrinho</h6>
                        <div class="text-secondary small" id="histSaldo"></div>
                    </div>
                    <button type="button" class="btn-close btn-close-white flex-shrink-0" data-bs-dismiss="modal"></button>
                </div>
            </div>
            <div class="modal-body p-0">
                <!-- Barra de progresso se tiver meta -->
                <div id="histProgressoWrap" class="px-4 pt-3 pb-2" style="display:none;">
                    <div class="progress rounded-pill mb-1" style="height:8px;background:rgba(255,255,255,0.07);">
                        <div class="progress-bar rounded-pill" id="histProgressoBar" style="width:0%;"></div>
                    </div>
                    <div class="d-flex justify-content-between">
                        <span class="text-secondary" style="font-size:0.72rem;" id="histPct"></span>
                        <span class="text-secondary" style="font-size:0.72rem;" id="histMeta"></span>
                    </div>
                </div>
                <!-- Botões de ação rápida -->
                <div class="d-flex gap-2 px-4 py-3 border-bottom border-secondary-subtle">
                    <button class="btn btn-sm rounded-pill flex-grow-1 fw-semibold" id="histBtnDepositar"
                            style="background:rgba(22,163,74,0.12);color:#16a34a;border:1px solid rgba(22,163,74,0.3);">
                        <i class="bi bi-arrow-down-circle me-1"></i> Depositar
                    </button>
                    <button class="btn btn-sm rounded-pill flex-grow-1 fw-semibold" id="histBtnRetirar"
                            style="background:rgba(245,158,11,0.12);color:#f59e0b;border:1px solid rgba(245,158,11,0.3);">
                        <i class="bi bi-arrow-up-circle me-1"></i> Retirar
                    </button>
                    <button class="btn btn-sm btn-outline-secondary rounded-pill px-3" id="histBtnEditar" title="Editar">
                        <i class="bi bi-pencil"></i>
                    </button>
                </div>
                <!-- Lista de transações -->
                <div id="histLista" style="max-height:320px;overflow-y:auto;">
                    <div class="text-center text-secondary py-5 small">
                        <i class="bi bi-clock-history fs-2 d-block mb-2 opacity-25"></i>
                        Nenhuma movimentação ainda.
                    </div>
                </div>
            </div>
            <div class="modal-footer border-top border-secondary-subtle p-3">
                <button class="btn btn-sm btn-outline-danger rounded-pill px-3" id="histBtnExcluir">
                    <i class="bi bi-trash3 me-1"></i> Excluir cofrinho
                </button>
                <button class="btn btn-sm rounded-pill px-3" id="histBtnReajustar"
                        style="background:rgba(8,145,178,0.12);color:#0891b2;border:1px solid rgba(8,145,178,0.3);">
                    <i class="bi bi-sliders me-1"></i> Reajustar saldo
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ── Modal: Definir Meta/Orçamento por Categoria ─────────────────────── -->
<div class="modal fade" id="modalDefinirMeta" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content bg-body-tertiary border-secondary-subtle rounded-4">
            <div class="modal-header border-bottom border-secondary-subtle p-3">
                <h6 class="modal-title text-light fw-bold mb-0" id="modalDefinirMetaTitulo">Definir meta</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="salvar_meta_categoria.php" id="formDefinirMeta">
                <input type="hidden" name="categoria_id" id="metaCategoriaId">
                <input type="hidden" name="mes" value="<?php echo (int) $mes_atual ?>">
                <input type="hidden" name="ano" value="<?php echo (int) $ano_atual ?>">
                <input type="hidden" name="carteira" value="<?php echo htmlspecialchars($carteira_selecionada ?? '') ?>">
                <input type="hidden" name="acao" id="metaAcao" value="salvar">
                <div class="modal-body p-4">
                    <label class="form-label text-secondary small mb-1" id="modalDefinirMetaLabel">Valor mensal</label>
                    <div class="input-group">
                        <span class="input-group-text bg-dark border-secondary-subtle text-secondary">R$</span>
                        <input type="text" inputmode="numeric" name="valor_meta" id="metaValorInput"
                               class="form-control bg-dark border-secondary-subtle text-light" placeholder="0,00"
                               oninput="mascaraMoeda(this)" required>
                    </div>
                </div>
                <div class="modal-footer border-top border-secondary-subtle d-flex justify-content-between p-2">
                    <button type="button" class="btn btn-sm btn-link text-danger text-decoration-none" id="btnRemoverMeta" style="display:none;">
                        Remover meta
                    </button>
                    <button type="submit" class="btn btn-sm btn-warning fw-bold px-3 rounded-pill ms-auto">
                        Salvar
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ── Modal: Criar Cofrinho ──────────────────────────────────────────── -->
<!-- ══════════════════════════════════════════════════════════════════════ -->
<div class="modal fade" id="modalCriarCofrinho" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" style="max-width:480px;">
        <div class="modal-content border-secondary-subtle shadow-lg rounded-4" style="background:var(--bg-card);">
            <div class="modal-header border-bottom border-secondary-subtle p-4">
                <h6 class="modal-title text-light fw-bold">
                    <i class="bi bi-piggy-bank me-2" style="color:#f59e0b;"></i> Novo Cofrinho
                </h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="cofrinho/processa_cofrinho.php?acao=criar">
                <div class="modal-body p-4">
                    <!-- Nome -->
                    <div class="mb-3">
                        <label class="form-label text-secondary small fw-semibold">Nome do cofrinho *</label>
                        <input type="text" name="nome" class="form-control rounded-3 border-secondary-subtle"
                               style="background:var(--bg-hover);color:var(--text-main);"
                               placeholder="Ex: Casa própria, Viagem..." required maxlength="100">
                    </div>

                    <!-- Ícone -->
                    <div class="mb-3">
                        <label class="form-label text-secondary small fw-semibold">Ícone</label>
                        <div class="d-flex flex-wrap gap-2" id="icone-picker">
                            <?php
                            $icones = [
                                'bi-piggy-bank' => 'Porquinho',
                                'bi-house'      => 'Casa',
                                'bi-car-front'  => 'Carro',
                                'bi-airplane'   => 'Viagem',
                                'bi-heart'      => 'Saúde',
                                'bi-stars'      => 'Sonho',
                                'bi-trophy'     => 'Meta',
                                'bi-gift'       => 'Presente',
                            ];
                            foreach ($icones as $slug => $label):
                            ?>
                            <label class="cofrinho-icone-opt" title="<?= $label ?>">
                                <input type="radio" name="icone" value="<?= $slug ?>" <?= $slug === 'bi-piggy-bank' ? 'checked' : '' ?> style="display:none;">
                                <span class="d-flex align-items-center justify-content-center rounded-3"
                                      style="width:44px;height:44px;cursor:pointer;border:2px solid transparent;background:rgba(255,255,255,0.05);transition:all .15s;">
                                    <i class="bi <?= $slug ?>" style="font-size:1.3rem;"></i>
                                </span>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Cor -->
                    <div class="mb-3">
                        <label class="form-label text-secondary small fw-semibold">Cor</label>
                        <div class="d-flex flex-wrap gap-2" id="cor-picker">
                            <?php
                            $cores = [
                                '#f59e0b' => 'Âmbar',
                                '#7c3aed' => 'Roxo',
                                '#2563eb' => 'Azul',
                                '#16a34a' => 'Verde',
                                '#dc2626' => 'Vermelho',
                                '#0891b2' => 'Ciano',
                                '#374151' => 'Cinza',
                            ];
                            foreach ($cores as $hex => $nome):
                            ?>
                            <label class="cofrinho-cor-opt" title="<?= $nome ?>">
                                <input type="radio" name="cor" value="<?= $hex ?>" <?= $hex === '#f59e0b' ? 'checked' : '' ?> style="display:none;">
                                <span class="d-flex align-items-center justify-content-center rounded-circle"
                                      style="width:32px;height:32px;cursor:pointer;background:<?= $hex ?>;border:3px solid transparent;transition:all .15s;"></span>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Carteira -->
                    <div class="mb-3">
                        <label class="form-label text-secondary small fw-semibold">Carteira vinculada *</label>
                        <select name="carteira" class="form-select rounded-3 border-secondary-subtle"
                                style="background:var(--bg-hover);color:var(--text-main);" required>
                            <?php foreach ($carteiras as $cart): ?>
                                <option value="<?= htmlspecialchars($cart['IDCarteira']) ?>">
                                    <?= htmlspecialchars($cart['TipoCarteira']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Meta (opcional) -->
                    <div class="mb-3">
                        <label class="form-label text-secondary small fw-semibold">Meta (R$) <span class="opacity-50">— opcional</span></label>
                        <input type="text" name="meta" inputmode="numeric" class="form-control rounded-3 border-secondary-subtle"
                               style="background:var(--bg-hover);color:var(--text-main);"
                               placeholder="R$ 0,00" oninput="mascaraMoeda(this)" autocomplete="off">
                    </div>

                    <!-- Data limite (opcional) -->
                    <div class="mb-1">
                        <label class="form-label text-secondary small fw-semibold">Data limite <span class="opacity-50">— opcional</span></label>
                        <input type="date" name="data_limite" class="form-control rounded-3 border-secondary-subtle"
                               style="background:var(--bg-hover);color:var(--text-main);">
                    </div>
                </div>
                <div class="modal-footer border-top border-secondary-subtle p-3">
                    <button type="button" class="btn btn-outline-secondary rounded-pill px-3" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn fw-semibold rounded-pill px-4"
                            style="background:rgba(245,158,11,0.15);color:#f59e0b;border:1px solid rgba(245,158,11,0.4);">
                        <i class="bi bi-piggy-bank me-1"></i> Criar cofrinho
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ── Modal: Editar Cofrinho ─────────────────────────────────────────── -->
<div class="modal fade" id="modalEditarCofrinho" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" style="max-width:480px;">
        <div class="modal-content border-secondary-subtle shadow-lg rounded-4" style="background:var(--bg-card);">
            <div class="modal-header border-bottom border-secondary-subtle p-4">
                <h6 class="modal-title text-light fw-bold"><i class="bi bi-pencil me-2" style="color:#f59e0b;"></i> Editar Cofrinho</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="cofrinho/processa_cofrinho.php?acao=editar">
                <input type="hidden" name="id_cofrinho" id="editar_id">
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label text-secondary small fw-semibold">Nome *</label>
                        <input type="text" name="nome" id="editar_nome" class="form-control rounded-3 border-secondary-subtle"
                               style="background:var(--bg-hover);color:var(--text-main);" required maxlength="100">
                    </div>
                    <div class="mb-3">
                        <label class="form-label text-secondary small fw-semibold">Ícone</label>
                        <div class="d-flex flex-wrap gap-2" id="editar-icone-picker">
                            <?php foreach ($icones as $slug => $label): ?>
                            <label class="cofrinho-icone-opt" title="<?= $label ?>">
                                <input type="radio" name="icone" value="<?= $slug ?>" style="display:none;">
                                <span class="d-flex align-items-center justify-content-center rounded-3"
                                      style="width:44px;height:44px;cursor:pointer;border:2px solid transparent;background:rgba(255,255,255,0.05);transition:all .15s;">
                                    <i class="bi <?= $slug ?>" style="font-size:1.3rem;"></i>
                                </span>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label text-secondary small fw-semibold">Cor</label>
                        <div class="d-flex flex-wrap gap-2" id="editar-cor-picker">
                            <?php foreach ($cores as $hex => $nome): ?>
                            <label class="cofrinho-cor-opt" title="<?= $nome ?>">
                                <input type="radio" name="cor" value="<?= $hex ?>" style="display:none;">
                                <span class="d-flex align-items-center justify-content-center rounded-circle"
                                      style="width:32px;height:32px;cursor:pointer;background:<?= $hex ?>;border:3px solid transparent;transition:all .15s;"></span>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label text-secondary small fw-semibold">Meta (R$) <span class="opacity-50">— opcional</span></label>
                        <input type="text" name="meta" id="editar_meta" inputmode="numeric" class="form-control rounded-3 border-secondary-subtle"
                               style="background:var(--bg-hover);color:var(--text-main);" placeholder="R$ 0,00" oninput="mascaraMoeda(this)" autocomplete="off">
                    </div>
                    <div class="mb-1">
                        <label class="form-label text-secondary small fw-semibold">Data limite <span class="opacity-50">— opcional</span></label>
                        <input type="date" name="data_limite" id="editar_data_limite" class="form-control rounded-3 border-secondary-subtle"
                               style="background:var(--bg-hover);color:var(--text-main);">
                    </div>
                </div>
                <div class="modal-footer border-top border-secondary-subtle p-3">
                    <button type="button" class="btn btn-outline-secondary rounded-pill px-3" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn fw-semibold rounded-pill px-4"
                            style="background:rgba(245,158,11,0.15);color:#f59e0b;border:1px solid rgba(245,158,11,0.4);">
                        <i class="bi bi-check-lg me-1"></i> Salvar
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ── Modal: Depositar ───────────────────────────────────────────────── -->
<div class="modal fade" id="modalDepositar" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" style="max-width:380px;">
        <div class="modal-content border-secondary-subtle shadow-lg rounded-4" style="background:var(--bg-card);">
            <div class="modal-header border-bottom border-secondary-subtle p-4">
                <h6 class="modal-title text-light fw-bold" id="modalDepositarTitulo">
                    <i class="bi bi-arrow-down-circle me-2 text-success"></i> Depositar
                </h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="cofrinho/processa_cofrinho.php?acao=depositar">
                <input type="hidden" name="id_cofrinho" id="dep_id">
                <div class="modal-body p-4">
                    <label class="form-label text-secondary small fw-semibold">Valor (R$) *</label>
                    <input type="text" name="valor" id="dep_valor" inputmode="numeric"
                           class="form-control form-control-lg rounded-3 border-secondary-subtle"
                           style="background:var(--bg-hover);color:var(--text-main);font-size:1.4rem;font-weight:700;"
                           placeholder="R$ 0,00" oninput="mascaraMoeda(this)" autocomplete="off" required>
                    <label class="form-label text-secondary small fw-semibold mt-3">Descrição <span class="opacity-50">— opcional</span></label>
                    <input type="text" name="descricao" class="form-control rounded-3 border-secondary-subtle"
                           style="background:var(--bg-hover);color:var(--text-main);" placeholder="Ex: Salário, economias...">
                    <div class="text-secondary small mt-2">
                        <i class="bi bi-info-circle me-1"></i>O valor será debitado do saldo da carteira vinculada.
                    </div>
                </div>
                <div class="modal-footer border-top border-secondary-subtle p-3">
                    <button type="button" class="btn btn-outline-secondary rounded-pill px-3" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn fw-semibold rounded-pill px-4"
                            style="background:rgba(22,163,74,0.15);color:#16a34a;border:1px solid rgba(22,163,74,0.4);">
                        <i class="bi bi-check-lg me-1"></i> Confirmar depósito
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ── Modal: Retirar ─────────────────────────────────────────────────── -->
<div class="modal fade" id="modalRetirar" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" style="max-width:380px;">
        <div class="modal-content border-secondary-subtle shadow-lg rounded-4" style="background:var(--bg-card);">
            <div class="modal-header border-bottom border-secondary-subtle p-4">
                <h6 class="modal-title text-light fw-bold" id="modalRetirarTitulo">
                    <i class="bi bi-arrow-up-circle me-2" style="color:#f59e0b;"></i> Retirar valor
                </h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="cofrinho/processa_cofrinho.php?acao=retirar">
                <input type="hidden" name="id_cofrinho" id="ret_id">
                <div class="modal-body p-4">
                    <label class="form-label text-secondary small fw-semibold">Valor a retirar (R$) *</label>
                    <input type="text" name="valor" id="ret_valor" inputmode="numeric"
                           class="form-control form-control-lg rounded-3 border-secondary-subtle"
                           style="background:var(--bg-hover);color:var(--text-main);font-size:1.4rem;font-weight:700;"
                           placeholder="R$ 0,00" oninput="mascaraMoeda(this)" autocomplete="off" required>
                    <label class="form-label text-secondary small fw-semibold mt-3">Descrição <span class="opacity-50">— opcional</span></label>
                    <input type="text" name="descricao" class="form-control rounded-3 border-secondary-subtle"
                           style="background:var(--bg-hover);color:var(--text-main);" placeholder="Ex: Emergência, compra...">
                    <div class="text-secondary small mt-2">
                        <i class="bi bi-info-circle me-1"></i>O valor será devolvido ao saldo da carteira vinculada.
                    </div>
                </div>
                <div class="modal-footer border-top border-secondary-subtle p-3">
                    <button type="button" class="btn btn-outline-secondary rounded-pill px-3" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn fw-semibold rounded-pill px-4"
                            style="background:rgba(245,158,11,0.15);color:#f59e0b;border:1px solid rgba(245,158,11,0.4);">
                        <i class="bi bi-check-lg me-1"></i> Confirmar retirada
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ── Modal: Reajustar ───────────────────────────────────────────────── -->
<div class="modal fade" id="modalReajustar" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" style="max-width:380px;">
        <div class="modal-content border-secondary-subtle shadow-lg rounded-4" style="background:var(--bg-card);">
            <div class="modal-header border-bottom border-secondary-subtle p-4">
                <h6 class="modal-title text-light fw-bold" id="modalReajustarTitulo">
                    <i class="bi bi-sliders me-2 text-info"></i> Reajustar saldo
                </h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="cofrinho/processa_cofrinho.php?acao=reajustar">
                <input type="hidden" name="id_cofrinho" id="reaj_id">
                <input type="hidden" name="valor_atual" id="reaj_valor_atual">
                <div class="modal-body p-4">
                    <div class="mb-3 p-3 rounded-3" style="background:rgba(255,255,255,0.04);border:1px solid var(--bs-border-color);">
                        <div class="text-secondary small">Saldo atual</div>
                        <div class="fw-bold text-light" id="reaj_saldo_display" style="font-size:1.1rem;"></div>
                    </div>
                    <label class="form-label text-secondary small fw-semibold">Novo saldo desejado (R$) *</label>
                    <input type="text" name="novo_valor" id="reaj_novo_valor" inputmode="numeric"
                           class="form-control form-control-lg rounded-3 border-secondary-subtle"
                           style="background:var(--bg-hover);color:var(--text-main);font-size:1.4rem;font-weight:700;"
                           placeholder="R$ 0,00" oninput="mascaraMoeda(this)" autocomplete="off" required>
                    <div class="text-secondary small mt-2">
                        <i class="bi bi-info-circle me-1"></i>A diferença será lançada como depósito ou retirada automática.
                    </div>
                </div>
                <div class="modal-footer border-top border-secondary-subtle p-3">
                    <button type="button" class="btn btn-outline-secondary rounded-pill px-3" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn fw-semibold rounded-pill px-4"
                            style="background:rgba(8,145,178,0.15);color:#0891b2;border:1px solid rgba(8,145,178,0.4);">
                        <i class="bi bi-check-lg me-1"></i> Aplicar reajuste
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ── Modal: Excluir ─────────────────────────────────────────────────── -->
<div class="modal fade" id="modalExcluir" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" style="max-width:380px;">
        <div class="modal-content border-secondary-subtle shadow-lg rounded-4" style="background:var(--bg-card);">
            <div class="modal-header border-bottom border-secondary-subtle p-4">
                <h6 class="modal-title text-light fw-bold"><i class="bi bi-trash3 me-2 text-danger"></i> Excluir cofrinho</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="cofrinho/processa_cofrinho.php?acao=excluir">
                <input type="hidden" name="id_cofrinho" id="excluir_id">
                <div class="modal-body p-4 text-center">
                    <i class="bi bi-exclamation-triangle text-danger" style="font-size:2.5rem;"></i>
                    <p class="text-light fw-semibold mt-3 mb-1" id="excluir_titulo">Excluir este cofrinho?</p>
                    <p class="text-secondary small">Esta ação é permanente. Todas as movimentações serão apagadas e o saldo retorna à carteira apenas via retirada prévia.</p>
                </div>
                <div class="modal-footer border-top border-secondary-subtle p-3">
                    <button type="button" class="btn btn-outline-secondary rounded-pill px-3" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-danger rounded-pill px-4 fw-semibold">
                        <i class="bi bi-trash3 me-1"></i> Confirmar exclusão
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ── Modal: Editar Registro do Cofrinho ────────────────────────────── -->
<div class="modal fade" id="modalEditarRegistro" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" style="max-width:360px;">
        <div class="modal-content border-secondary-subtle shadow-lg rounded-4" style="background:var(--bg-card);">
            <div class="modal-header border-bottom border-secondary-subtle p-4">
                <h6 class="modal-title text-light fw-bold"><i class="bi bi-pencil me-2" style="color:#f59e0b;"></i> Editar movimentação</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <input type="hidden" id="editReg_id">
                <div class="mb-3">
                    <label class="form-label text-secondary small fw-semibold">Valor (R$) *</label>
                    <input type="text" id="editReg_val" inputmode="numeric" class="form-control rounded-3 border-secondary-subtle"
                           style="background:var(--bg-hover);color:var(--text-main);font-size:1.2rem;font-weight:700;"
                           placeholder="R$ 0,00" oninput="mascaraMoeda(this)" autocomplete="off" required>
                </div>
                <div class="mb-1">
                    <label class="form-label text-secondary small fw-semibold">Descrição <span class="opacity-50">— opcional</span></label>
                    <input type="text" id="editReg_desc" class="form-control rounded-3 border-secondary-subtle"
                           style="background:var(--bg-hover);color:var(--text-main);" maxlength="200">
                </div>
            </div>
            <div class="modal-footer border-top border-secondary-subtle p-3">
                <button type="button" class="btn btn-outline-secondary rounded-pill px-3" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" id="editRegSalvarBtn" class="btn fw-semibold rounded-pill px-4"
                        style="background:rgba(245,158,11,0.15);color:#f59e0b;border:1px solid rgba(245,158,11,0.4);">
                    <i class="bi bi-check-lg me-1"></i> Salvar
                </button>
            </div>
        </div>
    </div>
</div>

<style>
.cofrinho-icone-opt input:checked + span,
.cofrinho-icone-opt span:hover {
    border-color: #f59e0b !important;
    background: rgba(245,158,11,0.15) !important;
}
.cofrinho-cor-opt input:checked + span {
    border-color: #fff !important;
    box-shadow: 0 0 0 2px rgba(255,255,255,0.4);
}
.cofrinho-cor-opt span:hover { transform: scale(1.15); }
.cofrinho-header-click:hover { filter: brightness(1.08); }
</style>

<script>
// ── Estado do cofrinho atual no histórico ──────────────────────────
var _cofAtual = {};

function _modal(id) { return new bootstrap.Modal(document.getElementById(id)); }
function _get(id)   { return document.getElementById(id); }

// ── Histórico (clique no header do card) ──────────────────────────
function abrirHistorico(id, nome, cor, valAtual, valMeta, icone, histJSON, dataLimite) {
    _cofAtual = { id: id, nome: nome, cor: cor, valAtual: valAtual, valMeta: valMeta, icone: icone, dataLimite: dataLimite || '' };
    var hist = typeof histJSON === 'string' ? JSON.parse(histJSON) : histJSON;

    // Header
    _get('histIconeBox').style.background = cor + '33';
    _get('histIcone').className = 'bi ' + icone;
    _get('histIcone').style.color = cor;
    _get('histNome').textContent = nome;
    _get('histSaldo').textContent = 'R$ ' + valAtual.toLocaleString('pt-BR', {minimumFractionDigits:2, maximumFractionDigits:2}) + ' guardado';

    // Progresso
    if (valMeta !== null && valMeta > 0) {
        var pct = Math.min(100, Math.round((valAtual / valMeta) * 1000) / 10);
        _get('histProgressoWrap').style.display = '';
        _get('histProgressoBar').style.width  = pct + '%';
        _get('histProgressoBar').style.background = cor;
        _get('histPct').textContent  = pct + '% concluído';
        _get('histMeta').textContent = 'Meta: R$ ' + valMeta.toLocaleString('pt-BR', {minimumFractionDigits:2, maximumFractionDigits:2});
    } else {
        _get('histProgressoWrap').style.display = 'none';
    }

    // Lista de movimentações
    var lista = _get('histLista');
    if (!hist || hist.length === 0) {
        lista.innerHTML = '<div class="text-center text-secondary py-5 small"><i class="bi bi-clock-history fs-2 d-block mb-2 opacity-25"></i>Nenhuma movimentação ainda.</div>';
    } else {
        var html = '<div class="list-group list-group-flush">';
        hist.forEach(function(t) {
            var isDeposito = t.TipoRegistro === 'cofrinho';
            var sinal  = isDeposito ? '+' : '-';
            var cor2   = isDeposito ? '#16a34a' : '#f59e0b';
            var icone2 = isDeposito ? 'bi-arrow-down-circle' : 'bi-arrow-up-circle';
            var data   = new Date(t.MomentoRegistro).toLocaleDateString('pt-BR');
            var desc   = t.Descricao || (isDeposito ? 'Depósito' : 'Retirada');
            var val    = parseFloat(t.Valor).toLocaleString('pt-BR', {minimumFractionDigits:2, maximumFractionDigits:2});
            var rid    = t.IDRegistro;
            var valorRaw = parseFloat(t.Valor);
            html += '<div class="cof-hist-item list-group-item border-0 border-bottom border-secondary-subtle py-2 px-4"' +
                    ' data-rid="' + rid + '" data-valor="' + valorRaw + '" data-tipo="' + t.TipoRegistro + '" data-desc="' + desc.replace(/"/g,'&quot;') + '"' +
                    ' style="background:transparent;">' +
                    '<div class="d-flex align-items-center gap-3">' +
                    '<span class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0"' +
                    ' style="width:34px;height:34px;background:' + cor2 + '18;">' +
                    '<i class="bi ' + icone2 + '" style="color:' + cor2 + ';font-size:1rem;"></i></span>' +
                    '<div class="flex-grow-1 min-w-0"><div class="text-light fw-semibold small text-truncate">' + desc + '</div>' +
                    '<div class="text-secondary" style="font-size:0.72rem;">' + data + '</div></div>' +
                    '<div class="d-flex align-items-center gap-1 flex-shrink-0">' +
                    '<span class="fw-bold me-1" style="color:' + cor2 + ';font-size:0.92rem;">' + sinal + ' R$ ' + val + '</span>' +
                    '<button class="btn btn-sm p-1 cof-hist-edit-btn" title="Editar registro" style="color:#888;line-height:1;opacity:0;transition:opacity .15s;"><i class="bi bi-pencil" style="font-size:0.78rem;"></i></button>' +
                    '<button class="btn btn-sm p-1 cof-hist-del-btn" title="Excluir registro" style="color:#f87171;line-height:1;opacity:0;transition:opacity .15s;"><i class="bi bi-trash3" style="font-size:0.78rem;"></i></button>' +
                    '</div>' +
                    '</div></div>';
        });
        html += '</div>';
        lista.innerHTML = html;

        // Hover: mostra botões ao passar o mouse
        lista.querySelectorAll('.cof-hist-item').forEach(function(row) {
            row.addEventListener('mouseenter', function() {
                row.querySelectorAll('.cof-hist-edit-btn,.cof-hist-del-btn').forEach(function(b){ b.style.opacity='1'; });
            });
            row.addEventListener('mouseleave', function() {
                row.querySelectorAll('.cof-hist-edit-btn,.cof-hist-del-btn').forEach(function(b){ b.style.opacity='0'; });
            });
            // Excluir registro
            row.querySelector('.cof-hist-del-btn').addEventListener('click', function(e) {
                e.stopPropagation();
                if (!confirm('Excluir este registro?\n\nO saldo do cofrinho será ajustado automaticamente.')) return;
                var rid = row.dataset.rid;
                var form = new FormData();
                form.append('acao','excluir_registro'); form.append('id_registro', rid);
                fetch('cofrinho/processa_cofrinho.php', { method:'POST', body: new URLSearchParams({ acao:'excluir_registro', id_registro: rid }) })
                .then(function(r){ return r.json(); })
                .then(function(d){
                    if (d.ok) { row.remove(); } else { alert('Erro: ' + (d.erro||'desconhecido')); }
                }).catch(function(){ alert('Erro de conexão.'); });
            });
            // Editar registro
            row.querySelector('.cof-hist-edit-btn').addEventListener('click', function(e) {
                e.stopPropagation();
                _get('editReg_id').value   = row.dataset.rid;
                _get('editReg_val').value  = row.dataset.valor;
                mascaraMoeda(_get('editReg_val'));
                _get('editReg_desc').value = row.dataset.desc;
                _modal('modalEditarRegistro').show();
            });
        });
    }

    // Botões de ação do modal
    _get('histBtnDepositar').onclick = function() {
        bootstrap.Modal.getInstance(_get('modalHistorico')).hide();
        setTimeout(function() { abrirDepositar(id, nome); }, 200);
    };
    _get('histBtnRetirar').onclick = function() {
        bootstrap.Modal.getInstance(_get('modalHistorico')).hide();
        setTimeout(function() { abrirRetirar(id, nome); }, 200);
    };
    _get('histBtnEditar').onclick = function() {
        bootstrap.Modal.getInstance(_get('modalHistorico')).hide();
        setTimeout(function() {
            abrirEditar(_cofAtual.id, _cofAtual.nome, _cofAtual.cor, _cofAtual.icone, _cofAtual.valMeta, _cofAtual.dataLimite);
        }, 200);
    };
    _get('histBtnExcluir').onclick  = function() {
        bootstrap.Modal.getInstance(_get('modalHistorico')).hide();
        setTimeout(function() { abrirExcluir(id, nome); }, 200);
    };
    _get('histBtnReajustar').onclick = function() {
        bootstrap.Modal.getInstance(_get('modalHistorico')).hide();
        setTimeout(function() { abrirReajustar(id, nome, valAtual); }, 200);
    };

    _modal('modalHistorico').show();
}

// ── Depositar ─────────────────────────────────────────────────────
function abrirDepositar(id, nome) {
    _get('dep_id').value = id;
    _get('dep_valor').value = '';
    _get('modalDepositarTitulo').innerHTML =
        '<i class="bi bi-arrow-down-circle me-2 text-success"></i> Depositar em <strong>' + nome + '</strong>';
    _modal('modalDepositar').show();
    setTimeout(function() { _get('dep_valor').focus(); }, 400);
}

// ── Retirar ───────────────────────────────────────────────────────
function abrirRetirar(id, nome) {
    _get('ret_id').value = id;
    _get('ret_valor').value = '';
    _get('modalRetirarTitulo').innerHTML =
        '<i class="bi bi-arrow-up-circle me-2" style="color:#f59e0b;"></i> Retirar de <strong>' + nome + '</strong>';
    _modal('modalRetirar').show();
    setTimeout(function() { _get('ret_valor').focus(); }, 400);
}

// ── Reajustar ─────────────────────────────────────────────────────
function abrirReajustar(id, nome, valAtual) {
    _get('reaj_id').value = id;
    _get('reaj_valor_atual').value = valAtual;
    _get('reaj_novo_valor').value = '';
    _get('modalReajustarTitulo').innerHTML =
        '<i class="bi bi-sliders me-2 text-info"></i> Reajustar: <strong>' + nome + '</strong>';
    _get('reaj_saldo_display').textContent = 'R$ ' + parseFloat(valAtual).toLocaleString('pt-BR', {minimumFractionDigits:2, maximumFractionDigits:2});
    _modal('modalReajustar').show();
    setTimeout(function() { _get('reaj_novo_valor').focus(); }, 400);
}

// ── Editar ────────────────────────────────────────────────────────
function abrirEditar(id, nome, cor, icone, meta, dataLimite) {
    _get('editar_id').value = id;
    _get('editar_nome').value = nome;
    var metaInp = _get('editar_meta');
    metaInp.value = meta !== null ? meta : '';
    if (metaInp.value !== '') mascaraMoeda(metaInp);
    _get('editar_data_limite').value = dataLimite || '';

    // Marca ícone correto
    document.querySelectorAll('#editar-icone-picker input[type=radio]').forEach(function(r) {
        r.checked = (r.value === icone);
    });
    // Marca cor correta
    document.querySelectorAll('#editar-cor-picker input[type=radio]').forEach(function(r) {
        r.checked = (r.value === cor);
    });

    _modal('modalEditarCofrinho').show();
}

// ── Excluir ───────────────────────────────────────────────────────
function abrirExcluir(id, nome) {
    _get('excluir_id').value = id;
    _get('excluir_titulo').textContent = 'Excluir o cofrinho "' + nome + '"?';
    _modal('modalExcluir').show();
}

// ── Salvar edição de registro individual ─────────────────────────
document.addEventListener('DOMContentLoaded', function() {
    var btn = _get('editRegSalvarBtn');
    if (!btn) return;
    btn.addEventListener('click', function() {
        var rid   = _get('editReg_id').value;
        var valor = parseBRL(_get('editReg_val').value);
        var desc  = _get('editReg_desc').value.trim();
        if (!rid || valor <= 0) { alert('Informe um valor válido.'); return; }
        btn.disabled = true;
        fetch('cofrinho/processa_cofrinho.php', {
            method: 'POST',
            body: new URLSearchParams({ acao: 'editar_registro', id_registro: rid, valor: valor, descricao: desc })
        }).then(function(r){ return r.json(); })
        .then(function(d){
            btn.disabled = false;
            if (d.ok) {
                bootstrap.Modal.getInstance(_get('modalEditarRegistro')).hide();
                // Atualiza a linha na lista sem recarregar
                var row = document.querySelector('.cof-hist-item[data-rid="' + rid + '"]');
                if (row) {
                    var tipo = row.dataset.tipo;
                    var isD  = tipo === 'cofrinho';
                    var cor2 = isD ? '#16a34a' : '#f59e0b';
                    var sinal = isD ? '+' : '-';
                    row.dataset.valor = valor;
                    row.dataset.desc  = desc;
                    var valFmt = valor.toLocaleString('pt-BR', {minimumFractionDigits:2, maximumFractionDigits:2});
                    row.querySelector('.text-light.fw-semibold').textContent = desc || (isD ? 'Depósito' : 'Retirada');
                    row.querySelector('.fw-bold').textContent = sinal + ' R$ ' + valFmt;
                    row.querySelector('.fw-bold').style.color = cor2;
                }
            } else { alert('Erro: ' + (d.erro||'desconhecido')); }
        }).catch(function(){ btn.disabled = false; alert('Erro de conexão.'); });
    });
});
</script>

<?php require_once 'geral/footer.php'; ?>