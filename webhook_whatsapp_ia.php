<?php
// webhook_whatsapp_ia.php
// Recebe mensagens WhatsApp via Evolution API, interpreta com Gemini e cria Registro.
// Configure o webhook na Evolution API apontando para:
//   https://meuauralis.com/webhook_whatsapp_ia.php

require_once __DIR__ . '/config/conexao.php';
require_once __DIR__ . '/config/funcoes.php';
require_once __DIR__ . '/config/gemini.php';

// Responde 200 imediatamente — Evolution re-tenta em não-2xx
header('Content-Type: application/json');
http_response_code(200);
echo '{"ok":true}';

if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request(); // libera a conexão HTTP; continua processando
} else {
    flush();
    ob_flush();
}

// ── 1. Parse payload ──────────────────────────────────────────────────────────

$raw     = file_get_contents('php://input');
$payload = json_decode($raw, true);

if (!$payload || ($payload['event'] ?? '') !== 'messages.upsert') exit;

$data = $payload['data'] ?? [];

// Ignora mensagens enviadas pelo próprio bot e mensagens de grupo
if ($data['key']['fromMe'] ?? false) exit;
$remoteJid = $data['key']['remoteJid'] ?? '';
if (str_contains($remoteJid, '@g.us')) exit;

// ── 2. Identifica usuário pelo telefone ───────────────────────────────────────

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

if (!$usuario) exit; // Número desconhecido — ignora silenciosamente

$uid     = $usuario['IDUsuario'];
$msgType = $data['messageType'] ?? 'conversation';

// ── 3. Extrai conteúdo da mensagem ────────────────────────────────────────────

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
        $texto      = $data['message'][$msgType]['caption'] ?? '';
        $imagemMime = $data['message'][$msgType]['mimetype'] ?? 'image/jpeg';

        // Webhook com base64 embutido (webhookBase64: true na config da Evolution)
        $imagemBase64 = $data['base64']
            ?? $data['message'][$msgType]['base64']
            ?? null;

        // Fallback: busca via API
        if (!$imagemBase64) {
            $imagemBase64 = _waFetchBase64($data);
        }
        break;
}

if (!$texto && !$imagemBase64) exit;

// ── 4. Contexto do usuário (carteiras + categorias) ───────────────────────────

try {
    $stmtC = $pdo->prepare(
        "SELECT IDCarteira, NomeCarteira FROM Carteira
         WHERE FKUsuarioDono = :uid ORDER BY MomentoCriacao ASC LIMIT 10"
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
    $carteiras  = [];
    $categorias = [];
}

if (!$carteiras) {
    _waReply($telefone, "❌ Não encontrei carteiras na sua conta. Acesse meuauralis.com para configurar.");
    exit;
}

// ── 5. Monta prompt e chama Gemini ────────────────────────────────────────────

$hoje      = date('Y-m-d');
$cartsList = json_encode(
    array_map(fn($c) => ['id' => $c['IDCarteira'], 'nome' => $c['NomeCarteira']], $carteiras),
    JSON_UNESCAPED_UNICODE
);
$catDesp = json_encode(array_values(array_map(
    fn($c) => ['id' => $c['IDCategoria'], 'nome' => $c['NomeCategoria']],
    array_filter($categorias, fn($c) => $c['TipoCategoria'] === 'despesa')
)), JSON_UNESCAPED_UNICODE);
$catRec = json_encode(array_values(array_map(
    fn($c) => ['id' => $c['IDCategoria'], 'nome' => $c['NomeCategoria']],
    array_filter($categorias, fn($c) => $c['TipoCategoria'] === 'receita')
)), JSON_UNESCAPED_UNICODE);

$imgCtx = $imagemBase64
    ? "Uma imagem foi enviada (pode ser comprovante, nota fiscal ou print). Leia os dados financeiros e use o texto como complemento."
    : "";

$prompt = <<<EOT
Você é o assistente financeiro do Auralis. {$imgCtx}
Mensagem do usuário: "{$texto}"
Data de hoje: {$hoje}

Carteiras disponíveis: {$cartsList}
Categorias de despesa: {$catDesp}
Categorias de receita: {$catRec}

Regras:
- Use a PRIMEIRA carteira se nenhuma for mencionada pelo nome
- Escolha a categoria cujo nome seja mais parecido; null se não houver match claro
- Comprovante de PIX/TED/transferência enviado = despesa; recebido = receita
- Se for saudação, pergunta, agradecimento ou texto sem valor financeiro, retorne ok=false
- "data" deve ser YYYY-MM-DD; se disser "hoje" ou não mencionar data, use {$hoje}
- "valor" deve ser número decimal positivo (ex: 50.00), nunca string
- "descricao" deve ter no máximo 60 caracteres, sem prefixos como "Despesa:" ou "Receita:"

Responda APENAS com JSON válido (sem markdown, sem texto fora do JSON):
{
  "ok": true,
  "tipo": "despesa",
  "valor": 0.00,
  "descricao": "texto curto",
  "data": "YYYY-MM-DD",
  "id_carteira": "uuid",
  "id_categoria": "uuid ou null",
  "nome_carteira": "nome",
  "nome_categoria": "nome ou null"
}
Se não for transação financeira: {"ok": false, "motivo": "explicação curta em português"}
EOT;

$resultado = _waGemini($prompt, $imagemBase64, $imagemMime);

// ── 6. Trata resultado ────────────────────────────────────────────────────────

if (!$resultado || !isset($resultado['ok'])) {
    _waReply($telefone, "⚠️ Serviço de IA indisponível no momento. Tente novamente em instantes.");
    exit;
}

if (!$resultado['ok']) {
    $motivo = $resultado['motivo'] ?? 'não reconheci uma transação financeira';
    _waReply($telefone,
        "❓ Hmm, {$motivo}.\n\n" .
        "💬 *Exemplos que funcionam:*\n" .
        "• _\"Paguei 50 de uber\"_\n" .
        "• _\"Recebi 2000 de salário hoje\"_\n" .
        "• _\"Gastei 120 no mercado ontem\"_\n" .
        "• _[foto de comprovante PIX]_"
    );
    exit;
}

// ── 7. Cria o Registro ────────────────────────────────────────────────────────

$idCarteira  = $resultado['id_carteira'] ?? $carteiras[0]['IDCarteira'];
$idCategoria = !empty($resultado['id_categoria']) ? $resultado['id_categoria'] : null;
$dataReg     = $resultado['data'] ?? $hoje;
$valor       = abs((float)($resultado['valor'] ?? 0));
$descricao   = mb_substr(trim($resultado['descricao'] ?? ''), 0, 255);
$tipo        = in_array($resultado['tipo'] ?? '', ['receita', 'despesa']) ? $resultado['tipo'] : 'despesa';

if (!$valor || !$descricao) {
    _waReply($telefone, "❌ Não consegui identificar valor ou descrição. Tente com mais detalhes.");
    exit;
}

try {
    $pdo->prepare("
        INSERT INTO Registro
            (IDRegistro, Valor, Descricao, FKCarteira, FKUsuario, FKCategoria,
             TipoRegistro, DataVencimento, StatusRegistro, Recorrente)
        VALUES (:id, :val, :desc, :cart, :uid, :cat, :tipo, :data, 'efetivado', 0)
    ")->execute([
        ':id'   => gerarUuid(),
        ':val'  => $valor,
        ':desc' => $descricao,
        ':cart' => $idCarteira,
        ':uid'  => $uid,
        ':cat'  => $idCategoria,
        ':tipo' => $tipo,
        ':data' => $dataReg,
    ]);
} catch (Throwable $e) {
    _waReply($telefone, "❌ Erro ao salvar o registro. Tente novamente.");
    exit;
}

