<?php
// webhook_whatsapp_ia.php
// Recebe mensagens WhatsApp via Evolution API, interpreta com Gemini e executa ações.
// Webhook: https://meuauralis.com/webhook_whatsapp_ia.php

require_once __DIR__ . '/config/conexao.php';
require_once __DIR__ . '/config/funcoes.php';
require_once __DIR__ . '/config/gemini.php';

header('Content-Type: application/json');
http_response_code(200);
echo '{"ok":true}';

if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
} else { flush(); ob_flush(); }

// ── 1. Parse payload ──────────────────────────────────────────────────────────

$raw     = file_get_contents('php://input');
$payload = json_decode($raw, true);

if (!$payload || ($payload['event'] ?? '') !== 'messages.upsert') exit;

$data = $payload['data'] ?? [];

if ($data['key']['fromMe'] ?? false) exit;
$remoteJid = $data['key']['remoteJid'] ?? '';
if (str_contains($remoteJid, '@g.us')) exit;

// ── 2. Identifica usuário ─────────────────────────────────────────────────────

$telefone = preg_replace('/\D/', '', explode('@', $remoteJid)[0]);
if (strlen($telefone) < 10) exit;

try {
    $stmtU = $pdo->prepare(
        "SELECT IDUsuario, Nome FROM Usuario
         WHERE Telefone = :tel AND StatusConta = 'ativo' LIMIT 1"
    );
    $stmtU->execute([':tel' => $telefone]);
    $usuario = $stmtU->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) { exit; }

if (!$usuario) exit;

$uid     = $usuario['IDUsuario'];
$msgType = $data['messageType'] ?? 'conversation';

// ── 3. Rate limiting — proteção contra bot/spam ───────────────────────────────
// Máximo 15 mensagens em 2 minutos (humano normal: 1-3/min). Drop silencioso.

_waGarantirTabela($pdo);

try {
    $stmtRate = $pdo->prepare(
        "SELECT COUNT(*) FROM MensagemWA
         WHERE FKUsuario = :uid AND Role = 'user' AND CriadoEm > DATE_SUB(NOW(), INTERVAL 2 MINUTE)"
    );
    $stmtRate->execute([':uid' => $uid]);
    if ((int)$stmtRate->fetchColumn() >= 15) exit;
} catch (Throwable $e) {}

// ── 4. Extrai conteúdo ────────────────────────────────────────────────────────

$texto        = '';
$imagemBase64 = null;
$imagemMime   = 'image/jpeg';

switch ($msgType) {
    case 'conversation':
        $texto = $data['message']['conversation'] ?? '';
        break;
    case 'extendedTextMessage':
        $texto = $data['message']['extendedTextMessage']['text'] ?? '';
        break;
    case 'imageMessage':
    case 'documentMessage':
        $texto        = $data['message'][$msgType]['caption'] ?? '';
        $imagemMime   = $data['message'][$msgType]['mimetype'] ?? 'image/jpeg';
        $imagemBase64 = $data['base64'] ?? $data['message'][$msgType]['base64'] ?? null;
        if (!$imagemBase64) $imagemBase64 = _waFetchBase64($data);
        break;
}

if (!$texto && !$imagemBase64) exit;

// ── 5. Contexto do usuário ────────────────────────────────────────────────────

try {
    $stmtC = $pdo->prepare(
        "SELECT IDCarteira, TipoCarteira AS NomeCarteira FROM Carteira
         WHERE FKUsuarioDono = :uid ORDER BY Principal DESC, TipoCarteira ASC LIMIT 10"
    );
    $stmtC->execute([':uid' => $uid]);
    $carteiras = $stmtC->fetchAll(PDO::FETCH_ASSOC);

    $stmtCat = $pdo->prepare(
        "SELECT IDCategoria, NomeCategoria, TipoCategoria FROM Categoria
         WHERE FKUsuario = :uid ORDER BY NomeCategoria ASC"
    );
    $stmtCat->execute([':uid' => $uid]);
    $categorias = $stmtCat->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $carteiras = []; $categorias = []; }

if (!$carteiras) {
    _waReply($telefone, "❌ Não encontrei carteiras na sua conta. Acesse meuauralis.com para configurar.");
    exit;
}

