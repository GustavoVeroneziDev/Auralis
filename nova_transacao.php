<?php
// ==============================================================================
// 1. LÓGICA PHP (Processamento de Dados)
// ==============================================================================
session_start();
if (!isset($_SESSION['usuario_id'])) {
    header("Location: usuario/login.php");
    exit;
}
require_once 'config/conexao.php';

// Função auxiliar para gerar UUID no padrão MySQL
if (!function_exists('gerarUuid')) {
    function gerarUuid()
    {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x', mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000, mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff));
    }
}

$usuario_id = $_SESSION['usuario_id'];
$carteiras = [];
$categorias = [];
$erro = null;

// --- VERIFICA SE É MODO DE EDIÇÃO ---
$id_editar = $_GET['editar'] ?? null;
$transacao_edit = null;

if ($id_editar) {
    // Busca os dados da transação específica para preencher o formulário
    $sqlEdit = "SELECT * FROM Registro WHERE IDRegistro = :id AND FKUsuario = :uid";
    $stmtEdit = $pdo->prepare($sqlEdit);
    $stmtEdit->execute([':id' => $id_editar, ':uid' => $usuario_id]);
    $transacao_edit = $stmtEdit->fetch();

    // Trava de segurança: se a transação não existir, volta pro painel
    if (!$transacao_edit) {
        header("Location: dashboard.php");
        exit;
    }
}

// UX INTELIGENTE: Pega o tipo para filtrar o banco. Se for edição, trava no tipo original.
$tipo_sugerido = $_POST['tipo_registro'] ?? ($transacao_edit ? $transacao_edit['TipoRegistro'] : ($_GET['tipo'] ?? 'despesa'));

try {
    // Busca carteiras (Lembrete: Mudei para a sintaxe do MySQL puro)
    $sqlCarteiras = "
        SELECT DISTINCT c.IDCarteira, c.TipoCarteira
        FROM Carteira c
        LEFT JOIN MembroCarteira mc ON mc.FKCarteira = c.IDCarteira AND mc.FKUsuario = :uid_membro AND mc.StatusConvite = 1
        WHERE c.FKUsuarioDono = :uid_dono OR mc.FKCarteira IS NOT NULL
        ORDER BY c.TipoCarteira ASC
    ";
    $stmtC = $pdo->prepare($sqlCarteiras);
    $stmtC->execute([':uid_dono' => $usuario_id, ':uid_membro' => $usuario_id]);
    $carteiras = $stmtC->fetchAll();

    // Busca APENAS as categorias do tipo sugerido
    $sqlCategorias = "
        SELECT IDCategoria, NomeCategoria 
        FROM Categoria 
        WHERE FKUsuario = :uid AND TipoCategoria = :tipo 
        ORDER BY NomeCategoria ASC
    ";
    $stmtCat = $pdo->prepare($sqlCategorias);
    $stmtCat->execute([':uid' => $usuario_id, ':tipo' => $tipo_sugerido]);
    $categorias = $stmtCat->fetchAll();
} catch (PDOException $e) {
    $carteiras = [];
    $categorias = [];
}

