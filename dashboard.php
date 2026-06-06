<?php

// MODO DEBUG: LIGA A LANTERNA PARA VER O ERRO REAL
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
session_start();

if (!isset($_SESSION['usuario_id'])) {
    header("Location: usuario/login.php");
    exit;
}

// 2. Conecta ao banco de dados
require_once 'config/conexao.php';
require_once 'config/funcoes.php';

// Função auxiliar para gerar UUID no padrão MySQL
if (!function_exists('gerarUuid')) {
    function gerarUuid()
    {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x', mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000, mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff));
    }
}

$usuario_id = $_SESSION['usuario_id'];
$carteiras  = [];

try {
    $sql  = "SELECT IDCarteira, TipoCarteira FROM Carteira WHERE FKUsuarioDono = :usuario_id ORDER BY TipoCarteira ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':usuario_id' => $usuario_id]);
    $carteiras = $stmt->fetchAll();
} catch (PDOException $e) {
    $carteiras = [];
}

$totalCarteiras = count($carteiras);

// ==============================================================================
// MOTOR DE RECORRÊNCIA AURALIS (Executado no carregamento do Dashboard)
// ==============================================================================
$mesAnoAtual = date('Y-m');

try {
    // 1. Verifica se o sistema já rodou este mês
    $sqlConfig  = "SELECT Valor FROM ConfiguracaoSistema WHERE Chave = 'ultima_recorrencia' AND FKUsuario = :uid";
    $stmtConfig = $pdo->prepare($sqlConfig);
    $stmtConfig->execute([':uid' => $usuario_id]);
    $ultimaExecucao = $stmtConfig->fetchColumn();

    if ($ultimaExecucao !== $mesAnoAtual) {
        // 2. Busca contas recorrentes do mês passado (para evitar gaps)
        $mesAnterior = date('Y-m', strtotime('-1 month'));

        // CORREÇÃO: TO_CHAR trocado por DATE_FORMAT e booleano para 1
        $sqlRec  = "SELECT * FROM Registro WHERE FKUsuario = :uid AND Recorrente = 1 AND (GrupoParcela IS NULL OR TotalParcelas IS NOT NULL) AND DATE_FORMAT(MomentoRegistro, '%Y-%m') = :mes_ant";
        $stmtRec = $pdo->prepare($sqlRec);
        $stmtRec->execute([':uid' => $usuario_id, ':mes_ant' => $mesAnterior]);
        $contas = $stmtRec->fetchAll();

        if (!empty($contas)) {
            // CORREÇÃO: Adicionado IDRegistro manual
            $sqlInsert = "INSERT INTO Registro (IDRegistro, TipoRegistro, Valor, Descricao, MomentoRegistro, DataVencimento, StatusRegistro, Recorrente, DiaVencimento, FKCarteira, FKUsuario, FKCategoria)
                              VALUES (:id, :tipo, :valor, :desc, :momento, :venc, 'pendente', 1, :dia, :cart, :uid, :cat)";
            $stmtInsert = $pdo->prepare($sqlInsert);

            foreach ($contas as $c) {
                $novaData = date('Y-m') . '-' . str_pad($c['DiaVencimento'], 2, '0', STR_PAD_LEFT);

                $stmtInsert->execute([
                    ':id'       => gerarUuid(),
                    ':tipo'     => $c['TipoRegistro'],
                    ':valor'    => $c['Valor'],
                    ':desc'     => $c['Descricao'],
                    ':momento'  => $novaData,
                    ':venc'     => $novaData,
                    ':dia'      => $c['DiaVencimento'],
                    ':cart'     => $c['FKCarteira'],
                    ':uid'      => $usuario_id,
                    ':cat'      => $c['FKCategoria'],
                ]);
            }
        }

        // 3. Atualiza a "memória" do sistema
        if ($ultimaExecucao === false) {
            $sqlUpd = "INSERT INTO ConfiguracaoSistema (Chave, Valor, FKUsuario) VALUES ('ultima_recorrencia', :v, :uid)";
        } else {
            $sqlUpd = "UPDATE ConfiguracaoSistema SET Valor = :v WHERE Chave = 'ultima_recorrencia' AND FKUsuario = :uid";
        }
        $pdo->prepare($sqlUpd)->execute([':v' => $mesAnoAtual, ':uid' => $usuario_id]);
    }
} catch (PDOException $e) {
    // Falha silenciosa
}
// ==============================================================================

// --- VERIFICA SE É O PRIMEIRO ACESSO ---
$is_primeiro_acesso = false;
try {
    $sqlTotalTrans = "SELECT COUNT(*) FROM Registro WHERE FKUsuario = :uid";
    $stmtTotal     = $pdo->prepare($sqlTotalTrans);
    $stmtTotal->execute([':uid' => $usuario_id]);
    if ($stmtTotal->fetchColumn() == 0) {
        $is_primeiro_acesso = true;
    }
} catch (PDOException $e) {
}

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

