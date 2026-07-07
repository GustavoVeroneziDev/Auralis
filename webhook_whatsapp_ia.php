<?php
// webhook_whatsapp_ia.php
// Recebe mensagens WhatsApp via Evolution API, interpreta com Gemini e executa ações.
// Webhook configurado na Evolution API: https://meuauralis.com/webhook_whatsapp_ia.php

require_once __DIR__ . '/config/conexao.php';
require_once __DIR__ . '/config/funcoes.php';
require_once __DIR__ . '/config/gemini.php';

header('Content-Type: application/json');
http_response_code(200);
echo '{"ok":true}';

if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
} else {
    flush();
    ob_flush();
}

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

// ── 3. Extrai conteúdo ────────────────────────────────────────────────────────

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

// ── 4. Contexto do usuário ────────────────────────────────────────────────────

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
} catch (Throwable $e) {
    $carteiras = []; $categorias = [];
}

if (!$carteiras) {
    _waReply($telefone, "❌ Não encontrei carteiras na sua conta. Acesse meuauralis.com para configurar.");
    exit;
}

// ── 5. Monta prompt ───────────────────────────────────────────────────────────

$hoje      = date('Y-m-d');
$cartsList = json_encode(array_map(fn($c) => ['id' => $c['IDCarteira'], 'nome' => $c['NomeCarteira']], $carteiras), JSON_UNESCAPED_UNICODE);
$catDesp   = json_encode(array_values(array_map(fn($c) => ['id' => $c['IDCategoria'], 'nome' => $c['NomeCategoria']], array_filter($categorias, fn($c) => $c['TipoCategoria'] === 'despesa'))), JSON_UNESCAPED_UNICODE);
$catRec    = json_encode(array_values(array_map(fn($c) => ['id' => $c['IDCategoria'], 'nome' => $c['NomeCategoria']], array_filter($categorias, fn($c) => $c['TipoCategoria'] === 'receita'))), JSON_UNESCAPED_UNICODE);
$imgCtx    = $imagemBase64 ? "Uma imagem foi enviada (comprovante/nota fiscal). Leia os dados financeiros dela." : "";

$prompt = <<<EOT
Você é o assistente financeiro do Auralis. {$imgCtx}
Mensagem: "{$texto}"
Hoje: {$hoje}

Carteiras: {$cartsList}
Categorias despesa: {$catDesp}
Categorias receita: {$catRec}

Determine a action correta:
- "registrar": 1 ou mais transações financeiras na mensagem
- "consultar": pergunta sobre gastos, saldo, contas pendentes, histórico
- "cancelar": pede pra cancelar/desfazer o último lançamento
- "ajuda": saudação, agradecimento ou mensagem sem sentido financeiro

=== JSON para "registrar" ===
{
  "action": "registrar",
  "registros": [
    {
      "tipo": "despesa",
      "valor": 0.00,
      "descricao": "max 60 chars sem prefixo",
      "data": "YYYY-MM-DD",
      "id_carteira": "uuid (primeira se não mencionada)",
      "id_categoria": "uuid ou null",
      "nome_carteira": "nome",
      "nome_categoria": "nome ou null",
      "parcelas": 1
    }
  ]
}
Regras de registrar:
- Múltiplas transações na mesma mensagem = múltiplos objetos em "registros"
- "parcelas" > 1 quando mencionar parcelamento (ex: "12x de 150")
- valor = decimal positivo; data relativa ("ontem", "semana que vem") = YYYY-MM-DD exato
- comprovante enviado = despesa; comprovante recebido = receita

=== JSON para "consultar" ===
{
  "action": "consultar",
  "consulta": {
    "tipo": "gastos",
    "periodo": "mes",
    "tipo_registro": "despesa"
  }
}
Valores de "tipo": "gastos" | "pendentes" | "saldo" | "ultimo"
Valores de "periodo": "hoje" | "semana" | "mes" | "ano"
tipo_registro: "despesa" | "receita" | null (null = ambos)
Exemplos: "quanto gastei?" → gastos/mes/despesa | "tenho conta pra pagar?" → pendentes/semana/despesa | "qual meu saldo?" → saldo/mes/null