// Cofrinhos ativos
$cofrinhos = [];
try {
    $stmtCof = $pdo->prepare("
        SELECT c.IDCofrinho, c.Nome, c.ValorMeta,
               COALESCE(SUM(CASE WHEN r.TipoRegistro='cofrinho'          THEN r.Valor
                                 WHEN r.TipoRegistro='cofrinho_retirada' THEN -r.Valor
                                 ELSE 0 END), 0) AS Saldo
        FROM Cofrinho c
        LEFT JOIN Registro r ON r.FKCofrinho = c.IDCofrinho AND r.FKUsuario = :uid
        WHERE c.FKUsuario = :uid2 AND c.Ativo = 1
        GROUP BY c.IDCofrinho, c.Nome, c.ValorMeta
        ORDER BY c.Nome ASC
    ");
    $stmtCof->execute([':uid' => $uid, ':uid2' => $uid]);
    $cofrinhos = $stmtCof->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

// ── 6. Personalidade, histórico e perfil permanente ──────────────────────────

// Lê personalidade + perfil em uma query
$personalidade = 'parceiro';
$perfilIA      = [];
try {
    $stmtPrefs = $pdo->prepare(
        "SELECT Chave, Valor FROM ConfiguracaoSistema WHERE Chave IN ('wa_personalidade','wa_perfil_ia') AND FKUsuario = :uid"
    );
    $stmtPrefs->execute([':uid' => $uid]);
    foreach ($stmtPrefs->fetchAll(PDO::FETCH_KEY_PAIR) as $k => $v) {
        if ($k === 'wa_personalidade' && $v) $personalidade = $v;
        if ($k === 'wa_perfil_ia'     && $v) $perfilIA = json_decode($v, true) ?: [];
    }
} catch (Throwable $e) {}

// Histórico de conversa (últimas 10 mensagens)
$historico = _waLoadHistory($pdo, $uid, 10);

// Cleanup probabilístico (~5%) — sem cron
if (mt_rand(1, 20) === 1) {
    try {
        $pdo->prepare("DELETE FROM MensagemWA WHERE FKUsuario = :uid AND CriadoEm < DATE_SUB(NOW(), INTERVAL 30 DAY)")
            ->execute([':uid' => $uid]);
    } catch (Throwable $e) {}
}

// ── 7. Monta system prompt ────────────────────────────────────────────────────

$hoje      = date('Y-m-d');
$nomeUser  = explode(' ', $usuario['Nome'])[0];
$cartsList = json_encode(array_map(fn($c) => ['id' => $c['IDCarteira'], 'nome' => $c['NomeCarteira']], $carteiras), JSON_UNESCAPED_UNICODE);
$catDesp   = json_encode(array_values(array_map(fn($c) => ['id' => $c['IDCategoria'], 'nome' => $c['NomeCategoria']], array_filter($categorias, fn($c) => $c['TipoCategoria'] === 'despesa'))), JSON_UNESCAPED_UNICODE);
$catRec    = json_encode(array_values(array_map(fn($c) => ['id' => $c['IDCategoria'], 'nome' => $c['NomeCategoria']], array_filter($categorias, fn($c) => $c['TipoCategoria'] === 'receita'))), JSON_UNESCAPED_UNICODE);
$cofList   = $cofrinhos ? json_encode(array_map(fn($c) => ['id' => $c['IDCofrinho'], 'nome' => $c['Nome'], 'meta' => (float)$c['ValorMeta'], 'saldo' => (float)$c['Saldo']], $cofrinhos), JSON_UNESCAPED_UNICODE) : '[]';

$tomVoz = $personalidade === 'profissional'
    ? "Seja direto e profissional. Sem expressões informais ou emojis desnecessários."
    : "Seja natural como um amigo que manja de dinheiro. Linguagem casual, sem frescura. Emojis com moderação e só quando fizerem sentido.";

$imgCtx = $imagemBase64 ? " O usuário enviou uma imagem — leia os dados financeiros dela (comprovante, nota fiscal, etc.)." : "";

$perfilCtx = '';
if ($perfilIA) {
    $perfilJson = json_encode($perfilIA, JSON_UNESCAPED_UNICODE);
    $perfilCtx  = "\nPerfil aprendido sobre {$nomeUser}: {$perfilJson}";
}

// ── Contexto rico pré-buscado (Gemini responde direto sem precisar de consultar) ──

// Snapshot do mês
$recEfet = 0; $despEfet = 0; $totalPendente = 0; $qtdPendente = 0;
try {
    $stmtSnap = $pdo->prepare("
        SELECT
            SUM(CASE WHEN TipoRegistro IN ('receita') AND StatusRegistro='efetivado' THEN Valor ELSE 0 END) AS RecEfet,
            SUM(CASE WHEN TipoRegistro IN ('despesa') AND StatusRegistro='efetivado' THEN Valor ELSE 0 END) AS DespEfet,
            SUM(CASE WHEN StatusRegistro='pendente' AND TipoRegistro IN ('receita','despesa') THEN Valor ELSE 0 END) AS Pendente,
            COUNT(CASE WHEN StatusRegistro='pendente' AND TipoRegistro IN ('receita','despesa') THEN 1 END) AS QtdPend
        FROM Registro WHERE FKUsuario = :uid
          AND COALESCE(DataVencimento, DATE(MomentoRegistro)) BETWEEN :ini AND :fim
    ");
    $stmtSnap->execute([':uid' => $uid, ':ini' => date('Y-m-01'), ':fim' => date('Y-m-t')]);
    $snap = $stmtSnap->fetch(PDO::FETCH_ASSOC);
    if ($snap) {
        $recEfet      = (float)$snap['RecEfet'];
        $despEfet     = (float)$snap['DespEfet'];
        $totalPendente = (float)$snap['Pendente'];
        $qtdPendente  = (int)$snap['QtdPend'];
    }
} catch (Throwable $e) {}

$saldoMes = $recEfet - $despEfet;

// Pendentes próximos 14 dias (lista detalhada para o Gemini usar diretamente)
$pendentesCtx = '';
try {
    $stmtPend = $pdo->prepare("
        SELECT r.Descricao, r.Valor, r.TipoRegistro, r.DataVencimento, c.NomeCategoria
        FROM Registro r LEFT JOIN Categoria c ON c.IDCategoria = r.FKCategoria
        WHERE r.FKUsuario = :uid AND r.StatusRegistro = 'pendente'
          AND r.DataVencimento BETWEEN :ini AND :fim
          AND r.TipoRegistro IN ('receita','despesa')
        ORDER BY r.DataVencimento ASC LIMIT 20
    ");
    $stmtPend->execute([':uid' => $uid, ':ini' => $hoje, ':fim' => date('Y-m-d', strtotime('+14 days'))]);
    $pendRows = $stmtPend->fetchAll(PDO::FETCH_ASSOC);
    if ($pendRows) {
        $pendLines = array_map(fn($r) =>
            date('d/m', strtotime($r['DataVencimento'])) . ' ' .
            $r['Descricao'] . ' R$' . number_format($r['Valor'], 2, ',', '.') .
            ($r['TipoRegistro'] === 'receita' ? ' [rec]' : '') .
            ($r['NomeCategoria'] ? ' (' . $r['NomeCategoria'] . ')' : ''),
            $pendRows
        );
        $pendentesCtx = "\nPendentes nos próximos 14 dias: " . implode('; ', $pendLines) . '.';
    } else {
        $pendentesCtx = "\nPendentes nos próximos 14 dias: nenhum.";
    }
} catch (Throwable $e) {}

// Últimos 3 registros
$ultimosCtx = '';
try {
    $stmtUlt = $pdo->prepare("
        SELECT r.Descricao, r.Valor, r.TipoRegistro, r.DataVencimento, r.StatusRegistro
        FROM Registro r WHERE r.FKUsuario = :uid AND r.StatusRegistro != 'cancelado'
        ORDER BY r.MomentoRegistro DESC LIMIT 3
    ");
    $stmtUlt->execute([':uid' => $uid]);
    $ultRows = $stmtUlt->fetchAll(PDO::FETCH_ASSOC);
    if ($ultRows) {
        $ultLines = array_map(fn($r) =>
            $r['Descricao'] . ' R$' . number_format($r['Valor'], 2, ',', '.') .
            ' [' . $r['TipoRegistro'] . '/' . $r['StatusRegistro'] . ']',
            $ultRows
        );
        $ultimosCtx = "\nÚltimos lançamentos: " . implode('; ', $ultLines) . '.';
    }
} catch (Throwable $e) {}

$saldoFmt    = 'R$' . number_format(abs($saldoMes), 2, ',', '.') . ($saldoMes < 0 ? ' (negativo)' : '');
$recFmt      = 'R$' . number_format($recEfet,      2, ',', '.');
$despFmt     = 'R$' . number_format($despEfet,     2, ',', '.');
$pendFmt     = 'R$' . number_format($totalPendente, 2, ',', '.');

$systemPrompt = <<<EOT
Você é o Auralis, assistente financeiro de {$nomeUser}.{$imgCtx}{$perfilCtx}

{$tomVoz}

REGRAS DE COMPORTAMENTO — siga sem exceção:
1. Responda DIRETAMENTE o que foi perguntado. Sem título, sem header, sem "aqui está o resumo", sem apresentação antes da resposta.
2. Use os dados do contexto para responder conversacionalmente sempre que possível. Reserve a action "consultar" para consultas que precisem de cálculos agregados que não estão no contexto (ex: breakdown por categoria, períodos muito anteriores).
3. Se a mensagem for ambígua ou você não tiver certeza do que a pessoa quer, use action "clarificar" — não tente adivinhar e errar.
4. Uma reação casual ("kkk", "valeu", "tá bom") merece uma resposta casual curta. Não repita dados financeiros que já foram mostrados logo antes.
5. Nunca invente dados. Se não tiver a informação no contexto e precisar de DB, use "consultar".
6. Múltiplas intenções na mesma mensagem → use "acoes" array.

Contexto financeiro atual de {$nomeUser}:
- Hoje: {$hoje}
- Mês atual: receitas efetivadas={$recFmt}, despesas efetivadas={$despFmt}, saldo={$saldoFmt}, pendentes={$qtdPendente} itens={$pendFmt}{$pendentesCtx}{$ultimosCtx}
- Carteiras: {$cartsList}
- Cofrinhos: {$cofList}
- Categorias despesa: {$catDesp}
- Categorias receita: {$catRec}

ACTIONS disponíveis (responda SEMPRE com JSON válido, sem markdown):

"conversar" — resposta livre para perguntas, cálculos com dados do contexto, opiniões, reações, bate-papo. USE ISSO quando conseguir responder com o contexto acima:
{"action":"conversar","resposta":"resposta direta e natural"}

"clarificar" — quando não entende ou a mensagem tem 2+ interpretações válidas:
{"action":"clarificar","pergunta":"pergunta curta e objetiva","opcoes":["interpretação A","interpretação B"]}

"registrar" — lançar transações financeiras:
{"action":"registrar","registros":[{"tipo":"despesa","valor":0.00,"descricao":"max 60 chars","data":"YYYY-MM-DD","id_carteira":"uuid","id_categoria":"uuid|null","nome_carteira":"nome","nome_categoria":"nome|null","parcelas":1}]}
Regras: primeira carteira se não mencionada; data relativa → YYYY-MM-DD exato; parcelas>1 para parcelamentos.

"efetivar" — marcar pendente(s) como pago/recebido:
{"action":"efetivar","descricoes":["texto parcial"]}

"cofrinho_depositar":
{"action":"cofrinho_depositar","nome_cofrinho":"nome","valor":0.00}

"cofrinho_criar":
{"action":"cofrinho_criar","nome":"nome","meta":0.00}

"consultar" — USE APENAS para cálculos que exigem DB: breakdown por categoria, períodos passados, totais não presentes no contexto:
{"action":"consultar","consulta":{"tipo":"gastos|pendentes|saldo|ultimo","periodo":"hoje|semana|mes|ano","tipo_registro":"despesa|receita|null"}}

"cancelar":
{"action":"cancelar"}

"ajuda" — SOMENTE se pedir lista de comandos explicitamente:
{"action":"ajuda"}

"acoes" — múltiplas intenções distintas:
{"acoes":[{acao1},{acao2}]}

CAMPO OPCIONAL "_perfil_atualizado" — só inclua se aprendeu algo novo (apelido, tom, contexto financeiro recorrente). OMITA se nada novo.
EOT;

// ── 8. Chama Gemini com histórico ─────────────────────────────────────────────

$resultado = _waGemini($systemPrompt, $historico, $texto, $imagemBase64, $imagemMime);

if (!$resultado) {
    _waReply($telefone, "⚠️ IA sem resposta. Tente novamente em instantes.");
    exit;
}

if (!empty($resultado['_api_error'])) {
    $code = $resultado['_code'];
    if ($code === 429) {
        _waReply($telefone, "⏳ Limite de requisições atingido. Aguarde um minuto e tente novamente.");
    } else {
        _waReply($telefone, "❌ Erro da IA (código {$code}). Contate o suporte.");
    }
    exit;
}

// ── 9. Despacha actions (simples ou múltiplas) ────────────────────────────────

// Normaliza para sempre trabalhar com array de ações
if (!empty($resultado['acoes']) && is_array($resultado['acoes'])) {
    $acoes = $resultado['acoes'];
} else {
    $acoes = [$resultado];
}

$respostas = [];
foreach ($acoes as $acao) {
    $respostas[] = _waDespachar($pdo, $uid, $acao, $carteiras, $cofrinhos, $hoje, $personalidade);
}

$resposta = implode("\n\n", $respostas);

_waReply($telefone, $resposta);
_waSaveHistory($pdo, $uid, $texto, $resposta);

// Atualiza perfil permanente se a IA aprendeu algo novo
if (!empty($resultado['_perfil_atualizado']) && is_array($resultado['_perfil_atualizado'])) {
    _waSalvarPerfil($pdo, $uid, $resultado['_perfil_atualizado']);
}

exit;

// ── Despachante central ───────────────────────────────────────────────────────

function _waDespachar(PDO $pdo, string $uid, array $acao, array $carteiras, array $cofrinhos, string $hoje, string $personalidade): string
{
    $action = $acao['action'] ?? 'conversar';
    return match($action) {
        'registrar'          => _waRegistrar($pdo, $uid, $acao['registros'] ?? [], $carteiras, $hoje),
        'efetivar'           => _waEfetivar($pdo, $uid, $acao['descricoes'] ?? []),
        'cofrinho_depositar' => _waCofrinhoDepositar($pdo, $uid, $acao, $cofrinhos, $carteiras, $hoje),
        'cofrinho_criar'     => _waCofinhoCriar($pdo, $uid, $acao, $carteiras, $hoje),
        'consultar'          => _waConsultar($pdo, $uid, $acao['consulta'] ?? [], $hoje),
        'cancelar'           => _waCancelar($pdo, $uid),
        'ajuda'              => _waAjuda($personalidade),
        'clarificar'         => _waClarificar($acao),
        'conversar'          => !empty($acao['resposta']) ? (string)$acao['resposta'] : "Pode elaborar mais?",
        default              => !empty($acao['resposta']) ? (string)$acao['resposta'] : "Não entendi bem. Pode repetir?",
    };
}

// ── Handlers ──────────────────────────────────────────────────────────────────

function _waRegistrar(PDO $pdo, string $uid, array $registros, array $carteiras, string $hoje): string
{
    if (!$registros) return "❌ Não identifiquei nenhuma transação. Tente com mais detalhes.";

    $confirmacoes = [];
    $erros        = 0;

    $stmtIns = $pdo->prepare("
        INSERT INTO Registro
            (IDRegistro, Valor, Descricao, FKCarteira, FKUsuario, FKCategoria,
             TipoRegistro, DataVencimento, StatusRegistro, Recorrente,
             GrupoParcela, ParcelaAtual, TotalParcelas)
        VALUES (:id, :val, :desc, :cart, :uid, :cat, :tipo, :data, :status, 0,
                :grupo, :parc_atual, :total_parc)
    ");

    foreach ($registros as $r) {
        $valor    = abs((float)($r['valor'] ?? 0));
        $descricao = mb_substr(trim($r['descricao'] ?? ''), 0, 200);
        $tipo     = in_array($r['tipo'] ?? '', ['receita', 'despesa']) ? $r['tipo'] : 'despesa';
        $dataBase = $r['data'] ?? $hoje;
        $cart     = $r['id_carteira'] ?? $carteiras[0]['IDCarteira'];
        $cat      = !empty($r['id_categoria']) ? $r['id_categoria'] : null;
        $parcelas = max(1, (int)($r['parcelas'] ?? 1));

        if (!$valor || !$descricao) { $erros++; continue; }

        $grupoParcela = $parcelas > 1 ? gerarUuid() : null;

        for ($i = 1; $i <= $parcelas; $i++) {
            $dataParcela = $parcelas > 1
                ? date('Y-m-d', strtotime($dataBase . ' +' . ($i - 1) . ' month'))
                : $dataBase;

            $status      = $dataParcela > $hoje ? 'pendente' : 'efetivado';
            $descParcela = $parcelas > 1 ? mb_substr($descricao, 0, 50) . " {$i}/{$parcelas}" : $descricao;

            try {
                $stmtIns->execute([
                    ':id'         => gerarUuid(),
                    ':val'        => $valor,
                    ':desc'       => $descParcela,
                    ':cart'       => $cart,
                    ':uid'        => $uid,
                    ':cat'        => $cat,
                    ':tipo'       => $tipo,
                    ':data'       => $dataParcela,
                    ':status'     => $status,
                    ':grupo'      => $grupoParcela,
                    ':parc_atual' => $parcelas > 1 ? $i : null,
                    ':total_parc' => $parcelas > 1 ? $parcelas : null,
                ]);
            } catch (Throwable $e) { $erros++; continue 2; }
        }

        $icon    = $tipo === 'receita' ? '📈' : '📉';
        $valFmt  = 'R$ ' . number_format($valor, 2, ',', '.');
        $dataFmt = date('d/m/Y', strtotime($dataBase));
        $catNome = !empty($r['nome_categoria']) ? " · " . $r['nome_categoria'] : '';
        $cartNome = $r['nome_carteira'] ?? $carteiras[0]['NomeCarteira'];
        $pendente = $dataBase > $hoje ? ' _(pendente)_' : '';

        if ($parcelas > 1) {
            $totalFmt = 'R$ ' . number_format($valor * $parcelas, 2, ',', '.');
            $fimFmt   = date('d/m/Y', strtotime($dataBase . ' +' . ($parcelas - 1) . ' month'));
            $confirmacoes[] = "{$icon} *{$descricao}*\n   {$valFmt}/mês × {$parcelas} = {$totalFmt}{$catNome} · {$cartNome}\n   📅 {$dataFmt} → {$fimFmt}";
        } else {
            $confirmacoes[] = "{$icon} *{$descricao}*: {$valFmt}{$catNome} · {$cartNome} · 📅 {$dataFmt}{$pendente}";
        }
    }

    if (!$confirmacoes) return "❌ Não consegui salvar os registros. Tente novamente.";

    $header  = count($confirmacoes) === 1 ? "✅ *Registrado!*" : "✅ *" . count($confirmacoes) . " registros salvos!*";
    $erroTxt = $erros ? "\n\n⚠️ {$erros} item(ns) não salvo(s)." : '';

    return $header . "\n\n" . implode("\n", $confirmacoes) . $erroTxt . "\n\n_meuauralis.com_ 👆";
}

function _waEfetivar(PDO $pdo, string $uid, array $descricoes): string
{
    if (!$descricoes) return "❌ Não identifiquei qual registro efetivar.";

    $efetivados = [];
    $naoEncontrados = [];

    $stmtBusca = $pdo->prepare("
        SELECT IDRegistro, Descricao, Valor, TipoRegistro, DataVencimento
        FROM Registro
        WHERE FKUsuario = :uid AND StatusRegistro = 'pendente' AND Descricao LIKE :desc
        ORDER BY DataVencimento ASC LIMIT 3
    ");
    $stmtUpd = $pdo->prepare("UPDATE Registro SET StatusRegistro = 'efetivado' WHERE IDRegistro = :id");

    foreach ($descricoes as $desc) {
        $desc = trim((string)$desc);
        if (!$desc) continue;

        try {
            $stmtBusca->execute([':uid' => $uid, ':desc' => '%' . $desc . '%']);
            $rows = $stmtBusca->fetchAll(PDO::FETCH_ASSOC);

            if (!$rows) {
                $naoEncontrados[] = $desc;
                continue;
            }

            // Se encontrou mais de um, pega o mais próximo da data atual
            $row = $rows[0];
            $stmtUpd->execute([':id' => $row['IDRegistro']]);

            $icon   = $row['TipoRegistro'] === 'receita' ? '📈' : '📉';
            $val    = 'R$ ' . number_format((float)$row['Valor'], 2, ',', '.');
            $dtFmt  = $row['DataVencimento'] ? date('d/m', strtotime($row['DataVencimento'])) : '—';
            $efetivados[] = "{$icon} *{$row['Descricao']}*: {$val} (📅 {$dtFmt})";
        } catch (Throwable $e) { $naoEncontrados[] = $desc; }
    }

    $msg = '';
    if ($efetivados) {
        $msg .= "✅ *Efetivado" . (count($efetivados) > 1 ? "s" : "") . "!*\n\n" . implode("\n", $efetivados);
    }
    if ($naoEncontrados) {
        $msg .= ($msg ? "\n\n" : "") . "⚠️ Não encontrei pendente com: " . implode(', ', array_map(fn($d) => "*{$d}*", $naoEncontrados));
    }

    return $msg ?: "❌ Nenhum registro pendente encontrado.";
}

function _waCofrinhoDepositar(PDO $pdo, string $uid, array $acao, array $cofrinhos, array $carteiras, string $hoje): string
{
    $nomeBusca = trim($acao['nome_cofrinho'] ?? '');
    $valor     = abs((float)($acao['valor'] ?? 0));

    if (!$nomeBusca || !$valor) return "❌ Informe o nome do cofrinho e o valor.";

    // Busca cofrinho por nome parcial (case insensitive)
    $cofrinho = null;
    foreach ($cofrinhos as $c) {
        if (stripos($c['Nome'], $nomeBusca) !== false) {
            $cofrinho = $c;
            break;
        }
    }

    if (!$cofrinho) {
        $lista = implode(', ', array_map(fn($c) => "*{$c['Nome']}*", $cofrinhos));
        return "❌ Cofrinho \"{$nomeBusca}\" não encontrado.\n\nSeus cofrinhos: " . ($lista ?: "nenhum ainda.");
    }

    // Busca carteira vinculada ao cofrinho
    try {
        $stmtCofDet = $pdo->prepare("SELECT FKCarteira FROM Cofrinho WHERE IDCofrinho = :id AND FKUsuario = :uid AND Ativo = 1");
        $stmtCofDet->execute([':id' => $cofrinho['IDCofrinho'], ':uid' => $uid]);
        $cofDet = $stmtCofDet->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return "❌ Erro ao acessar o cofrinho."; }

    if (!$cofDet) return "❌ Cofrinho não encontrado ou inativo.";

    $idCarteira = $cofDet['FKCarteira'] ?? ($carteiras[0]['IDCarteira'] ?? null);

    try {
        $pdo->prepare("
            INSERT INTO Registro (IDRegistro, TipoRegistro, Valor, Descricao, MomentoRegistro, DataVencimento,
                                  StatusRegistro, Recorrente, DiaVencimento, FKCarteira, FKUsuario, FKCofrinho)
            VALUES (:id, 'cofrinho', :val, :desc, NOW(), :hoje,
                    'efetivado', 0, NULL, :cart, :uid, :cof)
        ")->execute([
            ':id'   => gerarUuid(),
            ':val'  => $valor,
            ':desc' => 'Aporte via WhatsApp',
            ':hoje' => $hoje,
            ':cart' => $idCarteira,
            ':uid'  => $uid,
            ':cof'  => $cofrinho['IDCofrinho'],
        ]);
    } catch (Throwable $e) { return "❌ Erro ao registrar aporte."; }

    $valFmt      = 'R$ ' . number_format($valor, 2, ',', '.');
    $novoSaldo   = (float)$cofrinho['Saldo'] + $valor;
    $saldoFmt    = 'R$ ' . number_format($novoSaldo, 2, ',', '.');
    $meta        = (float)$cofrinho['ValorMeta'];
    $progresso   = '';
    if ($meta > 0) {
        $pct       = min(100, round($novoSaldo / $meta * 100));
        $faltaFmt  = 'R$ ' . number_format(max(0, $meta - $novoSaldo), 2, ',', '.');
        $progresso = "\n🎯 Meta: " . 'R$ ' . number_format($meta, 2, ',', '.') . " · {$pct}% atingido" . ($novoSaldo >= $meta ? " 🎉" : " (falta {$faltaFmt})");
    }

    return "🏦 *Aporte no cofrinho \"{$cofrinho['Nome']}\"!*\n\n+{$valFmt} · saldo: {$saldoFmt}{$progresso}";
}

function _waCofinhoCriar(PDO $pdo, string $uid, array $acao, array $carteiras, string $hoje): string
{
    $nome = mb_substr(trim($acao['nome'] ?? ''), 0, 100);
    $meta = abs((float)($acao['meta'] ?? 0));

    if (!$nome) return "❌ Informe um nome para o cofrinho.";

    $cart = $carteiras[0]['IDCarteira'] ?? null;
    if (!$cart) return "❌ Nenhuma carteira encontrada para vincular o cofrinho.";

    // Garante que ENUM inclui cofrinho (auto-migrate igual ao processa_cofrinho)
    try {
        $enumRow = $pdo->query("SHOW COLUMNS FROM Registro LIKE 'TipoRegistro'")->fetch(PDO::FETCH_ASSOC);
        if ($enumRow && strpos($enumRow['Type'] ?? '', 'cofrinho') === false) {
            $pdo->exec("ALTER TABLE Registro MODIFY COLUMN TipoRegistro ENUM('receita','despesa','cofrinho','cofrinho_retirada') NOT NULL DEFAULT 'despesa'");
        }
    } catch (Throwable $e) {}

    try {
        $pdo->prepare("
            INSERT INTO Cofrinho (IDCofrinho, FKUsuario, FKCarteira, Nome, Icone, Cor, ValorMeta, DataLimite)
            VALUES (:id, :uid, :cart, :nome, '🏦', '#d4af37', :meta, NULL)
        ")->execute([
            ':id'   => gerarUuid(),
            ':uid'  => $uid,
            ':cart' => $cart,
            ':nome' => $nome,
            ':meta' => $meta,
        ]);
    } catch (Throwable $e) { return "❌ Erro ao criar cofrinho. Tente pelo app."; }

    $metaTxt = $meta > 0 ? "\n🎯 Meta: R$ " . number_format($meta, 2, ',', '.') : '';

    return "🏦 *Cofrinho \"{$nome}\" criado!*{$metaTxt}\n\nAgora você pode mandar \"deposita X no {$nome}\" para começar a guardar. 💰";
}

function _waClarificar(array $acao): string
{
    $pergunta = trim($acao['pergunta'] ?? 'Pode explicar melhor?');
    $opcoes   = array_filter((array)($acao['opcoes'] ?? []));

    if (!$opcoes) return $pergunta;

    $letras = ['a', 'b', 'c', 'd'];
    $linhas = [];
    foreach (array_values($opcoes) as $i => $op) {
        $linhas[] = ($letras[$i] ?? ($i + 1)) . ') ' . $op;
    }
    return $pergunta . "\n\n" . implode("\n", $linhas);
}

function _waConsultar(PDO $pdo, string $uid, array $consulta, string $hoje): string
{
    $tipo    = $consulta['tipo']          ?? 'gastos';
    $periodo = $consulta['periodo']       ?? 'mes';
    $tipoReg = $consulta['tipo_registro'] ?? null;

    switch ($periodo) {
        case 'hoje':
            $ini = $hoje; $fim = $hoje; $label = 'hoje';
            break;
        case 'semana':
            $ini = date('Y-m-d', strtotime('monday this week'));
            $fim = date('Y-m-d', strtotime('sunday this week'));
            $label = 'esta semana';
            break;
        case 'ano':
            $ini = date('Y-01-01'); $fim = date('Y-12-31');
            $label = 'este ano (' . date('Y') . ')';
            break;
        default:
            $ini = date('Y-m-01'); $fim = date('Y-m-t');
            $meses = ['','jan','fev','mar','abr','mai','jun','jul','ago','set','out','nov','dez'];
            $label = 'este mês (' . $meses[(int)date('n')] . '/' . date('y') . ')';
    }

    try {
        switch ($tipo) {

            case 'pendentes':
                $stmt = $pdo->prepare("
                    SELECT r.Descricao, r.Valor, r.TipoRegistro, r.DataVencimento, c.NomeCategoria
                    FROM Registro r LEFT JOIN Categoria c ON c.IDCategoria = r.FKCategoria
                    WHERE r.FKUsuario = :uid AND r.StatusRegistro = 'pendente'
                      AND r.DataVencimento BETWEEN :ini AND :fim
                      " . ($tipoReg ? "AND r.TipoRegistro = :tipo" : "") . "
                    ORDER BY r.DataVencimento ASC LIMIT 20
                ");
                $p = [':uid' => $uid, ':ini' => $ini, ':fim' => $fim];
                if ($tipoReg) $p[':tipo'] = $tipoReg;
                $stmt->execute($p);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

                if (!$rows) return "Nada pendente pra {$label}. ✅";

                $total  = array_sum(array_column($rows, 'Valor'));
                $linhas = array_map(fn($r) =>
                    "• " . date('d/m', strtotime($r['DataVencimento'])) .
                    " *{$r['Descricao']}*: R$ " . number_format($r['Valor'], 2, ',', '.') .
                    ($r['NomeCategoria'] ? " ({$r['NomeCategoria']})" : ''),
                    $rows
                );
                $intro = count($rows) === 1 ? "Só uma pendência pra {$label}:" : count($rows) . " pendências pra {$label}:";
                return "{$intro}\n\n" . implode("\n", $linhas) .
                       "\n\n💰 Total: R$ " . number_format($total, 2, ',', '.');

            case 'saldo':
                $stmt = $pdo->prepare("
                    SELECT TipoRegistro, SUM(Valor) AS Total FROM Registro
                    WHERE FKUsuario = :uid AND StatusRegistro = 'efetivado'
                      AND COALESCE(DataVencimento, DATE(MomentoRegistro)) BETWEEN :ini AND :fim
                    GROUP BY TipoRegistro
                ");
                $stmt->execute([':uid' => $uid, ':ini' => $ini, ':fim' => $fim]);
                $rows  = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
                $rec   = (float)($rows['receita'] ?? 0);
                $desp  = (float)($rows['despesa']  ?? 0);
                $saldo = $rec - $desp;

                $saldoStr = 'R$ ' . number_format(abs($saldo), 2, ',', '.');
                $status   = $saldo >= 0 ? "positivo em {$saldoStr} 📈" : "negativo em {$saldoStr} 📉";
                return "Saldo {$label}: {$status}\n\n" .
                       "Receitas: R$ " . number_format($rec,  2, ',', '.') . "\n" .
                       "Despesas: R$ " . number_format($desp, 2, ',', '.');

            case 'ultimo':
                $stmt = $pdo->prepare("
                    SELECT r.Descricao, r.Valor, r.TipoRegistro, r.DataVencimento, r.StatusRegistro, c.NomeCategoria
                    FROM Registro r LEFT JOIN Categoria c ON c.IDCategoria = r.FKCategoria
                    WHERE r.FKUsuario = :uid AND r.StatusRegistro != 'cancelado'
                    ORDER BY r.MomentoRegistro DESC LIMIT 1
                ");
                $stmt->execute([':uid' => $uid]);
                $r = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$r) return "Nenhum registro ainda.";

                $icon    = $r['TipoRegistro'] === 'receita' ? '📈' : '📉';
                $dataFmt = $r['DataVencimento'] ? date('d/m/Y', strtotime($r['DataVencimento'])) : '—';
                $catStr  = $r['NomeCategoria'] ? " · {$r['NomeCategoria']}" : '';
                return "Último: {$icon} *{$r['Descricao']}* — R$ " . number_format($r['Valor'], 2, ',', '.') .
                       " · {$dataFmt}{$catStr} · {$r['StatusRegistro']}";

            default: // gastos
                $tipoQuery = $tipoReg ?? 'despesa';
                $stmt = $pdo->prepare("
                    SELECT c.NomeCategoria, SUM(r.Valor) AS Total
                    FROM Registro r LEFT JOIN Categoria c ON c.IDCategoria = r.FKCategoria
                    WHERE r.FKUsuario = :uid AND r.TipoRegistro = :tipo
                      AND r.StatusRegistro = 'efetivado'
                      AND COALESCE(r.DataVencimento, DATE(r.MomentoRegistro)) BETWEEN :ini AND :fim
                    GROUP BY r.FKCategoria ORDER BY Total DESC LIMIT 10
                ");
                $stmt->execute([':uid' => $uid, ':tipo' => $tipoQuery, ':ini' => $ini, ':fim' => $fim]);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

                $tipoLabel = $tipoQuery === 'receita' ? 'receitas' : 'gastos';
                if (!$rows) return "Sem {$tipoLabel} registrados pra {$label}.";

                $total  = array_sum(array_column($rows, 'Total'));
                $linhas = array_map(fn($r) =>
                    "• " . ($r['NomeCategoria'] ?? 'Sem categoria') . ": R$ " . number_format($r['Total'], 2, ',', '.'),
                    $rows
                );
                return ucfirst($tipoLabel) . " {$label}:\n\n" . implode("\n", $linhas) .
                       "\n\nTotal: *R$ " . number_format($total, 2, ',', '.') . "*";
        }
    } catch (Throwable $e) {
        return "❌ Erro ao consultar. Tente novamente.";
    }
}

function _waCancelar(PDO $pdo, string $uid): string
{
    try {
        $stmt = $pdo->prepare("
            SELECT IDRegistro, Descricao, Valor, TipoRegistro FROM Registro
            WHERE FKUsuario = :uid AND StatusRegistro != 'cancelado'
            ORDER BY MomentoRegistro DESC LIMIT 1
        ");
        $stmt->execute([':uid' => $uid]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$r) return "Nenhum registro para cancelar.";

        $pdo->prepare("UPDATE Registro SET StatusRegistro = 'cancelado' WHERE IDRegistro = :id")
            ->execute([':id' => $r['IDRegistro']]);

        $icon = $r['TipoRegistro'] === 'receita' ? '📈' : '📉';
        return "↩️ *Cancelado!*\n\n{$icon} {$r['Descricao']}: R$ " . number_format($r['Valor'], 2, ',', '.') . "\n\n_Marcado como cancelado no app._";
    } catch (Throwable $e) {
        return "❌ Erro ao cancelar. Tente novamente.";
    }
}

function _waAjuda(string $personalidade = 'parceiro'): string
{
    if ($personalidade === 'profissional') {
        return "Comandos disponíveis:\n\n" .
               "• Registrar: \"Paguei 50 de uber\"\n" .
               "• Efetivar pendente: \"Paguei o Spotify\"\n" .
               "• Parcelamento: \"Comprei em 12x de 150\"\n" .
               "• Múltiplos: \"Paguei 50 de uber e 30 de almoço\"\n" .
               "• Cofrinho: \"Deposita 200 no cofrinho Viagem\"\n" .
               "• Criar cofrinho: \"Cria cofrinho Carro com meta 6000\"\n" .
               "• Consultar: \"Quanto gastei esse mês?\"\n" .
               "• Saldo: \"Qual meu saldo?\"\n" .
               "• Pendentes: \"Tenho conta pra pagar?\"\n" .
               "• Cancelar: \"Cancela o último lançamento\"\n" .
               "• Comprovantes: envie a foto diretamente";
    }

    return "Opa! 👋 Pode mandar à vontade:\n\n" .
           "📝 *Registrar:*\n" .
           "• _\"Paguei 55 no corte de cabelo\"_\n" .
           "• _\"Recebi 2000 de salário hoje\"_\n" .
           "• _\"Comprei celular em 12x de 150\"_\n" .
           "• _\"Paguei 50 de uber e 30 de almoço\"_\n" .
           "• _[foto de comprovante PIX]_\n\n" .
           "✅ *Efetivar pendente:*\n" .
           "• _\"Paguei o Spotify\"_ / _\"Recebi o salário\"_\n\n" .
           "🏦 *Cofrinhos:*\n" .
           "• _\"Deposita 200 no cofrinho Viagem\"_\n" .
           "• _\"Cria cofrinho Carro com meta 6000\"_\n\n" .
           "📊 *Consultar:*\n" .
           "• _\"Quanto gastei esse mês?\"_\n" .
           "• _\"Tenho conta pra pagar essa semana?\"_\n" .
           "• _\"Qual meu saldo?\"_\n\n" .
           "↩️ *Desfazer:*\n" .
           "• _\"Cancela o último lançamento\"_";
}

// ── Perfil permanente ─────────────────────────────────────────────────────────

function _waSalvarPerfil(PDO $pdo, string $uid, array $perfil): void
{
    if (!isset($perfil['notas']) || !is_array($perfil['notas'])) $perfil['notas'] = [];
    $perfil['notas'] = array_slice(array_filter(array_map('strval', $perfil['notas'])), 0, 20);
    if (isset($perfil['apelido']))  $perfil['apelido'] = mb_substr((string)$perfil['apelido'], 0, 60);
    if (isset($perfil['tom']))      $perfil['tom']     = mb_substr((string)$perfil['tom'],     0, 200);

    $json = json_encode($perfil, JSON_UNESCAPED_UNICODE);
    if (!$json || strlen($json) > 4000) return;

    try {
        $stmtChk = $pdo->prepare("SELECT COUNT(*) FROM ConfiguracaoSistema WHERE Chave = 'wa_perfil_ia' AND FKUsuario = :uid");
        $stmtChk->execute([':uid' => $uid]);
        if ($stmtChk->fetchColumn() > 0) {
            $pdo->prepare("UPDATE ConfiguracaoSistema SET Valor = :v WHERE Chave = 'wa_perfil_ia' AND FKUsuario = :uid")
                ->execute([':v' => $json, ':uid' => $uid]);
        } else {
            $pdo->prepare("INSERT INTO ConfiguracaoSistema (Chave, Valor, FKUsuario) VALUES ('wa_perfil_ia', :v, :uid)")
                ->execute([':v' => $json, ':uid' => $uid]);
        }
    } catch (Throwable $e) {}
}

// ── Histórico de conversa ─────────────────────────────────────────────────────

function _waGarantirTabela(PDO $pdo): void
{
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS MensagemWA (
                IDMensagem  VARCHAR(36)          NOT NULL,
                FKUsuario   VARCHAR(36)          NOT NULL,
                Role        ENUM('user','model') NOT NULL DEFAULT 'user',
                Conteudo    TEXT                 NOT NULL,
                CriadoEm   TIMESTAMP            NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (IDMensagem),
                KEY idx_usuario_data (FKUsuario, CriadoEm)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (Throwable $e) {}
}

function _waLoadHistory(PDO $pdo, string $uid, int $limit = 10): array
{
    try {
        $stmt = $pdo->prepare("
            SELECT Role, Conteudo FROM MensagemWA
            WHERE FKUsuario = :uid
            ORDER BY CriadoEm DESC LIMIT :lim
        ");
        $stmt->bindValue(':uid', $uid);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC));
    } catch (Throwable $e) { return []; }
}

function _waSaveHistory(PDO $pdo, string $uid, string $userMsg, string $botMsg): void
{
    try {
        $stmt = $pdo->prepare(
            "INSERT INTO MensagemWA (IDMensagem, FKUsuario, Role, Conteudo) VALUES (:id, :uid, :role, :msg)"
        );
        $stmt->execute([':id' => gerarUuid(), ':uid' => $uid, ':role' => 'user',  ':msg' => mb_substr($userMsg, 0, 2000)]);
        $stmt->execute([':id' => gerarUuid(), ':uid' => $uid, ':role' => 'model', ':msg' => mb_substr($botMsg,  0, 2000)]);
    } catch (Throwable $e) {}
}

// ── Funções da API ────────────────────────────────────────────────────────────

function _waReply(string $numero, string $msg): void
{
    if (function_exists('enviarWhatsAppNotificacao')) {
        enviarWhatsAppNotificacao($numero, $msg);
    }
}

function _waGemini(string $sysPrompt, array $historico, string $textoAtual, ?string $base64 = null, string $mime = 'image/jpeg'): ?array
{
    if (!defined('GEMINI_API_KEY')) return null;

    $contents = [];
    foreach ($historico as $msg) {
        $contents[] = ['role' => $msg['Role'], 'parts' => [['text' => $msg['Conteudo']]]];
    }

    $partsAtual = [['text' => $textoAtual]];
    if ($base64) {
        $partsAtual[] = ['inline_data' => ['mime_type' => $mime, 'data' => $base64]];
    }
    $contents[] = ['role' => 'user', 'parts' => $partsAtual];

    $body = json_encode([
        'systemInstruction' => ['parts' => [['text' => $sysPrompt]]],
        'contents'          => $contents,
        'generationConfig'  => ['temperature' => 0.1, 'responseMimeType' => 'application/json'],
    ]);

    $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . GEMINI_MODEL . ':generateContent?key=' . GEMINI_API_KEY;

    $ctx = stream_context_create(['http' => [
        'method' => 'POST', 'header' => "Content-Type: application/json\r\n",
        'content' => $body, 'timeout' => 30, 'ignore_errors' => true,
    ]]);

    $resp = @file_get_contents($url, false, $ctx);
    if (!$resp) return null;

    $api = json_decode($resp, true);
    if (isset($api['error'])) {
        return ['_api_error' => true, '_code' => $api['error']['code'] ?? 0, '_msg' => $api['error']['message'] ?? ''];
    }

    $text = $api['candidates'][0]['content']['parts'][0]['text'] ?? null;
    if (!$text) return null;

    $text = preg_replace('/^```(?:json)?\s*|\s*```$/s', '', trim($text));
    return json_decode($text, true);
}

function _waFetchBase64(array $data): ?string
{
    $url  = 'https://evolution.meuauralis.com/chat/getBase64FromMediaMessage/Auralis';
    $body = json_encode(['message' => ['key' => $data['key'] ?? [], 'message' => $data['message'] ?? []]]);
    $ctx  = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/json\r\napikey: 44c816e1478a4754e859bd609e4099aaab417cf60bf07bf9\r\n",
        'content' => $body, 'timeout' => 15, 'ignore_errors' => true,
    ]]);
    $resp = @file_get_contents($url, false, $ctx);
    if (!$resp) return null;
    return json_decode($resp, true)['base64'] ?? null;
}