// --- LÓGICA DE AÇÃO: ALTERAR STATUS, EXCLUIR OU AJUSTAR SALDO ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    $carteira_url = isset($_GET['carteira']) ? "&carteira=" . $_GET['carteira'] : "";
    $redirectBase = "dashboard.php?mes={$mes_atual}&ano={$ano_atual}{$carteira_url}";

    if ($_POST['action'] === 'toggle_status') {
        $id_registro = $_POST['registro_id'];
        $novo_status = $_POST['novo_status'];
        if (in_array($novo_status, ['pendente', 'efetivado'])) {
            try {
                $sqlToggle  = "UPDATE Registro SET StatusRegistro = :status WHERE IDRegistro = :id AND FKUsuario = :uid";
                $stmtToggle = $pdo->prepare($sqlToggle);
                $stmtToggle->execute([':status' => $novo_status, ':id' => $id_registro, ':uid' => $usuario_id]);
                header("Location: " . $redirectBase);
                exit;
            } catch (PDOException $e) {
            }
        }
    }

    if ($_POST['action'] === 'excluir_registro') {
        $id_registro = $_POST['registro_id'];
        try {
            $sqlDel  = "DELETE FROM Registro WHERE IDRegistro = :id AND FKUsuario = :uid";
            $stmtDel = $pdo->prepare($sqlDel);
            $stmtDel->execute([':id' => $id_registro, ':uid' => $usuario_id]);
            header("Location: " . $redirectBase . "&sucesso=excluido");
            exit;
        } catch (PDOException $e) {
        }
    }

    if ($_POST['action'] === 'excluir_recorrente_grupo') {
        $id_registro   = $_POST['registro_id'];
        $grupo_id      = $_POST['grupo_parcela'];
        $data_base     = $_POST['momento_registro'];
        $tipo_exclusao = $_POST['tipo_exclusao'] ?? 'apenas_este';

        try {
            if ($tipo_exclusao === 'futuros' && !empty($grupo_id)) {
                // Exclui o registro selecionado E todas as projeções futuras pendentes do grupo
                $sqlDel = "
                    DELETE FROM Registro 
                    WHERE FKUsuario = :uid 
                      AND GrupoParcela = :grupo
                      AND (IDRegistro = :id OR (MomentoRegistro > :data_base AND StatusRegistro = 'pendente' AND TotalParcelas IS NULL))
                ";
                $stmtDel = $pdo->prepare($sqlDel);
                $stmtDel->execute([
                    ':uid'       => $usuario_id,
                    ':grupo'     => $grupo_id,
                    ':id'        => $id_registro,
                    ':data_base' => $data_base
                ]);
            } else {
                // Comportamento padrão: exclui apenas o mês selecionado
                $sqlDel  = "DELETE FROM Registro WHERE IDRegistro = :id AND FKUsuario = :uid";
                $stmtDel = $pdo->prepare($sqlDel);
                $stmtDel->execute([':id' => $id_registro, ':uid' => $usuario_id]);
            }
            header("Location: " . $redirectBase . "&sucesso=excluido");
            exit;
        } catch (PDOException $e) {
        }
    }

    if ($_POST['action'] === 'excluir_parcelado_grupo') {
        $id_registro   = $_POST['registro_id'];
        $grupo_id      = $_POST['grupo_parcela'];
        $parcela_atual = (int)$_POST['parcela_atual'];
        $tipo_exclusao = $_POST['tipo_exclusao'] ?? 'apenas_este';

        try {
            if ($tipo_exclusao === 'futuros' && !empty($grupo_id)) {
                // Exclui a parcela selecionada e todas as que vêm DEPOIS dela
                $sqlDel = "
                    DELETE FROM Registro 
                    WHERE FKUsuario = :uid 
                      AND GrupoParcela = :grupo
                      AND ParcelaAtual >= :parc_atual
                ";
                $stmtDel = $pdo->prepare($sqlDel);
                $stmtDel->execute([
                    ':uid'       => $usuario_id,
                    ':grupo'     => $grupo_id,
                    ':parc_atual' => $parcela_atual
                ]);
            } else {
                // Exclui apenas a parcela selecionada
                $sqlDel  = "DELETE FROM Registro WHERE IDRegistro = :id AND FKUsuario = :uid";
                $stmtDel = $pdo->prepare($sqlDel);
                $stmtDel->execute([':id' => $id_registro, ':uid' => $usuario_id]);
            }
            header("Location: " . $redirectBase . "&sucesso=excluido");
            exit;
        } catch (PDOException $e) {
        }
    }

    if ($_POST['action'] === 'ajustar_saldo') {
        $saldo_informado    = (float) str_replace(',', '.', $_POST['saldo_real']);
        $saldo_sistema      = (float) $_POST['saldo_sistema_atual'];
        $carteira_id_ajuste = $_POST['carteira_id_ajuste'];

        $diferenca = $saldo_informado - $saldo_sistema;

        // A MÁGICA AQUI: Se for o primeiro acesso, ele SALVA o registro mesmo que o valor seja zero.
        if (abs($diferenca) > 0.009 || $is_primeiro_acesso) {
            // Se for >= 0, é receita. Assim, o zero fica registrado como receita inicial.
            $tipoRegistro  = ($diferenca >= 0) ? 'receita' : 'despesa';
            $valorRegistro = abs($diferenca);
            $descricao     = $is_primeiro_acesso ? 'Saldo Inicial' : 'Ajuste de Saldo';

            try {
                $sqlCat  = "SELECT IDCategoria FROM Categoria WHERE FKUsuario = :uid AND NomeCategoria = 'Ajuste de Saldo' AND TipoCategoria = :tipo LIMIT 1";
                $stmtCat = $pdo->prepare($sqlCat);
                $stmtCat->execute([':uid' => $usuario_id, ':tipo' => $tipoRegistro]);
                $catId = $stmtCat->fetchColumn();

                if (!$catId) {
                    $sqlNovaCat  = "INSERT INTO Categoria (IDCategoria, NomeCategoria, TipoCategoria, IconeCategoria, FKUsuario) VALUES (:id, 'Ajuste de Saldo', :tipo, 'bi-gear-fill', :uid)";
                    $stmtNovaCat = $pdo->prepare($sqlNovaCat);
                    $stmtNovaCat->execute([':id' => gerarUuid(), ':tipo' => $tipoRegistro, ':uid' => $usuario_id]);

                    $stmtCat->execute([':uid' => $usuario_id, ':tipo' => $tipoRegistro]);
                    $catId = $stmtCat->fetchColumn();
                }

                $sqlAjuste = "
                        INSERT INTO Registro (
                            IDRegistro, TipoRegistro, Valor, Descricao, MomentoRegistro,
                            StatusRegistro, FKCarteira, FKUsuario, FKCategoria
                        ) VALUES (
                            :id, :tipo, :valor, :descricao, CURRENT_DATE,
                            'efetivado', :carteira, :usuario, :categoria
                        )
                    ";
                $stmtAjuste = $pdo->prepare($sqlAjuste);
                $stmtAjuste->execute([
                    ':id'        => gerarUuid(),
                    ':tipo'      => $tipoRegistro,
                    ':valor'     => $valorRegistro,
                    ':descricao' => $descricao,
                    ':carteira'  => $carteira_id_ajuste,
                    ':usuario'   => $usuario_id,
                    ':categoria' => $catId,
                ]);

                // Se for o primeiro acesso, limpa a URL para não exibir avisos repetidos
                if ($is_primeiro_acesso) {
                    header("Location: " . explode('&', $redirectBase)[0] . "&sucesso=ajustado");
                } else {
                    header("Location: " . $redirectBase . "&sucesso=ajustado");
                }
                exit;
            } catch (PDOException $e) {
            }
        } else {
            header("Location: " . $redirectBase);
            exit;
        }
    }
}

// --- LÓGICA DO FILTRO DE CARTEIRA ---
if (isset($_GET['carteira'])) {
    $carteira_selecionada = $_GET['carteira'];
} else {
    $carteira_selecionada = ($totalCarteiras > 0) ? $carteiras[0]['IDCarteira'] : null;
}

$nome_carteira_atual = 'Carteira';
foreach ($carteiras as $cart) {
    if ($cart['IDCarteira'] == $carteira_selecionada) {
        $nome_carteira_atual = $cart['TipoCarteira'];
        break;
    }
}

$link_ant  = "?mes={$mes_ant}&ano={$ano_ant}" . ($carteira_selecionada ? "&carteira={$carteira_selecionada}" : "");
$link_prox = "?mes={$mes_prox}&ano={$ano_prox}" . ($carteira_selecionada ? "&carteira={$carteira_selecionada}" : "");

// --- LÓGICA DE DADOS REAIS DO DASHBOARD ---
$saldoAtual  = 0.00;
$receitasMes = 0.00;
$despesasMes = 0.00;
$transacoes  = [];

if ($carteira_selecionada) {
    try {
        $sqlSaldo = "
                SELECT
                    COALESCE(SUM(CASE WHEN TipoRegistro = 'receita' THEN Valor ELSE 0 END), 0) as total_rec_hist,
                    COALESCE(SUM(CASE WHEN TipoRegistro = 'despesa' THEN Valor ELSE 0 END), 0) as total_des_hist
                FROM Registro
                WHERE FKCarteira = :carteira_id
                  AND FKUsuario = :usuario_id
                  AND StatusRegistro = 'efetivado'
            ";
        $stmtSaldo = $pdo->prepare($sqlSaldo);
        $stmtSaldo->execute([':carteira_id' => $carteira_selecionada, ':usuario_id' => $usuario_id]);
        $resultSaldo = $stmtSaldo->fetch();

        if ($resultSaldo) {
            $saldoAtual = (float) $resultSaldo['total_rec_hist'] - (float) $resultSaldo['total_des_hist'];
        }

        // CORREÇÃO: EXTRACT trocado por MONTH() e YEAR()
        $sqlMes = "
                SELECT
                    COALESCE(SUM(CASE WHEN TipoRegistro = 'receita' THEN Valor ELSE 0 END), 0) as total_receitas,
                    COALESCE(SUM(CASE WHEN TipoRegistro = 'despesa' THEN Valor ELSE 0 END), 0) as total_despesas
                FROM Registro
                WHERE FKCarteira = :carteira_id
                  AND FKUsuario = :usuario_id
                  AND StatusRegistro = 'efetivado'
                  AND MONTH(MomentoRegistro) = :mes
                  AND YEAR(MomentoRegistro) = :ano
            ";
        $stmtMes = $pdo->prepare($sqlMes);
        $stmtMes->execute([
            ':carteira_id' => $carteira_selecionada,
            ':usuario_id'  => $usuario_id,
            ':mes'         => $mes_atual,
            ':ano'         => $ano_atual,
        ]);
        $resultMes = $stmtMes->fetch();

        if ($resultMes) {
            $receitasMes = (float) $resultMes['total_receitas'];
            $despesasMes = (float) $resultMes['total_despesas'];
        }

        // CORREÇÃO: EXTRACT trocado por MONTH() e YEAR()
        $sqlTransacoes = "
                SELECT
                    r.IDRegistro, r.MomentoRegistro, r.Valor, r.Descricao, r.TipoRegistro, r.StatusRegistro,
                    r.DataVencimento, r.Recorrente, r.DiaVencimento,
                    r.GrupoParcela, r.ParcelaAtual, r.TotalParcelas,
                    c.NomeCategoria, c.IconeCategoria
                FROM Registro r
                LEFT JOIN Categoria c ON r.FKCategoria = c.IDCategoria
                WHERE r.FKCarteira = :carteira_id
                  AND r.FKUsuario = :usuario_id
                  AND MONTH(r.MomentoRegistro) = :mes
                  AND YEAR(r.MomentoRegistro) = :ano
                ORDER BY r.MomentoRegistro DESC, r.IDRegistro DESC
                LIMIT 50
            ";
        $stmtTrans = $pdo->prepare($sqlTransacoes);
        $stmtTrans->execute([
            ':carteira_id' => $carteira_selecionada,
            ':usuario_id'  => $usuario_id,
            ':mes'         => $mes_atual,
            ':ano'         => $ano_atual,
        ]);
        $transacoes = $stmtTrans->fetchAll();
    } catch (PDOException $e) {
    }
}