=== JSON para "cancelar" ===
{"action": "cancelar"}

=== JSON para "ajuda" ===
{"action": "ajuda"}

Responda APENAS JSON válido, sem markdown.
EOT;

// ── 6. Chama Gemini ───────────────────────────────────────────────────────────

$resultado = _waGemini($prompt, $imagemBase64, $imagemMime);

if (!$resultado) {
    _waReply($telefone, "⚠️ IA sem resposta. Tente novamente em instantes.");
    exit;
}

if (!empty($resultado['_api_error'])) {
    $code = $resultado['_code'];
    if ($code === 429) {
        _waReply($telefone, "⏳ Limite de requisições atingido. Aguarde 1 minuto e tente novamente.");
    } else {
        _waReply($telefone, "❌ Erro da IA (código {$code}). Contate o suporte.");
    }
    exit;
}

// ── 7. Executa action ─────────────────────────────────────────────────────────

$action = $resultado['action'] ?? 'ajuda';

switch ($action) {
    case 'registrar':
        _waRegistrar($pdo, $uid, $telefone, $resultado['registros'] ?? [], $carteiras, $hoje);
        break;
    case 'consultar':
        _waConsultar($pdo, $uid, $telefone, $resultado['consulta'] ?? [], $hoje);
        break;
    case 'cancelar':
        _waCancelar($pdo, $uid, $telefone);
        break;
    default:
        _waReply($telefone,
            "👋 Olá! Aqui estão algumas coisas que você pode fazer:\n\n" .
            "📝 *Registrar:*\n" .
            "• _\"Paguei 55 no corte de cabelo\"_\n" .
            "• _\"Recebi 2000 de salário hoje\"_\n" .
            "• _\"Uber 23,50 ontem\"_\n" .
            "• _\"Comprei celular em 12x de 150\"_\n" .
            "• _\"Paguei 50 de uber e 30 de almoço\"_\n" .
            "• _[foto de comprovante PIX]_\n\n" .
            "📊 *Consultar:*\n" .
            "• _\"Quanto gastei esse mês?\"_\n" .
            "• _\"Tenho conta pra pagar essa semana?\"_\n" .
            "• _\"Qual meu saldo?\"_\n\n" .
            "↩️ *Desfazer:*\n" .
            "• _\"Cancela o último lançamento\"_"
        );
}

exit;

// ── Handlers de action ────────────────────────────────────────────────────────