// ── 8. Confirmação ────────────────────────────────────────────────────────────

$tipoLabel = $tipo === 'receita' ? '📈 Receita' : '📉 Despesa';
$valorFmt  = 'R$ ' . number_format($valor, 2, ',', '.');
$dataFmt   = date('d/m/Y', strtotime($dataReg));
$catLinha  = !empty($resultado['nome_categoria']) ? "\n🏷️ " . $resultado['nome_categoria'] : '';
$cartNome  = $resultado['nome_carteira'] ?? $carteiras[0]['NomeCarteira'];

_waReply($telefone,
    "✅ *Registrado!*\n\n" .
    "{$tipoLabel}: *{$valorFmt}*\n" .
    "📝 {$descricao}\n" .
    "📅 {$dataFmt}" .
    $catLinha . "\n" .
    "👛 {$cartNome}\n\n" .
    "_Veja em meuauralis.com_ 👆"
);

exit;

// ── Funções auxiliares ────────────────────────────────────────────────────────

function _waReply(string $numero, string $msg): void
{
    if (function_exists('enviarWhatsAppNotificacao')) {
        enviarWhatsAppNotificacao($numero, $msg);
    }
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
        'generationConfig' => [
            'temperature'        => 0.1,
            'responseMimeType'   => 'application/json',
        ],
    ]);

    $url = 'https://generativelanguage.googleapis.com/v1beta/models/'
         . GEMINI_MODEL . ':generateContent?key=' . GEMINI_API_KEY;

    $ctx = stream_context_create(['http' => [
        'method'        => 'POST',
        'header'        => "Content-Type: application/json\r\n",
        'content'       => $body,
        'timeout'       => 30,
        'ignore_errors' => true,
    ]]);

    $resp = @file_get_contents($url, false, $ctx);
    if (!$resp) return null;

    $api  = json_decode($resp, true);
    $text = $api['candidates'][0]['content']['parts'][0]['text'] ?? null;
    if (!$text) return null;

    // Remove blocos markdown caso o modelo ignore a instrução
    $text = preg_replace('/^```(?:json)?\s*|\s*```$/s', '', trim($text));

    return json_decode($text, true);
}

function _waFetchBase64(array $data): ?string
{
    $url  = 'https://evolution.meuauralis.com/chat/getBase64FromMediaMessage/Auralis';
    $body = json_encode([
        'message' => [
            'key'     => $data['key']     ?? [],
            'message' => $data['message'] ?? [],
        ],
    ]);
    $ctx = stream_context_create(['http' => [
        'method'        => 'POST',
        'header'        => "Content-Type: application/json\r\napikey: 44c816e1478a4754e859bd609e4099aaab417cf60bf07bf9\r\n",
        'content'       => $body,
        'timeout'       => 15,
        'ignore_errors' => true,
    ]]);
    $resp = @file_get_contents($url, false, $ctx);
    if (!$resp) return null;
    $decoded = json_decode($resp, true);
    return $decoded['base64'] ?? null;
}