// ── Verifica se assinatura ainda está válida (1x por sessão) ────────────
verificarExpiracao($pdo);

// ── COMPARAÇÃO: totais do mês ANTERIOR (para badges de variação) ──────────
$receitasMesAnt = 0.00;
$despesasMesAnt = 0.00;

if ($carteira_selecionada) {
    try {
        $sqlMesAnt = "
                SELECT
                    COALESCE(SUM(CASE WHEN TipoRegistro = 'receita' THEN Valor ELSE 0 END), 0) as total_receitas,
                    COALESCE(SUM(CASE WHEN TipoRegistro = 'despesa' THEN Valor ELSE 0 END), 0) as total_despesas
                FROM Registro
                WHERE FKCarteira = :carteira_id
                  AND FKUsuario  = :usuario_id
                  AND StatusRegistro = 'efetivado'
                  AND MONTH(MomentoRegistro) = :mes
                  AND YEAR(MomentoRegistro)  = :ano
            ";
        $stmtAnt = $pdo->prepare($sqlMesAnt);
        $stmtAnt->execute([
            ':carteira_id' => $carteira_selecionada,
            ':usuario_id'  => $usuario_id,
            ':mes'         => $mes_ant,
            ':ano'         => $ano_ant,
        ]);
        $resAnt = $stmtAnt->fetch();
        if ($resAnt) {
            $receitasMesAnt = (float) $resAnt['total_receitas'];
            $despesasMesAnt = (float) $resAnt['total_despesas'];
        }
    } catch (PDOException $e) {
    }
}

// ── GASTOS ESPERADOS: pendentes do mês atual ──────────────────────────────
$despesasPendentes = 0.00;
$receitasPendentes = 0.00;

if ($carteira_selecionada) {
    try {
        $sqlPend = "
                SELECT
                    COALESCE(SUM(CASE WHEN TipoRegistro = 'despesa' THEN Valor ELSE 0 END), 0) as pend_desp,
                    COALESCE(SUM(CASE WHEN TipoRegistro = 'receita' THEN Valor ELSE 0 END), 0) as pend_rec
                FROM Registro
                WHERE FKCarteira = :carteira_id
                  AND FKUsuario  = :usuario_id
                  AND StatusRegistro = 'pendente'
                  AND MONTH(MomentoRegistro) = :mes
                  AND YEAR(MomentoRegistro)  = :ano
            ";
        $stmtPend = $pdo->prepare($sqlPend);
        $stmtPend->execute([
            ':carteira_id' => $carteira_selecionada,
            ':usuario_id'  => $usuario_id,
            ':mes'         => $mes_atual,
            ':ano'         => $ano_atual,
        ]);
        $resPend = $stmtPend->fetch();
        if ($resPend) {
            $despesasPendentes = (float) $resPend['pend_desp'];
            $receitasPendentes = (float) $resPend['pend_rec'];
        }
    } catch (PDOException $e) {
    }
}

// ── Função helper: calcula variação percentual e retorna badge HTML ────────

function badgeVar(float $atual, float $anterior, bool $invertido = false): string
{
    // Sem dado anterior → sem badge. Menos poluição visual.
    if ($anterior <= 0) return '';
    $delta = (($atual - $anterior) / $anterior) * 100;
    $abs   = abs(round($delta, 1));
    if ($abs < 0.5) return '';
    $subiu    = $delta > 0;
    $positivo = $invertido ? !$subiu : $subiu;
    // bg-opacity-20 deixa o badge invisível porque text-{cor} combina com bg-{cor}.
    // Usamos text-white sobre fundo colorido sólido.
    $cor  = $positivo ? '28a745' : 'dc3545';
    $icon = $subiu ? 'bi-arrow-up-short' : 'bi-arrow-down-short';
    return "<span class='ms-1' style='display:inline-flex;align-items:center;background:#{$cor}22;color:#{$cor};border:1px solid #{$cor}44;border-radius:999px;padding:1px 7px;font-size:0.68rem;font-weight:600;'><i class='bi {$icon}'></i>{$abs}%</span>";
}

require_once 'geral/header.php';
?>

