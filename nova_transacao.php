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
    elseif ($parcelado && ($numParcelas < 2 || $numParcelas > 48)) $erro = "Número de parcelas deve ser entre 2 e 48.";
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
                // ── CRIAÇÃO PARCELADA (Restaurada e Blindada) ────────────────
                $grupoParcela = gerarUuid();
                $dataBase     = new DateTime($dataRegistro);

                $valorParcela = floor(($valor / $numParcelas) * 100) / 100;
                $resto        = $valor - ($valorParcela * $numParcelas);

                $sqlParcela = "
                    INSERT INTO Registro (
                        IDRegistro, TipoRegistro, Valor, Descricao, MomentoRegistro, DataVencimento,
                        StatusRegistro, Recorrente, DiaVencimento, FKCarteira, FKUsuario, FKCategoria,
                        GrupoParcela, ParcelaAtual, TotalParcelas
                    ) VALUES (
                        :id, :tipo, :valor, :descricao, :momento, :vencimento,
                        :status, 0, NULL, :carteira, :usuario, :categoria,
                        :grupo, :parc_atual, :tot_parc
                    )
                ";
                $stmtP = $pdo->prepare($sqlParcela);

                for ($i = 0; $i < $numParcelas; $i++) {
                    // Cálculo matemático exato para não pular meses com 31 dias
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
                        ':descricao' => $descricao,
                        ':momento'    => $dataStr,
                        ':vencimento' => $dataStr,
                        ':status'     => $statusP,
                        ':carteira'  => $carteiraId,
                        ':usuario'    => $usuario_id,
                        ':categoria' => $categoriaId,
                        ':grupo'      => $grupoParcela,
                        ':parc_atual' => ($i + 1),
                        ':tot_parc'   => $numParcelas
                    ]);
                }
                header("Location: dashboard.php?sucesso=parcelado&parcelas={$numParcelas}");
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
                <div class="alert alert-danger d-flex align-items-center gap-2 rounded-3">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                    <span><?= htmlspecialchars($erro) ?></span>
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

                        <div class="accordion accordion-flush mb-5 border border-border-color rounded-3 overflow-hidden auralis-line-input" id="accordionMaisDetalhes">
                            <div class="accordion-item bg-transparent">
                                <h2 class="accordion-header">
                                    <button class="accordion-button <?= (!empty($val_venc) || $val_rec) ? '' : 'collapsed' ?> bg-transparent text-secondary-analysis shadow-none py-2 px-3 small fs-7" type="button" data-bs-toggle="collapse" data-bs-target="#collapseDetalhes">
                                        Mais detalhes (Vencimento, Recorrência)
                                    </button>
                                </h2>
                                <div id="collapseDetalhes" class="accordion-collapse collapse <?= (!empty($val_venc) || $val_rec) ? 'show' : '' ?>" data-bs-parent="#accordionMaisDetalhes">
                                    <div class="accordion-body border-top border-border-color pt-3 px-3 fs-7 bg-charcoal">
                                        <?php
                                        // Mostra opção de atualizar o grupo apenas se estiver editando uma recorrência ativa
                                        if ($is_edicao && !empty($transacao_edit['GrupoParcela']) && empty($transacao_edit['TotalParcelas'])):
                                        ?>
                                            <div class="form-check form-switch mt-3 pt-3 border-top border-border-color toggle-analysis toggle-analysis-muted">
                                                <input class="form-check-input bg-dark border-border-color shadow-none" type="checkbox" name="editar_futuros" id="editar_futuros" checked>
                                                <label class="form-check-label text-light fs-7 fw-semibold" for="editar_futuros">
                                                    Aplicar novo valor/descrição neste e em <strong>todos os meses futuros pendentes</strong>.
                                                </label>
                                            </div>
                                        <?php endif; ?>
                                        <div class="mb-3">
                                            <label class="form-label text-secondary-analysis fs-7 mb-1">Data de Vencimento</label>
                                            <input type="date" name="data_vencimento" class="form-control bg-dark border-border-color text-light-analysis fs-7" value="<?= htmlspecialchars($val_venc) ?>">
                                        </div>

                                        <div class="form-check form-switch mb-2 toggle-analysis toggle-analysis-muted">
                                            <input class="form-check-input bg-dark border-border-color shadow-none" type="checkbox" name="recorrente" id="recorrente" <?= $val_rec ? 'checked' : '' ?>>
                                            <label class="form-check-label text-muted-analysis fs-7" for="recorrente">Conta recorrente</label>
                                        </div>

                                        <div id="bloco_recorrencia" style="display: <?= $val_rec ? 'block' : 'none' ?>;" class="ps-4 border-start border-border-color mt-2 bg-charcoal">
                                            <label class="form-label text-secondary-analysis fs-7 mb-1">Dia do mês</label>
                                            <input type="number" name="dia_vencimento" id="dia_vencimento"
                                                class="form-control bg-dark border-border-color text-light-analysis form-control-sm w-50 no-spinners fs-7"
                                                min="1" max="31" placeholder="Ex: 10" value="<?= htmlspecialchars($val_dia) ?>">
                                        </div>

                                    </div>
                                </div>
                            </div>
                        </div>

                        <?php if (!$is_edicao): ?>
                            <!-- ── Parcelamento ──────────────────────────────────── -->
                            <div class="mb-4 auralis-line-input pb-3">
                                <div class="d-flex align-items-center justify-content-between mb-2">
                                    <div class="d-flex align-items-center">
                                        <i class="bi bi-credit-card-2-front text-secondary-analysis me-3 w-icon text-center"></i>
                                        <span class="text-light fs-6">Parcelado</span>
                                    </div>
                                    <div class="form-check form-switch fs-4 mb-0 toggle-analysis">
                                        <input class="form-check-input bg-dark border-border-color shadow-none" type="checkbox" name="parcelado" id="toggle_parcelado" role="switch" <?= $val_parcelado ? 'checked' : '' ?>>
                                    </div>
                                </div>

                                <div id="bloco_parcelamento" style="display: <?= $val_parcelado ? 'block' : 'none' ?>;" class="ps-4 border-start border-border-color mt-2">
                                    <label class="form-label text-secondary-analysis fs-7 mb-1">Número de parcelas</label>
                                    <div class="d-flex align-items-center gap-3">
                                        <input type="number" name="num_parcelas" id="num_parcelas"
                                            class="form-control bg-dark border-border-color text-light-analysis form-control-sm no-spinners fs-7"
                                            min="2" max="48" placeholder="Ex: 3"
                                            value="<?= htmlspecialchars($val_num_parc) ?>"
                                            style="width: 80px;">
                                        <div id="preview_parcela" class="text-muted fs-7"></div>
                                    </div>
                                    <p class="text-secondary fs-7 mt-2 mb-0">
                                        <i class="bi bi-info-circle me-1"></i>
                                        O sistema criará uma entrada por mês, começando na data informada.
                                    </p>
                                </div>
                            </div>
                        <?php endif; ?>

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

    // ── Parcelamento toggle ──────────────────────────────────────────────────
    const toggleParcelado = document.getElementById('toggle_parcelado');
    const blocoParcelamento = document.getElementById('bloco_parcelamento');
    const inputParcelas = document.getElementById('num_parcelas');
    const previewParcela = document.getElementById('preview_parcela');
    const inputValor = document.getElementById('valor');

    function atualizarPreviewParcela() {
        if (!toggleParcelado || !toggleParcelado.checked || !previewParcela) return;

        // Limpa a máscara do R$ pegando só os números puros e dividindo por 100
        let rawStr = (inputValor ? inputValor.value : '0').replace(/\D/g, '');
        const valor = (parseFloat(rawStr) / 100) || 0;

        const n = parseInt(inputParcelas ? inputParcelas.value : 0) || 0;
        if (valor > 0 && n >= 2) {
            const parcela = (valor / n).toLocaleString('pt-BR', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
            previewParcela.innerHTML = '<span style="color:var(--accent);font-weight:600;">' + n + 'x de R$ ' + parcela + '</span>';
        } else {
            previewParcela.textContent = '';
        }
    }

    if (toggleParcelado) {
        toggleParcelado.addEventListener('change', function() {
            const ativo = this.checked;
            if (blocoParcelamento) blocoParcelamento.style.display = ativo ? 'block' : 'none';
            // Parcelado e Recorrente são mutuamente exclusivos
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

    if (formTransacao) {
        formTransacao.addEventListener('submit', function(event) {

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