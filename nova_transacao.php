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
require_once 'config/funcoes.php';
require_once 'config/funcoes_cartao.php';

$usuario_id = $_SESSION['usuario_id'];
$carteiras = [];
$categorias = [];
$cartoes = [];
$erro = null;

// URL de retorno após salvar — whitelist para evitar open redirect
$_urlVoltar = (function ($raw) {
    if (empty($raw)) return 'dashboard.php';
    $base = basename(strtok($raw, '?'));
    return in_array($base, ['dashboard.php', 'agenda.php']) ? $raw : 'dashboard.php';
})($_POST['voltar'] ?? $_GET['voltar'] ?? '');

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

// Limites do plano para filtrar seletores (em edição, exibe tudo para não quebrar registros existentes)
$_limitesNT  = limitesDoPlano();
$_planoNT    = strtolower($_SESSION['plano'] ?? 'free');
$_testeNT    = function_exists('obterHorasRestantesTeste') ? (obterHorasRestantesTeste() > 0) : false;
$_limCartNT  = ($id_editar || $_planoNT !== 'free' || $_testeNT) ? PHP_INT_MAX : $_limitesNT['carteiras'];
$_limCatNT   = ($id_editar || $_planoNT !== 'free' || $_testeNT) ? PHP_INT_MAX : $_limitesNT['categorias'];

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
    $carteiras = array_slice($stmtC->fetchAll(), 0, $_limCartNT === PHP_INT_MAX ? 9999 : $_limCartNT);

    // Busca APENAS as categorias do tipo sugerido
    $sqlCategorias = "
        SELECT IDCategoria, NomeCategoria
        FROM Categoria
        WHERE FKUsuario = :uid AND TipoCategoria = :tipo
        ORDER BY NomeCategoria ASC
    ";
    $tipoCat = ($tipo_sugerido === 'cartao') ? 'despesa' : $tipo_sugerido;
    $stmtCat = $pdo->prepare($sqlCategorias);
    $stmtCat->execute([':uid' => $usuario_id, ':tipo' => $tipoCat]);
    $categorias = array_slice($stmtCat->fetchAll(), 0, $_limCatNT === PHP_INT_MAX ? 9999 : $_limCatNT);
} catch (PDOException $e) {
    $carteiras = [];
    $categorias = [];
}

$cartoes = (!$id_editar) ? cartao_listarAtivos($pdo, $usuario_id) : [];

// Busca comprovantes existentes (modo edição)
$comprovantes = [];
if ($id_editar && $transacao_edit) {
    try {
        $stmtComp = $pdo->prepare("SELECT * FROM Comprovante WHERE FKRegistro = :reg AND FKUsuario = :uid ORDER BY MomentoUpload ASC");
        $stmtComp->execute([':reg' => $id_editar, ':uid' => $usuario_id]);
        $comprovantes = $stmtComp->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $comprovantes = [];
    }
}

// Helper: processa e salva arquivos enviados para um registro
function processarComprovantes(PDO $pdo, string $registroId, string $usuarioId): void
{
    if (empty($_FILES['comprovantes']['tmp_name'][0])) return;
    $allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'application/pdf'];
    $maxSize = 5 * 1024 * 1024;
    foreach ($_FILES['comprovantes']['tmp_name'] as $i => $tmp) {
        if ($_FILES['comprovantes']['error'][$i] !== UPLOAD_ERR_OK) continue;
        if ($_FILES['comprovantes']['size'][$i] > $maxSize) continue;
        $mime = mime_content_type($tmp);
        if (!in_array($mime, $allowed)) continue;
        $ext = match ($mime) {
            'application/pdf' => 'pdf',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            default => 'bin',
        };
        $filename = gerarUuid() . '.' . $ext;
        $dir = __DIR__ . '/uploads/comprovantes/' . $usuarioId . '/';
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        if (move_uploaded_file($tmp, $dir . $filename)) {
            $pdo->prepare("INSERT INTO Comprovante (IDComprovante, FKRegistro, FKUsuario, NomeArquivo, NomeOriginal, TipoMime, Tamanho) VALUES (:id, :reg, :uid, :nome, :orig, :mime, :tam)")
                ->execute([
                    ':id'   => gerarUuid(),
                    ':reg'  => $registroId,
                    ':uid'  => $usuarioId,
                    ':nome' => $filename,
                    ':orig' => mb_substr(basename($_FILES['comprovantes']['name'][$i]), 0, 255),
                    ':mime' => $mime,
                    ':tam'  => (int)$_FILES['comprovantes']['size'][$i],
                ]);
        }
    }
}