function _waRegistrar(PDO $pdo, string $uid, string $tel, array $registros, array $carteiras, string $hoje): void
{
    if (!$registros) {
        _waReply($tel, "❌ Não identifiquei nenhuma transação. Tente com mais detalhes.");
        return;
    }

    $confirmacoes = [];
    $erros        = 0;

    $stmtIns = $pdo->prepare("
        INSERT INTO Registro
            (IDRegistro, Valor, Descricao, FKCarteira, FKUsuario, FKCategoria,
             TipoRegistro, DataVencimento, StatusRegistro, Recorrente,
             GrupoParcela, ParcelaAtual, TotalParcelas)
        VALUES (:id, :val, :desc, :cart, :uid, :cat, :tipo, :data, :status, 0,
                :grupo, :parcela_atual, :total_parcelas)
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

            $status = $dataParcela > $hoje ? 'pendente' : 'efetivado';

            $descParcela = $parcelas > 1
                ? mb_substr($descricao, 0, 50) . " {$i}/{$parcelas}"
                : $descricao;

            try {
                $stmtIns->execute([
                    ':id'            => gerarUuid(),
                    ':val'           => $valor,
                    ':desc'          => $descParcela,
                    ':cart'          => $cart,
                    ':uid'           => $uid,
                    ':cat'           => $cat,
                    ':tipo'          => $tipo,
                    ':data'          => $dataParcela,
                    ':status'        => $status,
                    ':grupo'         => $grupoParcela,
                    ':parcela_atual' => $parcelas > 1 ? $i : null,
                    ':total_parcelas'=> $parcelas > 1 ? $parcelas : null,
                ]);
            } catch (Throwable $e) { $erros++; continue 2; }
        }

        $tipoIcon  = $tipo === 'receita' ? '📈' : '📉';
        $valorFmt  = 'R$ ' . number_format($valor, 2, ',', '.');
        $dataFmt   = date('d/m/Y', strtotime($dataBase));
        $catNome   = !empty($r['nome_categoria']) ? " · " . $r['nome_categoria'] : '';
        $cartNome  = $r['nome_carteira'] ?? $carteiras[0]['NomeCarteira'];
        $status    = $dataBase > $hoje ? ' _(pendente)_' : '';
        $parcelInfo = $parcelas > 1 ? " · {$parcelas}x" : '';

        if ($parcelas > 1) {
            $valorTotal = 'R$ ' . number_format($valor * $parcelas, 2, ',', '.');
            $confirmacoes[] = "{$tipoIcon} *{$descricao}*\n   {$valorFmt}/mês × {$parcelas} = {$valorTotal}{$catNome} · {$cartNome}\n   📅 {$dataFmt} até " . date('d/m/Y', strtotime($dataBase . ' +' . ($parcelas - 1) . ' month'));
        } else {
            $confirmacoes[] = "{$tipoIcon} *{$descricao}*: {$valorFmt}{$parcelInfo}{$catNome} · {$cartNome} · 📅 {$dataFmt}{$status}";
        }
    }

    if (!$confirmacoes) {
        _waReply($tel, "❌ Não consegui salvar os registros. Tente novamente.");
        return;
    }

    $header = count($confirmacoes) === 1 ? "✅ *Registrado!*" : "✅ *" . count($confirmacoes) . " registros salvos!*";
    $erroTxt = $erros ? "\n\n⚠️ {$erros} item(ns) não salvos." : '';
    _waReply($tel, $header . "\n\n" . implode("\n", $confirmacoes) . $erroTxt . "\n\n_meuauralis.com_ 👆");
}