// Processa o Formulário quando o usuário clica em Salvar (Criar ou Atualizar)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tipoRegistro   = trim($_POST['tipo_registro'] ?? '');

    // ── LIMPEZA DA MÁSCARA ──────────────────────────────────────────
    $valorPost  = trim($_POST['valor'] ?? '');

    // 1. Remove letras, "R$", espaços normais e espaços invisíveis!
    // Sobram apenas números, pontos e vírgulas (Ex: 1.500,50)
    $valorLimpo = preg_replace('/[^\d.,]/', '', $valorPost);

    // 2. Converte para o padrão americano de Banco de Dados (1500.50)
    if (strpos($valorLimpo, ',') !== false) {
        $valorLimpo = str_replace('.', '', $valorLimpo); // Remove pontos de milhar
        $valorRaw   = str_replace(',', '.', $valorLimpo); // Troca vírgula por ponto
    } else {
        $valorRaw   = $valorLimpo; // Já está no formato certo ou é número inteiro
    }
    // ────────────────────────────────────────────────────────────────

    $descricao      = trim($_POST['descricao'] ?? '');
    $dataRegistro   = trim($_POST['data_registro'] ?? '');
    $dataVencimento = trim($_POST['data_vencimento'] ?? '');
    $statusRegistro = trim($_POST['status_registro'] ?? '');
    $carteiraId     = trim($_POST['carteira_id'] ?? '');
    $categoriaId    = trim($_POST['categoria_id'] ?? '') ?: null;
    $subCategoriaId = trim($_POST['subcategoria_id'] ?? '') ?: null;
    $recorrente     = isset($_POST['recorrente']) ? 1 : 0;
    $diaVencimento  = $recorrente ? intval($_POST['dia_vencimento'] ?? 0) : null;
    $parcelado      = isset($_POST['parcelado']) ? 1 : 0;
    $numParcelas    = $parcelado ? max(2, min(48, intval($_POST['num_parcelas'] ?? 2))) : 1;

    // Validações (agora usando o valorRaw limpo)
    if (!in_array($tipoRegistro, ['receita', 'despesa'])) $erro = "Tipo de registro inválido.";
    elseif (empty($valorRaw) || !is_numeric($valorRaw)) $erro = "Informe um valor numérico válido.";
    elseif (floatval($valorRaw) <= 0) $erro = "O valor deve ser maior que zero.";
    elseif (empty($descricao)) $erro = "A descrição não pode ficar em branco.";
    elseif (empty($dataRegistro)) $erro = "Selecione a data do registro.";
    elseif (!in_array($statusRegistro, ['pendente', 'efetivado'])) $erro = "Status inválido.";
    elseif (empty($carteiraId)) $erro = "Selecione uma carteira.";
    elseif ($recorrente && ($diaVencimento < 1 || $diaVencimento > 31)) $erro = "Dia de vencimento inválido (1 a 31).";
    elseif ($parcelado && intval($_POST['num_parcelas'] ?? 0) === 1) $erro = "O número de parcelas não pode ser 1. Se não quiser parcelar, desative a opção de parcelamento.";
    elseif ($parcelado && $recorrente) $erro = "Uma transação não pode ser parcelada E recorrente ao mesmo tempo.";

    if (!$erro) {
        $valor = $valorRaw; // O valor já está limpo
        $dataVencimento = !empty($dataVencimento) ? $dataVencimento : null;

        try {
            if (isset($_POST['id_editar']) && !empty($_POST['id_editar'])) {
                // ── ATUALIZAÇÃO (UPDATE) ─────────────────────────────────────
                $sql = "
                    UPDATE Registro SET
                        TipoRegistro = :tipo, Valor = :valor, Descricao = :descricao,
                        MomentoRegistro = :momento, DataVencimento = :vencimento,
                        StatusRegistro = :status, Recorrente = :recorrente, DiaVencimento = :dia,
                        FKCarteira = :carteira, FKCategoria = :categoria
                    WHERE IDRegistro = :id_editar AND FKUsuario = :usuario
                ";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    ':tipo'      => $tipoRegistro,
                    ':valor'     => $valor,
                    ':descricao' => $descricao,
                    ':momento'   => $dataRegistro,
                    ':vencimento' => $dataVencimento,
                    ':status'    => $statusRegistro,
                    ':recorrente' => $recorrente,
                    ':dia'       => $diaVencimento,
                    ':carteira'  => $carteiraId,
                    ':categoria' => $categoriaId,
                    ':id_editar' => $_POST['id_editar'],
                    ':usuario'   => $usuario_id,
                ]);

                // ── PROPAGAÇÃO DE EDIÇÃO (FUTUROS) ───────────────────────────
                $grupoAtual = $transacao_edit['GrupoParcela'] ?? null;
                $dataAtual  = $transacao_edit['MomentoRegistro'];

                if (isset($_POST['editar_futuros']) && $grupoAtual) {
                    $sqlFuturos = "
                        UPDATE Registro SET
                            Valor = :valor, Descricao = :descricao, 
                            FKCarteira = :carteira, FKCategoria = :categoria
                        WHERE GrupoParcela = :grupo
                          AND FKUsuario = :usuario
                          AND IDRegistro != :id_editar
                          AND MomentoRegistro > :data_base
                          AND StatusRegistro = 'pendente'
                          AND TotalParcelas IS NULL
                    ";
                    $stmtF = $pdo->prepare($sqlFuturos);
                    $stmtF->execute([
                        ':valor'     => $valor,
                        ':descricao' => $descricao,
                        ':carteira'  => $carteiraId,
                        ':categoria' => $categoriaId,
                        ':grupo'     => $grupoAtual,
                        ':usuario'   => $usuario_id,
                        ':id_editar' => $_POST['id_editar'], // Correção: Variável adicionada
                        ':data_base' => $dataAtual
                    ]);
                }
                header("Location: dashboard.php?sucesso=editado");
            } elseif ($parcelado && $numParcelas >= 2) {
                // ── CRIAÇÃO PARCELADA ────────────────
                $grupoParcela = gerarUuid();
                $dataBase     = new DateTime($dataRegistro);

                $valorJurosTotal = 0;
                $jurosPorParcela = null;

                // 1. VERIFICAÇÃO DE ACESSO (PRO, VIP OU TESTE)
                $planoUsuarioLogado = strtolower($_SESSION['plano'] ?? 'free');
                $horasTesteRestantes = function_exists('obterHorasRestantesTeste') ? obterHorasRestantesTeste() : 0;
                $acessoLiberadoJuros = ($planoUsuarioLogado === 'pro' || $planoUsuarioLogado === 'vip' || $horasTesteRestantes > 0);

                // 2. LÓGICA DE JUROS (COM TRAVA DE SEGURANÇA)
                if ($acessoLiberadoJuros && isset($_POST['tipo_juros']) && $_POST['tipo_juros'] === 'com') {
                    $valJurosLimpo = preg_replace('/[^\d.,]/', '', $_POST['valor_parcela_juros'] ?? '0');
                    if (strpos($valJurosLimpo, ',') !== false) {
                        $valJurosLimpo = str_replace('.', '', $valJurosLimpo);
                        $valJurosRaw   = str_replace(',', '.', $valJurosLimpo);
                    } else {
                        $valJurosRaw = $valJurosLimpo;
                    }

                    $parcelaComJuros = (float)$valJurosRaw;

                    if ($parcelaComJuros > 0) {
                        $valorTotalComJuros = $parcelaComJuros * $numParcelas;

                        // Bloqueia se o total com juros for menor ou igual ao valor original
                        if ($valorTotalComJuros <= (float)$valorRaw) {
                            $erro = "O valor total com juros (R$ " . number_format($valorTotalComJuros, 2, ',', '.') . ") deve ser maior que o valor original (R$ " . number_format((float)$valorRaw, 2, ',', '.') . "). Corrija o valor da parcela.";
                        } else {
                            $valorJurosTotal = $valorTotalComJuros - $valor;
                            $valor           = $valorTotalComJuros;
                        }
                    }
                }

                // Se a validação de juros gerou um erro, interrompe o bloco de inserção
                if ($erro) {
                    goto fim_processamento;
                }

                $valorParcela = floor(($valor / $numParcelas) * 100) / 100;
                $resto        = $valor - ($valorParcela * $numParcelas);

                // Divide o juros total pelo número de parcelas para salvar em cada linha
                if ($valorJurosTotal > 0) {
                    $jurosPorParcela = round($valorJurosTotal / $numParcelas, 2);
                }

                $sqlParcela = "
                    INSERT INTO Registro (
                        IDRegistro, TipoRegistro, Valor, ValorJuros, Descricao, MomentoRegistro, DataVencimento,
                        StatusRegistro, Recorrente, DiaVencimento, FKCarteira, FKUsuario, FKCategoria,
                        GrupoParcela, ParcelaAtual, TotalParcelas
                    ) VALUES (
                        :id, :tipo, :valor, :juros, :descricao, :momento, :vencimento,
                        :status, 0, NULL, :carteira, :usuario, :categoria,
                        :grupo, :parc_atual, :tot_parc
                    )
                ";
                $stmtP = $pdo->prepare($sqlParcela);

                for ($i = 0; $i < $numParcelas; $i++) {
                    $mesAlvo = (int)$dataBase->format('m') + $i;
                    $anoAlvo = (int)$dataBase->format('Y') + floor(($mesAlvo - 1) / 12);
                    $mesAlvo = (($mesAlvo - 1) % 12) + 1;
                    $diaAlvo = (int)$dataBase->format('d');
                    $diaCorreto = min($diaAlvo, date('t', strtotime(sprintf('%04d-%02d-01', $anoAlvo, $mesAlvo))));
                    $dataStr = sprintf('%04d-%02d-%02d', $anoAlvo, $mesAlvo, $diaCorreto);

                    $valAtual = ($i === 0) ? ($valorParcela + $resto) : $valorParcela;
                    $statusP  = ($i === 0) ? $statusRegistro : 'pendente';

                    $stmtP->execute([
                        ':id'         => gerarUuid(),
                        ':tipo'      => $tipoRegistro,
                        ':valor'      => $valAtual,
                        ':juros'     => $jurosPorParcela,
                        ':descricao'  => $descricao,
                        ':momento'   => $dataStr,
                        ':vencimento' => $dataStr,
                        ':status'    => $statusP,
                        ':carteira'   => $carteiraId,
                        ':usuario'   => $usuario_id,
                        ':categoria'  => $categoriaId,
                        ':grupo'     => $grupoParcela,
                        ':parc_atual' => ($i + 1),
                        ':tot_parc'  => $numParcelas
                    ]);
                }
                fim_processamento:
                if (!$erro) {
                    header("Location: dashboard.php?sucesso=parcelado&parcelas={$numParcelas}");
                }
            } elseif ($recorrente) {
                // ── CRIAÇÃO RECORRENTE (Fix NULL e Pulo de Mês) ──────────────
                $grupoRecorrencia = gerarUuid();
                $dataBase         = new DateTime($dataRegistro);
                $limiteMeses      = 24;

                // Removemos explicitamente ParcelaAtual e TotalParcelas para usar o DEFAULT do banco e evitar crash
                $sqlInsert = "
                    INSERT INTO Registro (
                        IDRegistro, TipoRegistro, Valor, Descricao, MomentoRegistro, DataVencimento,
                        StatusRegistro, Recorrente, DiaVencimento, FKCarteira, FKUsuario, FKCategoria,
                        GrupoParcela
                    ) VALUES (
                        :id, :tipo, :valor, :descricao, :momento, :vencimento,
                        :status, 1, :dia, :carteira, :usuario, :categoria, :grupo
                    )
                ";
                $stmtR = $pdo->prepare($sqlInsert);

                for ($i = 0; $i < $limiteMeses; $i++) {
                    // Cálculo matemático para forçar o mês e ano corretos sequencialmente
                    $mesAlvo = (int)$dataBase->format('m') + $i;
                    $anoAlvo = (int)$dataBase->format('Y') + floor(($mesAlvo - 1) / 12);
                    $mesAlvo = (($mesAlvo - 1) % 12) + 1;

                    $diaCorreto = min($diaVencimento, date('t', strtotime(sprintf('%04d-%02d-01', $anoAlvo, $mesAlvo))));
                    $dataStr = sprintf('%04d-%02d-%02d', $anoAlvo, $mesAlvo, $diaCorreto);

                    $statusRec = ($i === 0) ? $statusRegistro : 'pendente';

                    $stmtR->execute([
                        ':id'         => gerarUuid(),
                        ':tipo'      => $tipoRegistro,
                        ':valor'      => $valor,
                        ':descricao' => $descricao,
                        ':momento'    => $dataStr,
                        ':vencimento' => $dataStr,
                        ':status'     => $statusRec,
                        ':dia'       => $diaCorreto,
                        ':carteira'   => $carteiraId,
                        ':usuario'   => $usuario_id,
                        ':categoria'  => $categoriaId,
                        ':grupo'     => $grupoRecorrencia
                    ]);
                }
                header("Location: dashboard.php?sucesso=recorrente");
            } else {
                // ── CRIAÇÃO SIMPLES (Transação Única) ────────────────────────
                $sql = "
                    INSERT INTO Registro (
                        IDRegistro, TipoRegistro, Valor, Descricao, MomentoRegistro, DataVencimento,
                        StatusRegistro, Recorrente, DiaVencimento, FKCarteira, FKUsuario, FKCategoria
                    ) VALUES (
                        :id, :tipo, :valor, :descricao, :momento, :vencimento,
                        :status, :recorrente, :dia, :carteira, :usuario, :categoria
                    )
                ";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    ':id'         => gerarUuid(),
                    ':tipo'      => $tipoRegistro,
                    ':valor'      => $valor,
                    ':descricao' => $descricao,
                    ':momento'    => $dataRegistro,
                    ':vencimento' => $dataVencimento,
                    ':status'     => $statusRegistro,
                    ':recorrente' => $recorrente ? 1 : 0,
                    ':dia'        => $diaVencimento,
                    ':carteira'  => $carteiraId,
                    ':usuario'    => $usuario_id,
                    ':categoria' => $categoriaId,
                ]);
                header("Location: dashboard.php?sucesso=registro");
            }
            exit;
        } catch (PDOException $e) {
            $erro = "Erro ao salvar o registro: " . $e->getMessage();
        }
    }
}