<main class="container-fluid py-4 mt-2 flex-grow-1" style="max-width: 1500px; padding-inline: var(--space-page-x); min-height: 100vh;">

    <?php if ($totalCarteiras == 0): ?>
        <div class="row justify-content-center mt-5 pt-5">
            <div class="col-md-8 text-center">
                <div class="mb-4">
                    <div class="d-inline-flex align-items-center justify-content-center bg-dark border border-secondary-subtle rounded-circle" style="width: 120px; height: 120px;">
                        <i class="bi bi-wallet2 text-secondary opacity-50" style="font-size: 3rem;"></i>
                    </div>
                </div>
                <h2 class="fw-bold text-light mb-3">Nenhuma carteira encontrada</h2>
                <p class="text-secondary mb-5 fs-5 px-md-5">Para começar a controlar o seu dinheiro, você precisa criar o seu primeiro espaço.</p>
                <a href="carteira/nova_carteira.php" class="btn btn-primary btn-lg fw-bold text-dark px-5 py-3 shadow cardCentral">
                    <i class="bi bi-plus-circle-fill me-2"></i> Criar Minha Primeira Carteira
                </a>
            </div>
        </div>
    <?php else: ?>

        <?php if (isset($_GET['sucesso'])): ?>
            <?php
            $msg = '';
            if ($_GET['sucesso'] === 'registro') {
                $msg = 'Transação salva com sucesso!';
            }
            if ($_GET['sucesso'] === 'editado') {
                $msg = 'Transação atualizada com sucesso!';
            }
            if ($_GET['sucesso'] === 'excluido') {
                $msg = 'Transação excluída!';
            }
            if ($_GET['sucesso'] === 'ajustado') {
                $msg = 'Prontinho! Seu saldo foi ajustado e agora está real.';
            }
            // ADICIONE ESTA CONDIÇÃO AQUI:
            if ($_GET['sucesso'] === 'criada') {
                $msg = 'Nova carteira criada! Agora informe seu saldo para começar.';
            }
            if ($_GET['sucesso'] === 'parcelado') {
                $n = isset($_GET['parcelas']) ? (int)$_GET['parcelas'] : '';
                $msg = "Compra parcelada em {$n}x registrada com sucesso!";
            }
            ?>

            <?php if ($msg): ?>
                <div class="alert alert-success d-flex align-items-center gap-2 rounded-3 shadow-sm border-0 bg-success bg-opacity-10 text-success fw-semibold alert-dismissible fade show" role="alert">
                    <i class="bi bi-check-circle-fill"></i> <span><?php echo $msg ?></span>
                    <button type="button" class="btn-close btn-close-white opacity-50" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <div class="mb-3 border-bottom border-secondary-subtle pb-3">
            <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3">

                <div class="d-flex align-items-center justify-content-between justify-content-lg-start gap-2 w-100 w-lg-auto">

                    <h2 class="fw-bold text-light mb-0 d-none d-lg-block me-2" style="white-space: nowrap; font-size: clamp(1rem, 2vw, 1.35rem);">Visão Geral</h2>

                    <div class="d-flex align-items-center bg-dark border border-secondary-subtle rounded-pill shadow-sm flex-shrink-0" style="padding: 2px 4px;">
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

                    <div class="dropdown flex-shrink-0">
                        <button class="btn border-secondary-subtle text-light shadow-sm fw-semibold dropdown-toggle d-flex align-items-center rounded-3 transition-hover px-2 px-sm-3"
                            type="button" data-bs-toggle="dropdown" aria-expanded="false"
                            style="font-size: 0.875rem; background-color: var(--bg-charcoal-analysis); max-width: 150px;">
                            <span class="text-truncate d-flex align-items-center">
                                <i class="bi bi-wallet2 me-1 me-sm-2" style="color: var(--primary-gold-analysis); flex-shrink: 0;"></i>
                                <?php echo htmlspecialchars($nome_carteira_atual); ?>
                            </span>
                        </button>

                        <ul class="dropdown-menu dropdown-menu-dark shadow-lg border-secondary-subtle mt-2" style="background-color:#1a1d21; min-width:220px;">
                            <li class="px-3 pt-2 pb-1 text-secondary small text-uppercase fw-bold tracking-wide">Alternar Carteira</li>
                            <li>
                                <hr class="dropdown-divider border-secondary-subtle my-1">
                            </li>
                            <?php foreach ($carteiras as $cart): ?>
                                <li>
                                    <a class="dropdown-item d-flex align-items-center gap-2 py-2 transition-hover <?php echo $carteira_selecionada == $cart['IDCarteira'] ? 'active' : '' ?>"
                                        href="?mes=<?php echo $mes_atual ?>&ano=<?php echo $ano_atual ?>&carteira=<?php echo htmlspecialchars($cart['IDCarteira']) ?>"
                                        style="font-size:0.9rem;">
                                        <?php if ($carteira_selecionada == $cart['IDCarteira']): ?>
                                            <i class="bi bi-check-circle-fill flex-shrink-0" style="color:var(--primary-gold-analysis);"></i>
                                            <span class="fw-bold text-truncate" style="color:var(--primary-gold-analysis); max-width:160px;" title="<?php echo htmlspecialchars($cart['TipoCarteira']); ?>">
                                                <?php echo htmlspecialchars($cart['TipoCarteira']); ?>
                                            </span>
                                        <?php else: ?>
                                            <i class="bi bi-circle flex-shrink-0 text-secondary opacity-50"></i>
                                            <span class="text-light text-truncate" style="max-width:160px;" title="<?php echo htmlspecialchars($cart['TipoCarteira']); ?>">
                                                <?php echo htmlspecialchars($cart['TipoCarteira']); ?>
                                            </span>
                                        <?php endif; ?>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>

                <div class="d-flex gap-2 w-100 w-lg-auto mt-1 mt-lg-0">
                    <a href="nova_transacao.php?carteira_id=<?php echo urlencode($carteira_selecionada) ?>&tipo=receita"
                        class="btn btn-outline-success fw-semibold d-flex align-items-center justify-content-center gap-1 rounded-pill transition-hover shadow-sm flex-grow-1"
                        style="font-size: 0.875rem; padding: 0.375rem 0.875rem;">
                        <i class="bi bi-arrow-up-short fs-5"></i> Receita
                    </a>

                    <a href="nova_transacao.php?carteira_id=<?php echo urlencode($carteira_selecionada) ?>&tipo=despesa"
                        class="btn btn-outline-danger fw-semibold d-flex align-items-center justify-content-center gap-1 rounded-pill transition-hover shadow-sm flex-grow-1"
                        style="font-size: 0.875rem; padding: 0.375rem 0.875rem;">
                        <i class="bi bi-arrow-down-short fs-5"></i> Despesa
                    </a>
                </div>

            </div>
        </div>

        <!-- ── Cards de Resumo ─────────────────────────────────────── -->
        <div class="row g-3 mb-3">
            <!-- Saldo -->
            <div class="col-12 col-md-4">
                <div class="card bg-body-tertiary border-secondary-subtle shadow-sm h-100 rounded-4">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <p class="text-secondary fw-semibold mb-0 small text-truncate me-2">Saldo: <?php echo htmlspecialchars($nome_carteira_atual); ?></p>
                            <div class="bg-primary bg-opacity-10 p-2 rounded-3 flex-shrink-0">
                                <i class="bi bi-wallet2" style="color: var(--primary-gold-analysis) !important;"></i>
                            </div>
                        </div>
                        <div class="fw-bold text-light mb-1" style="font-size: var(--fs-card-val);">R$ <?php echo number_format($saldoAtual ?? 0, 2, ',', '.') ?></div>
                        <div class="d-flex justify-content-between align-items-center mt-2">
                            <small class="text-secondary">Total disponível hoje</small>
                            <button class="btn btn-sm btn-link text-secondary p-0 transition-hover" data-bs-toggle="modal" data-bs-target="#modalAjusteSaldo" title="Ajustar Saldo Real">
                                <i class="bi bi-pencil-square"></i>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
            <!-- Receitas -->
            <div class="col-6 col-md-4">
                <div class="card bg-body-tertiary border-secondary-subtle shadow-sm h-100 rounded-4">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <p class="text-secondary fw-semibold mb-0 small">Receitas (<?php echo $nome_mes ?>)</p>
                            <div class="bg-success bg-opacity-10 p-2 rounded-3 flex-shrink-0 d-none d-sm-flex">
                                <i class="bi bi-graph-up-arrow text-success"></i>
                            </div>
                        </div>
                        <div class="fw-bold text-success mb-1" style="font-size: var(--fs-card-val);">R$ <?php echo number_format($receitasMes ?? 0, 2, ',', '.') ?></div>
                        <div class="mt-2 d-flex align-items-center flex-wrap gap-1">
                            <?php echo badgeVar($receitasMes, $receitasMesAnt, false); ?>
                            <?php if ($receitasPendentes > 0): ?>
                                <span style="display:inline-flex;align-items:center;background:#FFB80022;color:#FFB800;border:1px solid #FFB80055;border-radius:999px;padding:1px 7px;font-size:0.68rem;font-weight:600;" title="A receber este mês">
                                    <i class="bi bi-clock me-1"></i>+ R$ <?php echo number_format($receitasPendentes, 2, ',', '.') ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            <!-- Despesas -->
            <div class="col-6 col-md-4">
                <div class="card bg-body-tertiary border-secondary-subtle shadow-sm h-100 rounded-4">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <p class="text-secondary fw-semibold mb-0 small">Despesas (<?php echo $nome_mes ?>)</p>
                            <div class="bg-danger bg-opacity-10 p-2 rounded-3 flex-shrink-0 d-none d-sm-flex">
                                <i class="bi bi-graph-down-arrow text-danger"></i>
                            </div>
                        </div>
                        <div class="fw-bold text-danger mb-1" style="font-size: var(--fs-card-val);">R$ <?php echo number_format($despesasMes ?? 0, 2, ',', '.') ?></div>
                        <div class="mt-2 d-flex align-items-center flex-wrap gap-1">
                            <?php echo badgeVar($despesasMes, $despesasMesAnt, true); ?>
                            <?php if ($despesasPendentes > 0): ?>
                                <span style="display:inline-flex;align-items:center;background:#FFB80022;color:#FFB800;border:1px solid #FFB80055;border-radius:999px;padding:1px 7px;font-size:0.68rem;font-weight:600;" title="A pagar este mês">
                                    <i class="bi bi-clock me-1"></i>+ R$ <?php echo number_format($despesasPendentes, 2, ',', '.') ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── Barra de Gastos Esperados ──────────────────────────────────── -->
        <?php if ($despesasPendentes > 0 || $receitasPendentes > 0): ?>
            <div class="card bg-body-tertiary border-secondary-subtle rounded-4 shadow-sm mb-4">
                <div class="card-body py-3 px-3">
                    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
                        <div class="d-flex align-items-center gap-2">
                            <i class="bi bi-hourglass-split text-warning"></i>
                            <span class="fw-semibold text-light" style="font-size:0.875rem;">Aguardando confirmação em <?php echo $nome_mes ?></span>
                        </div>
                        <div class="d-flex flex-wrap gap-3">
                            <?php if ($receitasPendentes > 0): ?>
                                <div class="d-flex align-items-center gap-2">
                                    <span class="text-secondary small">A receber:</span>
                                    <span class="fw-bold text-success" style="font-size:0.9rem;">R$ <?php echo number_format($receitasPendentes, 2, ',', '.') ?></span>
                                </div>
                            <?php endif; ?>
                            <?php if ($despesasPendentes > 0): ?>
                                <div class="d-flex align-items-center gap-2">
                                    <span class="text-secondary small">A pagar:</span>
                                    <span class="fw-bold text-danger" style="font-size:0.9rem;">R$ <?php echo number_format($despesasPendentes, 2, ',', '.') ?></span>
                                </div>
                            <?php endif; ?>
                            <?php
                            $projecaoSaldo = $saldoAtual + $receitasPendentes - $despesasPendentes;
                            ?>
                            <div class="d-flex align-items-center gap-2 border-start border-secondary-subtle ps-3">
                                <span class="text-secondary small">Saldo projetado:</span>
                                <span class="fw-bold <?php echo $projecaoSaldo >= 0 ? 'text-light' : 'text-danger' ?>" style="font-size:0.9rem;">
                                    R$ <?php echo number_format($projecaoSaldo, 2, ',', '.') ?>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <h4 class="fw-bold text-light mb-3 mt-4">Transações de <?php echo $nome_mes ?></h4>

        <div class="table-responsive rounded-4 border border-secondary-subtle shadow-sm mb-5">
            <table class="table table-dark table-hover align-middle mb-0 auralis-table">
                <thead class="table-active border-secondary-subtle text-secondary small text-uppercase">
                    <tr>
                        <th class="ps-3 ps-md-4 py-3 border-0">Descrição</th>
                        <th class="py-3 border-0 d-none d-md-table-cell">Categoria</th>
                        <th class="py-3 border-0 d-none d-md-table-cell">Data</th>
                        <th class="py-3 border-0 d-none d-md-table-cell">Status</th>
                        <th class="text-end pe-3 pe-md-4 py-3 border-0">Valor</th>
                    </tr>
                </thead>
                <tbody class="border-top-0">
                    <?php foreach ($transacoes as $index => $t):
                        $isDespesa     = ($t['TipoRegistro'] === 'despesa');
                        $sinalValor    = $isDespesa ? '-' : '+';
                        $corValor      = $isDespesa ? 'text-danger' : 'text-success';
                        $dataFormatada = date('d/m/Y', strtotime($t['MomentoRegistro']));
                        $iconeTipo     = $isDespesa ? '<i class="bi bi-arrow-down-short fs-5 text-danger bg-danger bg-opacity-10 rounded-circle p-1 me-3"></i>'
                            : '<i class="bi bi-arrow-up-short fs-5 text-success bg-success bg-opacity-10 rounded-circle p-1 me-3"></i>';

                        $rowId           = "transacao-" . $index;
                        $isPendente      = ($t['StatusRegistro'] === 'pendente');
                        $textoAcaoStatus = $isDespesa ? 'Marcar como Pago' : 'Marcar como Recebido';
                    ?>
                        <tr data-bs-toggle="collapse" data-bs-target="#<?php echo $rowId ?>" class="cursor-pointer transition-hover" style="cursor: pointer;">

                            <td class="ps-3 ps-md-4 py-3 border-secondary-subtle">
                                <div class="d-flex align-items-center gap-2">
                                    <?php echo $iconeTipo ?>
                                    <div>
                                        <span class="text-light fw-semibold">
                                            <?php if ($t['Recorrente'] == 1): ?>
                                                <i class="bi bi-arrow-repeat me-1" style="color: var(--primary-gold-analysis);" title="Conta Recorrente"></i>
                                            <?php endif; ?>
                                            <?php echo htmlspecialchars($t['Descricao']) ?>
                                        </span>

                                        <div class="mt-1 d-flex flex-wrap gap-1 align-items-center">

                                            <div class="d-md-none">
                                                <?php if ($isPendente): ?>
                                                    <span class="badge bg-warning text-dark px-1 py-1" style="font-size: 0.6rem;"><i class="bi bi-clock-history"></i> Pendente</span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary bg-opacity-25 text-light px-1 py-1" style="font-size: 0.6rem;"><i class="bi bi-check2-circle"></i> Efetivado</span>
                                                <?php endif; ?>
                                            </div>

                                            <?php if (!empty($t['TotalParcelas']) && $t['TotalParcelas'] > 1): ?>
                                                <span class="badge bg-secondary bg-opacity-25 text-secondary px-1 py-1" style="font-size:0.6rem;">
                                                    <i class="bi bi-credit-card-2-front"></i> <?php echo $t['ParcelaAtual'] ?>/<?php echo $t['TotalParcelas'] ?>
                                                </span>
                                            <?php endif; ?>

                                        </div>
                                    </div>
                                </div>
                            </td>

                            <td class="py-3 border-secondary-subtle text-secondary small d-none d-md-table-cell">
                                <div class="d-flex align-items-center">
                                    <i class="bi <?php echo htmlspecialchars($t['IconeCategoria'] ?? 'bi-tag') ?> me-2 fs-6"></i>
                                    <span><?php echo htmlspecialchars($t['NomeCategoria'] ?? 'Sem categoria') ?></span>
                                </div>
                            </td>

                            <td class="py-3 border-secondary-subtle text-secondary small d-none d-md-table-cell">
                                <?php echo $dataFormatada ?>
                            </td>

                            <td class="py-3 border-secondary-subtle d-none d-md-table-cell">
                                <?php if ($isPendente): ?>
                                    <span class="badge bg-warning text-dark px-2 py-1 rounded-pill fw-semibold shadow-sm"><i class="bi bi-clock-history me-1"></i> Pendente</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary bg-opacity-25 text-light px-2 py-1 rounded-pill"><i class="bi bi-check2-circle me-1"></i> Efetivado</span>
                                <?php endif; ?>
                            </td>

                            <td class="text-end pe-3 pe-md-4 py-3 border-secondary-subtle fw-bold <?php echo $corValor ?>">
                                <?php echo $sinalValor ?> R$ <?php echo number_format($t['Valor'], 2, ',', '.') ?>
                            </td>
                        </tr>

                        <tr class="border-0" style="border: 0 !important;">
                            <td colspan="5" class="p-0 border-0" style="border: 0 !important;">
                                <div class="collapse" id="<?php echo $rowId ?>">
                                    <div class="p-3 p-md-4 bg-charcoal-analysis border-bottom border-secondary-subtle d-flex flex-column flex-md-row justify-content-between align-items-start gap-3">

                                        <div class="d-flex gap-4 w-100 w-md-auto">
                                            <?php $labelData = $isDespesa ? 'Vencimento' : 'Recebimento'; ?>
                                            <div>
                                                <span class="d-block text-secondary small text-uppercase mb-1"><?php echo $labelData ?></span>
                                                <span class="text-light fs-6">
                                                    <?php echo (! empty($t['DataVencimento']) && strtotime($t['DataVencimento'])) ? date('d/m/Y', strtotime($t['DataVencimento'])) : '<span class="text-muted">Não definido</span>' ?>
                                                </span>
                                            </div>

                                            <?php if ($t['Recorrente'] == 1): ?>
                                                <div>
                                                    <span class="d-block text-secondary small text-uppercase mb-1">Recorrência</span>
                                                    <span class="text-light fs-6">Sim (Dia <?php echo htmlspecialchars($t['DiaVencimento']); ?>)</span>
                                                </div>
                                            <?php elseif (!empty($t['TotalParcelas']) && $t['TotalParcelas'] > 1): ?>
                                                <div>
                                                    <span class="d-block text-secondary small text-uppercase mb-1">Parcelado</span>
                                                    <span class="text-light fs-6">Parcela <?php echo $t['ParcelaAtual']; ?> de <?php echo $t['TotalParcelas']; ?></span>
                                                </div>
                                            <?php endif; ?>
                                        </div>

                                        <div class="d-flex gap-2 w-100 w-md-auto justify-content-end">
                                            <form method="POST" action="" class="m-0">
                                                <input type="hidden" name="action" value="toggle_status">
                                                <input type="hidden" name="registro_id" value="<?php echo $t['IDRegistro'] ?>">
                                                <?php if ($isPendente): ?>
                                                    <input type="hidden" name="novo_status" value="efetivado">
                                                    <button type="submit" class="btn btn-sm btn-outline-success rounded-pill fw-semibold px-3 d-inline-flex align-items-center gap-1 w-100 justify-content-center">
                                                        <i class="bi bi-check-circle"></i> <span class="d-none d-sm-inline"><?php echo $textoAcaoStatus ?></span>
                                                    </button>
                                                <?php else: ?>
                                                    <input type="hidden" name="novo_status" value="pendente">
                                                    <button type="submit" class="btn btn-sm btn-outline-secondary rounded-pill fw-semibold px-3 d-inline-flex align-items-center gap-1 w-100 justify-content-center" title="Desfazer">
                                                        <i class="bi bi-arrow-counterclockwise"></i> <span class="d-none d-sm-inline">Desfazer</span>
                                                    </button>
                                                <?php endif; ?>
                                            </form>

                                            <a href="nova_transacao.php?editar=<?php echo $t['IDRegistro'] ?>" class="btn btn-sm btn-outline-warning rounded-pill fw-semibold px-3 d-inline-flex align-items-center gap-1 transition-hover">
                                                <i class="bi bi-pencil-square"></i> <span class="d-none d-sm-inline">Editar</span>
                                            </a>

                                            <?php
                                            // Identifica o tipo de transação
                                            $is_recorrente = ($t['Recorrente'] == 1 && !empty($t['GrupoParcela']) && empty($t['TotalParcelas']));
                                            $is_parcelado  = (!empty($t['TotalParcelas']) && $t['TotalParcelas'] > 1 && !empty($t['GrupoParcela']));

                                            if ($is_recorrente):
                                            ?>
                                                <!-- BOTÃO: EXCLUIR RECORRENTE -->
                                                <button type="button"
                                                    class="btn btn-sm btn-outline-danger rounded-pill fw-semibold px-3 d-inline-flex align-items-center gap-1 transition-hover"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#modalExcluirRecorrente"
                                                    data-id="<?php echo $t['IDRegistro'] ?>"
                                                    data-grupo="<?php echo $t['GrupoParcela'] ?>"
                                                    data-data="<?php echo $t['MomentoRegistro'] ?>">
                                                    <i class="bi bi-trash3"></i> <span class="d-none d-sm-inline">Excluir</span>
                                                </button>

                                            <?php elseif ($is_parcelado): ?>
                                                <!-- BOTÃO: EXCLUIR PARCELADO -->
                                                <button type="button"
                                                    class="btn btn-sm btn-outline-danger rounded-pill fw-semibold px-3 d-inline-flex align-items-center gap-1 transition-hover"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#modalExcluirParcelado"
                                                    data-id="<?php echo $t['IDRegistro'] ?>"
                                                    data-grupo="<?php echo $t['GrupoParcela'] ?>"
                                                    data-parcela="<?php echo $t['ParcelaAtual'] ?>">
                                                    <i class="bi bi-trash3"></i> <span class="d-none d-sm-inline">Excluir</span>
                                                </button>

                                            <?php else: ?>
                                                <!-- BOTÃO: EXCLUIR NORMAL -->
                                                <button type="button"
                                                    class="btn btn-sm btn-outline-danger rounded-pill fw-semibold px-3 d-inline-flex align-items-center gap-1 transition-hover"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#modalExcluirNormal"
                                                    data-id="<?php echo $t['IDRegistro'] ?>">
                                                    <i class="bi bi-trash3"></i> <span class="d-none d-sm-inline">Excluir</span>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</main>

