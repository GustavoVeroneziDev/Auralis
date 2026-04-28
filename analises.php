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
    if ($mes_ant < 1) {$mes_ant = 12;
    $ano_ant--;}

    $mes_prox = $mes_atual + 1;
    $ano_prox = $ano_atual;
    if ($mes_prox > 12) {$mes_prox = 1;
    $ano_prox++;}

    $meses_pt = [1 => 'Janeiro', 2 => 'Fevereiro', 3 => 'Março', 4 => 'Abril', 5 => 'Maio', 6 => 'Junho', 7 => 'Julho', 8 => 'Agosto', 9 => 'Setembro', 10 => 'Outubro', 11 => 'Novembro', 12 => 'Dezembro'];
    $nome_mes = $meses_pt[$mes_atual];

    $link_ant  = "?mes={$mes_ant}&ano={$ano_ant}";
    $link_prox = "?mes={$mes_prox}&ano={$ano_prox}";

    // --- BUSCA DE DADOS ---
    $transacoes           = [];
    $totalDespesas        = 0;
    $totalReceitas        = 0;
    $gastosPorCategoria   = [];
    $receitasPorCategoria = [];

    try {
    $sql = '
        SELECT
            r."Valor", r."Descricao", r."TipoRegistro", r."MomentoRegistro",
            COALESCE(c."NomeCategoria", \'Sem Categoria\') as "Categoria",
            COALESCE(c."IconeCategoria", \'bi-tag\') as "Icone"
        FROM "Registro" r
        LEFT JOIN "Categoria" c ON r."FKCategoria" = c."IDCategoria"
        WHERE r."FKUsuario" = :uid
          AND r."StatusRegistro" = \'efetivado\'
          AND EXTRACT(MONTH FROM r."MomentoRegistro") = :mes
          AND EXTRACT(YEAR FROM r."MomentoRegistro") = :ano
        ORDER BY r."MomentoRegistro" DESC
    ';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':uid' => $usuario_id, ':mes' => $mes_atual, ':ano' => $ano_atual]);
    $transacoes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($transacoes as $t) {
        $valor = (float) $t['Valor'];
        $cat   = $t['Categoria'];

        if ($t['TipoRegistro'] === 'despesa') {
            $totalDespesas += $valor;
            if (! isset($gastosPorCategoria[$cat])) {
                $gastosPorCategoria[$cat] = 0;
            }

            $gastosPorCategoria[$cat] += $valor;
        } else {
            $totalReceitas += $valor;
            if (! isset($receitasPorCategoria[$cat])) {
                $receitasPorCategoria[$cat] = 0;
            }

            $receitasPorCategoria[$cat] += $valor;
        }
    }
    } catch (PDOException $e) {}

    arsort($gastosPorCategoria);
    $maiorGastoCat   = key($gastosPorCategoria);
    $maiorGastoValor = current($gastosPorCategoria);

    arsort($receitasPorCategoria);
    $maiorReceitaCat   = key($receitasPorCategoria);
    $maiorReceitaValor = current($receitasPorCategoria);

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

<main class="container py-4 mt-3 flex-grow-1" style="min-height: 100vh;">

    <div class="d-flex justify-content-between align-items-center mb-4 border-bottom border-secondary-subtle pb-3 flex-wrap gap-3">
        <h2 class="fw-bold text-light mb-0"><i class="bi bi-pie-chart text-primary me-2" style="color: var(--primary-gold-analysis) !important;"></i> Análises</h2>

        <div class="d-flex align-items-center bg-dark border border-secondary-subtle rounded-pill px-2 py-1 shadow-sm">
            <a href="<?php echo $link_ant ?>" class="btn btn-sm btn-link text-light opacity-75 transition-hover text-decoration-none fs-5 d-flex align-items-center justify-content-center" style="width: 35px; height: 35px;">
                <i class="bi bi-caret-left-fill"></i>
            </a>
            <button type="button" class="btn btn-link text-light text-decoration-none fw-bold px-2 transition-hover d-flex align-items-center justify-content-center"
        style="min-width: 140px; font-size: 0.95rem;"
        data-bs-toggle="modal" data-bs-target="#modalSeletorMes">
    <?php echo $nome_mes ?> <?php echo $ano_atual ?>
    <i class="bi bi-chevron-down ms-2 fs-7 opacity-75"></i>
</button>
            <a href="<?php echo $link_prox ?>" class="btn btn-sm btn-link text-light opacity-75 transition-hover text-decoration-none fs-5 d-flex align-items-center justify-content-center" style="width: 35px; height: 35px;">
                <i class="bi bi-caret-right-fill"></i>
            </a>
        </div>
    </div>

    <div class="row g-4 mb-5">
        <div class="col-md-6">
            <div class="card bg-body-tertiary border-secondary-subtle shadow-sm h-100 rounded-4">
                <div class="card-body p-4 d-flex align-items-center">
                    <div class="bg-danger bg-opacity-10 p-3 rounded-circle me-4">
                        <i class="bi bi-fire text-danger fs-1"></i>
                    </div>
                    <div>
                        <p class="text-secondary small mb-1 text-uppercase fw-bold tracking-wide">Maior Fuga de Capital (Gastos)</p>
                        <?php if ($maiorGastoCat): ?>
                            <h4 class="fw-bold text-light mb-0"><?php echo htmlspecialchars($maiorGastoCat) ?></h4>
                            <span class="text-danger fw-semibold fs-5">R$ <?php echo number_format($maiorGastoValor, 2, ',', '.') ?></span>
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
                    <div class="bg-success bg-opacity-10 p-3 rounded-circle me-4">
                        <i class="bi bi-trophy text-success fs-1"></i>
                    </div>
                    <div>
                        <p class="text-secondary small mb-1 text-uppercase fw-bold tracking-wide">Principal Motor de Renda</p>
                        <?php if ($maiorReceitaCat): ?>
                            <h4 class="fw-bold text-light mb-0"><?php echo htmlspecialchars($maiorReceitaCat) ?></h4>
                            <span class="text-success fw-semibold fs-5">R$ <?php echo number_format($maiorReceitaValor, 2, ',', '.') ?></span>
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
                    <div class="position-relative d-flex justify-content-center align-items-center w-100" style="max-width: 320px; aspect-ratio: 1;">
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
                <div class="card-body p-0 overflow-auto" style="max-height: 400px;" id="lista-detalhes-despesa">
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
                    <div class="position-relative d-flex justify-content-center align-items-center w-100" style="max-width: 320px; aspect-ratio: 1;">
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
                <div class="card-body p-0 overflow-auto" style="max-height: 400px;" id="lista-detalhes-receita">
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
            <p class="text-secondary">Parece que você não tem transações efetivadas em <?php echo $nome_mes ?>.</p>
        </div>
    <?php endif; ?>

</main>

<style>
    .bg-card-analysis { background-color: #2A2A2A; }
    .tracking-wide { letter-spacing: 0.05em; }

    #lista-detalhes-despesa::-webkit-scrollbar, #lista-detalhes-receita::-webkit-scrollbar { width: 6px; }
    #lista-detalhes-despesa::-webkit-scrollbar-track, #lista-detalhes-receita::-webkit-scrollbar-track { background: transparent; }
    #lista-detalhes-despesa::-webkit-scrollbar-thumb, #lista-detalhes-receita::-webkit-scrollbar-thumb { background-color: #444; border-radius: 10px; }
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
                    $mesesAbrev = [1=>'Jan', 2=>'Fev', 3=>'Mar', 4=>'Abr', 5=>'Mai', 6=>'Jun', 7=>'Jul', 8=>'Ago', 9=>'Set', 10=>'Out', 11=>'Nov', 12=>'Dez'];
                    foreach($mesesAbrev as $num => $nome): 
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
    // Javascript levemente ajustado para funcionar com o novo visual do ano
    function mudarAnoModal(delta) {
        const inputAno = document.getElementById('anoModalInput');
        const displayAno = document.getElementById('anoModalDisplay');
        
        let novoAno = parseInt(inputAno.value) + delta;
        
        inputAno.value = novoAno;
        displayAno.innerText = novoAno; // Atualiza o texto na tela
    }

    function irParaMes(mes) {
        const ano = document.getElementById('anoModalInput').value;
        const urlParams = new URLSearchParams(window.location.search);
        
        urlParams.set('mes', mes);
        urlParams.set('ano', ano);
        
        window.location.search = urlParams.toString();
    }
</script>
<script>
    const transacoesBrutas = <?php echo $dadosJsonTransacoes ?>;

    // Paletas de Cores (Despesas = Quentes/Terrosas | Receitas = Frias/Verdes)
    const coresDespesas = ['#AA8C2C', '#D4AF37', '#E7C665', '#E63946', '#F4A261', '#E9C46A', '#9C6644'];
    const coresReceitas = ['#06D6A0', '#118AB2', '#2A9D8F', '#264653', '#457B9D', '#1D3557', '#0077B6'];

    // GRÁFICO DE DESPESAS
    if (document.getElementById('graficoDespesas')) {
        const ctxDespesas = document.getElementById('graficoDespesas').getContext('2d');
        const chartDespesas = new Chart(ctxDespesas, {
            type: 'doughnut',
            data: {
                labels: <?php echo $dadosJsonLabelsDespesas ?>,
                datasets: [{
                    data: <?php echo $dadosJsonValoresDespesas ?>,
                    backgroundColor: coresDespesas,
                    borderWidth: 2,
                    borderColor: '#1a1d21'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false, // Permite que o CSS assuma o controle do tamanho perfeito
                cutout: '75%',
                plugins: { legend: { display: false } },
                onClick: (event, elements) => {
                    if (elements.length > 0) {
                        const index = elements[0].index;
                        const categoriaClicada = chartDespesas.data.labels[index];
                        atualizarListaDetalhes(categoriaClicada, 'despesa');
                    }
                }
            }
        });
    }

    // GRÁFICO DE RECEITAS
    if (document.getElementById('graficoReceitas')) {
        const ctxReceitas = document.getElementById('graficoReceitas').getContext('2d');
        const chartReceitas = new Chart(ctxReceitas, {
            type: 'doughnut',
            data: {
                labels: <?php echo $dadosJsonLabelsReceitas ?>,
                datasets: [{
                    data: <?php echo $dadosJsonValoresReceitas ?>,
                    backgroundColor: coresReceitas,
                    borderWidth: 2,
                    borderColor: '#1a1d21'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '75%',
                plugins: { legend: { display: false } },
                onClick: (event, elements) => {
                    if (elements.length > 0) {
                        const index = elements[0].index;
                        const categoriaClicada = chartReceitas.data.labels[index];
                        atualizarListaDetalhes(categoriaClicada, 'receita');
                    }
                }
            }
        });
    }

    // FUNÇÃO ÚNICA PARA ATUALIZAR QUALQUER LISTA
    function atualizarListaDetalhes(categoriaFiltro, tipo) {
        const containerLista = document.getElementById(`lista-detalhes-${tipo}`);
        const badgeCategoria = document.getElementById(`badge-categoria-${tipo}`);

        badgeCategoria.innerText = categoriaFiltro;
        // Muda a cor da badge dependendo do tipo
        badgeCategoria.className = tipo === 'despesa' ? 'badge bg-warning text-dark' : 'badge bg-info text-dark';

        const transacoesFiltradas = transacoesBrutas.filter(t =>
            t.TipoRegistro === tipo && t.Categoria === categoriaFiltro
        );

        let htmlLista = '<div class="list-group list-group-flush">';
        transacoesFiltradas.forEach(t => {
            // Corta a string no espaço vazio, pegando só o "YYYY-MM-DD", e adiciona o meio-dia para evitar bugs de fuso horário
            const dataApenas = t.MomentoRegistro.split(' ')[0];
            const dataStr = new Date(dataApenas + 'T12:00:00').toLocaleDateString('pt-BR');            const valorFormatado = new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(t.Valor);
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