function _waConsultar(PDO $pdo, string $uid, string $tel, array $consulta, string $hoje): void
{
    $tipo    = $consulta['tipo']         ?? 'gastos';
    $periodo = $consulta['periodo']      ?? 'mes';
    $tipoReg = $consulta['tipo_registro'] ?? null;

    // Calcula intervalo de datas
    switch ($periodo) {
        case 'hoje':
            $inicio = $hoje; $fim = $hoje;
            $periodoLabel = 'hoje';
            break;
        case 'semana':
            $inicio = date('Y-m-d', strtotime('monday this week'));
            $fim    = date('Y-m-d', strtotime('sunday this week'));
            $periodoLabel = 'esta semana';
            break;
        case 'ano':
            $inicio = date('Y-01-01'); $fim = date('Y-12-31');
            $periodoLabel = 'este ano (' . date('Y') . ')';
            break;
        default: // mes
            $inicio = date('Y-m-01'); $fim = date('Y-m-t');
            $periodoLabel = 'este mês (' . _nomeMes(date('n')) . ')';
    }

    try {
        switch ($tipo) {
            case 'pendentes':
                $stmt = $pdo->prepare("
                    SELECT r.Descricao, r.Valor, r.TipoRegistro, r.DataVencimento,
                           c.NomeCategoria
                    FROM Registro r
                    LEFT JOIN Categoria c ON c.IDCategoria = r.FKCategoria
                    WHERE r.FKUsuario = :uid
                      AND r.StatusRegistro = 'pendente'
                      AND r.DataVencimento BETWEEN :ini AND :fim
                      " . ($tipoReg ? "AND r.TipoRegistro = :tipo" : "") . "
                    ORDER BY r.DataVencimento ASC
                    LIMIT 20
                ");
                $params = [':uid' => $uid, ':ini' => $inicio, ':fim' => $fim];
                if ($tipoReg) $params[':tipo'] = $tipoReg;
                $stmt->execute($params);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

                if (!$rows) {
                    _waReply($tel, "✅ Nenhuma conta pendente para {$periodoLabel}!");
                    return;
                }

                $total = array_sum(array_column($rows, 'Valor'));
                $linhas = array_map(fn($r) =>
                    "• " . date('d/m', strtotime($r['DataVencimento'])) .
                    " *{$r['Descricao']}*: R$ " . number_format($r['Valor'], 2, ',', '.') .
                    ($r['NomeCategoria'] ? " ({$r['NomeCategoria']})" : ''),
                    $rows
                );
                $msg = "📋 *Pendentes — {$periodoLabel}*\n\n" . implode("\n", $linhas) .
                       "\n\n💰 Total: R$ " . number_format($total, 2, ',', '.');
                _waReply($tel, $msg);
                break;

            case 'saldo':
                $stmt = $pdo->prepare("
                    SELECT TipoRegistro, SUM(Valor) AS Total
                    FROM Registro
                    WHERE FKUsuario = :uid
                      AND StatusRegistro = 'efetivado'
                      AND COALESCE(DataVencimento, DATE(MomentoRegistro)) BETWEEN :ini AND :fim
                    GROUP BY TipoRegistro
                ");
                $stmt->execute([':uid' => $uid, ':ini' => $inicio, ':fim' => $fim]);
                $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

                $rec  = (float)($rows['receita']  ?? 0);
                $desp = (float)($rows['despesa']  ?? 0);
                $saldo = $rec - $desp;
                $sinalIcon = $saldo >= 0 ? '📈' : '📉';

                _waReply($tel,
                    "💰 *Saldo — {$periodoLabel}*\n\n" .
                    "📈 Receitas: R$ " . number_format($rec,  2, ',', '.') . "\n" .
                    "📉 Despesas: R$ " . number_format($desp, 2, ',', '.') . "\n" .
                    "─────────────────\n" .
                    "{$sinalIcon} *Saldo: R$ " . number_format(abs($saldo), 2, ',', '.') . ($saldo < 0 ? ' (negativo)*' : '*')
                );
                break;

            case 'ultimo':
                $stmt = $pdo->prepare("
                    SELECT r.Descricao, r.Valor, r.TipoRegistro,
                           r.DataVencimento, r.StatusRegistro, r.IDRegistro,
                           c.NomeCategoria
                    FROM Registro r
                    LEFT JOIN Categoria c ON c.IDCategoria = r.FKCategoria
                    WHERE r.FKUsuario = :uid AND r.StatusRegistro != 'cancelado'
                    ORDER BY r.MomentoRegistro DESC LIMIT 1
                ");
                $stmt->execute([':uid' => $uid]);
                $r = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$r) { _waReply($tel, "Nenhum registro encontrado."); return; }

                $tipoIcon = $r['TipoRegistro'] === 'receita' ? '📈' : '📉';
                $dataFmt  = $r['DataVencimento'] ? date('d/m/Y', strtotime($r['DataVencimento'])) : '—';
                _waReply($tel,
                    "🔍 *Último registro:*\n\n" .
                    "{$tipoIcon} *{$r['Descricao']}*\n" .
                    "💵 R$ " . number_format($r['Valor'], 2, ',', '.') . "\n" .
                    "📅 {$dataFmt}\n" .
                    ($r['NomeCategoria'] ? "🏷️ {$r['NomeCategoria']}\n" : '') .
                    "Status: {$r['StatusRegistro']}"
                );
                break;

            default: // gastos
                $stmt = $pdo->prepare("
                    SELECT c.NomeCategoria, SUM(r.Valor) AS Total
                    FROM Registro r
                    LEFT JOIN Categoria c ON c.IDCategoria = r.FKCategoria
                    WHERE r.FKUsuario = :uid
                      AND r.TipoRegistro = :tipo
                      AND r.StatusRegistro = 'efetivado'
                      AND COALESCE(r.DataVencimento, DATE(r.MomentoRegistro)) BETWEEN :ini AND :fim
                    GROUP BY r.FKCategoria
                    ORDER BY Total DESC
                    LIMIT 10
                ");
                $tipoQuery = $tipoReg ?? 'despesa';
                $stmt->execute([':uid' => $uid, ':tipo' => $tipoQuery, ':ini' => $inicio, ':fim' => $fim]);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

                if (!$rows) {
                    $tipoLabel = $tipoQuery === 'receita' ? 'receitas' : 'gastos';
                    _waReply($tel, "Nenhum(a) {$tipoLabel} encontrado(s) para {$periodoLabel}.");
                    return;
                }

                $total  = array_sum(array_column($rows, 'Total'));
                $tipoHeader = $tipoQuery === 'receita' ? '📈 Receitas' : '📉 Gastos';
                $linhas = array_map(fn($r) =>
                    "• " . ($r['NomeCategoria'] ?? 'Sem categoria') .
                    ": R$ " . number_format($r['Total'], 2, ',', '.'),
                    $rows
                );
                _waReply($tel,
                    "{$tipoHeader} — *{$periodoLabel}*\n\n" .
                    implode("\n", $linhas) .
                    "\n\n💰 Total: *R$ " . number_format($total, 2, ',', '.') . "*"
                );
        }
    } catch (Throwable $e) {
        _waReply($tel, "❌ Erro ao consultar. Tente novamente.");
    }
}

function _waCancelar(PDO $pdo, string $uid, string $tel): void
{
    try {
        $stmt = $pdo->prepare("
            SELECT IDRegistro, Descricao, Valor, TipoRegistro
            FROM Registro
            WHERE FKUsuario = :uid AND StatusRegistro != 'cancelado'
            ORDER BY MomentoRegistro DESC LIMIT 1
        ");
        $stmt->execute([':uid' => $uid]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$r) { _waReply($tel, "Nenhum registro para cancelar."); return; }

        $pdo->prepare("UPDATE Registro SET StatusRegistro = 'cancelado' WHERE IDRegistro = :id")
            ->execute([':id' => $r['IDRegistro']]);

        $tipoIcon = $r['TipoRegistro'] === 'receita' ? '📈' : '📉';
        _waReply($tel,
            "↩️ *Cancelado!*\n\n" .
            "{$tipoIcon} {$r['Descricao']}: R$ " . number_format($r['Valor'], 2, ',', '.') . "\n\n" .
            "_O registro foi marcado como cancelado no app._"
        );
    } catch (Throwable $e) {
        _waReply($tel, "❌ Erro ao cancelar. Tente novamente.");
    }
}

// ── Funções auxiliares ────────────────────────────────────────────────────────

function _waReply(string $numero, string $msg): void
{
    if (function_exists('enviarWhatsAppNotificacao')) {
        enviarWhatsAppNotificacao($numero, $msg);
    }
}

function _nomeMes(int $n): string
{
    return ['', 'janeiro','fevereiro','março','abril','maio','junho',
             'julho','agosto','setembro','outubro','novembro','dezembro'][$n] ?? '';
}

function _waGemini(string $prompt, ?string $base64 = null, string $mime = 'image/jpeg'): ?array
{
    if (!defined('GEMINI_API_KEY')) return null;

    $parts = [['text' => $prompt]];
    if ($base64) {
        $parts[] = ['inline_data' => ['mime_type' => $mime, 'data' => $base64]];
    }

    $body = json_encode([
        'contents'         => [['parts' => $parts]],
        'generationConfig' => ['temperature' => 0.1, 'responseMimeType' => 'application/json'],
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
