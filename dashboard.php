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

    // Função auxiliar para gerar UUID no padrão MySQL
    if (!function_exists('gerarUuid')) {
        function gerarUuid() {
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
            $sqlRec  = "SELECT * FROM Registro WHERE FKUsuario = :uid AND Recorrente = 1 AND DATE_FORMAT(MomentoRegistro, '%Y-%m') = :mes_ant";
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
    } catch (PDOException $e) {}

    // --- LÓGICA DE NAVEGAÇÃO DE TEMPO ---
    $mes_atual = isset($_GET['mes']) ? (int) $_GET['mes'] : (int) date('m');
    $ano_atual = isset($_GET['ano']) ? (int) $_GET['ano'] : (int) date('Y');

    $mes_ant = $mes_atual - 1;
    $ano_ant = $ano_atual;
    if ($mes_ant < 1) { $mes_ant = 12; $ano_ant--; }

    $mes_prox = $mes_atual + 1;
    $ano_prox = $ano_atual;
    if ($mes_prox > 12) { $mes_prox = 1; $ano_prox++; }

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
                } catch (PDOException $e) {}
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
            } catch (PDOException $e) {}
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
                } catch (PDOException $e) {}
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
                    c.NomeCategoria, c.IconeCategoria
                FROM Registro r
                LEFT JOIN Categoria c ON r.FKCategoria = c.IDCategoria
                WHERE r.FKCarteira = :carteira_id
                  AND r.FKUsuario = :usuario_id
                  AND MONTH(r.MomentoRegistro) = :mes
                  AND YEAR(r.MomentoRegistro) = :ano
                ORDER BY r.MomentoRegistro DESC, r.created_at DESC
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

        } catch (PDOException $e) {}
    }

    require_once 'geral/header.php';
?>

<main class="container py-4 mt-3 flex-grow-1" style="min-height: 100vh;">

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
            ?>

            <?php if ($msg): ?>
            <div class="alert alert-success d-flex align-items-center gap-2 rounded-3 shadow-sm border-0 bg-success bg-opacity-10 text-success fw-semibold alert-dismissible fade show" role="alert">
                <i class="bi bi-check-circle-fill"></i> <span><?php echo $msg ?></span>
                <button type="button" class="btn-close btn-close-white opacity-50" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php endif; ?>
        <?php endif; ?>

        <div class="d-flex justify-content-between align-items-center mb-4 border-bottom border-secondary-subtle pb-3 flex-wrap gap-3">
            <div class="d-flex align-items-center gap-4">
                <h2 class="fw-bold text-light mb-0">Visão Geral</h2>

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

            <div class="d-flex gap-2">
                <div class="d-flex align-items-center gap-3">
