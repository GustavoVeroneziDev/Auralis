<?php
session_start();
if (!isset($_SESSION['usuario_id'])) {
    header("Location: usuario/login.php");
    exit;
}

require_once 'config/conexao.php';

// 1. Lógica do Calendário (Mês e Ano atuais)
$mes = isset($_GET['mes']) ? (int)$_GET['mes'] : (int)date('n');
$ano = isset($_GET['ano']) ? (int)$_GET['ano'] : (int)date('Y');

// Navegação (Mês anterior e próximo)
$mes_ant = $mes - 1;
$ano_ant = $ano;
if ($mes_ant == 0) {
    $mes_ant = 12;
    $ano_ant--;
}

$mes_prox = $mes + 1;
$ano_prox = $ano;
if ($mes_prox == 13) {
    $mes_prox = 1;
    $ano_prox++;
}

// Dados do mês para a grade
$primeiro_dia_mes = mktime(0, 0, 0, $mes, 1, $ano);
$dias_no_mes = date('t', $primeiro_dia_mes);
$dia_semana_primeiro = date('w', $primeiro_dia_mes); // 0 (Dom) a 6 (Sáb)

// Nomes em português
$meses_pt = ['', 'Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];
$nome_mes_atual = $meses_pt[$mes];

// ==============================================================================
// 2. BUSCA NO BANCO DE DADOS (Simulação)
// ==============================================================================
// Aqui você fará um SELECT na sua tabela de Registros buscando tudo que cai neste $mes e $ano.
// Para este exemplo, vou simular um array estruturado por dias.
$eventos_mock = [
    '5' => [
        ['titulo' => 'Conta de Luz', 'tipo' => 'despesa', 'valor' => 185.00],
    ],
    '10' => [
        ['titulo' => 'Salário', 'tipo' => 'receita', 'valor' => 3500.00],
    ],
    '12' => [
        ['titulo' => 'Assinatura Auralis PRO', 'tipo' => 'despesa', 'valor' => 29.90],
        ['titulo' => 'Netflix', 'tipo' => 'despesa', 'valor' => 39.90]
    ]
];

require_once 'geral/header.php';
?>

<main class="container-fluid py-4 flex-grow-1" style="max-width: 1400px; padding-inline: var(--space-page-x);">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 class="fw-bold text-light mb-0 d-flex align-items-center gap-2">
            <i class="bi bi-calendar3 text-primary" style="color: var(--primary-gold-analysis) !important;"></i>
            Agenda Financeira
        </h2>

        <div class="d-flex align-items-center gap-3 bg-body-tertiary px-3 py-2 rounded-pill border border-secondary-subtle">
            <a href="?mes=<?= $mes_ant ?>&ano=<?= $ano_ant ?>" class="text-secondary text-decoration-none transition-hover px-2">
                <i class="bi bi-chevron-left"></i>
            </a>
            <span class="text-light fw-bold text-capitalize" style="min-width: 120px; text-align: center;">
                <?= $nome_mes_atual ?> de <?= $ano ?>
            </span>
            <a href="?mes=<?= $mes_prox ?>&ano=<?= $ano_prox ?>" class="text-secondary text-decoration-none transition-hover px-2">
                <i class="bi bi-chevron-right"></i>
            </a>
        </div>
    </div>

    <div class="notion-calendar-wrapper shadow-lg">

        <div class="notion-calendar-header">
            <div>Domingo</div>
            <div>Segunda</div>
            <div>Terça</div>
            <div>Quarta</div>
            <div>Quinta</div>
            <div>Sexta</div>
            <div>Sábado</div>
        </div>

        <div class="notion-calendar-grid">
            <?php
            // Preenche os espaços vazios do início da semana (dias do mês anterior)
            for ($i = 0; $i < $dia_semana_primeiro; $i++) {
                echo '<div class="notion-day empty-day"></div>';
            }

            // Preenche os dias reais do mês atual
            for ($dia = 1; $dia <= $dias_no_mes; $dia++) {

                // Verifica se o dia atual no loop é hoje (para destacar a bolinha)
                $is_hoje = ($dia == date('j') && $mes == date('n') && $ano == date('Y'));
                $classe_hoje = $is_hoje ? 'today-marker' : '';

                echo '<div class="notion-day">';
                echo '  <div class="notion-date"><span class="' . $classe_hoje . '">' . $dia . '</span></div>';

                // Se existirem eventos/transações neste dia, nós os desenhamos como pílulas
                if (isset($eventos_mock[$dia])) {
                    echo '<div class="notion-events-container">';
                    foreach ($eventos_mock[$dia] as $evento) {

                        // Define a cor baseada no tipo (Receita = Verde, Despesa = Vermelho)
                        $classe_evento = ($evento['tipo'] === 'receita') ? 'event-receita' : 'event-despesa';

                        echo '<div class="notion-event ' . $classe_evento . '" title="' . htmlspecialchars($evento['titulo']) . ' - R$ ' . number_format($evento['valor'], 2, ',', '.') . '">';
                        echo '  <span class="event-title">' . htmlspecialchars($evento['titulo']) . '</span>';
                        echo '</div>';
                    }
                    echo '</div>';
                }

                echo '</div>';
            }

            // Preenche os espaços vazios do final da semana para a grade fechar o quadrado certinho
            $dias_sobrando = (7 - (($dia_semana_primeiro + $dias_no_mes) % 7)) % 7;
            for ($i = 0; $i < $dias_sobrando; $i++) {
                echo '<div class="notion-day empty-day"></div>';
            }
            ?>
        </div>
    </div>
</main>

<style>
    /* Variáveis baseadas no seu dark mode */
    :root {
        --notion-border: #333333;
        --notion-bg-main: #1a1d21;
        --notion-bg-cell: #222529;
        --notion-bg-hover: #2c2f35;
        --notion-text-muted: #888888;
    }

    /* O container que abraça a tabela inteira e desenha a borda externa */
    .notion-calendar-wrapper {
        background-color: var(--notion-border);
        border: 1px solid var(--notion-border);
        border-radius: 8px;
        overflow: hidden;
        /* Corta as quinas para respeitar o radius */
    }

    /* Cabeçalho (Seg, Ter, Qua...) */
    .notion-calendar-header {
        display: grid;
        grid-template-columns: repeat(7, 1fr);
        gap: 1px;
        /* Esse gap com o fundo do wrapper cria a "linha" fina */
        background-color: var(--notion-border);
        padding-bottom: 1px;
    }

    .notion-calendar-header div {
        background-color: var(--notion-bg-main);
        color: var(--notion-text-muted);
        font-size: 0.75rem;
        text-transform: lowercase;
        text-align: right;
        padding: 8px 12px;
        font-weight: 500;
    }

    /* Grade dos dias */
    .notion-calendar-grid {
        display: grid;
        grid-template-columns: repeat(7, 1fr);
        gap: 1px;
        /* A mágica da borda fina de 1px */
        background-color: var(--notion-border);
    }

    /* Cada quadradinho de dia */
    .notion-day {
        background-color: var(--notion-bg-cell);
        min-height: 140px;
        padding: 8px;
        display: flex;
        flex-direction: column;
        transition: background-color 0.2s ease;
    }

    .notion-day:hover:not(.empty-day) {
        background-color: var(--notion-bg-hover);
        cursor: pointer;
    }

    .empty-day {
        background-color: var(--notion-bg-main);
        opacity: 0.5;
    }

    /* O número do dia (fica na direita, igual ao Notion) */
    .notion-date {
        text-align: right;
        font-size: 0.85rem;
        color: #aaaaaa;
        margin-bottom: 8px;
    }

    /* Destaca o dia de Hoje com a bolinha vermelha do Notion */
    .today-marker {
        background-color: #eb5757;
        color: white;
        border-radius: 50%;
        width: 24px;
        height: 24px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-weight: bold;
    }

    /* Container de Eventos dentro do dia */
    .notion-events-container {
        display: flex;
        flex-direction: column;
        gap: 4px;
    }

    /* A pílula do evento */
    .notion-event {
        font-size: 0.75rem;
        padding: 4px 8px;
        border-radius: 4px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        font-weight: 500;
        transition: filter 0.2s;
    }

    .notion-event:hover {
        filter: brightness(1.2);
    }

    /* Cores das pílulas (Despesa e Receita) */
    .event-despesa {
        background-color: rgba(235, 87, 87, 0.15);
        /* Fundo vermelho suave */
        color: #ff7676;
        /* Texto avermelhado */
    }

    .event-receita {
        background-color: rgba(39, 174, 96, 0.15);
        /* Fundo verde suave */
        color: #4ade80;
        /* Texto esverdeado */
    }

    /* Responsividade para celular: transforma em lista */
    @media (max-width: 768px) {
        .notion-calendar-header {
            display: none;
        }

        /* Esconde Seg, Ter... no celular */
        .notion-calendar-grid {
            grid-template-columns: 1fr;
            /* 1 coluna só */
            gap: 0;
        }

        .notion-day {
            min-height: auto;
            border-bottom: 1px solid var(--notion-border);
            flex-direction: row;
            /* Dia na esquerda, eventos na direita */
            align-items: flex-start;
            gap: 15px;
        }

        .notion-date {
            margin-bottom: 0;
            min-width: 30px;
            text-align: left;
        }

        .empty-day {
            display: none;
        }

        /* Esconde dias em branco no celular */
        .notion-events-container {
            flex-grow: 1;
        }
    }
</style>

<?php require_once 'geral/footer.php'; ?>