// ── CARTÃO DE CRÉDITO — handler separado ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && trim($_POST['tipo_registro'] ?? '') === 'cartao') {
    $cartaoId    = trim($_POST['cartao_cc_id'] ?? '');
    $descricao   = trim($_POST['descricao'] ?? '');
    $dataCompra  = trim($_POST['data_registro'] ?? '');
    $categoriaId = trim($_POST['categoria_id'] ?? '') ?: null;
    $parcelado   = isset($_POST['parcelado_cc']) ? 1 : 0;
    $numParcelas = $parcelado ? max(1, min(48, intval($_POST['num_parcelas_cc'] ?? 1))) : 1;

    $valorPost  = trim($_POST['valor'] ?? '');
    $valorLimpo = preg_replace('/[^\d.,]/', '', $valorPost);
    if (strpos($valorLimpo, ',') !== false) {
        $valorLimpo = str_replace('.', '', $valorLimpo);
        $valorRaw   = str_replace(',', '.', $valorLimpo);
    } else {
        $valorRaw = $valorLimpo;
    }

    if (empty($cartaoId))                     $erro = 'Selecione um cartão.';
    elseif (empty($descricao))                $erro = 'A descrição não pode ficar em branco.';
    elseif (empty($dataCompra))               $erro = 'Selecione a data da compra.';
    elseif (empty($valorRaw) || !is_numeric($valorRaw)) $erro = 'Informe um valor numérico válido.';
    elseif (floatval($valorRaw) <= 0)         $erro = 'O valor deve ser maior que zero.';

    if (!$erro) {
        $stmtCC = $pdo->prepare("SELECT * FROM CartaoCredito WHERE IDCartao = :id AND FKUsuario = :uid AND Ativo = 1");
        $stmtCC->execute([':id' => $cartaoId, ':uid' => $usuario_id]);
        $cartao = $stmtCC->fetch(PDO::FETCH_ASSOC);
        if (!$cartao) $erro = 'Cartão não encontrado.';
    }

    if (!$erro) {
        $grupoParcela = ($numParcelas > 1) ? gerarUuid() : null;
        $mesRefBase   = _cc_mesRefAtual((int)$cartao['DiaFechamento'], (int)$cartao['DiaVencimento']);
        $valorTotal   = (float)$valorRaw;
        $valorParcela = floor(($valorTotal / $numParcelas) * 100) / 100;
        $resto        = $valorTotal - ($valorParcela * $numParcelas);

        $sqlLanc = "INSERT INTO LancamentoCartao
            (IDLancamento, FKFatura, FKCartao, FKUsuario, Descricao, Valor, DataCompra, FKCategoria, GrupoParcelamento, ParcelaAtual, TotalParcelas)
            VALUES (:id, :fid, :cid, :uid, :desc, :val, :data, :cat, :grupo, :parc, :tot)";
        $stmtL = $pdo->prepare($sqlLanc);

        for ($i = 0; $i < $numParcelas; $i++) {
            $mesRef = _cc_mesRefAdiante($mesRefBase, $i);
            $fatura = cartao_obterFaturaParaMesRef($pdo, $cartaoId, $usuario_id, $cartao, $mesRef);
            $val    = ($i === 0) ? ($valorParcela + $resto) : $valorParcela;

            $stmtL->execute([
                ':id'    => gerarUuid(),
                ':fid'   => $fatura['IDFatura'],
                ':cid'   => $cartaoId,
                ':uid'   => $usuario_id,
                ':desc'  => $descricao . ($numParcelas > 1 ? ' (' . ($i + 1) . '/' . $numParcelas . ')' : ''),
                ':val'   => round($val, 2),
                ':data'  => $dataCompra,
                ':cat'   => $categoriaId,
                ':grupo' => $grupoParcela,
                ':parc'  => ($numParcelas > 1) ? ($i + 1) : null,
                ':tot'   => ($numParcelas > 1) ? $numParcelas : null,
            ]);
            cartao_sincronizarPreview($pdo, $fatura['IDFatura'], $usuario_id, $cartao);
        }
        header("Location: " . $_urlVoltar . (strpos($_urlVoltar, '?') !== false ? '&' : '?') . "sucesso=registro");
        exit;
    }
    // Se erro, cai no formulário abaixo com $erro definido
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
    elseif ($parcelado && !isset($_POST['id_editar'])) {
        $_limParcNT = limitesDoPlano()['parcelas_max'];
        if ($numParcelas > $_limParcNT) {
            $erro = "Seu plano permite parcelar em até {$_limParcNT}x. Assine o PRO para parcelar em até 48x.";
        }
    }

    // Verifica limite mensal de registros (apenas para novas transações, não edições)
    if (!$erro && !isset($_POST['id_editar'])) {
        $_limMensalNT = limitesDoPlano()['transacoes_mes'];
        if ($_limMensalNT !== PHP_INT_MAX) {
            $stmtLimMes = $pdo->prepare(
                "SELECT COUNT(*) FROM Registro WHERE FKUsuario = :uid
                 AND YEAR(MomentoRegistro) = :ano AND MONTH(MomentoRegistro) = :mes"
            );
            $stmtLimMes->execute([
                ':uid' => $usuario_id,
                ':ano' => (int)date('Y'),
                ':mes' => (int)date('m'),
            ]);
            if ((int)$stmtLimMes->fetchColumn() >= $_limMensalNT) {
                $erro = "Você atingiu o limite de {$_limMensalNT} registros este mês do plano Free. Aguarde o próximo mês ou assine o PRO para registros ilimitados.";
            }
        }
    }

    if (!$erro) {
        $valor = $valorRaw; // O valor já está limpo
        $dataVencimento = !empty($dataVencimento) ? $dataVencimento : $dataRegistro;

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
                processarComprovantes($pdo, $_POST['id_editar'], $usuario_id);
                header("Location: " . $_urlVoltar . (strpos($_urlVoltar, '?') !== false ? '&' : '?') . "sucesso=editado");
            } elseif ($parcelado && $numParcelas >= 2) {
                // ── CRIAÇÃO PARCELADA ────────────────
                $grupoParcela = gerarUuid();
                $dataBase     = new DateTime($dataRegistro);

                $valorJurosTotal = 0;
                $jurosPorParcela = null;

                // 1. VERIFICAÇÃO DE ACESSO (PRO, VIP OU TESTE)
                $planoUsuarioLogado  = strtolower($_SESSION['plano'] ?? 'free');
                $horasTesteRestantes = function_exists('obterHorasRestantesTeste') ? obterHorasRestantesTeste() : 0;
                $acessoLiberadoJuros = ($planoUsuarioLogado === 'pro' || $planoUsuarioLogado === 'vip' || $horasTesteRestantes > 0);

                // 2. LÓGICA DE JUROS (COM TRAVA DE SEGURANÇA) — só calcula; não insere ainda
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

                // 3. Se a validação de juros gerou um erro, não insere nada — cai no bloco de exibição de erro
                if (!$erro) {
                    $valorParcela = floor(($valor / $numParcelas) * 100) / 100;
                    $resto        = $valor - ($valorParcela * $numParcelas);

                    // Divide o juros total pelo número de parcelas para salvar em cada linha
                    if ($valorJurosTotal > 0) {
                        $jurosPorParcela = round($valorJurosTotal / $numParcelas, 2);
                    }

                    // 4. INSERT parcelado — usa ValorJuros (requer ALTER TABLE, ver migration abaixo)
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

                    $primeiroIdParc = null;
                    for ($i = 0; $i < $numParcelas; $i++) {
                        $mesAlvo    = (int)$dataBase->format('m') + $i;
                        $anoAlvo    = (int)$dataBase->format('Y') + (int)floor(($mesAlvo - 1) / 12);
                        $mesAlvo    = (($mesAlvo - 1) % 12) + 1;
                        $diaAlvo    = (int)$dataBase->format('d');
                        $diaCorreto = min($diaAlvo, (int)date('t', strtotime(sprintf('%04d-%02d-01', $anoAlvo, $mesAlvo))));
                        $dataStr    = sprintf('%04d-%02d-%02d', $anoAlvo, $mesAlvo, $diaCorreto);

                        $valAtual = ($i === 0) ? ($valorParcela + $resto) : $valorParcela;
                        $statusP  = ($i === 0) ? $statusRegistro : 'pendente';

                        $idParcela = gerarUuid();
                        if ($primeiroIdParc === null) $primeiroIdParc = $idParcela;

                        $stmtP->execute([
                            ':id'        => $idParcela,
                            ':tipo'      => $tipoRegistro,
                            ':valor'     => $valAtual,
                            ':juros'     => $jurosPorParcela,
                            ':descricao' => $descricao,
                            ':momento'   => $dataStr,
                            ':vencimento' => $dataStr,
                            ':status'    => $statusP,
                            ':carteira'  => $carteiraId,
                            ':usuario'   => $usuario_id,
                            ':categoria' => $categoriaId,
                            ':grupo'     => $grupoParcela,
                            ':parc_atual' => ($i + 1),
                            ':tot_parc'  => $numParcelas,
                        ]);
                    }

                    if ($primeiroIdParc) processarComprovantes($pdo, $primeiroIdParc, $usuario_id);
                    header("Location: " . $_urlVoltar . (strpos($_urlVoltar, '?') !== false ? '&' : '?') . "sucesso=parcelado&parcelas={$numParcelas}");
                    exit;
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

                $primeiroIdRec = null;
                for ($i = 0; $i < $limiteMeses; $i++) {
                    // Cálculo matemático para forçar o mês e ano corretos sequencialmente
                    $mesAlvo = (int)$dataBase->format('m') + $i;
                    $anoAlvo = (int)$dataBase->format('Y') + floor(($mesAlvo - 1) / 12);
                    $mesAlvo = (($mesAlvo - 1) % 12) + 1;

                    $diaCorreto = min($diaVencimento, date('t', strtotime(sprintf('%04d-%02d-01', $anoAlvo, $mesAlvo))));
                    $dataStr = sprintf('%04d-%02d-%02d', $anoAlvo, $mesAlvo, $diaCorreto);

                    $statusRec = ($i === 0) ? $statusRegistro : 'pendente';

                    $idRec = gerarUuid();
                    if ($primeiroIdRec === null) $primeiroIdRec = $idRec;
                    $stmtR->execute([
                        ':id'         => $idRec,
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
                if ($primeiroIdRec) processarComprovantes($pdo, $primeiroIdRec, $usuario_id);
                header("Location: " . $_urlVoltar . (strpos($_urlVoltar, '?') !== false ? '&' : '?') . "sucesso=recorrente");
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
                $novoId = gerarUuid();
                $stmt->execute([
                    ':id'         => $novoId,
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
                processarComprovantes($pdo, $novoId, $usuario_id);
                header("Location: " . $_urlVoltar . (strpos($_urlVoltar, '?') !== false ? '&' : '?') . "sucesso=registro");
            }
            exit;
        } catch (PDOException $e) {
            $erro = "Erro ao salvar o registro: " . $e->getMessage();
        }
    }
}

// Valores Iniciais do Formulário
$val_valor  = $_POST['valor'] ?? ($transacao_edit ? $transacao_edit['Valor'] : ($_GET['_val'] ?? ''));
$val_desc   = $_POST['descricao'] ?? ($transacao_edit ? $transacao_edit['Descricao'] : ($_GET['_desc'] ?? ''));
$val_data   = $_POST['data_registro'] ?? ($transacao_edit ? date('Y-m-d', strtotime($transacao_edit['MomentoRegistro'])) : ($_GET['_data'] ?? ($_GET['data'] ?? date('Y-m-d'))));
$val_status = $_POST['status_registro'] ?? ($transacao_edit ? $transacao_edit['StatusRegistro'] : 'efetivado');

$val_cart   = $_POST['carteira_id'] ?? ($transacao_edit ? $transacao_edit['FKCarteira'] : ($_GET['carteira_id'] ?? ''));
// Se só tiver 1 carteira, já seleciona ela automaticamente (tanto na criação quanto na edição)
if (empty($val_cart) && count($carteiras) === 1) {
    $val_cart = $carteiras[0]['IDCarteira'];
}

$val_cat    = $_POST['categoria_id'] ?? ($transacao_edit ? $transacao_edit['FKCategoria'] : '');
$val_venc   = $_POST['data_vencimento'] ?? ($transacao_edit ? $transacao_edit['DataVencimento'] : ($_GET['data'] ?? ''));
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
                <a href="<?= htmlspecialchars($_GET['voltar'] ?? 'dashboard.php') ?>" class="btn btn-outline-secondary btn-sm">
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
                    <form id="formTransacao" method="POST" action="" novalidate enctype="multipart/form-data" class="auralis-premium-form p-4">
                        <input type="hidden" name="tipo_registro" value="<?= htmlspecialchars($tipo_sugerido) ?>">
                        <input type="hidden" name="voltar" value="<?= htmlspecialchars($_urlVoltar) ?>">
                        <?php if ($id_editar): ?>
                            <input type="hidden" name="id_editar" value="<?= htmlspecialchars($id_editar) ?>">
                        <?php endif; ?>

                        <?php if (!$is_edicao): ?>
                            <div class="d-flex gap-2 mb-4 p-1 rounded-3" style="background:rgba(255,255,255,0.03);border:1px solid #333;">
                                <a href="?tipo=receita<?= !empty($_GET['data']) ? '&data=' . urlencode($_GET['data']) : '' ?><?= !empty($_GET['carteira_id']) ? '&carteira_id=' . urlencode($_GET['carteira_id']) : '' ?>&voltar=<?= urlencode($_GET['voltar'] ?? 'dashboard.php') ?>"
                                    class="btn flex-grow-1 fw-bold rounded-3 py-2 d-flex align-items-center justify-content-center gap-1"
                                    style="<?= $tipo_sugerido === 'receita' ? 'background:rgba(6,214,160,0.18);color:#6ee7c7;border:1px solid rgba(6,214,160,0.5);' : 'background:transparent;color:#555;border:1px solid transparent;' ?>">
                                    <i class="bi bi-arrow-up-short" style="font-size:1.3rem;"></i> Receita
                                </a>
                                <a href="?tipo=despesa<?= !empty($_GET['data']) ? '&data=' . urlencode($_GET['data']) : '' ?><?= !empty($_GET['carteira_id']) ? '&carteira_id=' . urlencode($_GET['carteira_id']) : '' ?>&voltar=<?= urlencode($_GET['voltar'] ?? 'dashboard.php') ?>"
                                    class="btn flex-grow-1 fw-bold rounded-3 py-2 d-flex align-items-center justify-content-center gap-1"
                                    style="<?= $tipo_sugerido === 'despesa' ? 'background:rgba(230,57,70,0.18);color:#f87171;border:1px solid rgba(230,57,70,0.5);' : 'background:transparent;color:#555;border:1px solid transparent;' ?>">
                                    <i class="bi bi-arrow-down-short" style="font-size:1.3rem;"></i> Despesa
                                </a>
                                <?php if (!empty($cartoes)): ?>
                                <a href="?tipo=cartao<?= !empty($_GET['data']) ? '&data=' . urlencode($_GET['data']) : '' ?>&voltar=<?= urlencode($_GET['voltar'] ?? 'dashboard.php') ?><?= !empty($_GET['cartao_id']) ? '&cartao_id=' . urlencode($_GET['cartao_id']) : '' ?>"
                                    class="btn flex-grow-1 fw-bold rounded-3 py-2 d-flex align-items-center justify-content-center gap-1"
                                    style="<?= $tipo_sugerido === 'cartao' ? 'background:rgba(124,58,237,0.18);color:#a78bfa;border:1px solid rgba(124,58,237,0.5);' : 'background:transparent;color:#555;border:1px solid transparent;' ?>">
                                    <i class="bi bi-credit-card-2-front" style="font-size:1rem;"></i> Cartão
                                </a>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <div class="text-center mb-4">
                                <span class="badge badge-tipo rounded-pill px-4 py-2 shadow-sm">
                                    <?php if ($tipo_sugerido === 'receita'): ?>
                                        <span class="fw-bold text-success fs-5"><i class="bi bi-arrow-up-short"></i> Receita</span>
                                    <?php else: ?>
                                        <span class="fw-bold text-danger fs-5"><i class="bi bi-arrow-down-short"></i> Despesa</span>
                                    <?php endif; ?>
                                </span>
                            </div>
                        <?php endif; ?>

                        <?php if ($tipo_sugerido === 'cartao' && !$is_edicao): ?>
                        <!-- ── FORMULÁRIO CARTÃO DE CRÉDITO ───────────────── -->
                        <input type="hidden" name="tipo_registro" value="cartao">

                        <div class="mb-5 d-flex align-items-center justify-content-center pb-3 auralis-line-input">
                            <input type="text" inputmode="numeric" name="valor" id="valor"
                                class="form-control form-control-lg bg-transparent border-0 fw-bold text-center fs-1-large valor-input p-0 p-lg-1 no-spinners"
                                style="color:#a78bfa;"
                                placeholder="R$ 0,00" required autofocus autocomplete="off"
                                value="<?= htmlspecialchars($val_valor) ?>"
                                oninput="mascaraMoeda(this)">
                        </div>

                        <div class="d-flex align-items-center mb-4 pb-2 auralis-line-input">
                            <i class="bi bi-paragraph text-secondary-analysis me-3 w-icon text-center"></i>
                            <input type="text" name="descricao" id="descricao"
                                class="form-control bg-transparent border-0 text-light-analysis px-0 shadow-none fs-6 fw-bold"
                                placeholder="Descrição da compra:" maxlength="255" required
                                value="<?= htmlspecialchars($val_desc) ?>">
                        </div>

                        <div class="d-flex align-items-center mb-4 pb-2 auralis-line-input">
                            <i class="bi bi-credit-card-2-front me-3 w-icon text-center" style="color:#a78bfa;"></i>
                            <select name="cartao_cc_id" id="cartao_cc_id" class="form-select bg-transparent border-0 text-light-analysis px-0 shadow-none fw-semibold fs-6" required>
                                <option class="bg-card" value="" disabled <?= empty($_GET['cartao_id']) ? 'selected' : '' ?>>Selecione o Cartão</option>
                                <?php foreach ($cartoes as $cc): ?>
                                    <option class="bg-card" value="<?= htmlspecialchars($cc['IDCartao']) ?>"
                                        <?= (($_GET['cartao_id'] ?? '') === $cc['IDCartao']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($cc['Nome']) ?>
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

                        <!-- Parcelamento CC -->
                        <div class="accordion accordion-flush mb-5 border border-border-color rounded-3 overflow-hidden auralis-line-input" id="accordionCC">
                            <div class="accordion-item bg-transparent">
                                <h2 class="accordion-header">
                                    <button class="accordion-button collapsed bg-transparent text-secondary-analysis shadow-none py-2 px-3 small fs-7"
                                        type="button" data-bs-toggle="collapse" data-bs-target="#collapseCC">
                                        <i class="bi bi-sliders me-2"></i> Parcelamento
                                    </button>
                                </h2>
                                <div id="collapseCC" class="accordion-collapse collapse" data-bs-parent="#accordionCC">
                                    <div class="accordion-body border-top border-border-color pt-3 px-3 pb-4 bg-charcoal">
                                        <div class="d-flex align-items-start justify-content-between gap-3 mb-3">
                                            <div>
                                                <div class="text-light fw-semibold fs-7 mb-1 d-flex align-items-center gap-2">
                                                    <i class="bi bi-credit-card-2-front" style="color:#a78bfa;"></i>
                                                    Dividir em parcelas
                                                </div>
                                                <div class="text-secondary" style="font-size:0.75rem;">Distribuído automaticamente nas próximas faturas.</div>
                                            </div>
                                            <div class="form-check form-switch fs-4 mb-0 toggle-analysis flex-shrink-0 mt-1"
                                                style="--bs-form-check-bg:transparent;">
                                                <input class="form-check-input bg-dark border-border-color shadow-none"
                                                    type="checkbox" name="parcelado_cc" id="toggle_parcelado_cc">
                                            </div>
                                        </div>
                                        <div id="bloco_parc_cc" style="display:none;" class="ps-3 border-start border-border-color">
                                            <label class="form-label text-secondary-analysis fs-7 mb-1">Em quantas vezes?</label>
                                            <div class="d-flex align-items-center gap-3">
                                                <input type="number" name="num_parcelas_cc" id="num_parcelas_cc"
                                                    class="form-control bg-dark border-border-color text-light-analysis form-control-sm no-spinners fs-7"
                                                    style="max-width:100px;" min="2" max="48" placeholder="Ex: 3" value="2">
                                                <div id="preview_parc_cc" class="fs-7"></div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="d-grid mt-2">
                            <button id="btnSalvar" type="submit" class="btn fw-bold text-light py-3 rounded-pill fs-6 shadow-lg d-flex align-items-center justify-content-center"
                                style="background:rgba(124,58,237,0.25);border:1px solid rgba(124,58,237,0.5);">
                                Lançar no Cartão
                            </button>
                        </div>

                        <?php else: /* receita / despesa */ ?>

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
                                        <div id="bloco-vencimento" class="pt-3 border-top border-border-color"
                                            style="<?= $val_rec ? 'display:none;' : '' ?>">
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

                        <!-- ── COMPROVANTES / ANEXOS ─────────────────────────── -->
                        <?php
                        $planoComp   = strtolower($_SESSION['plano'] ?? 'free');
                        $testeComp   = function_exists('obterHorasRestantesTeste') ? (obterHorasRestantesTeste() > 0) : false;
                        $podeAnexar  = ($planoComp === 'pro' || $planoComp === 'vip' || $testeComp);
                        ?>
                        <div class="mb-4 pt-3 border-top border-border-color">

                            <?php if ($podeAnexar): ?>

                                <?php if (!empty($comprovantes)): ?>
                                    <div class="mb-3">
                                        <div class="text-secondary-analysis fw-semibold fs-7 mb-2 d-flex align-items-center gap-2">
                                            <i class="bi bi-paperclip"></i> Comprovantes anexados
                                        </div>
                                        <div id="listaComprovantes">
                                            <?php foreach ($comprovantes as $comp): ?>
                                                <?php $isImg = str_starts_with($comp['TipoMime'], 'image/'); ?>
                                                <div class="d-flex align-items-center gap-2 mb-2 p-2 rounded-3"
                                                    style="background:rgba(255,255,255,0.04); border:1px solid #333;"
                                                    id="comp-<?= htmlspecialchars($comp['IDComprovante']) ?>">
                                                    <i class="bi <?= $isImg ? 'bi-image' : 'bi-file-earmark-pdf' ?> flex-shrink-0"
                                                        style="color:<?= $isImg ? '#6ee7c7' : '#f87171' ?>; font-size:1.05rem;"></i>
                                                    <span class="text-secondary text-truncate flex-grow-1" style="font-size:0.8rem; max-width:180px;"
                                                        title="<?= htmlspecialchars($comp['NomeOriginal']) ?>">
                                                        <?= htmlspecialchars($comp['NomeOriginal']) ?>
                                                    </span>
                                                    <span class="text-secondary opacity-50 flex-shrink-0" style="font-size:0.7rem;">
                                                        <?= round($comp['Tamanho'] / 1024) ?> KB
                                                    </span>
                                                    <a href="/comprovante/ver.php?id=<?= htmlspecialchars($comp['IDComprovante']) ?>"
                                                        target="_blank"
                                                        class="btn btn-sm rounded-circle d-flex align-items-center justify-content-center flex-shrink-0"
                                                        style="width:28px;height:28px;padding:0;background:rgba(170,140,44,0.1);color:#AA8C2C;border:1px solid rgba(170,140,44,0.3);"
                                                        title="Visualizar">
                                                        <i class="bi bi-eye" style="font-size:0.7rem;"></i>
                                                    </a>
                                                    <a href="/comprovante/ver.php?id=<?= htmlspecialchars($comp['IDComprovante']) ?>&download=1"
                                                        class="btn btn-sm rounded-circle d-flex align-items-center justify-content-center flex-shrink-0"
                                                        style="width:28px;height:28px;padding:0;background:rgba(255,255,255,0.05);color:#888;border:1px solid #333;"
                                                        title="Baixar">
                                                        <i class="bi bi-download" style="font-size:0.65rem;"></i>
                                                    </a>
                                                    <button type="button"
                                                        class="btn btn-sm rounded-circle d-flex align-items-center justify-content-center flex-shrink-0 btn-deletar-comp"
                                                        data-id="<?= htmlspecialchars($comp['IDComprovante']) ?>"
                                                        style="width:28px;height:28px;padding:0;background:rgba(230,57,70,0.1);color:#f87171;border:1px solid rgba(230,57,70,0.3);"
                                                        title="Remover">
                                                        <i class="bi bi-trash3" style="font-size:0.65rem;"></i>
                                                    </button>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <label class="text-secondary-analysis fw-semibold fs-7 mb-2 d-flex align-items-center gap-2" for="comprovantes">
                                    <i class="bi bi-paperclip"></i>
                                    <?= !empty($comprovantes) ? 'Adicionar mais arquivos' : 'Comprovante / Anexo' ?>
                                    <span class="badge bg-secondary fw-normal" style="font-size:0.6rem;">Opcional</span>
                                </label>

                                <label for="comprovantes" id="dropzone"
                                    class="d-flex flex-column align-items-center justify-content-center rounded-3 text-center"
                                    style="border:2px dashed #333; padding:1.25rem 1rem; cursor:pointer; transition:border-color .2s, background .2s;">
                                    <i class="bi bi-cloud-upload mb-1 text-secondary-analysis" style="font-size:1.5rem;"></i>
                                    <span class="text-secondary" style="font-size:0.8rem;">Clique ou arraste arquivos aqui</span>
                                    <span style="font-size:0.68rem; color:#444;">Imagens (JPG, PNG, WEBP) ou PDF · máx. 5 MB cada</span>
                                </label>
                                <input type="file" name="comprovantes[]" id="comprovantes"
                                    accept="image/jpeg,image/png,image/webp,image/gif,application/pdf"
                                    multiple class="d-none">

                                <div id="previewNovos" class="mt-2"></div>

                            <?php else: ?>

                                <a href="/planos.php?upgrade=pro" class="d-flex align-items-center gap-3 rounded-3 text-decoration-none p-3"
                                    style="border:1px dashed rgba(124,58,237,0.35); background:rgba(124,58,237,0.05);">
                                    <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0"
                                        style="width:38px;height:38px;background:rgba(124,58,237,0.12);border:1px solid rgba(124,58,237,0.3);">
                                        <i class="bi bi-paperclip" style="color:#a78bfa; font-size:1rem;"></i>
                                    </div>
                                    <div>
                                        <div class="d-flex align-items-center gap-2 mb-1">
                                            <span class="text-light fw-semibold" style="font-size:0.85rem;">Comprovantes e Anexos</span>
                                            <span class="badge rounded-pill" style="background:rgba(124,58,237,0.2);color:#a78bfa;border:1px solid rgba(124,58,237,0.4);font-size:0.62rem;padding:2px 7px;">
                                                <i class="fi fi-br-crown" style="font-size:0.6rem;vertical-align:middle;margin-right:2px;"></i> PRO
                                            </span>
                                        </div>
                                        <div class="text-secondary" style="font-size:0.75rem;">Anexe boletos, notas fiscais e comprovantes a qualquer registro. <span style="color:#a78bfa;">Fazer upgrade →</span></div>
                                    </div>
                                </a>

                            <?php endif; ?>

                        </div>

                        <div class="d-grid mt-2">
                            <button id="btnSalvar" type="submit" class="btn btn-gold fw-bold text-dark py-3 rounded-pill fs-6 shadow-lg d-flex align-items-center justify-content-center transition-hover">
                                <?= $id_editar ? 'Salvar Alterações' : 'Salvar Transação' ?>
                            </button>
                        </div>

                        <?php endif; /* end cartao/receita-despesa */ ?>

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
            // Esconde vencimento (recorrente usa o próprio dia de recorrência)
            const blocoVenc = document.getElementById('bloco-vencimento');
            if (blocoVenc) blocoVenc.style.display = this.checked ? 'none' : '';
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
            inputValor.value = (parseFloat(inputValor.value) * 100).toFixed(0);
            mascaraMoeda(inputValor);
        }

        // Dropdown de carteira — atualiza hidden input e estado visual
        document.querySelectorAll('.carteira-nt-option').forEach(function(item) {
            item.addEventListener('click', function(e) {
                e.preventDefault();
                var id = this.dataset.id;
                var nome = this.dataset.nome;

                document.getElementById('carteira_id').value = id;
                document.getElementById('dropdownCarteiraNTLabel').innerHTML =
                    '<i class="bi bi-wallet2 me-2 flex-shrink-0" style="color:var(--primary-gold-analysis);"></i>' + nome;

                document.querySelectorAll('.carteira-nt-option').forEach(function(opt) {
                    var icon = opt.querySelector('.cart-nt-check');
                    var span = opt.querySelector('span');
                    if (opt.dataset.id === id) {
                        opt.classList.add('active');
                        icon.className = 'bi bi-check-circle-fill flex-shrink-0 cart-nt-check';
                        icon.style.color = 'var(--primary-gold-analysis)';
                        span.className = 'fw-bold text-truncate';
                        span.style.color = 'var(--primary-gold-analysis)';
                    } else {
                        opt.classList.remove('active');
                        icon.className = 'bi bi-circle flex-shrink-0 text-secondary opacity-50 cart-nt-check';
                        icon.style.color = '';
                        span.className = 'text-light text-truncate';
                        span.style.color = '';
                    }
                });
            });
        });

        // ── Dropzone de comprovantes ─────────────────────────────────────────
        const dz = document.getElementById('dropzone');
        const inp = document.getElementById('comprovantes');
        if (dz && inp) {
            dz.addEventListener('dragover', function(e) {
                e.preventDefault();
                dz.style.borderColor = 'var(--primary-gold-analysis)';
                dz.style.background = 'rgba(170,140,44,0.05)';
            });
            dz.addEventListener('dragleave', function() {
                dz.style.borderColor = '#333';
                dz.style.background = 'transparent';
            });
            dz.addEventListener('drop', function(e) {
                e.preventDefault();
                dz.style.borderColor = '#333';
                dz.style.background = 'transparent';
                const dt = new DataTransfer();
                [...(inp.files || []), ...e.dataTransfer.files].forEach(f => dt.items.add(f));
                inp.files = dt.files;
                atualizarPreviewAnexos();
            });
            inp.addEventListener('change', atualizarPreviewAnexos);
        }

        function atualizarPreviewAnexos() {
            const container = document.getElementById('previewNovos');
            if (!container || !inp) return;
            container.innerHTML = '';
            [...inp.files].forEach(function(f) {
                const isImg = f.type.startsWith('image/');
                const kb = Math.round(f.size / 1024);
                const div = document.createElement('div');
                div.className = 'd-flex align-items-center gap-2 p-2 rounded-3 mb-1';
                div.style.cssText = 'background:rgba(255,255,255,0.04);border:1px solid #333;';
                div.innerHTML =
                    '<i class="bi ' + (isImg ? 'bi-image' : 'bi-file-earmark-pdf') + ' flex-shrink-0" style="color:' + (isImg ? '#6ee7c7' : '#f87171') + ';font-size:1rem;"></i>' +
                    '<span class="text-secondary text-truncate flex-grow-1" style="font-size:0.78rem;max-width:220px;">' + f.name + '</span>' +
                    '<span class="text-secondary opacity-50 flex-shrink-0" style="font-size:0.7rem;white-space:nowrap;">' + kb + ' KB</span>';
                container.appendChild(div);
            });
        }

        // ── Deletar comprovante (AJAX) ────────────────────────────────────────
        document.querySelectorAll('.btn-deletar-comp').forEach(function(btn) {
            btn.addEventListener('click', function() {
                if (!confirm('Remover este arquivo permanentemente?')) return;
                const id = this.dataset.id;
                fetch('/comprovante/deletar.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    },
                    body: 'id=' + encodeURIComponent(id)
                }).then(function(r) {
                    return r.json();
                }).then(function(data) {
                    if (data.ok) {
                        const el = document.getElementById('comp-' + id);
                        if (el) el.remove();
                    }
                });
            });
        });

    });

    // ── PARCELAMENTO CC ─────────────────────────────────────────────────────
    (function () {
        const togCC   = document.getElementById('toggle_parcelado_cc');
        const blocoCC = document.getElementById('bloco_parc_cc');
        const numCC   = document.getElementById('num_parcelas_cc');
        const prevCC  = document.getElementById('preview_parc_cc');
        const valCC   = document.getElementById('valor');
        if (!togCC) return;
        togCC.addEventListener('change', function () {
            blocoCC.style.display = this.checked ? 'block' : 'none';
            calcPreviewCC();
        });
        if (numCC) numCC.addEventListener('input', calcPreviewCC);
        if (valCC) valCC.addEventListener('input', calcPreviewCC);
        function calcPreviewCC() {
            if (!togCC.checked || !prevCC) return;
            const n = parseInt(numCC ? numCC.value : 0) || 0;
            const raw = parseFloat((valCC ? valCC.value : '0').replace(/\D/g, '')) / 100 || 0;
            if (raw > 0 && n >= 2) {
                const p = (raw / n).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                prevCC.innerHTML = '<span style="color:#a78bfa;font-weight:600;">' + n + 'x de R$ ' + p + '</span>';
            } else {
                prevCC.textContent = '';
            }
        }
    })();

    // Preserva descrição, valor e data ao trocar o tipo de transação
    document.querySelectorAll('a[href*="tipo="]').forEach(function(link) {
        link.addEventListener('click', function(e) {
            const desc  = document.getElementById('descricao');
            const valor = document.getElementById('valor');
            const data  = document.querySelector('[name="data_registro"]');
            if (!desc && !valor) return;
            const params = new URLSearchParams();
            if (desc  && desc.value.trim())  params.set('_desc', desc.value.trim());
            if (valor && valor.value.trim()) params.set('_val',  valor.value.trim());
            if (data  && data.value)         params.set('_data', data.value);
            if (params.toString()) {
                e.preventDefault();
                location.href = link.href + '&' + params.toString();
            }
        });
    });
</script>
<?php require_once 'geral/footer.php'; ?>