// Valores Iniciais do Formulário
$val_valor  = $_POST['valor'] ?? ($transacao_edit ? $transacao_edit['Valor'] : '');
$val_desc   = $_POST['descricao'] ?? ($transacao_edit ? $transacao_edit['Descricao'] : '');
$val_data   = $_POST['data_registro'] ?? ($transacao_edit ? date('Y-m-d', strtotime($transacao_edit['MomentoRegistro'])) : date('Y-m-d'));
$val_status = $_POST['status_registro'] ?? ($transacao_edit ? $transacao_edit['StatusRegistro'] : 'efetivado');
$val_cart   = $_POST['carteira_id'] ?? ($transacao_edit ? $transacao_edit['FKCarteira'] : ($_GET['carteira_id'] ?? ''));
$val_cat    = $_POST['categoria_id'] ?? ($transacao_edit ? $transacao_edit['FKCategoria'] : '');
$val_venc   = $_POST['data_vencimento'] ?? ($transacao_edit ? $transacao_edit['DataVencimento'] : '');
$val_rec    = isset($_POST['recorrente']) ? true : ($transacao_edit ? $transacao_edit['Recorrente'] : false);
$val_dia        = $_POST['dia_vencimento'] ?? ($transacao_edit ? $transacao_edit['DiaVencimento'] : '');
$val_parcelado  = isset($_POST['parcelado']) ? true : false;
$val_num_parc   = $_POST['num_parcelas'] ?? 2;
// Na edição, parcelamento não está disponível para evitar inconsistências
$is_edicao      = !empty($id_editar);

require_once 'geral/header.php';
?>

