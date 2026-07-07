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
} catch (Throwable $e) { $carteiras = []; $categorias = []; }

if (!$carteiras) {
    _waReply($telefone, "❌ Não encontrei carteiras na sua conta. Acesse meuauralis.com para configurar.");
    exit;
}

// ── 5. Personalidade e histórico ──────────────────────────────────────────────

_waGarantirTabela($pdo);

// Lê personalidade salva (padrão: parceiro)
try {
    $stmtPers = $pdo->prepare(
        "SELECT Valor FROM ConfiguracaoSistema WHERE Chave = 'wa_personalidade' AND FKUsuario = :uid LIMIT 1"
    );
    $stmtPers->execute([':uid' => $uid]);
    $personalidade = $stmtPers->fetchColumn() ?: 'parceiro';
} catch (Throwable $e) { $personalidade = 'parceiro'; }

// Últimas 10 mensagens (excluindo a atual)
$historico = _waLoadHistory($pdo, $uid, 10);

// Cleanup probabilístico (~5% das requests) — sem cron
if (mt_rand(1, 20) === 1) {
    try {
        $pdo->prepare("DELETE FROM MensagemWA WHERE FKUsuario = :uid AND CriadoEm < DATE_SUB(NOW(), INTERVAL 30 DAY)")
            ->execute([':uid' => $uid]);
    } catch (Throwable $e) {}
}

// ── 6. Monta system prompt ────────────────────────────────────────────────────

$hoje      = date('Y-m-d');
$nomeUser  = explode(' ', $usuario['Nome'])[0];
$cartsList = json_encode(array_map(fn($c) => ['id' => $c['IDCarteira'], 'nome' => $c['NomeCarteira']], $carteiras), JSON_UNESCAPED_UNICODE);
$catDesp   = json_encode(array_values(array_map(fn($c) => ['id' => $c['IDCategoria'], 'nome' => $c['NomeCategoria']], array_filter($categorias, fn($c) => $c['TipoCategoria'] === 'despesa'))), JSON_UNESCAPED_UNICODE);
$catRec    = json_encode(array_values(array_map(fn($c) => ['id' => $c['IDCategoria'], 'nome' => $c['NomeCategoria']], array_filter($categorias, fn($c) => $c['TipoCategoria'] === 'receita'))), JSON_UNESCAPED_UNICODE);

$tomVoz = $personalidade === 'profissional'
    ? "Seja conciso e profissional. Respostas diretas, sem expressões informais ou emojis desnecessários."
    : "Seja natural e descontraído como um amigo que entende de dinheiro. Use linguagem casual, seja empático. Expressões como 'opa!', 'certinho!', 'anotado!' ficam ótimas. Emojis com moderação.";

$imgCtx = $imagemBase64 ? "O usuário também enviou uma imagem — pode ser comprovante ou nota fiscal, leia os dados financeiros dela." : "";

$systemPrompt = <<<EOT
Você é o Auralis, assistente financeiro pessoal de {$nomeUser}. {$tomVoz} {$imgCtx}

Contexto atual:
- Hoje: {$hoje}
- Carteiras de {$nomeUser}: {$cartsList}
- Categorias de despesa: {$catDesp}
- Categorias de receita: {$catRec}

Você precisa identificar a intenção e responder SEMPRE com JSON válido (sem markdown):

ACTION "registrar" — quando há 1 ou mais transações financeiras:
{"action":"registrar","registros":[{"tipo":"despesa","valor":0.00,"descricao":"max 60 chars","data":"YYYY-MM-DD","id_carteira":"uuid","id_categoria":"uuid|null","nome_carteira":"nome","nome_categoria":"nome|null","parcelas":1}]}
Regras: use primeira carteira se não mencionada; data relativa → YYYY-MM-DD exato; "parcelas">1 para parcelamentos; comprovante enviado=despesa, recebido=receita; múltiplas transações=múltiplos objetos em registros.

ACTION "consultar" — quando pergunta sobre gastos, saldo, pendências, histórico:
{"action":"consultar","consulta":{"tipo":"gastos|pendentes|saldo|ultimo","periodo":"hoje|semana|mes|ano","tipo_registro":"despesa|receita|null"}}

ACTION "cancelar" — quando pede pra desfazer/cancelar o último lançamento:
{"action":"cancelar"}

ACTION "ajuda" — saudação, agradecimento, ou quando não entender:
{"action":"ajuda"}

O histórico da conversa mostra trocas anteriores em linguagem natural — use para entender contexto, mas sua resposta deve sempre ser JSON.
EOT;

// ── 7. Chama Gemini com histórico ─────────────────────────────────────────────

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

// ── 8. Executa action e captura resposta ──────────────────────────────────────

$action = $resultado['action'] ?? 'ajuda';

$resposta = match($action) {
    'registrar' => _waRegistrar($pdo, $uid, $resultado['registros'] ?? [], $carteiras, $hoje),
    'consultar' => _waConsultar($pdo, $uid, $resultado['consulta'] ?? [], $hoje),
    'cancelar'  => _waCancelar($pdo, $uid),
    default     => _waAjuda($personalidade),
};