<div class="dropdown">
    <button class="btn border-secondary-subtle text-light shadow-sm fw-semibold dropdown-toggle d-flex justify-content-between align-items-center rounded-3 transition-hover"
            type="button"
            data-bs-toggle="dropdown"
            aria-expanded="false"
            style="width: 220px; background-color: var(--bg-charcoal-analysis);">

        <span class="text-truncate d-flex align-items-center">
            <i class="bi bi-wallet2 me-2" style="color: var(--primary-gold-analysis);"></i>
            <?php echo htmlspecialchars($nome_carteira_atual); ?>
        </span>
    </button>

    <ul class="dropdown-menu dropdown-menu-dark shadow-lg border-secondary-subtle mt-2 w-100" style="background-color: #1a1d21;">
        <li class="px-3 py-1 text-secondary small text-uppercase fw-bold tracking-wide">Alternar Carteira</li>
        <li><hr class="dropdown-divider border-secondary-subtle"></li>

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

                    <div class="vr bg-secondary opacity-25 mx-1 d-none d-md-block"></div>

                    <div class="d-flex gap-2">
                        <a href="nova_transacao.php?carteira_id=<?php echo urlencode($carteira_selecionada) ?>&tipo=receita"
                            class="btn btn-outline-success fw-bold d-flex align-items-center px-3 rounded-pill transition-hover shadow-sm">
                            <i class="bi bi-arrow-up-short fs-5"></i> <span class="d-none d-sm-inline ms-1">Receita</span>
                        </a>

                        <a href="nova_transacao.php?carteira_id=<?php echo urlencode($carteira_selecionada) ?>&tipo=despesa"
                            class="btn btn-outline-danger fw-bold d-flex align-items-center px-3 rounded-pill transition-hover shadow-sm">
                            <i class="bi bi-arrow-down-short fs-5"></i> <span class="d-none d-sm-inline ms-1">Despesa</span>
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-4 mb-5">
            <div class="col-md-4">
                <div class="card bg-body-tertiary border-secondary-subtle shadow-sm h-100 rounded-4">
                    <div class="card-body p-4 position-relative">
                        <style>
                            .inferiorDireito{
                                position: absolute;
                                bottom: 10px;
                                right: 10px;
                            }
                        </style>
                        <button class="btn btn-sm btn-outline-secondary position-absolute inferiorDireito m-3 rounded-pill transition-hover border-0 shadow-none"
                                data-bs-toggle="modal" data-bs-target="#modalAjusteSaldo" title="Ajustar Saldo Real">
                            <i class="bi bi-pencil-square fs-5"></i>
                        </button>

                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h6 class="card-title text-secondary mb-0 fw-semibold pe-4">Saldo: <?php echo htmlspecialchars($nome_carteira_atual); ?></h6>
                            <div class="p-2 bg-primary bg-opacity-10 rounded-3">
                                <i class="bi bi-wallet2 text-primary fs-5"></i>
                            </div>
                        </div>
                        <h3 class="fw-bold mb-1 <?php echo $saldoAtual < 0 ? 'text-danger' : 'text-light' ?>">
                            R$ <?php echo number_format($saldoAtual, 2, ',', '.') ?>
                        </h3>
                        <p class="text-secondary small mb-0">Total disponível hoje</p>
                    </div>
                </div>
            </div>

            <div class="col-md-4">
                <div class="card bg-body-tertiary border-secondary-subtle shadow-sm h-100 rounded-4">
                    <div class="card-body p-4">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h6 class="card-title text-secondary mb-0 fw-semibold">Receitas (<?php echo $nome_mes ?>)</h6>
                            <div class="p-2 bg-success bg-opacity-10 rounded-3">
                                <i class="bi bi-graph-up-arrow text-success fs-5"></i>
                            </div>
                        </div>
                        <h3 class="fw-bold text-success mb-1">
                            R$ <?php echo number_format($receitasMes, 2, ',', '.') ?>
                        </h3>
                    </div>
                </div>
            </div>

            <div class="col-md-4">
                <div class="card bg-body-tertiary border-secondary-subtle shadow-sm h-100 rounded-4">
                    <div class="card-body p-4">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h6 class="card-title text-secondary mb-0 fw-semibold">Despesas (<?php echo $nome_mes ?>)</h6>
                            <div class="p-2 bg-danger bg-opacity-10 rounded-3">
                                <i class="bi bi-graph-down-arrow text-danger fs-5"></i>
                            </div>
                        </div>
                        <h3 class="fw-bold text-danger mb-1">
                            R$ <?php echo number_format($despesasMes, 2, ',', '.') ?>
                        </h3>
                    </div>
                </div>
            </div>
        </div>

        <h4 class="fw-bold text-light mb-4">Transações de <?php echo $nome_mes ?></h4>
        <div class="card bg-dark border-secondary-subtle shadow-sm rounded-4 overflow-hidden">

            <?php if (empty($transacoes)): ?>
                <div class="card-body p-5 text-center">
                    <i class="bi bi-receipt text-secondary opacity-50 display-1 mb-3"></i>
                    <h5 class="text-light fw-bold">Nenhum registro em <?php echo $nome_mes ?></h5>
                    <p class="text-secondary mb-0">Esta carteira não tem movimentações neste mês.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive" style="overflow-x: visible;">
                    <table class="table table-dark table-hover align-middle mb-0 auralis-table">
                        <thead class="table-active border-secondary-subtle text-secondary small text-uppercase">
                            <tr>
                                <th class="ps-4 py-3 border-0">Descrição</th>
                                <th class="py-3 border-0">Categoria</th>
                                <th class="py-3 border-0">Data</th>
                                <th class="py-3 border-0">Status</th>
                                <th class="text-end pe-4 py-3 border-0">Valor</th>
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
                                <td class="ps-4 py-3 border-secondary-subtle">
                                    <div class="d-flex align-items-center">
                                        <?php echo $iconeTipo ?>
                                        <span class="text-light fw-semibold"><?php echo htmlspecialchars($t['Descricao']) ?></span>
                                    </div>
                                </td>
                                <td class="py-3 border-secondary-subtle text-secondary small">
                                    <div class="d-flex align-items-center">
                                        <i class="bi <?php echo htmlspecialchars($t['IconeCategoria'] ?? 'bi-tag') ?> me-2 fs-6"></i>
                                        <span><?php echo htmlspecialchars($t['NomeCategoria'] ?? 'Sem categoria') ?></span>
                                    </div>
                                </td>
                                <td class="py-3 border-secondary-subtle text-secondary small">
                                    <?php echo $dataFormatada ?>
                                </td>
                                <td class="py-3 border-secondary-subtle">
                                    <?php if ($isPendente): ?>
                                        <span class="badge bg-warning text-dark px-2 py-1 rounded-pill fw-semibold shadow-sm"><i class="bi bi-clock-history me-1"></i> Pendente</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary bg-opacity-25 text-light px-2 py-1 rounded-pill"><i class="bi bi-check2-circle me-1"></i> Efetivado</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end pe-4 py-3 border-secondary-subtle fw-bold <?php echo $corValor ?>">
                                    <?php echo $sinalValor ?> R$ <?php echo number_format($t['Valor'], 2, ',', '.') ?>
                                </td>
                            </tr>
                            <tr>
                                <td colspan="5" class="p-0 border-0">
                                    <div class="collapse" id="<?php echo $rowId ?>">
                                        <div class="p-4 bg-charcoal-analysis border-bottom border-secondary-subtle d-flex justify-content-between align-items-start">
                                            <div class="d-flex gap-4">
                                                <?php $labelData = $isDespesa ? 'Vencimento' : 'Recebimento'; ?>
                                                    <div>
                                                        <span class="d-block text-secondary small text-uppercase mb-1"><?php echo $labelData ?></span>
                                                        <span class="text-light fs-6">
                                                            <?php echo(! empty($t['DataVencimento']) && strtotime($t['DataVencimento'])) ? date('d/m/Y', strtotime($t['DataVencimento'])) : '<span class="text-muted">Não definido</span>' ?>
                                                        </span>
                                                    </div>
                                                <div>
                                                    <span class="d-block text-secondary small text-uppercase mb-1">Recorrência</span>
                                                    <span class="text-light fs-6">
                                                        <?php echo $t['Recorrente'] ? 'Sim (Dia ' . htmlspecialchars($t['DiaVencimento']) . ')' : 'Não' ?>
                                                    </span>
                                                </div>
                                            </div>

                                            <div class="d-flex gap-2">
                                                <form method="POST" action="" class="m-0">
                                                    <input type="hidden" name="action" value="toggle_status">
                                                    <input type="hidden" name="registro_id" value="<?php echo $t['IDRegistro'] ?>">
                                                    <?php if ($isPendente): ?>
                                                        <input type="hidden" name="novo_status" value="efetivado">
                                                        <button type="submit" class="btn btn-sm btn-outline-success rounded-pill fw-semibold px-3 d-inline-flex align-items-center gap-1">
                                                            <i class="bi bi-check-circle"></i> <?php echo $textoAcaoStatus ?>
                                                        </button>
                                                    <?php else: ?>
                                                        <input type="hidden" name="novo_status" value="pendente">
                                                        <button type="submit" class="btn btn-sm btn-outline-secondary rounded-pill fw-semibold px-3 d-inline-flex align-items-center gap-1">
                                                            <i class="bi bi-arrow-counterclockwise"></i> Desfazer
                                                        </button>
                                                    <?php endif; ?>
                                                </form>

                                                <a href="nova_transacao.php?editar=<?php echo $t['IDRegistro'] ?>" class="btn btn-sm btn-outline-warning rounded-pill fw-semibold px-3 d-inline-flex align-items-center gap-1 transition-hover">
                                                    <i class="bi bi-pencil-square"></i> Editar
                                                </a>

                                                <form method="POST" action="" class="m-0" onsubmit="return confirm('Tem certeza que deseja excluir esta transação? A ação não pode ser desfeita.');">
                                                    <input type="hidden" name="action" value="excluir_registro">
                                                    <input type="hidden" name="registro_id" value="<?php echo $t['IDRegistro'] ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger rounded-pill fw-semibold px-3 d-inline-flex align-items-center gap-1 transition-hover">
                                                        <i class="bi bi-trash3"></i> Excluir
                                                    </button>
                                                </form>
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
                    $mesesAbrev = [1=>'Jan', 2=>'Fev', 3=>'Mar', 4=>'Abr', 5=>'Mai', 6=>'Jun', 7=>'Jul', 8=>'Ago', 9=>'Set', 10=>'Out', 11=>'Nov', 12=>'Dez'];
                    foreach($mesesAbrev as $num => $nome): 
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
    .bg-charcoal-analysis { background-color: #1a1d21; }
    .auralis-table > tbody > tr.cursor-pointer:hover > td { background-color: rgba(255, 255, 255, 0.03) !important; }
    .table-active { background-color: #1a1d21 !important; }
    .no-spinners::-webkit-outer-spin-button, .no-spinners::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
    .no-spinners { -moz-appearance: textfield; }

    /* Estilos Acrílicos do Onboarding */
    #modalPrimeiraCarteira, #modalBoasVindas {
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
            window.history.replaceState({path: url.href}, '', url.href);
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
            var modal1 = new bootstrap.Modal(document.getElementById('modalPrimeiraCarteira'), { backdrop: 'static', keyboard: false });
            modal1.show();
        <?php elseif ($is_primeiro_acesso): ?>
            // Cena 2: Já tem carteira, mas não tem saldo inicial? Mostra Modal de Boas Vindas
            var modal2 = new bootstrap.Modal(document.getElementById('modalBoasVindas'), { backdrop: 'static', keyboard: false });
            modal2.show();
        <?php endif; ?>
    });
</script>

<?php require_once 'geral/footer.php'; ?>