<main class="container py-4 mt-2 flex-grow-1" style="padding-inline: var(--space-page-x);">
    <div class="row justify-content-center">
        <div class="col-md-9 col-lg-7">

            <div class="d-flex justify-content-between align-items-center mb-4 border-bottom border-secondary-subtle pb-3">
                <h2 class="fw-bold text-light mb-0"><?= $id_editar ? 'Editar Transação' : 'Nova Transação' ?></h2>
                <a href="dashboard.php" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-arrow-left me-1"></i> Voltar
                </a>
            </div>

            <?php if ($erro): ?>
                <div class="d-flex align-items-center gap-2 rounded-3 px-4 py-3 mb-3"
                    style="background-color: rgba(120,0,0,0.35); border: 1px solid rgba(200,50,50,0.45); color: #f28b8b;">
                    <i class="bi bi-exclamation-triangle-fill flex-shrink-0" style="font-size:0.95rem;"></i>
                    <span style="font-size:0.9rem; font-weight:500;"><?= htmlspecialchars($erro) ?></span>
                </div>
            <?php endif; ?>

            <?php if (empty($carteiras)): ?>
                <div class="alert alert-warning rounded-3">
                    <i class="bi bi-wallet2 me-2"></i> Você não tem nenhuma carteira. <a href="carteira/nova_carteira.php" class="alert-link">Criar carteira</a>.
                </div>
            <?php else: ?>

                <div class="card bg-body-tertiary border-secondary-subtle shadow-sm rounded-4">
                    <form id="formTransacao" method="POST" action="" novalidate class="auralis-premium-form p-4">
                        <input type="hidden" name="tipo_registro" value="<?= htmlspecialchars($tipo_sugerido) ?>">
                        <?php if ($id_editar): ?>
                            <input type="hidden" name="id_editar" value="<?= htmlspecialchars($id_editar) ?>">
                        <?php endif; ?>

                        <div class="text-center mb-4">
                            <span class="badge badge-tipo rounded-pill px-4 py-2 shadow-sm">
                                <?php if ($tipo_sugerido === 'receita'): ?>
                                    <span class="fw-bold text-success fs-5">💰 Receita</span>
                                <?php else: ?>
                                    <span class="fw-bold text-danger fs-5">💸 Despesa</span>
                                <?php endif; ?>
                            </span>
                        </div>

                        <div class="mb-5 d-flex align-items-center justify-content-center pb-3 auralis-line-input">
                            <input type="text" inputmode="numeric" name="valor" id="valor"
                                class="form-control form-control-lg bg-transparent border-0 text-gold-analysis fw-bold text-center fs-1-large valor-input p-0 p-lg-1 no-spinners"
                                placeholder="R$ 0,00" required autofocus autocomplete="off"
                                value="<?= htmlspecialchars($val_valor) ?>"
                                oninput="mascaraMoeda(this)">
                        </div>

                        <div class="d-flex align-items-center mb-4 pb-2 auralis-line-input">
                            <i class="bi bi-paragraph text-secondary-analysis me-3 w-icon text-center"></i>
                            <input type="text" name="descricao" id="descricao"
                                class="form-control bg-transparent border-0 text-light-analysis px-0 shadow-none fs-6 fw-bold"
                                placeholder="Descrição:" maxlength="255" required
                                value="<?= htmlspecialchars($val_desc) ?>">
                        </div>

                        <div class="d-flex align-items-center justify-content-between mb-4 pb-3 auralis-line-input">
                            <div class="d-flex align-items-center">
                                <i class="bi bi-clock-history text-secondary-analysis me-3 w-icon text-center"></i>
                                <span class="text-light fs-6" id="texto_status">Foi <?= $tipo_sugerido === 'receita' ? 'recebido' : 'pago' ?></span>
                            </div>
                            <div class="form-check form-switch fs-4 mb-0 toggle-analysis">
                                <input type="hidden" name="status_registro" id="status_real" value="<?= htmlspecialchars($val_status) ?>">
                                <input class="form-check-input bg-dark border-border-color shadow-none" type="checkbox" role="switch" id="toggle_status" <?= $val_status === 'efetivado' ? 'checked' : '' ?>>
                            </div>
                        </div>

                        <div class="d-flex align-items-center mb-4 pb-2 auralis-line-input">
                            <i class="bi bi-credit-card text-secondary-analysis me-3 w-icon text-center"></i>
                            <select name="carteira_id" id="carteira_id" class="form-select bg-transparent border-0 text-light-analysis px-0 shadow-none fw-semibold fs-6" required>
                                <option class="bg-card" value="" disabled <?= empty($val_cart) ? 'selected' : '' ?>>Selecione a Carteira</option>
                                <?php foreach ($carteiras as $cart): ?>
                                    <option class="bg-card" value="<?= htmlspecialchars($cart['IDCarteira']) ?>" <?= ($val_cart == $cart['IDCarteira']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($cart['TipoCarteira']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="row g-3 mb-4 auralis-line-input">
                            <div class="col-6 d-flex align-items-center border-end border-border-color pe-3">
                                <i class="bi bi-tags text-secondary-analysis me-2 fs-7"></i>
                                <select name="categoria_id" class="form-select bg-transparent border-0 text-muted-analysis px-0 shadow-none fs-7 fw-bold">
                                    <option class="bg-card" value="">Sem Categoria</option>
                                    <?php foreach ($categorias as $cat): ?>
                                        <option class="bg-card" value="<?= htmlspecialchars($cat['IDCategoria']) ?>" <?= ($val_cat == $cat['IDCategoria']) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($cat['NomeCategoria']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-6 d-flex align-items-center ps-3">
                                <i class="bi bi-calendar3 text-secondary-analysis me-2 fs-7"></i>
                                <input type="date" name="data_registro" class="form-control bg-transparent border-0 text-light-analysis px-0 shadow-none fs-7 fw-bold"
                                    value="<?= htmlspecialchars($val_data) ?>" required>
                            </div>
                        </div>

                        <?php
                        // ── INTELIGÊNCIA DE UX: Descobre o que estamos editando ──
                        $is_parcela    = $is_edicao && !empty($transacao_edit['TotalParcelas']);
                        $is_recorrente = $is_edicao && ($transacao_edit['Recorrente'] == 1);
                        ?>

                        <div class="accordion accordion-flush mb-5 border border-border-color rounded-3 overflow-hidden auralis-line-input" id="accordionMaisDetalhes">
                            <div class="accordion-item bg-transparent">
                                <h2 class="accordion-header">
                                    <button class="accordion-button <?= (!empty($val_venc) || $val_rec || $is_parcela) ? '' : 'collapsed' ?> bg-transparent text-secondary-analysis shadow-none py-2 px-3 small fs-7"
                                        type="button" data-bs-toggle="collapse" data-bs-target="#collapseDetalhes">
                                        <i class="bi bi-sliders me-2"></i> Configurações do lançamento
                                    </button>
                                </h2>
                                <div id="collapseDetalhes" class="accordion-collapse collapse <?= (!empty($val_venc) || $val_rec || $is_parcela) ? 'show' : '' ?>"
                                    data-bs-parent="#accordionMaisDetalhes">
                                    <div class="accordion-body border-top border-border-color pt-3 px-3 pb-4 bg-charcoal d-flex flex-column gap-4">

                                        <?php if ($is_recorrente && !empty($transacao_edit['GrupoParcela'])): ?>
                                            <div class="p-3 rounded-3 border border-border-color" style="background:rgba(255,255,255,.03);">
                                                <div class="form-check form-switch toggle-analysis toggle-analysis-muted">
                                                    <input class="form-check-input bg-dark border-border-color shadow-none" type="checkbox"
                                                        name="editar_futuros" id="editar_futuros" checked>
                                                    <label class="form-check-label text-light fs-7 fw-semibold" for="editar_futuros">
                                                        Aplicar alterações em <strong>todos os meses futuros pendentes</strong>
                                                    </label>
                                                </div>
                                            </div>
                                        <?php endif; ?>

                                        <?php if (!$is_edicao || $is_recorrente): ?>
                                            <!-- ── 1. RECORRENTE ──────────────────────────────────── -->
                                            <div>
                                                <div class="d-flex align-items-start justify-content-between gap-3"
                                                    <?= $is_recorrente ? 'style="pointer-events:none;opacity:0.6;"' : '' ?>>
                                                    <div>
                                                        <div class="text-light fw-semibold fs-7 mb-1 d-flex align-items-center gap-2">
                                                            <i class="bi bi-arrow-repeat text-success"></i>
                                                            Conta recorrente
                                                            <?= $is_recorrente ? '<span class="badge bg-secondary" style="font-size:0.6rem;">Fixo</span>' : '' ?>
                                                        </div>
                                                        <div class="text-secondary" style="font-size:0.75rem;">
                                                            Repete todo mês na mesma data — assinaturas, aluguel, academia.
                                                        </div>
                                                    </div>
                                                    <div class="form-check form-switch fs-4 mb-0 toggle-analysis flex-shrink-0 mt-1">
                                                        <input class="form-check-input bg-dark border-border-color shadow-none"
                                                            type="checkbox" name="recorrente" id="recorrente"
                                                            <?= $val_rec ? 'checked' : '' ?>>
                                                    </div>
                                                </div>

                                                <div id="bloco_recorrencia" style="display:<?= $val_rec ? 'block' : 'none' ?>;"
                                                    class="mt-3 ps-3 border-start border-border-color">
                                                    <label class="form-label text-secondary-analysis fs-7 mb-1">
                                                        todo mês vence em <span class="text-light fw-semibold">qual</span> dia?
                                                    </label>
                                                    <input type="number" name="dia_vencimento" id="dia_vencimento"
                                                        class="form-control bg-dark border-border-color text-light-analysis form-control-sm no-spinners fs-7"
                                                        style="max-width:100px;"
                                                        min="1" max="31" placeholder="Ex: 10"
                                                        value="<?= htmlspecialchars($val_dia) ?>"
                                                        <?= $is_recorrente ? 'readonly' : '' ?>>
                                                    <div class="text-secondary mt-1" style="font-size:0.72rem;">
                                                        Insira o dia que a cobrança cai todo mês (1 a 31).
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endif; ?>

                                        <?php if (!$is_edicao): ?>
                                            <!-- ── 2. PARCELADO ───────────────────────────────────── -->
                                            <div class="pt-3 border-top border-border-color">
                                                <div class="d-flex align-items-start justify-content-between gap-3">
                                                    <div>
                                                        <div class="text-light fw-semibold fs-7 mb-1 d-flex align-items-center gap-2">
                                                            <i class="bi bi-credit-card-2-front" style="color:#a78bfa;"></i>
                                                            <?= $tipo_sugerido === 'receita' ? 'Recebimento parcelado' : 'Compra parcelada' ?>
                                                        </div>
                                                        <div class="text-secondary" style="font-size:0.75rem;">
                                                            <?= $tipo_sugerido === 'receita'
                                                                ? 'Valor recebido em partes — comissão, prestação de serviço, etc.'
                                                                : 'Divide o valor em N meses — cartão ou carnê.' ?>
                                                        </div>
                                                    </div>
                                                    <div class="form-check form-switch fs-4 mb-0 toggle-analysis flex-shrink-0 mt-1">
                                                        <input class="form-check-input bg-dark border-border-color shadow-none"
                                                            type="checkbox" name="parcelado" id="toggle_parcelado"
                                                            <?= $val_parcelado ? 'checked' : '' ?>>
                                                    </div>
                                                </div>

                                                <div id="bloco_parcelamento" style="display:<?= $val_parcelado ? 'block' : 'none' ?>;"
                                                    class="mt-3 ps-3 border-start border-border-color">

                                                    <label class="form-label text-secondary-analysis fs-7 mb-1">Em quantas vezes?</label>
                                                    <div class="d-flex align-items-center gap-3 mb-3">
                                                        <input type="number" name="num_parcelas" id="num_parcelas"
                                                            class="form-control bg-dark border-border-color text-light-analysis form-control-sm no-spinners fs-7"
                                                            style="max-width:100px;" min="2" max="48" placeholder="Ex: 3"
                                                            value="<?= htmlspecialchars($val_num_parc) ?>">
                                                        <div id="preview_parcela" class="fs-7"></div>
                                                    </div>

                                                    <?php
                                                    $planoFront      = strtolower($_SESSION['plano'] ?? 'free');
                                                    $testeFront      = function_exists('obterHorasRestantesTeste') ? (obterHorasRestantesTeste() > 0) : false;
                                                    $liberaJuros     = ($planoFront === 'pro' || $planoFront === 'vip' || $testeFront);
                                                    $assinanteNativo = ($planoFront === 'pro' || $planoFront === 'vip');
                                                    ?>
                                                    <div class="d-flex gap-3 mb-2">
                                                        <div class="form-check">
                                                            <input class="form-check-input bg-dark border-border-color shadow-none"
                                                                type="radio" name="tipo_juros" id="juros_sem" value="sem" checked>
                                                            <label class="form-check-label text-light fs-7" for="juros_sem">Sem juros</label>
                                                        </div>
                                                        <div class="form-check" <?= !$liberaJuros ? 'title="Exclusivo Auralis PRO" data-bs-toggle="tooltip"' : '' ?>>
                                                            <input class="form-check-input bg-dark border-border-color shadow-none"
                                                                type="radio" name="tipo_juros" id="juros_com" value="com"
                                                                <?= !$liberaJuros ? 'disabled' : '' ?>>
                                                            <label class="form-check-label text-light fs-7 d-flex align-items-center gap-1" for="juros_com">
                                                                Com juros
                                                                <?php if (!$assinanteNativo): ?>
                                                                    <?= function_exists('badgePremium') ? badgePremium('pro', $testeFront) : '' ?>
                                                                <?php endif; ?>
                                                            </label>
                                                        </div>
                                                    </div>

                                                    <div id="bloco_com_juros" style="display:none;" class="mt-2 bg-charcoal p-3 border border-border-color rounded-3">
                                                        <label class="form-label text-secondary-analysis fs-7 mb-1">
                                                            Valor exato de <strong>cada parcela</strong> com juros:
                                                        </label>
                                                        <div class="input-group input-group-sm mb-1" style="max-width:200px;">
                                                            <span class="input-group-text bg-dark border-border-color text-secondary-analysis fs-7">R$</span>
                                                            <input type="text" inputmode="numeric" name="valor_parcela_juros" id="valor_parcela_juros"
                                                                class="form-control bg-dark border-border-color text-gold-analysis fw-bold fs-7 no-spinners"
                                                                placeholder="0,00"
                                                                oninput="mascaraMoeda(this); atualizarPreviewParcela();">
                                                        </div>
                                                        <div class="text-secondary opacity-75 mt-1" style="font-size:0.7rem;" id="preview_total_juros">
                                                            <i class="bi bi-calculator me-1"></i> Digite o valor da parcela para calcular.
                                                        </div>
                                                    </div>

                                                    <div class="text-secondary mt-2" style="font-size:0.72rem;">
                                                        <i class="bi bi-info-circle me-1"></i>
                                                        <?= $tipo_sugerido === 'receita'
                                                            ? 'Um recebimento por mês a partir da data acima. Mínimo 2x, máximo 48x.'
                                                            : 'Uma entrada por mês a partir da data acima. Mínimo 2x, máximo 48x.'
                                                        ?>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endif; ?>

                                        <!-- ── 3. DATA LIMITE PARA PAGAMENTO ─────────────────── -->
                                        <div class="pt-3 border-top border-border-color">
                                            <label class="text-light fw-semibold fs-7 mb-1 d-flex align-items-center gap-2">
                                                <i class="bi bi-calendar-x text-danger"></i>
                                                Data limite para pagamento
                                                <span class="badge bg-secondary fw-normal" style="font-size:0.62rem;">Opcional</span>
                                            </label>
                                            <div class="text-secondary mb-2" style="font-size:0.75rem;">
                                                Quando essa conta expira ou vence — ex: boleto, fatura de cartão.
                                            </div>
                                            <input type="date" name="data_vencimento"
                                                class="form-control bg-dark border-border-color text-light-analysis fs-7"
                                                value="<?= htmlspecialchars($val_venc) ?>">
                                        </div>

                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="d-grid mt-2">
                            <button id="btnSalvar" type="submit" class="btn btn-gold fw-bold text-dark py-3 rounded-pill fs-6 shadow-lg d-flex align-items-center justify-content-center transition-hover">
                                <?= $id_editar ? 'Salvar Alterações' : 'Salvar Transação' ?>
                            </button>
                        </div>

                    </form>
                </div>
            <?php endif; ?>
        </div>
    </div>
</main>


<style>
    :root {
        --primary-gold-analysis: #AA8C2C;
        --gold-glow-analysis: rgba(170, 140, 44, 0.3);
        --bg-main-analysis: #1F1F1F;
        --bg-card-analysis: #2A2A2A;
        --bg-charcoal-analysis: #222222;
        --border-color-analysis: #333333;
        --text-light-analysis: #E0E0E0;
        --text-muted-analysis: #888888;
        --text-gold-analysis: #D4AF37;
    }

    .auralis-premium-form .text-light {
        color: var(--text-light-analysis) !important;
    }

    .auralis-premium-form .text-secondary {
        color: var(--text-muted-analysis) !important;
    }

    .bg-dark {
        background-color: var(--bg-charcoal-analysis) !important;
    }

    .card {
        background-color: var(--bg-card-analysis) !important;
        border-color: var(--border-color-analysis) !important;
    }

    .auralis-premium-form input[type="text"]:focus,
    .auralis-premium-form input[type="number"]:focus,
    .auralis-premium-form select:focus {
        border-color: var(--primary-gold-analysis) !important;
        background-color: transparent !important;
        box-shadow: none;
    }

    .w-icon {
        width: 30px;
    }

    .w-icon i {
        font-size: 1.25rem;
    }

    .auralis-line-input {
        border-bottom: 1px solid var(--border-color-analysis);
        background-color: transparent !important;
    }

    .auralis-line-input .form-control,
    .auralis-line-input .form-select {
        color: var(--text-light-analysis) !important;
    }

    .no-spinners::-webkit-outer-spin-button,
    .no-spinners::-webkit-inner-spin-button {
        -webkit-appearance: none;
        margin: 0;
    }

    .no-spinners {
        -moz-appearance: textfield;
        appearance: none;
        padding-left: 2rem !important;
    }

    .fs-1-large {
        font-size: 3rem !important;
    }

    .fs-6 {
        font-size: 1rem !important;
    }

    .fs-7 {
        font-size: 0.875rem !important;
    }

    .fw-bold {
        font-weight: 700 !important;
    }

    .fw-semibold {
        font-weight: 600 !important;
    }

    .toggle-analysis .form-check-input {
        border-color: var(--border-color-analysis);
        cursor: pointer;
    }

    .toggle-analysis .form-check-input:checked {
        background-color: var(--primary-gold-analysis);
        border-color: var(--primary-gold-analysis);
    }

    .toggle-analysis .form-check-input:focus {
        border-color: var(--primary-gold-analysis);
        box-shadow: 0 0 0 0.25rem var(--gold-glow-analysis);
    }

    .toggle-analysis-muted .form-check-input:checked {
        opacity: 0.6;
    }

    .auralis-line-input select option {
        background-color: var(--bg-card-analysis);
        color: var(--text-light-analysis);
    }

    .badge-tipo {
        background: linear-gradient(135deg, #2a2a2a, #1f1f1f);
        border: 1px solid var(--border-color-analysis);
        min-width: 180px;
    }

    .w-icon .bi {
        transition: all 0.3s ease;
    }

    .auralis-premium-form input:focus~i.text-secondary-analysis,
    .auralis-premium-form select:focus~i.text-secondary-analysis {
        color: var(--primary-gold-analysis) !important;
        opacity: 0.8;
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
    // ==========================================
    // 1. LÓGICA DO SWITCH DE STATUS
    // ==========================================
    const toggleStatus = document.getElementById('toggle_status');
    const inputReal = document.getElementById('status_real');
    const textoStatus = document.getElementById('texto_status');
    const tipoAtual = "<?= htmlspecialchars($tipo_sugerido) ?>";

    function atualizarTextoToggle() {
        if (toggleStatus.checked) {
            inputReal.value = 'efetivado';
            textoStatus.innerText = 'Foi ' + (tipoAtual === 'receita' ? 'recebido' : 'pago');
            textoStatus.classList.remove('text-secondary-analysis');
            textoStatus.classList.add('text-light');
        } else {
            inputReal.value = 'pendente';
            textoStatus.innerText = 'Não ' + (tipoAtual === 'receita' ? 'recebido' : 'pago') + ' ainda';
            textoStatus.classList.remove('text-light');
            textoStatus.classList.add('text-secondary-analysis');
        }
    }

    if (toggleStatus) {
        toggleStatus.addEventListener('change', atualizarTextoToggle);
        atualizarTextoToggle(); // Roda ao carregar a página
    }

    // ==========================================
    // 2. LÓGICA DA RECORRÊNCIA
    // ==========================================
    const checkRecorrente = document.getElementById('recorrente');
    const blocoRecorrencia = document.getElementById('bloco_recorrencia');
    const inputDia = document.getElementById('dia_vencimento');

    // ── Recorrente toggle ────────────────────────────────────────────────────
    if (checkRecorrente) {
        checkRecorrente.addEventListener('change', function() {
            blocoRecorrencia.style.display = this.checked ? 'block' : 'none';
            inputDia.required = this.checked;
            // Desativa parcelamento se recorrente for ligado
            if (this.checked && toggleParcelado && toggleParcelado.checked) {
                toggleParcelado.checked = false;
                if (blocoParcelamento) blocoParcelamento.style.display = 'none';
            }
        });
    }

    // ==========================================
    // LÓGICA DE PARCELAMENTO E JUROS
    // ==========================================
    const toggleParcelado = document.getElementById('toggle_parcelado');
    const blocoParcelamento = document.getElementById('bloco_parcelamento');
    const inputParcelas = document.getElementById('num_parcelas');
    const previewParcela = document.getElementById('preview_parcela');
    const inputValor = document.getElementById('valor');

    const radioJurosSem = document.getElementById('juros_sem');
    const radioJurosCom = document.getElementById('juros_com');
    const blocoComJuros = document.getElementById('bloco_com_juros');
    const inputValorParcelaJuros = document.getElementById('valor_parcela_juros');
    const previewTotalJuros = document.getElementById('preview_total_juros');

    // Alterna a visibilidade da opção com juros
    if (radioJurosSem && radioJurosCom) {
        radioJurosSem.addEventListener('change', function() {
            blocoComJuros.style.display = 'none';
            atualizarPreviewParcela();
        });
        radioJurosCom.addEventListener('change', function() {
            blocoComJuros.style.display = 'block';
            atualizarPreviewParcela();
        });
    }

    // Calcula de baixo para cima (Parcela -> Total)
    function recalcularTotalComJuros() {
        if (!radioJurosCom || !radioJurosCom.checked) return;

        const n = parseInt(inputParcelas.value) || 0;
        let rawStr = inputValorParcelaJuros.value.replace(/\D/g, '');
        const valorParcela = (parseFloat(rawStr) / 100) || 0;

        if (n >= 2 && valorParcela > 0) {
            const valorTotal = valorParcela * n;

            // Atualiza o input gigante lá no topo da tela
            inputValor.value = valorTotal.toLocaleString('pt-BR', {
                style: 'currency',
                currency: 'BRL'
            });

            // Atualiza o textinho de preview
            const parcelaStr = valorParcela.toLocaleString('pt-BR', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
            previewParcela.innerHTML = '<span style="color:#d4af37;font-weight:600;">' + n + 'x de R$ ' + parcelaStr + '</span>';
        } else {
            previewParcela.textContent = '';
        }
    }

    // Calcula de cima para baixo (Total -> Parcela)
    function atualizarPreviewParcela() {
        if (!toggleParcelado || !toggleParcelado.checked || !previewParcela) return;

        const n = parseInt(inputParcelas ? inputParcelas.value : 0) || 0;
        let valorBaseRaw = (inputValor ? inputValor.value : '0').replace(/\D/g, '');
        const valorBase = (parseFloat(valorBaseRaw) / 100) || 0;

        // SE ESTIVER COM JUROS (VIP)
        if (radioJurosCom && radioJurosCom.checked) {
            let jurosRaw = inputValorParcelaJuros.value.replace(/\D/g, '');
            const valorParcelaComJuros = (parseFloat(jurosRaw) / 100) || 0;

            if (n >= 2 && valorParcelaComJuros > 0) {
                const totalComJuros = valorParcelaComJuros * n;
                const diferenca = totalComJuros - valorBase;

                const parcelaStr = valorParcelaComJuros.toLocaleString('pt-BR', {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2
                });
                const totalStr = totalComJuros.toLocaleString('pt-BR', {
                    style: 'currency',
                    currency: 'BRL'
                });

                previewParcela.innerHTML = '<span style="color:#d4af37;font-weight:600;">' + n + 'x de R$ ' + parcelaStr + '</span>';
                previewTotalJuros.innerHTML = `<span class="text-warning"><i class="bi bi-exclamation-triangle me-1"></i> Total real: ${totalStr} (R$ ${diferenca.toLocaleString('pt-BR', { minimumFractionDigits: 2 })} de juros).</span>`;
            } else {
                previewParcela.textContent = '';
                previewTotalJuros.innerHTML = '<i class="bi bi-calculator me-1"></i> Digite o valor da parcela para calcular.';
            }
            return;
        }

        // SE FOR SEM JUROS (PADRÃO)
        if (valorBase > 0 && n >= 2) {
            const parcela = (valorBase / n).toLocaleString('pt-BR', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
            previewParcela.innerHTML = '<span style="color:var(--text-gold-analysis);font-weight:600;">' + n + 'x de R$ ' + parcela + '</span>';
        } else {
            previewParcela.textContent = '';
        }
    }

    if (toggleParcelado) {
        toggleParcelado.addEventListener('change', function() {
            const ativo = this.checked;
            if (blocoParcelamento) blocoParcelamento.style.display = ativo ? 'block' : 'none';
            if (ativo && checkRecorrente && checkRecorrente.checked) {
                checkRecorrente.checked = false;
                if (blocoRecorrencia) blocoRecorrencia.style.display = 'none';
                if (inputDia) inputDia.required = false;
            }
            atualizarPreviewParcela();
        });
    }

    if (inputParcelas) inputParcelas.addEventListener('input', atualizarPreviewParcela);
    if (inputValor) inputValor.addEventListener('input', atualizarPreviewParcela);

    atualizarPreviewParcela();

    // ==========================================
    // TRAVA ANTI-SPAM (BLINDAGEM ABSOLUTA)
    // ==========================================
    const formTransacao = document.getElementById('formTransacao');
    const btnSalvar = document.getElementById('btnSalvar');

    // O nosso "Trinco" lógico
    let enviando = false;

    // Exibe erro inline no mesmo estilo do PHP, sem alert() do navegador
    function mostrarErroInline(mensagem, campoFoco = null) {
        let caixa = document.getElementById('erro-inline-js');
        if (!caixa) {
            caixa = document.createElement('div');
            caixa.id = 'erro-inline-js';
            // Insere antes do formulário
            formTransacao.parentNode.insertBefore(caixa, formTransacao);
        }
        caixa.innerHTML = `
            <div class="d-flex align-items-center gap-2 rounded-3 px-4 py-3 mb-3"
                style="background-color:rgba(120,0,0,0.35);border:1px solid rgba(200,50,50,0.45);color:#f28b8b;">
                <i class="bi bi-exclamation-triangle-fill flex-shrink-0" style="font-size:0.95rem;"></i>
                <span style="font-size:0.9rem;font-weight:500;">${mensagem}</span>
            </div>`;
        caixa.scrollIntoView({
            behavior: 'smooth',
            block: 'center'
        });
        if (campoFoco) campoFoco.focus();
    }

    if (formTransacao) {
        formTransacao.addEventListener('submit', function(event) {

            // ── VALIDAÇÃO 1: Parcelas igual a 1 ──────────────────────────
            const toggleParc = document.getElementById('toggle_parcelado');
            const inputNumParcelas = document.getElementById('num_parcelas');
            if (toggleParc && toggleParc.checked && inputNumParcelas) {
                const numParc = parseInt(inputNumParcelas.value, 10);
                if (numParc === 1) {
                    event.preventDefault();
                    mostrarErroInline('O número de parcelas não pode ser 1. Se não quiser parcelar, desative a opção de parcelamento.', inputNumParcelas);
                    return false;
                }
            }

            // ── VALIDAÇÃO 2: Juros não pode deixar o total ≤ valor original ─
            const radioJurosComCheck = document.getElementById('juros_com');
            const inputJurosCheck = document.getElementById('valor_parcela_juros');
            const inputValorCheck = document.getElementById('valor');
            if (toggleParc && toggleParc.checked && radioJurosComCheck && radioJurosComCheck.checked && inputJurosCheck && inputValorCheck && inputNumParcelas) {
                const numParc = parseInt(inputNumParcelas.value, 10) || 0;
                const rawJuros = parseFloat(inputJurosCheck.value.replace(/\D/g, '')) / 100 || 0;
                const rawOriginal = parseFloat(inputValorCheck.value.replace(/\D/g, '')) / 100 || 0;
                const totalComJuros = rawJuros * numParc;

                if (rawJuros > 0 && numParc >= 2 && totalComJuros <= rawOriginal) {
                    event.preventDefault();
                    const totalFmt = totalComJuros.toLocaleString('pt-BR', {
                        style: 'currency',
                        currency: 'BRL'
                    });
                    const origFmt = rawOriginal.toLocaleString('pt-BR', {
                        style: 'currency',
                        currency: 'BRL'
                    });
                    mostrarErroInline(`O valor total com juros (${totalFmt}) deve ser maior que o valor original (${origFmt}). Corrija o valor da parcela.`, inputJurosCheck);
                    return false;
                }
            }

            // Se o trinco já estiver trancado, bloqueia a tentativa e para tudo!
            if (enviando) {
                event.preventDefault(); // Cancela o 2º, 3º, 4º Enter...
                return false;
            }

            // Tranca o trinco na primeira vez que passa
            enviando = true;

            // Feedback visual no botão
            if (btnSalvar) {
                btnSalvar.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span> Salvando...';
                btnSalvar.style.pointerEvents = 'none'; // Impede novos cliques via CSS
                btnSalvar.classList.add('opacity-75'); // Deixa o botão meio transparente
            }
        });
    }

    function mascaraMoeda(input) {
        // 1. Remove tudo que não for número (tira letras, símbolos, etc)
        let valor = input.value.replace(/\D/g, '');

        // Se estiver vazio, não faz nada
        if (valor === '') {
            input.value = '';
            return;
        }

        // 2. Transforma em número e divide por 100 para criar os centavos (Ex: 1500 vira 15.00)
        valor = (parseInt(valor, 10) / 100);

        // 3. Formata nativamente para o padrão Real Brasileiro (R$ 1.500,00)
        input.value = valor.toLocaleString('pt-BR', {
            style: 'currency',
            currency: 'BRL'
        });
    }

    // Isso garante que se você estiver EDITANDO uma transação, 
    // o valor que vier do banco já apareça formatado.
    document.addEventListener("DOMContentLoaded", function() {
        let inputValor = document.getElementById('valor');
        if (inputValor.value !== '' && !inputValor.value.includes('R$')) {
            // Multiplica por 100 para simular a digitação sem a vírgula
            inputValor.value = (parseFloat(inputValor.value) * 100).toFixed(0);
            mascaraMoeda(inputValor);
        }
    });
</script>
<?php require_once 'geral/footer.php'; ?>