_waReply($telefone, $resposta);
_waSaveHistory($pdo, $uid, $texto, $resposta);

exit;

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

        $icon     = $tipo === 'receita' ? '📈' : '📉';
        $valFmt   = 'R$ ' . number_format($valor, 2, ',', '.');
        $dataFmt  = date('d/m/Y', strtotime($dataBase));
        $catNome  = !empty($r['nome_categoria']) ? " · " . $r['nome_categoria'] : '';
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

                if (!$rows) return "✅ Nenhuma conta pendente para {$label}!";

                $total  = array_sum(array_column($rows, 'Valor'));
                $linhas = array_map(fn($r) =>
                    "• " . date('d/m', strtotime($r['DataVencimento'])) .
                    " *{$r['Descricao']}*: R$ " . number_format($r['Valor'], 2, ',', '.') .
                    ($r['NomeCategoria'] ? " ({$r['NomeCategoria']})" : ''),
                    $rows
                );
                return "📋 *Pendentes — {$label}*\n\n" . implode("\n", $linhas) .
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
                $icon  = $saldo >= 0 ? '📈' : '📉';

                return "💰 *Saldo — {$label}*\n\n" .
                       "📈 Receitas: R$ " . number_format($rec,  2, ',', '.') . "\n" .
                       "📉 Despesas: R$ " . number_format($desp, 2, ',', '.') . "\n" .
                       "──────────────────\n" .
                       "{$icon} *Saldo: R$ " . number_format(abs($saldo), 2, ',', '.') . ($saldo < 0 ? " (negativo)*" : "*");

            case 'ultimo':
                $stmt = $pdo->prepare("
                    SELECT r.Descricao, r.Valor, r.TipoRegistro, r.DataVencimento, r.StatusRegistro, c.NomeCategoria
                    FROM Registro r LEFT JOIN Categoria c ON c.IDCategoria = r.FKCategoria
                    WHERE r.FKUsuario = :uid AND r.StatusRegistro != 'cancelado'
                    ORDER BY r.MomentoRegistro DESC LIMIT 1
                ");
                $stmt->execute([':uid' => $uid]);
                $r = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$r) return "Nenhum registro encontrado ainda.";

                $icon    = $r['TipoRegistro'] === 'receita' ? '📈' : '📉';
                $dataFmt = $r['DataVencimento'] ? date('d/m/Y', strtotime($r['DataVencimento'])) : '—';
                return "🔍 *Último registro*\n\n{$icon} *{$r['Descricao']}*\n" .
                       "💵 R$ " . number_format($r['Valor'], 2, ',', '.') . "\n" .
                       "📅 {$dataFmt}\n" .
                       ($r['NomeCategoria'] ? "🏷️ {$r['NomeCategoria']}\n" : '') .
                       "Status: {$r['StatusRegistro']}";

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

                if (!$rows) return "Nenhum(a) " . ($tipoQuery === 'receita' ? 'receita' : 'gasto') . " encontrado para {$label}.";

                $total  = array_sum(array_column($rows, 'Total'));
                $header = $tipoQuery === 'receita' ? '📈 Receitas' : '📉 Gastos';
                $linhas = array_map(fn($r) =>
                    "• " . ($r['NomeCategoria'] ?? 'Sem categoria') . ": R$ " . number_format($r['Total'], 2, ',', '.'),
                    $rows
                );
                return "{$header} — *{$label}*\n\n" . implode("\n", $linhas) .
                       "\n\n💰 Total: *R$ " . number_format($total, 2, ',', '.') . "*";
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
               "• Registrar despesa: \"Paguei 50 de uber\"\n" .
               "• Registrar receita: \"Recebi 2000 de salário\"\n" .
               "• Parcelamento: \"Comprei em 12x de 150\"\n" .
               "• Múltiplos: \"Paguei 50 de uber e 30 de almoço\"\n" .
               "• Consultar gastos: \"Quanto gastei esse mês?\"\n" .
               "• Contas pendentes: \"Tenho conta pra pagar?\"\n" .
               "• Saldo: \"Qual meu saldo?\"\n" .
               "• Cancelar: \"Cancela o último lançamento\"\n" .
               "• Comprovantes: envie a foto diretamente";
    }

    return "Opa! 👋 Pode mandar à vontade, funciona assim:\n\n" .
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
           "• _\"Cancela o último lançamento\"_";
}

// ── Histórico de conversa ─────────────────────────────────────────────────────

function _waGarantirTabela(PDO $pdo): void
{
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS MensagemWA (
                IDMensagem  VARCHAR(36)         NOT NULL,
                FKUsuario   VARCHAR(36)         NOT NULL,
                Role        ENUM('user','model') NOT NULL DEFAULT 'user',
                Conteudo    TEXT                NOT NULL,
                CriadoEm   TIMESTAMP           NOT NULL DEFAULT CURRENT_TIMESTAMP,
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
        // Inverte para ordem cronológica
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

    // Monta contents com histórico
    $contents = [];
    foreach ($historico as $msg) {
        $contents[] = [
            'role'  => $msg['Role'],
            'parts' => [['text' => $msg['Conteudo']]],
        ];
    }

    // Mensagem atual (com imagem se houver)
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