<!-- ======================================================================= -->
<!-- TODOS OS MODAIS DO DASHBOARD -->
<!-- ======================================================================= -->

<!-- MODAL: AJUSTE DE SALDO -->
<div class="modal fade" id="modalAjusteSaldo" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content bg-dark border-secondary-subtle shadow-lg rounded-4">
            <div class="modal-header border-bottom border-secondary-subtle">
                <h5 class="modal-title text-light fw-bold">
                    <i class="bi bi-sliders me-2 text-primary" style="color: var(--primary-gold-analysis) !important;"></i> Ajustar Saldo Real
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="">
                <div class="modal-body p-4">
                    <p class="text-secondary small mb-4">
                        Se o saldo do aplicativo estiver diferente do saldo do seu banco, informe o valor real abaixo. O Auralis criará um registro de ajuste automático para corrigir a diferença.
                    </p>

                    <input type="hidden" name="action" value="ajustar_saldo">
                    <input type="hidden" name="carteira_id_ajuste" value="<?php echo htmlspecialchars($carteira_selecionada ?? ''); ?>">
                    <input type="hidden" name="saldo_sistema_atual" value="<?php echo htmlspecialchars($saldoAtual ?? 0); ?>">

                    <div class="mb-3">
                        <label class="form-label text-secondary small">Qual o seu saldo exato hoje?</label>
                        <div class="input-group input-group-lg">
                            <span class="input-group-text bg-transparent border-secondary-subtle text-light fw-bold">R$</span>
                            <input type="number" step="0.01" name="saldo_real" class="form-control bg-transparent border-secondary-subtle text-light fw-bold shadow-none no-spinners" required placeholder="0,00" value="<?php echo number_format($saldoAtual ?? 0, 2, '.', '') ?>" autofocus>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-top border-secondary-subtle d-flex justify-content-between">
                    <button type="button" class="btn btn-link text-secondary text-decoration-none" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn fw-bold text-dark px-4 rounded-pill" style="background: linear-gradient(135deg, #FFB800 0%, #D4AF37 100%);">
                        Corrigir Saldo
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- MODAL: SELETOR DE MÊS -->
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
                <div class="d-flex justify-content-center align-items-center mb-4 bg-charcoal-analysis rounded-pill p-1 border border-secondary-subtle">
                    <button type="button" class="btn btn-sm btn-link text-secondary shadow-none" onclick="mudarAnoModal(-1)">
                        <i class="bi bi-chevron-left fs-5"></i>
                    </button>
                    <input type="number" id="anoModalInput" class="form-control bg-transparent border-0 text-light fw-bold text-center fs-4 mx-2 no-spinners shadow-none" style="width: 90px;" value="<?= $ano_atual ?>" readonly>
                    <button type="button" class="btn btn-sm btn-link text-secondary shadow-none" onclick="mudarAnoModal(1)">
                        <i class="bi bi-chevron-right fs-5"></i>
                    </button>
                </div>
                <div class="row g-2">
                    <?php
                    $mesesAbrev = [1 => 'Jan', 2 => 'Fev', 3 => 'Mar', 4 => 'Abr', 5 => 'Mai', 6 => 'Jun', 7 => 'Jul', 8 => 'Ago', 9 => 'Set', 10 => 'Out', 11 => 'Nov', 12 => 'Dez'];
                    foreach ($mesesAbrev as $num => $nome):
                        $isAtual = ($num == $mes_atual) ? 'btn-gold text-dark' : 'btn-outline-secondary text-light';
                    ?>
                        <div class="col-4">
                            <button type="button" class="btn w-100 <?= $isAtual ?> fw-semibold py-2 transition-hover" onclick="irParaMes(<?= $num ?>)">
                                <?= $nome ?>
                            </button>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</div>


<!-- MODAL: EXCLUIR RECORRENTE -->

<div class="modal fade" id="modalExcluirRecorrente" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm" style="max-width: 400px;">
        <div class="modal-content bg-dark border-secondary-subtle shadow-lg rounded-4">
            <div class="modal-header border-bottom border-secondary-subtle p-3">
                <h6 class="modal-title text-light fw-bold">
                    <i class="bi bi-trash3 me-2 text-danger"></i> Excluir Recorrência
                </h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="">
                <div class="modal-body p-4">
                    <p class="text-secondary small mb-3">
                        Esta é uma transação recorrente. Escolha a opção de exclusão ideal:
                    </p>
                    <input type="hidden" name="action" value="excluir_recorrente_grupo">
                    <input type="hidden" name="registro_id" id="excluir_recorrente_id">
                    <input type="hidden" name="grupo_parcela" id="excluir_grupo_id">
                    <input type="hidden" name="momento_registro" id="excluir_data_base">

                    <div class="form-check mb-3">
                        <input class="form-check-input" type="radio" name="tipo_exclusao" id="excluir_apenas_este" value="apenas_este" checked>
                        <label class="form-check-label text-light fw-semibold fs-7" for="excluir_apenas_este">
                            Excluir apenas este mês
                        </label>
                        <div class="text-secondary opacity-75" style="font-size: 0.75rem;">Os demais meses futuros continuam ativos.</div>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="tipo_exclusao" id="excluir_todos_futuros" value="futuros">
                        <label class="form-check-label text-light fw-semibold fs-7" for="excluir_todos_futuros">
                            Excluir este e os meses futuros pendentes
                        </label>
                        <div class="text-secondary opacity-75" style="font-size: 0.75rem;">Remove esta transação e todas as projeções não pagas/recebidas adiante.</div>
                    </div>
                </div>
                <div class="modal-footer border-top border-secondary-subtle d-flex justify-content-between p-2">
                    <button type="button" class="btn btn-sm btn-link text-secondary text-decoration-none" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-sm btn-danger fw-bold px-3 rounded-pill">
                        Confirmar Exclusão
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- MODAL: EXCLUIR PARCELADO -->
<div class="modal fade" id="modalExcluirParcelado" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm" style="max-width: 400px;">
        <div class="modal-content bg-dark border-secondary-subtle shadow-lg rounded-4">
            <div class="modal-header border-bottom border-secondary-subtle p-3">
                <h6 class="modal-title text-light fw-bold">
                    <i class="bi bi-credit-card-2-front me-2 text-danger"></i> Excluir Parcelamento
                </h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="">
                <div class="modal-body p-4">
                    <p class="text-secondary small mb-3">
                        Esta transação faz parte de uma compra parcelada. Escolha a opção ideal:
                    </p>
                    <input type="hidden" name="action" value="excluir_parcelado_grupo">
                    <input type="hidden" name="registro_id" id="excluir_parcelado_id">
                    <input type="hidden" name="grupo_parcela" id="excluir_parcelado_grupo_id">
                    <input type="hidden" name="parcela_atual" id="excluir_parcela_atual">

                    <div class="form-check mb-3">
                        <input class="form-check-input" type="radio" name="tipo_exclusao" id="excluir_apenas_esta_parcela" value="apenas_este" checked>
                        <label class="form-check-label text-light fw-semibold fs-7" for="excluir_apenas_esta_parcela">
                            Excluir apenas esta parcela
                        </label>
                        <div class="text-secondary opacity-75" style="font-size: 0.75rem;">As outras parcelas continuarão ativas no sistema.</div>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="tipo_exclusao" id="excluir_parcelas_futuras" value="futuros">
                        <label class="form-check-label text-light fw-semibold fs-7" for="excluir_parcelas_futuras">
                            Excluir esta e as próximas parcelas
                        </label>
                        <div class="text-secondary opacity-75" style="font-size: 0.75rem;">Apaga esta transação e todas as parcelas restantes.</div>
                    </div>
                </div>
                <div class="modal-footer border-top border-secondary-subtle d-flex justify-content-between p-2">
                    <button type="button" class="btn btn-sm btn-link text-secondary text-decoration-none" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-sm btn-danger fw-bold px-3 rounded-pill">
                        Confirmar Exclusão
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- MODAL: EXCLUIR NORMAL -->

<div class="modal fade" id="modalExcluirNormal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm" style="max-width: 400px;">
        <div class="modal-content bg-dark border-secondary-subtle shadow-lg rounded-4">
            <div class="modal-header border-bottom border-secondary-subtle p-3">
                <h6 class="modal-title text-light fw-bold">
                    <i class="bi bi-trash3 me-2 text-danger"></i> Excluir Transação
                </h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="">
                <div class="modal-body p-4 text-center">
                    <p class="text-secondary mb-0">Tem certeza que deseja excluir esta transação? Essa ação não pode ser desfeita.</p>
                    <input type="hidden" name="action" value="excluir_registro">
                    <input type="hidden" name="registro_id" id="excluir_normal_id">
                </div>
                <div class="modal-footer border-top border-secondary-subtle d-flex justify-content-between p-2">
                    <button type="button" class="btn btn-sm btn-link text-secondary text-decoration-none" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-sm btn-danger fw-bold px-3 rounded-pill">
                        Confirmar Exclusão
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<!-- ======================================================================= -->
<!-- MODAIS DE ONBOARDING (PRIMEIRO ACESSO) -->
<!-- ======================================================================= -->

<?php $primeiroNome = explode(' ', $_SESSION['usuario_nome'] ?? 'Visitante')[0]; ?>

<!-- ONBOARDING 1: CRIAR CARTEIRA -->
<div class="modal fade" id="modalPrimeiraCarteira" tabindex="-1" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content modal-boas-vindas-content border-0 rounded-4 overflow-hidden position-relative">
            <div class="position-absolute top-0 start-0 w-100 h-100" style="background: radial-gradient(circle at top left, rgba(170, 140, 44, 0.15), transparent 60%); pointer-events: none;"></div>

            <div class="modal-body p-5 text-center position-relative z-1">
                <div class="mb-4 d-inline-flex justify-content-center align-items-center bg-dark border border-secondary-subtle rounded-circle shadow-lg" style="width: 90px; height: 90px;">
                    <i class="bi bi-wallet2 text-primary" style="color: var(--primary-gold-analysis) !important; font-size: 2.5rem;"></i>
                </div>

                <h2 class="text-light fw-bold mb-3">Bem-vindo(a) ao Auralis, <?php echo htmlspecialchars($primeiroNome) ?>!</h2>
                <p class="text-secondary fs-5 mb-5 mx-auto" style="max-width: 600px;">
                    O primeiro passo para o controle absoluto é organizar onde o seu dinheiro fica. Vamos criar o seu primeiro espaço financeiro.
                </p>

                <form method="POST" action="carteira/processa_carteira.php" class="bg-dark border border-secondary-subtle rounded-4 p-4 text-start mx-auto shadow-sm" style="max-width: 500px;">
                    <label class="form-label text-light fw-semibold mb-2 fs-5">Como quer chamar sua conta principal?</label>
                    <div class="input-group input-group-lg mb-4 shadow-sm">
                        <span class="input-group-text bg-body-tertiary border-secondary-subtle text-secondary border-end-0"><i class="bi bi-tag-fill"></i></span>
                        <input type="text" name="tipo_carteira" class="form-control bg-body-tertiary border-secondary-subtle border-start-0 text-light fw-bold shadow-none fs-5 py-3" required value="Minha Carteira" autofocus>
                    </div>
                    <button type="submit" class="btn btn-gold btn-lg w-100 fw-bold text-dark rounded-pill py-3 shadow-lg transition-hover">
                        Criar Conta e Avançar <i class="bi bi-arrow-right ms-2"></i>
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- ONBOARDING 2: SALDO INICIAL -->
<div class="modal fade" id="modalBoasVindas" tabindex="-1" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content modal-boas-vindas-content border-0 rounded-4 overflow-hidden position-relative">
            <div class="position-absolute top-0 start-0 w-100 h-100" style="background: radial-gradient(circle at top right, rgba(170, 140, 44, 0.15), transparent 60%); pointer-events: none;"></div>

            <div class="modal-body p-5 text-center position-relative z-1">
                <div class="mb-4 d-inline-flex justify-content-center align-items-center bg-dark border border-secondary-subtle rounded-circle shadow-lg" style="width: 90px; height: 90px;">
                    <i class="bi bi-rocket-takeoff text-primary" style="color: var(--primary-gold-analysis) !important; font-size: 2.5rem;"></i>
                </div>

                <h2 class="text-light fw-bold mb-3">Tudo pronto, <?php echo htmlspecialchars($primeiroNome) ?>!</h2>
                <p class="text-secondary fs-5 mb-5 mx-auto" style="max-width: 650px;">
                    Sua carteira <strong>"<?php echo htmlspecialchars($nome_carteira_atual ?? ''); ?>"</strong> está pronta! Para que o Auralis calcule tudo com precisão desde o primeiro dia, precisamos conhecer a sua realidade hoje. <strong>Some todo o dinheiro que você tem agora</strong> (seja no saldo do banco, na gaveta ou na carteira física) e insira o valor total abaixo. Esse será o nosso ponto de partida.
                </p>

                <div class="bg-dark border border-secondary-subtle rounded-4 p-4 text-start mx-auto shadow-sm" style="max-width: 500px;">
                    <label class="form-label text-light fw-semibold mb-3 fs-5 d-block text-center">
                        Qual o seu saldo total exato hoje nesta conta?
                    </label>

                    <form method="POST" action="">
                        <input type="hidden" name="action" value="ajustar_saldo">
                        <input type="hidden" name="carteira_id_ajuste" value="<?php echo htmlspecialchars($carteira_selecionada ?? ''); ?>">
                        <input type="hidden" name="saldo_sistema_atual" value="0">

                        <div class="input-group input-group-lg mb-4 shadow-sm">
                            <span class="input-group-text bg-body-tertiary border-secondary-subtle text-primary fw-bold border-end-0 fs-4" style="color: var(--primary-gold-analysis) !important;">R$</span>
                            <input type="number" step="0.01" name="saldo_real" class="form-control bg-body-tertiary border-secondary-subtle border-start-0 text-light fw-bold shadow-none no-spinners fs-3 py-3" required placeholder="0,00" autofocus>
                        </div>

                        <button type="submit" class="btn btn-gold btn-lg w-100 fw-bold text-dark rounded-pill py-3 shadow-lg transition-hover">
                            Iniciar Minha Jornada
                        </button>

                        <div class="text-center mt-4">
                            <!-- O TRUQUE: Esse botão preenche "0" invisivelmente e salva, libertando o usuário! -->
                            <button type="button" class="btn btn-link text-secondary text-decoration-none small transition-hover"
                                onclick="document.querySelector('input[name=\'saldo_real\']').value = '0'; this.closest('form').submit();">
                                Pular por enquanto (Começar zerado)
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
    .bg-charcoal-analysis {
        background-color: #1a1d21;
    }

    .auralis-table>tbody>tr.cursor-pointer:hover>td {
        background-color: rgba(255, 255, 255, 0.03) !important;
    }

    .table-active {
        background-color: #1a1d21 !important;
    }

    .no-spinners::-webkit-outer-spin-button,
    .no-spinners::-webkit-inner-spin-button {
        -webkit-appearance: none;
        margin: 0;
    }

    .no-spinners {
        -moz-appearance: textfield;
    }

    /* Estilos Acrílicos do Onboarding */
    #modalPrimeiraCarteira,
    #modalBoasVindas {
        backdrop-filter: blur(12px);
        -webkit-backdrop-filter: blur(12px);
        background-color: rgba(0, 0, 0, 0.65);
    }

    .modal-boas-vindas-content {
        background-color: #181A1F !important;
        border: 1px solid rgba(255, 255, 255, 0.08) !important;
        box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.7);
    }

    .btn-gold {
        background: linear-gradient(135deg, #FFB800 0%, #D4AF37 100%);
        border: none;
    }

    .btn-gold:hover {
        background: linear-gradient(135deg, #FFD04F 0%, #E7C665 100%);
        color: #000 !important;
        box-shadow: 0 6px 20px rgba(212, 175, 55, 0.4) !important;
    }
</style>

<script>
    // Limpeza da URL para não repetir alertas no F5
    if (window.history.replaceState) {
        const url = new URL(window.location);
        if (url.searchParams.has('sucesso')) {
            url.searchParams.delete('sucesso');
            window.history.replaceState({
                path: url.href
            }, '', url.href);
        }
    }

    // Scripts do Seletor de Mês
    function mudarAnoModal(delta) {
        const inputAno = document.getElementById('anoModalInput');
        inputAno.value = parseInt(inputAno.value) + delta;
    }

    function irParaMes(mes) {
        const ano = document.getElementById('anoModalInput').value;
        const urlParams = new URLSearchParams(window.location.search);
        urlParams.set('mes', mes);
        urlParams.set('ano', ano);
        window.location.search = urlParams.toString();
    }

    // =======================================================================
    // MOTOR DE DISPARO DO ONBOARDING
    // =======================================================================
    document.addEventListener("DOMContentLoaded", function() {
        <?php if ($totalCarteiras == 0): ?>
            // Cena 1: Se não tem carteira, mostra Modal de Criar
            var modal1 = new bootstrap.Modal(document.getElementById('modalPrimeiraCarteira'), {
                backdrop: 'static',
                keyboard: false
            });
            modal1.show();
        <?php elseif ($is_primeiro_acesso): ?>
            // Cena 2: Já tem carteira, mas não tem saldo inicial? Mostra Modal de Boas Vindas
            var modal2 = new bootstrap.Modal(document.getElementById('modalBoasVindas'), {
                backdrop: 'static',
                keyboard: false
            });
            modal2.show();
        <?php endif; ?>
    });
    // Script para alimentar o Modal de Exclusão de Recorrência
    const modalExcluirRecorrente = document.getElementById('modalExcluirRecorrente');
    if (modalExcluirRecorrente) {
        modalExcluirRecorrente.addEventListener('show.bs.modal', function(event) {
            const button = event.relatedTarget;

            const id = button.getAttribute('data-id');
            const grupo = button.getAttribute('data-grupo');
            const data = button.getAttribute('data-data');

            document.getElementById('excluir_recorrente_id').value = id;
            document.getElementById('excluir_grupo_id').value = grupo;
            document.getElementById('excluir_data_base').value = data;
        });
    }

    const modalExcluirNormal = document.getElementById('modalExcluirNormal');
    if (modalExcluirNormal) {
        modalExcluirNormal.addEventListener('show.bs.modal', function(event) {
            const button = event.relatedTarget;
            document.getElementById('excluir_normal_id').value = button.getAttribute('data-id');
        });
    }

    // Script Modal Exclusão de Compra Parcelada
    const modalExcluirParcelado = document.getElementById('modalExcluirParcelado');
    if (modalExcluirParcelado) {
        modalExcluirParcelado.addEventListener('show.bs.modal', function(event) {
            const button = event.relatedTarget;
            document.getElementById('excluir_parcelado_id').value = button.getAttribute('data-id');
            document.getElementById('excluir_parcelado_grupo_id').value = button.getAttribute('data-grupo');
            document.getElementById('excluir_parcela_atual').value = button.getAttribute('data-parcela');
        });
    }
</script>

<?php require_once 'geral/footer.php'; ?>