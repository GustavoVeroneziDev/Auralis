<?php
// MODO DEBUG: ativar apenas em desenvolvimento local
if ($_SERVER['HTTP_HOST'] === 'localhost' || $_SERVER['HTTP_HOST'] === '127.0.0.1') {
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);
}
session_start();

if (!isset($_SESSION['usuario_id'])) {
    header("Location: usuario/login.php");
    exit;
}

// 2. Conecta ao banco de dados
require_once 'config/conexao.php';
require_once 'config/funcoes.php';
require_once 'config/funcoes_cartao.php';

$usuario_id = $_SESSION['usuario_id'];
cartao_verificarFechamentos($pdo, $usuario_id);
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
                // Se for transferência, sincroniza o par (ambos os lados)
                try {
                    $chkTransf = $pdo->prepare("SELECT GrupoParcela FROM Registro WHERE IDRegistro = :id AND FKUsuario = :uid AND TipoRegistro IN ('transferencia_saida','transferencia_entrada')");
                    $chkTransf->execute([':id' => $id_registro, ':uid' => $usuario_id]);
                    $grupoPar = $chkTransf->fetchColumn();
                    if ($grupoPar) {
                        $pdo->prepare("UPDATE Registro SET StatusRegistro = :status WHERE GrupoParcela = :g AND FKUsuario = :uid AND TipoRegistro IN ('transferencia_saida','transferencia_entrada') AND IDRegistro != :id")
                            ->execute([':status' => $novo_status, ':g' => $grupoPar, ':uid' => $usuario_id, ':id' => $id_registro]);
                    }
                } catch (PDOException $e) {}
                // Sincroniza status da fatura de cartão vinculada
                try {
                    if ($novo_status === 'efetivado') {
                        $pdo->prepare(
                            "UPDATE FaturaCartao SET Status='paga' WHERE FKRegistroPagamento=:rid AND FKUsuario=:uid AND Status='fechada'"
                        )->execute([':rid' => $id_registro, ':uid' => $usuario_id]);
                    } elseif ($novo_status === 'pendente') {
                        $pdo->prepare(
                            "UPDATE FaturaCartao SET Status='fechada' WHERE FKRegistroPagamento=:rid AND FKUsuario=:uid AND Status='paga'"
                        )->execute([':rid' => $id_registro, ':uid' => $usuario_id]);
                    }
                } catch (PDOException $e) {
                }
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
$_carteiraIds = array_column($carteiras, 'IDCarteira');
if (isset($_GET['carteira'])) {
    $carteira_selecionada = $_GET['carteira'];
    // Valida e persiste na sessão
    if (in_array($carteira_selecionada, $_carteiraIds)) {
        $_SESSION['ultima_carteira'] = $carteira_selecionada;
    } else {
        $carteira_selecionada = ($totalCarteiras > 0) ? $carteiras[0]['IDCarteira'] : null;
    }
} else {
    // Restaura da sessão (mesma lógica do localStorage: lembra a última carteira usada)
    $fromSession = $_SESSION['ultima_carteira'] ?? null;
    if ($fromSession && in_array($fromSession, $_carteiraIds)) {
        $carteira_selecionada = $fromSession;
    } else {
        $carteira_selecionada = ($totalCarteiras > 0) ? $carteiras[0]['IDCarteira'] : null;
        if ($carteira_selecionada) $_SESSION['ultima_carteira'] = $carteira_selecionada;
    }
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

// URL de retorno para nova_transacao — preserva contexto atual
$_uv_dash = 'dashboard.php?mes=' . $mes_atual . '&ano=' . $ano_atual . ($carteira_selecionada ? '&carteira=' . urlencode($carteira_selecionada) : '');
// Acesso a comprovantes (PRO/VIP)
$_temAcessoComp = function_exists('recursoDisponivelParaPlano') ? recursoDisponivelParaPlano('comprovantes') : false;

// --- LÓGICA DE DADOS REAIS DO DASHBOARD ---
$saldoAtual  = 0.00;
$receitasMes = 0.00;
$despesasMes = 0.00;
$transacoes  = [];

if ($carteira_selecionada) {
    try {
        $sqlSaldo = "
                SELECT
                    COALESCE(SUM(CASE WHEN TipoRegistro = 'receita'               THEN Valor ELSE 0 END), 0) as total_rec_hist,
                    COALESCE(SUM(CASE WHEN TipoRegistro = 'despesa'               THEN Valor ELSE 0 END), 0) as total_des_hist,
                    COALESCE(SUM(CASE WHEN TipoRegistro = 'cofrinho'              THEN Valor ELSE 0 END), 0) as total_cof_dep,
                    COALESCE(SUM(CASE WHEN TipoRegistro = 'cofrinho_retirada'     THEN Valor ELSE 0 END), 0) as total_cof_ret,
                    COALESCE(SUM(CASE WHEN TipoRegistro = 'transferencia_entrada' THEN Valor ELSE 0 END), 0) as total_transf_in,
                    COALESCE(SUM(CASE WHEN TipoRegistro = 'transferencia_saida'   THEN Valor ELSE 0 END), 0) as total_transf_out
                FROM Registro
                WHERE FKCarteira = :carteira_id
                  AND FKUsuario = :usuario_id
                  AND StatusRegistro = 'efetivado'
            ";
        $stmtSaldo = $pdo->prepare($sqlSaldo);
        $stmtSaldo->execute([':carteira_id' => $carteira_selecionada, ':usuario_id' => $usuario_id]);
        $resultSaldo = $stmtSaldo->fetch();

        if ($resultSaldo) {
            $saldoAtual = (float) $resultSaldo['total_rec_hist']
                + (float) $resultSaldo['total_cof_ret']
                + (float) $resultSaldo['total_transf_in']
                - (float) $resultSaldo['total_des_hist']
                - (float) $resultSaldo['total_cof_dep']
                - (float) $resultSaldo['total_transf_out'];
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
                    c.NomeCategoria, c.IconeCategoria,
                    (SELECT COUNT(*) FROM Comprovante WHERE FKRegistro = r.IDRegistro AND FKUsuario = r.FKUsuario) AS qtd_comprovantes,
                    cart_par.TipoCarteira AS NomeCarteiraTransferencia
                FROM Registro r
                LEFT JOIN Categoria c ON r.FKCategoria = c.IDCategoria
                LEFT JOIN Registro r_par ON (
                    r.TipoRegistro IN ('transferencia_saida','transferencia_entrada')
                    AND r.GrupoParcela IS NOT NULL
                    AND r_par.GrupoParcela = r.GrupoParcela
                    AND r_par.IDRegistro != r.IDRegistro
                )
                LEFT JOIN Carteira cart_par ON cart_par.IDCarteira = r_par.FKCarteira
                WHERE r.FKCarteira = :carteira_id
                  AND r.FKUsuario = :usuario_id
                  AND r.TipoRegistro NOT IN ('cofrinho','cofrinho_retirada')
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


// ── Cartões de crédito: faturas abertas para exibir no painel ────────────
$faturasAbertasDash = [];
try {
    $stmtFC = $pdo->prepare("
        SELECT f.IDFatura, f.FKCartao, f.MesReferencia, f.DataFechamento, f.DataVencimento,
               c.Nome AS NomeCartao, c.Bandeira, c.Cor, c.IDCartao,
               COALESCE(SUM(l.Valor), 0) AS TotalAcumulado
        FROM FaturaCartao f
        JOIN CartaoCredito c ON f.FKCartao = c.IDCartao
        LEFT JOIN LancamentoCartao l ON l.FKFatura = f.IDFatura
        WHERE f.FKUsuario = :uid
          AND f.Status = 'aberta'
          AND f.DataFechamento = (
              SELECT MIN(f2.DataFechamento)
              FROM FaturaCartao f2
              WHERE f2.FKCartao = f.FKCartao
                AND f2.FKUsuario = f.FKUsuario
                AND f2.Status = 'aberta'
          )
        GROUP BY f.IDFatura, f.FKCartao, f.MesReferencia, f.DataFechamento, f.DataVencimento,
                 c.Nome, c.Bandeira, c.Cor, c.IDCartao
        ORDER BY c.Nome ASC
    ");
    $stmtFC->execute([':uid' => $usuario_id]);
    $faturasAbertasDash = $stmtFC->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
}

// Remove Registros de preview de CC da lista de transações (não são transações reais)
try {
    $stmtPv = $pdo->prepare(
        "SELECT FKRegistroPreview FROM FaturaCartao WHERE FKUsuario = :uid AND FKRegistroPreview IS NOT NULL"
    );
    $stmtPv->execute([':uid' => $usuario_id]);
    $idsPreview = array_column($stmtPv->fetchAll(PDO::FETCH_ASSOC), 'FKRegistroPreview');
    if (!empty($idsPreview)) {
        $transacoes = array_values(array_filter($transacoes, fn($t) => !in_array($t['IDRegistro'], $idsPreview)));
    }
} catch (PDOException $e) {
}

// ── Cofrinhos: lista individual para cards no dashboard ─────────────────
$listaCofrinhosDash = [];
$totalCofrinhos     = 0.0;
$qtdCofrinhos       = 0;
try {
    $stmtCof = $pdo->prepare("
        SELECT co.IDCofrinho, co.Nome, co.Icone, co.Cor, co.ValorMeta, co.DataLimite,
               COALESCE(SUM(CASE WHEN r.TipoRegistro='cofrinho'          THEN  r.Valor
                                 WHEN r.TipoRegistro='cofrinho_retirada' THEN -r.Valor
                                 ELSE 0 END), 0) as ValorAtual
        FROM Cofrinho co
        LEFT JOIN Registro r ON r.FKCofrinho = co.IDCofrinho
                             AND r.TipoRegistro IN ('cofrinho','cofrinho_retirada')
        WHERE co.FKUsuario = :uid AND co.Ativo = 1
        GROUP BY co.IDCofrinho, co.Nome, co.Icone, co.Cor, co.ValorMeta, co.DataLimite
        ORDER BY co.DataCriacao ASC
    ");
    $stmtCof->execute([':uid' => $usuario_id]);
    $listaCofrinhosDash = $stmtCof->fetchAll(PDO::FETCH_ASSOC);
    $qtdCofrinhos       = count($listaCofrinhosDash);
    $totalCofrinhos     = array_sum(array_column($listaCofrinhosDash, 'ValorAtual'));
} catch (PDOException $e) {
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
    $bg     = $positivo ? 'var(--color-income-bg)'     : 'var(--color-expense-bg)';
    $color  = $positivo ? 'var(--color-income-text)'   : 'var(--color-expense-text)';
    $border = $positivo ? 'var(--color-income-border)'  : 'var(--color-expense-border)';
    $icon   = $subiu ? 'bi-arrow-up-short' : 'bi-arrow-down-short';
    return "<span class='ms-1' style='display:inline-flex;align-items:center;background:{$bg};color:{$color};border:1px solid {$border};border-radius:999px;padding:1px 7px;font-size:0.68rem;font-weight:600;'><i class='bi {$icon}'></i>{$abs}%</span>";
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

        <?php
        $msg = '';
        if (isset($_GET['sucesso'])) {
            if ($_GET['sucesso'] === 'registro')  $msg = 'Transação salva!';
            if ($_GET['sucesso'] === 'editado')   $msg = 'Transação atualizada!';
            if ($_GET['sucesso'] === 'excluido')  $msg = 'Transação excluída!';
            if ($_GET['sucesso'] === 'ajustado')  $msg = 'Saldo ajustado com sucesso!';
            if ($_GET['sucesso'] === 'criada')    $msg = 'Nova carteira criada!';
            if ($_GET['sucesso'] === 'parcelado') {
                $n = isset($_GET['parcelas']) ? (int)$_GET['parcelas'] : '';
                $msg = "Compra parcelada em {$n}x registrada!";
            }
        }
        if ($msg): ?>
            <script>
                window._pendingToast = <?php echo json_encode($msg) ?>;
            </script>
        <?php endif; ?>

        <div class="mb-3 border-bottom border-secondary-subtle pb-3">
            <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3">

                <div class="d-flex align-items-center justify-content-between justify-content-lg-start gap-2 w-100 w-lg-auto">

                    <div class="d-flex align-items-center rounded-pill shadow-sm flex-shrink-0" style="padding:2px 4px;background:var(--bg-card);border:1px solid var(--card-border-color);">
                        <a href="<?php echo $link_ant ?>" class="btn btn-sm btn-link transition-hover text-decoration-none d-flex align-items-center justify-content-center" style="width:30px;height:30px;color:var(--accent);">
                            <i class="bi bi-caret-left-fill" style="font-size:0.65rem;"></i>
                        </a>

                        <button type="button" class="btn btn-link text-decoration-none fw-semibold px-1 transition-hover d-flex align-items-center justify-content-center"
                            style="font-size:0.875rem;white-space:nowrap;color:var(--text-main);"
                            data-bs-toggle="modal" data-bs-target="#modalSeletorMes">
                            <?php echo $nome_mes ?> <span class="d-none d-sm-inline ms-1"><?php echo $ano_atual ?></span>
                            <i class="bi bi-chevron-down ms-1 opacity-75" style="font-size: 0.65rem;"></i>
                        </button>

                        <a href="<?php echo $link_prox ?>" class="btn btn-sm btn-link transition-hover text-decoration-none d-flex align-items-center justify-content-center" style="width:30px;height:30px;color:var(--accent);">
                            <i class="bi bi-caret-right-fill" style="font-size:0.65rem;"></i>
                        </a>
                    </div>

                    <div class="dropdown flex-shrink-0">
                        <button class="btn shadow-sm fw-semibold dropdown-toggle d-flex align-items-center rounded-3 transition-hover px-2 px-sm-3"
                            style="background:var(--bg-card);border:1px solid var(--card-border-color);color:var(--text-main);font-size:0.875rem;max-width:200px;"
                            type="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <span class="text-truncate d-flex align-items-center">
                                <i class="bi bi-wallet2 me-1 me-sm-2" style="color:var(--accent);flex-shrink:0;"></i>
                                <?php echo htmlspecialchars($nome_carteira_atual); ?>
                            </span>
                        </button>

                        <ul class="dropdown-menu shadow-lg border-secondary-subtle mt-2" style="background-color:var(--bg-card);border-color:var(--card-border-color);min-width:220px;">
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
                    <a href="nova_transacao.php?carteira_id=<?php echo urlencode($carteira_selecionada) ?>&tipo=receita&voltar=<?php echo urlencode($_uv_dash) ?>"
                        class="btn btn-outline-success fw-semibold d-flex align-items-center justify-content-center gap-1 rounded-pill transition-hover shadow-sm flex-grow-1"
                        style="font-size: 0.875rem; padding: 0.375rem 0.875rem;">
                        <i class="bi bi-arrow-up-short fs-5"></i> Receita
                    </a>

                    <a href="nova_transacao.php?carteira_id=<?php echo urlencode($carteira_selecionada) ?>&tipo=despesa&voltar=<?php echo urlencode($_uv_dash) ?>"
                        class="btn btn-outline-danger fw-semibold d-flex align-items-center justify-content-center gap-1 rounded-pill transition-hover shadow-sm flex-grow-1"
                        style="font-size: 0.875rem; padding: 0.375rem 0.875rem;">
                        <i class="bi bi-arrow-down-short fs-5"></i> Despesa
                    </a>

                    <?php if ($totalCarteiras >= 2): ?>
                    <button type="button"
                        class="btn btn-outline-info fw-semibold d-flex align-items-center justify-content-center gap-1 rounded-pill transition-hover shadow-sm flex-grow-1"
                        style="font-size: 0.875rem; padding: 0.375rem 0.875rem;"
                        data-bs-toggle="modal" data-bs-target="#modalTransferencia">
                        <i class="bi bi-arrow-left-right" style="font-size:0.95rem;"></i> Transferir
                    </button>
                    <?php endif; ?>
                </div>

            </div>
        </div>

        <!-- ── Cards de Resumo ─────────────────────────────────────── -->
        <div class="row g-3 mb-3">
            <!-- Saldo -->
            <div class="col-12 col-md-4">
                <div class="card h-100 rounded-4 shadow-sm" style="background:var(--bg-card);border:1px solid var(--card-border-color);">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <p class="text-secondary fw-semibold mb-0 small text-truncate me-2">Saldo: <?php echo htmlspecialchars($nome_carteira_atual); ?></p>
                            <div class="p-2 rounded-3 flex-shrink-0" style="background:rgba(var(--bs-primary-rgb),0.12);">
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
                <div class="card h-100 rounded-4 shadow-sm" style="background:var(--color-income-bg);border:1px solid var(--color-income-border);">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <p class="fw-semibold mb-0 small" style="color:var(--color-income-text);">Receitas (<?php echo $nome_mes ?>)</p>
                            <div class="p-2 rounded-3 flex-shrink-0 d-none d-sm-flex" style="background:rgba(6,214,160,0.15);">
                                <i class="bi bi-graph-up-arrow" style="color:var(--color-income-text);"></i>
                            </div>
                        </div>
                        <div class="fw-bold mb-1" style="font-size:var(--fs-card-val);color:var(--color-income-text);">R$ <?php echo number_format($receitasMes ?? 0, 2, ',', '.') ?></div>
                        <div class="mt-2 d-flex align-items-center flex-wrap gap-1">
                            <?php echo badgeVar($receitasMes, $receitasMesAnt, false); ?>
                        </div>
                    </div>
                </div>
            </div>
            <!-- Despesas -->
            <div class="col-6 col-md-4">
                <div class="card h-100 rounded-4 shadow-sm" style="background:var(--color-expense-bg);border:1px solid var(--color-expense-border);">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <p class="fw-semibold mb-0 small" style="color:var(--color-expense-text);">Despesas (<?php echo $nome_mes ?>)</p>
                            <div class="p-2 rounded-3 flex-shrink-0 d-none d-sm-flex" style="background:rgba(230,57,70,0.15);">
                                <i class="bi bi-graph-down-arrow" style="color:var(--color-expense-text);"></i>
                            </div>
                        </div>
                        <div class="fw-bold mb-1" style="font-size:var(--fs-card-val);color:var(--color-expense-text);">R$ <?php echo number_format($despesasMes ?? 0, 2, ',', '.') ?></div>
                        <div class="mt-2 d-flex align-items-center flex-wrap gap-1">
                            <?php echo badgeVar($despesasMes, $despesasMesAnt, true); ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── Barra de Gastos Esperados (pendentes) ─────────────────────── -->
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
                            <?php $projecaoSaldo = $saldoAtual + $receitasPendentes - $despesasPendentes; ?>
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

        <!-- ── Seção Cofrinhos & Metas ───────────────────────────────────── -->
        <?php if ($qtdCofrinhos > 0): ?>
            <div class="d-flex align-items-center justify-content-between mb-3 mt-2">
                <button class="d-flex align-items-center gap-2 btn p-0 border-0 bg-transparent" onclick="toggleSection('cofrinhos')">
                    <i class="bi bi-piggy-bank" style="color:#f59e0b;font-size:1rem;"></i>
                    <span class="fw-bold text-light" style="font-size:1.05rem;">Cofrinhos</span>
                    <span class="text-secondary fw-normal small"><?= $qtdCofrinhos ?> ativo<?= $qtdCofrinhos > 1 ? 's' : '' ?></span>
                    <i class="bi bi-chevron-up text-secondary ms-1" id="chev-cofrinhos" style="font-size:0.75rem;transition:transform .2s;"></i>
                </button>
            </div>
            <div id="sec-cofrinhos" class="row g-3 mb-4">
                <?php foreach ($listaCofrinhosDash as $cof):
                    $cor      = htmlspecialchars($cof['Cor'] ?? '#f59e0b');
                    $icone    = htmlspecialchars($cof['Icone'] ?? 'bi-piggy-bank');
                    $valAtual = (float) $cof['ValorAtual'];
                    $valMeta  = $cof['ValorMeta'] !== null ? (float) $cof['ValorMeta'] : null;
                    $pct      = ($valMeta !== null && $valMeta > 0)
                        ? min(100, round(($valAtual / $valMeta) * 100, 1))
                        : null;
                ?>
                    <div class="col-12 col-sm-6 col-xl-4">
                        <a href="analises.php?carteira=<?= urlencode($carteira_selecionada ?? '') ?>#cofrinhos"
                            class="text-decoration-none d-block h-100">
                            <div class="h-100 rounded-4 shadow-sm"
                                style="background:var(--bg-card);border:1px solid var(--bs-border-color);border-left:3px solid <?= $cor ?> !important;transition:all .18s;">
                                <div class="p-3">
                                    <!-- Cabeçalho -->
                                    <div class="d-flex align-items-start justify-content-between mb-3">
                                        <div class="d-flex align-items-center gap-2">
                                            <div class="d-flex align-items-center justify-content-center rounded-3 flex-shrink-0"
                                                style="width:36px;height:36px;background:<?= $cor ?>22;">
                                                <i class="bi <?= $icone ?>" style="color:<?= $cor ?>;font-size:1rem;"></i>
                                            </div>
                                            <div>
                                                <div class="fw-bold text-light lh-1" style="font-size:0.9rem;"><?= htmlspecialchars($cof['Nome']) ?></div>
                                                <?php if ($valMeta !== null): ?>
                                                    <div class="text-secondary mt-1" style="font-size:0.7rem;">Meta: R$ <?= number_format($valMeta, 2, ',', '.') ?></div>
                                                <?php else: ?>
                                                    <div class="text-secondary mt-1" style="font-size:0.7rem;">Sem meta</div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <?php if ($pct !== null): ?>
                                            <span class="flex-shrink-0" style="display:inline-flex;align-items:center;background:<?= $cor ?>18;color:<?= $cor ?>;border:1px solid <?= $cor ?>33;border-radius:999px;padding:2px 8px;font-size:0.62rem;font-weight:700;">
                                                <?= $pct ?>%
                                            </span>
                                        <?php endif; ?>
                                    </div>

                                    <!-- Valor e barra -->
                                    <div class="mb-2">
                                        <div class="fw-bold text-light" style="font-size:1.05rem;">
                                            R$ <?= number_format($valAtual, 2, ',', '.') ?>
                                        </div>
                                        <?php if ($pct !== null): ?>
                                            <div class="mt-2">
                                                <div class="progress rounded-pill" style="height:5px;background:rgba(255,255,255,0.07);">
                                                    <div class="progress-bar rounded-pill" role="progressbar"
                                                        style="width:<?= $pct ?>%;background:<?= $cor ?>;"
                                                        aria-valuenow="<?= $pct ?>" aria-valuemin="0" aria-valuemax="100"></div>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                    </div>

                                    <!-- Rodapé -->
                                    <div class="mt-2 pt-2 d-flex align-items-center gap-1" style="border-top:1px solid var(--bs-border-color);color:var(--text-muted);font-size:0.72rem;">
                                        <i class="bi bi-arrow-right-circle" style="font-size:0.75rem;"></i>
                                        Ver cofrinho
                                    </div>
                                </div>
                            </div>
                        </a>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($faturasAbertasDash)): ?>
            <!-- ── Seção de Cartões de Crédito ───────────────────────────────── -->
            <div class="d-flex align-items-center justify-content-between mb-3 mt-4">
                <button class="d-flex align-items-center gap-2 btn p-0 border-0 bg-transparent" onclick="toggleSection('cartoes')">
                    <i class="bi bi-credit-card-2-front" style="color:var(--primary-gold-analysis);font-size:1rem;"></i>
                    <span class="fw-bold text-light" style="font-size:1.05rem;">Cartões de Crédito</span>
                    <span class="text-secondary fw-normal small"><?= count($faturasAbertasDash) ?> aberto<?= count($faturasAbertasDash) > 1 ? 's' : '' ?></span>
                    <i class="bi bi-chevron-up text-secondary ms-1" id="chev-cartoes" style="font-size:0.75rem;transition:transform .2s;"></i>
                </button>
            </div>

            <div id="sec-cartoes" class="row g-3 mb-4">
                <?php foreach ($faturasAbertasDash as $fat):
                    $corCartao  = htmlspecialchars($fat['Cor'] ?? '#7c3aed');
                    $dataFech   = (!empty($fat['DataFechamento']) && $fat['DataFechamento'] !== '0000-00-00')
                        ? date('d/m/Y', strtotime($fat['DataFechamento'])) : '—';
                    $dataVenc   = (!empty($fat['DataVencimento']) && $fat['DataVencimento'] !== '0000-00-00')
                        ? date('d/m/Y', strtotime($fat['DataVencimento'])) : '—';
                    $totalFat   = (float)$fat['TotalAcumulado'];
                    $bandeira   = ucfirst($fat['Bandeira'] ?? 'Cartão');
                ?>
                    <div class="col-12 col-sm-6 col-xl-4">
                        <a href="/cartao_credito/fatura.php?cartao=<?php echo urlencode($fat['IDCartao']) ?>"
                            class="text-decoration-none d-block h-100">
                            <div class="h-100 rounded-4 shadow-sm cc-dash-card"
                                style="background:var(--bg-card); border:1px solid var(--bs-border-color); border-left:3px solid <?php echo $corCartao ?> !important; transition:all .18s;">
                                <div class="p-3">
                                    <!-- Cabeçalho do cartão -->
                                    <div class="d-flex align-items-start justify-content-between mb-3">
                                        <div class="d-flex align-items-center gap-2">
                                            <div class="d-flex align-items-center justify-content-center rounded-3 flex-shrink-0"
                                                style="width:36px;height:36px;background:<?php echo $corCartao ?>22;">
                                                <i class="bi bi-credit-card-2-front" style="color:<?php echo $corCartao ?>;font-size:1rem;"></i>
                                            </div>
                                            <div>
                                                <div class="fw-bold text-light lh-1" style="font-size:0.9rem;"><?php echo htmlspecialchars($fat['NomeCartao']) ?></div>
                                                <div class="text-secondary mt-1" style="font-size:0.7rem;"><?php echo $bandeira ?></div>
                                            </div>
                                        </div>
                                        <span class="flex-shrink-0" style="display:inline-flex;align-items:center;background:#22c55e18;color:#22c55e;border:1px solid #22c55e33;border-radius:999px;padding:2px 8px;font-size:0.62rem;font-weight:700;letter-spacing:.03em;">
                                            ● ABERTA
                                        </span>
                                    </div>

                                    <!-- Datas + total -->
                                    <div class="d-flex align-items-end justify-content-between">
                                        <div>
                                            <div class="text-secondary mb-1" style="font-size:0.68rem;">
                                                <i class="bi bi-lock me-1" style="font-size:0.6rem;"></i>Fecha <?php echo $dataFech ?>
                                            </div>
                                            <div class="text-secondary" style="font-size:0.68rem;">
                                                <i class="bi bi-calendar-check me-1" style="font-size:0.6rem;"></i>Vence <?php echo $dataVenc ?>
                                            </div>
                                        </div>
                                        <div class="text-end">
                                            <div class="text-secondary mb-1" style="font-size:0.62rem;">Total acumulado</div>
                                            <div class="fw-bold <?php echo $totalFat > 0 ? 'text-danger' : 'text-secondary' ?>" style="font-size:1.05rem;">
                                                R$ <?php echo number_format($totalFat, 2, ',', '.') ?>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Rodapé -->
                                    <div class="mt-2 pt-2 d-flex align-items-center gap-1" style="border-top:1px solid var(--bs-border-color);color:var(--text-muted);font-size:0.72rem;">
                                        <i class="bi bi-arrow-right-circle" style="font-size:0.75rem;"></i>
                                        Ver fatura completa
                                    </div>
                                </div>
                            </div>
                        </a>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- Cabeçalho de impressão (só aparece no print) -->
        <div class="print-header" style="display:none;">
            <div class="print-header-logo">Auralis</div>
            <div class="print-header-meta">
                Transações de <?= $nome_mes . ' ' . $ano_atual ?><br>
                Carteira: <?= htmlspecialchars($nome_carteira_atual ?? '') ?><br>
                Gerado em <?= date('d/m/Y H:i') ?>
            </div>
        </div>

        <div class="d-flex align-items-center justify-content-between mb-3 mt-4">
            <h4 class="fw-bold text-light mb-0">Transações de <?php echo $nome_mes ?></h4>
            <div class="d-flex gap-2 no-print">
                <a href="/exportar.php?tipo=transacoes&mes=<?= $mes_atual ?>&ano=<?= $ano_atual ?>&carteira=<?= urlencode($carteira_selecionada) ?>"
                    class="btn btn-sm d-flex align-items-center gap-1 rounded-3"
                    style="background:var(--bg-card);border:1px solid var(--card-border-color);color:var(--text-main);font-size:0.78rem;"
                    title="Exportar transações do mês em CSV">
                    <i class="bi bi-filetype-csv" style="color:var(--accent);font-size:0.9rem;"></i>
                    <span class="d-none d-sm-inline">Exportar CSV</span>
                </a>
                <button onclick="window.print()"
                    class="btn btn-sm d-flex align-items-center gap-1 rounded-3"
                    style="background:var(--bg-card);border:1px solid var(--card-border-color);color:var(--text-main);font-size:0.78rem;"
                    title="Salvar relatório como PDF">
                    <i class="bi bi-printer" style="color:var(--accent);font-size:0.9rem;"></i>
                    <span class="d-none d-sm-inline">Exportar PDF</span>
                </button>
            </div>
        </div>

        <div class="no-print mb-3 d-flex flex-wrap gap-2 align-items-center">
            <div class="flex-grow-1 position-relative" style="min-width:200px;max-width:360px;">
                <i class="bi bi-search position-absolute" style="left:12px;top:50%;transform:translateY(-50%);color:var(--text-muted);font-size:0.8rem;pointer-events:none;"></i>
                <input type="text" id="buscaInput" placeholder="Buscar por descrição, valor, categoria…"
                       class="form-control form-control-sm shadow-none"
                       style="background:var(--bg-card);border:1px solid var(--card-border-color);color:var(--text-main);padding-left:34px;border-radius:20px;font-size:0.82rem;">
            </div>
            <div class="d-flex gap-1 flex-wrap">
                <button class="btn btn-sm busca-pill active" data-filtro="tudo">Tudo</button>
                <button class="btn btn-sm busca-pill" data-filtro="receita">Receitas</button>
                <button class="btn btn-sm busca-pill" data-filtro="despesa">Despesas</button>
                <button class="btn btn-sm busca-pill" data-filtro="transferencia">Transferências</button>
                <button class="btn btn-sm busca-pill" data-filtro="pendente">Pendentes</button>
                <button class="btn btn-sm busca-pill" data-filtro="efetivado">Efetivados</button>
            </div>
        </div>

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
                    <tr id="buscaVazio" style="display:none;">
                        <td colspan="5" class="text-center text-secondary py-5">
                            <i class="bi bi-search fs-3 d-block mb-2 opacity-50"></i>
                            Nenhuma transação encontrada para essa busca.
                        </td>
                    </tr>
                    <?php foreach ($transacoes as $index => $t):
                        $isTransfSaida  = ($t['TipoRegistro'] === 'transferencia_saida');
                        $isTransfEntr   = ($t['TipoRegistro'] === 'transferencia_entrada');
                        $isTransfer     = $isTransfSaida || $isTransfEntr;
                        $isDespesa      = ($t['TipoRegistro'] === 'despesa');
                        $sinalValor     = ($isDespesa || $isTransfSaida) ? '-' : '+';
                        $corValor       = ($isDespesa || $isTransfSaida) ? 'text-danger' : 'text-success';
                        $dataFormatada  = date('d/m/Y', strtotime($t['MomentoRegistro']));

                        if ($isTransfer) {
                            $iconeTipo = '<span class="d-inline-flex align-items-center justify-content-center rounded-circle flex-shrink-0 me-3" style="width:32px;height:32px;min-width:32px;background:rgba(96,165,250,0.12);"><i class="bi bi-arrow-left-right" style="color:#60a5fa;font-size:0.85rem;"></i></span>';
                        } elseif ($isDespesa) {
                            $iconeTipo = '<span class="d-inline-flex align-items-center justify-content-center rounded-circle bg-danger bg-opacity-10 flex-shrink-0 me-3" style="width:32px;height:32px;min-width:32px;"><i class="bi bi-arrow-down-short text-danger" style="font-size:1.1rem;"></i></span>';
                        } else {
                            $iconeTipo = '<span class="d-inline-flex align-items-center justify-content-center rounded-circle bg-success bg-opacity-10 flex-shrink-0 me-3" style="width:32px;height:32px;min-width:32px;"><i class="bi bi-arrow-up-short text-success" style="font-size:1.1rem;"></i></span>';
                        }

                        $rowId           = "transacao-" . $index;
                        $isPendente      = ($t['StatusRegistro'] === 'pendente');
                        $textoAcaoStatus = $isTransfer ? 'Confirmar' : ($isDespesa ? 'Marcar como Pago' : 'Marcar como Recebido');
                    ?>
                        <tr data-bs-toggle="collapse" data-bs-target="#<?php echo $rowId ?>"
                            class="cursor-pointer transition-hover tr-transacao"
                            style="cursor:pointer;"
                            data-desc="<?= strtolower(htmlspecialchars($t['Descricao'])) ?>"
                            data-cat="<?= strtolower(htmlspecialchars($t['NomeCategoria'] ?? '')) ?>"
                            data-valor="<?= number_format((float)$t['Valor'], 2, ',', '.') ?>"
                            data-status="<?= htmlspecialchars($t['StatusRegistro']) ?>"
                            data-tipo="<?= htmlspecialchars($t['TipoRegistro']) ?>">

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
                                        <?php if ($isTransfer && !empty($t['NomeCarteiraTransferencia'])): ?>
                                            <div class="mt-1" style="font-size:0.72rem;color:#60a5fa;">
                                                <?php echo $isTransfSaida ? '→' : '←' ?> <?php echo htmlspecialchars($t['NomeCarteiraTransferencia']) ?>
                                            </div>
                                        <?php endif; ?>

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
                                            <?php if ($isTransfer): ?>
                                                <div>
                                                    <span class="d-block text-secondary small text-uppercase mb-1"><?php echo $isTransfSaida ? 'Destino' : 'Origem' ?></span>
                                                    <span class="text-light fs-6" style="color:#60a5fa!important;">
                                                        <?php echo htmlspecialchars($t['NomeCarteiraTransferencia'] ?? '—') ?>
                                                    </span>
                                                </div>
                                                <div>
                                                    <span class="d-block text-secondary small text-uppercase mb-1">Data</span>
                                                    <span class="text-light fs-6"><?php echo $dataFormatada ?></span>
                                                </div>
                                            <?php else: ?>
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

                                            <?php if (!$isTransfer): ?>
                                            <a href="nova_transacao.php?editar=<?php echo $t['IDRegistro'] ?>&voltar=<?php echo urlencode($_uv_dash) ?>" class="btn btn-sm btn-outline-warning rounded-pill fw-semibold px-3 d-inline-flex align-items-center gap-1 transition-hover">
                                                <i class="bi bi-pencil-square"></i> <span class="d-none d-sm-inline">Editar</span>
                                            </a>
                                            <?php endif; ?>

                                            <?php if ($_temAcessoComp && ($t['qtd_comprovantes'] ?? 0) > 0): ?>
                                                <button type="button"
                                                    class="btn btn-sm btn-outline-info rounded-pill fw-semibold px-3 d-inline-flex align-items-center gap-1 transition-hover"
                                                    title="Ver comprovante"
                                                    onclick="abrirComprovantes('<?php echo $t['IDRegistro'] ?>')">
                                                    <i class="bi bi-eye"></i>
                                                </button>
                                            <?php endif; ?>

                                            <?php if ($isTransfer): ?>
                                                <!-- BOTÃO: EXCLUIR TRANSFERÊNCIA (par inteiro) -->
                                                <button type="button"
                                                    class="btn btn-sm btn-outline-danger rounded-pill fw-semibold px-3 d-inline-flex align-items-center gap-1 transition-hover"
                                                    onclick="excluirTransferencia('<?php echo htmlspecialchars($t['GrupoParcela'] ?? '') ?>')">
                                                    <i class="bi bi-trash3"></i> <span class="d-none d-sm-inline">Excluir</span>
                                                </button>
                                            <?php else: ?>
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
                                            <?php endif; // isTransfer ?>
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
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
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
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
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
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
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
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
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
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
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
<!-- MODAL: VISUALIZAR COMPROVANTES -->
<div class="modal fade" id="modalComprovantes" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-secondary-subtle" style="background:var(--bg-card);">
            <div class="modal-header border-secondary-subtle px-4 py-3">
                <h6 class="modal-title fw-bold text-light mb-0"><i class="bi bi-paperclip me-2"></i>Comprovantes</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4" id="modalComprovantesBody">
                <div class="text-center text-secondary py-4"><i class="bi bi-hourglass-split me-2"></i>Carregando...</div>
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

<!-- ── Modal: Transferência entre Carteiras ─────────────────────────────── -->
<?php if ($totalCarteiras >= 2): ?>
<div class="modal fade" id="modalTransferencia" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-secondary-subtle rounded-4" style="background:var(--bg-card);">
            <div class="modal-header border-secondary-subtle pb-2">
                <div class="d-flex align-items-center gap-2">
                    <span class="d-inline-flex align-items-center justify-content-center rounded-3" style="width:34px;height:34px;background:rgba(96,165,250,0.12);">
                        <i class="bi bi-arrow-left-right" style="color:#60a5fa;font-size:0.95rem;"></i>
                    </span>
                    <h5 class="modal-title fw-bold text-light mb-0">Transferência entre Carteiras</h5>
                </div>
                <button type="button" class="btn-close btn-close-white opacity-50" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body pt-3">
                <div id="transf-erro" class="alert alert-danger d-none py-2 small" role="alert"></div>

                <div class="mb-3">
                    <label class="form-label text-secondary small fw-semibold text-uppercase mb-1">De (origem)</label>
                    <select id="transf-de" class="form-select bg-body-tertiary border-secondary-subtle text-light">
                        <?php foreach ($carteiras as $cart): ?>
                            <option value="<?php echo htmlspecialchars($cart['IDCarteira']) ?>"
                                <?php echo ($carteira_selecionada == $cart['IDCarteira']) ? 'selected' : '' ?>>
                                <?php echo htmlspecialchars($cart['TipoCarteira']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="d-flex justify-content-center my-1">
                    <i class="bi bi-arrow-down text-secondary" style="font-size:1.1rem;"></i>
                </div>

                <div class="mb-3">
                    <label class="form-label text-secondary small fw-semibold text-uppercase mb-1">Para (destino)</label>
                    <select id="transf-para" class="form-select bg-body-tertiary border-secondary-subtle text-light">
                        <?php foreach ($carteiras as $cart): ?>
                            <option value="<?php echo htmlspecialchars($cart['IDCarteira']) ?>"
                                <?php echo ($carteira_selecionada != $cart['IDCarteira']) ? 'selected' : '' ?>>
                                <?php echo htmlspecialchars($cart['TipoCarteira']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="mb-3">
                    <label class="form-label text-secondary small fw-semibold text-uppercase mb-1">Valor</label>
                    <div class="input-group">
                        <span class="input-group-text bg-body-tertiary border-secondary-subtle text-secondary fw-bold">R$</span>
                        <input type="number" id="transf-valor" class="form-control bg-body-tertiary border-secondary-subtle text-light"
                            min="0.01" step="0.01" placeholder="0,00">
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label text-secondary small fw-semibold text-uppercase mb-1">Descrição</label>
                    <input type="text" id="transf-desc" class="form-control bg-body-tertiary border-secondary-subtle text-light"
                        value="Transferência entre carteiras" maxlength="120">
                </div>

                <div class="row g-2">
                    <div class="col-7">
                        <label class="form-label text-secondary small fw-semibold text-uppercase mb-1">Data</label>
                        <input type="date" id="transf-data" class="form-control bg-body-tertiary border-secondary-subtle text-light"
                            value="<?php echo date('Y-m-d') ?>">
                    </div>
                    <div class="col-5">
                        <label class="form-label text-secondary small fw-semibold text-uppercase mb-1">Status</label>
                        <select id="transf-status" class="form-select bg-body-tertiary border-secondary-subtle text-light">
                            <option value="efetivado">Efetivado</option>
                            <option value="pendente">Pendente</option>
                        </select>
                    </div>
                </div>
            </div>
            <div class="modal-footer border-secondary-subtle pt-2">
                <button type="button" class="btn btn-outline-secondary rounded-pill px-4" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" id="transf-salvar"
                    class="btn fw-semibold rounded-pill px-4"
                    style="background:rgba(96,165,250,0.15);color:#60a5fa;border:1px solid rgba(96,165,250,0.35);">
                    <i class="bi bi-arrow-left-right me-1"></i> Transferir
                </button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<style>
    .bg-charcoal-analysis {
        background-color: var(--bg-charcoal-analysis);
    }

    .auralis-table>tbody>tr.cursor-pointer:hover>td {
        background-color: var(--table-row-hover) !important;
        color: var(--text-main) !important;
    }

    .cc-dash-card:hover {
        background: var(--bg-hover) !important;
        border-color: var(--border-color-analysis) !important;
        transform: translateY(-2px);
        box-shadow: 0 6px 20px var(--gold-glow-analysis) !important;
    }

    .table-active {
        background-color: var(--bg-charcoal-analysis) !important;
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
        background-color: var(--bg-card) !important;
        border: 1px solid var(--bs-border-color) !important;
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

    function abrirComprovantes(registroId) {
        const modal = new bootstrap.Modal(document.getElementById('modalComprovantes'));
        const body = document.getElementById('modalComprovantesBody');
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
                        html += `<div class="text-center"><img src="${url}" class="img-fluid rounded-3" style="max-height:420px;object-fit:contain;" alt="${a.NomeOriginal}">
                                 <p class="text-secondary small mt-2">${a.NomeOriginal}</p></div>`;
                    } else {
                        html += `<div class="d-flex align-items-center gap-3 p-3 rounded-3" style="background:rgba(255,255,255,0.04);border:1px solid #333;">
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

    // ── Transferência entre Carteiras ──────────────────────────────────────
    (function() {
        var btn = document.getElementById('transf-salvar');
        if (!btn) return;
        btn.addEventListener('click', function() {
            var de     = document.getElementById('transf-de').value;
            var para   = document.getElementById('transf-para').value;
            var valor  = parseFloat(document.getElementById('transf-valor').value);
            var desc   = document.getElementById('transf-desc').value.trim();
            var data   = document.getElementById('transf-data').value;
            var status = document.getElementById('transf-status').value;
            var erroEl = document.getElementById('transf-erro');

            erroEl.classList.add('d-none');
            if (de === para)       { erroEl.textContent = 'Selecione carteiras diferentes.'; erroEl.classList.remove('d-none'); return; }
            if (!valor || valor <= 0) { erroEl.textContent = 'Informe um valor válido.'; erroEl.classList.remove('d-none'); return; }
            if (!data)             { erroEl.textContent = 'Informe a data.'; erroEl.classList.remove('d-none'); return; }

            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Transferindo...';

            var fd = new FormData();
            fd.append('acao',   'criar');
            fd.append('de',     de);
            fd.append('para',   para);
            fd.append('valor',  valor);
            fd.append('desc',   desc || 'Transferência entre carteiras');
            fd.append('data',   data);
            fd.append('status', status);

            fetch('carteira/transferencia.php', { method: 'POST', body: fd })
                .then(function(r) { return r.json(); })
                .then(function(res) {
                    if (res.ok) {
                        bootstrap.Modal.getInstance(document.getElementById('modalTransferencia')).hide();
                        window.location.reload();
                    } else {
                        var msgs = {
                            carteiras_iguais:  'Selecione carteiras diferentes.',
                            valor_invalido:    'Valor inválido.',
                            carteira_invalida: 'Carteira inválida ou sem permissão.',
                            data_invalida:     'Data inválida.'
                        };
                        erroEl.textContent = msgs[res.erro] || ('Erro: ' + (res.erro || 'desconhecido'));
                        erroEl.classList.remove('d-none');
                        btn.disabled = false;
                        btn.innerHTML = '<i class="bi bi-arrow-left-right me-1"></i> Transferir';
                    }
                })
                .catch(function() {
                    erroEl.textContent = 'Erro de comunicação. Tente novamente.';
                    erroEl.classList.remove('d-none');
                    btn.disabled = false;
                    btn.innerHTML = '<i class="bi bi-arrow-left-right me-1"></i> Transferir';
                });
        });

        // Limpa erro e reseta botão ao reabrir o modal
        var modal = document.getElementById('modalTransferencia');
        if (modal) {
            modal.addEventListener('show.bs.modal', function() {
                document.getElementById('transf-erro').classList.add('d-none');
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-arrow-left-right me-1"></i> Transferir';
                document.getElementById('transf-valor').value = '';
            });
        }
    })();

    function excluirTransferencia(grupo) {
        if (!grupo || !confirm('Excluir esta transferência? Isso remove o registro em ambas as carteiras.')) return;
        var fd = new FormData();
        fd.append('acao',  'excluir');
        fd.append('grupo', grupo);
        fetch('carteira/transferencia.php', { method: 'POST', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(res) {
                if (res.ok) { window.location.reload(); }
                else { alert('Erro ao excluir: ' + (res.erro || 'desconhecido')); }
            });
    }

    function toggleSection(key) {
        var sec  = document.getElementById('sec-' + key);
        var chev = document.getElementById('chev-' + key);
        if (!sec) return;
        var collapsed = sec.style.display === 'none';
        sec.style.display  = collapsed ? '' : 'none';
        chev.style.transform = collapsed ? '' : 'rotate(180deg)';
        try { localStorage.setItem('dash_sec_' + key, collapsed ? '1' : '0'); } catch(e) {}
    }

    (function() {
        ['cofrinhos','cartoes'].forEach(function(key) {
            var saved = null;
            try { saved = localStorage.getItem('dash_sec_' + key); } catch(e) {}
            if (saved === '0') {
                var sec  = document.getElementById('sec-' + key);
                var chev = document.getElementById('chev-' + key);
                if (sec)  sec.style.display = 'none';
                if (chev) chev.style.transform = 'rotate(180deg)';
            }
        });
    })();
</script>

<style>
.busca-pill {
    background: var(--bg-card);
    border: 1px solid var(--card-border-color);
    color: var(--text-muted);
    border-radius: 20px;
    font-size: 0.75rem;
    padding: 3px 12px;
    transition: background .15s, color .15s, border-color .15s;
}
.busca-pill:hover { background: var(--bg-hover); color: var(--text-main); }
.busca-pill.active {
    background: var(--accent);
    border-color: var(--accent);
    color: #000;
    font-weight: 600;
}
</style>
<script>
(function () {
    var input      = document.getElementById('buscaInput');
    var pills      = document.querySelectorAll('.busca-pill');
    var emptyRow   = document.getElementById('buscaVazio');
    var filtroAtivo = 'tudo';

    function filtrar() {
        var texto = input ? input.value.toLowerCase().trim() : '';
        var rows  = document.querySelectorAll('tr.tr-transacao');
        var visiveis = 0;

        rows.forEach(function (tr) {
            var desc   = tr.dataset.desc   || '';
            var cat    = tr.dataset.cat    || '';
            var valor  = tr.dataset.valor  || '';
            var status = tr.dataset.status || '';
            var tipo   = tr.dataset.tipo   || '';

            var matchTexto = !texto ||
                desc.indexOf(texto) !== -1 ||
                cat.indexOf(texto)  !== -1 ||
                valor.indexOf(texto) !== -1;

            var matchFiltro = true;
            if      (filtroAtivo === 'receita')       matchFiltro = tipo === 'receita';
            else if (filtroAtivo === 'despesa')       matchFiltro = tipo === 'despesa';
            else if (filtroAtivo === 'transferencia') matchFiltro = tipo.indexOf('transferencia') === 0;
            else if (filtroAtivo === 'pendente')      matchFiltro = status === 'pendente';
            else if (filtroAtivo === 'efetivado')     matchFiltro = status === 'efetivado';

            var visivel = matchTexto && matchFiltro;
            tr.style.display = visivel ? '' : 'none';
            var det = tr.nextElementSibling;
            if (det) det.style.display = visivel ? '' : 'none';
            if (visivel) visiveis++;
        });

        if (emptyRow) emptyRow.style.display = visiveis === 0 ? '' : 'none';
    }

    if (input) input.addEventListener('input', filtrar);

    pills.forEach(function (pill) {
        pill.addEventListener('click', function () {
            pills.forEach(function (p) { p.classList.remove('active'); });
            this.classList.add('active');
            filtroAtivo = this.dataset.filtro;
            filtrar();
        });
    });
})();
</script>

<?php require_once 'geral/footer.php'; ?>