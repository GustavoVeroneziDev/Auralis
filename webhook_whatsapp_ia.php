<?php
// webhook_whatsapp_ia.php
// Recebe mensagens WhatsApp via Evolution API, interpreta com Gemini e executa ações.
// Webhook: https://meuauralis.com/webhook_whatsapp_ia.php?token=... (valor real em
// admin/webhook_ia.php — configure essa URL completa no painel da Evolution API; sem o
// token certo, TODA chamada real é rejeitada com 403 também).

require_once __DIR__ . '/config/conexao.php';
require_once __DIR__ . '/config/funcoes.php';
require_once __DIR__ . '/config/gemini.php';

// ── 0. Verificação de segurança ────────────────────────────────────────────────
// Sem isso, essa URL é pública — qualquer um na internet que a descobrisse podia mandar
// POST se passando pela Evolution API. Em volume, isso sozinho já derruba a capacidade
// do servidor (cada tentativa gasta um processo PHP + consulta ao banco antes de sair);
// e se alguém soubesse o telefone de um cliente real, dava pra forjar payloads gerando
// chamadas de IA (custo) e mensagens de WhatsApp reais indevidas pra essa pessoa. Confere
// o token ANTES de fazer qualquer outra coisa — nem decodifica o payload se não bater.
// hash_equals() em vez de "===" pra não vazar timing de quantos caracteres bateram.
if (!hash_equals(waWebhookToken($pdo), (string)($_GET['token'] ?? ''))) {
    http_response_code(403);
    exit;
}

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

$data      = $payload['data'] ?? [];
$remoteJid = $data['key']['remoteJid'] ?? '';
if (str_contains($remoteJid, '@g.us')) exit;

// Mensagem enviada pelo próprio número da empresa — dispara tanto quando é a IA
// respondendo via API quanto quando um admin digita direto no WhatsApp Business. Delega
// pra função que sabe diferenciar os dois (ver comentário nela) e pausar a IA se for
// intervenção manual de verdade.
if ($data['key']['fromMe'] ?? false) {
    $textoFromMe = $data['message']['conversation'] ?? $data['message']['extendedTextMessage']['text'] ?? '';
    _waTratarMensagemFromMe($pdo, $remoteJid, $textoFromMe);
    exit;
}

// ── 2. Identifica usuário ─────────────────────────────────────────────────────

$telefone = preg_replace('/\D/', '', explode('@', $remoteJid)[0]);
if (strlen($telefone) < 10) exit;

try {
    $stmtU = $pdo->prepare(
        "SELECT IDUsuario, Nome, CodigoIndicacao FROM Usuario
         WHERE Telefone = :tel AND StatusConta = 'ativo' LIMIT 1"
    );
    $stmtU->execute([':tel' => $telefone]);
    $usuario = $stmtU->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) { exit; }

if (!$usuario) exit;

$uid     = $usuario['IDUsuario'];
$msgType = $data['messageType'] ?? 'conversation';

_waGarantirTabela($pdo);

// ── 2.05 IA pausada por intervenção manual do admin ───────────────────────────────
// Se um admin assumiu essa conversa recentemente (ver _waTratarMensagemFromMe abaixo),
// a IA fica quieta até o prazo expirar — evita ela responder "por cima" de quem já tá
// sendo atendido de verdade por uma pessoa.
try {
    $stmtPause = $pdo->prepare("SELECT Valor FROM ConfiguracaoSistema WHERE Chave = 'wa_pausado_ate' AND FKUsuario = :uid");
    $stmtPause->execute([':uid' => $uid]);
    $pausadoAte = $stmtPause->fetchColumn();
    if ($pausadoAte && strtotime($pausadoAte) > time()) {
        // Ainda registra o que a pessoa mandou (contexto pro humano ver e pra própria IA
        // quando retomar depois), só não responde nada.
        $textoPausa = $data['message']['conversation'] ?? $data['message']['extendedTextMessage']['text'] ?? '[mídia]';
        try {
            $pdo->prepare("INSERT INTO MensagemWA (IDMensagem, FKUsuario, Role, Conteudo) VALUES (:id, :uid, 'user', :msg)")
                ->execute([':id' => gerarUuid(), ':uid' => $uid, ':msg' => mb_substr($textoPausa, 0, 2000)]);
        } catch (Throwable $e) {}
        exit;
    }
} catch (Throwable $e) {}

// ── 2.1 Extrai conteúdo ────────────────────────────────────────────────────────
// Precisa vir antes da restrição de plano: o comando de "parar de mandar lembrete"
// e o registro de "a pessoa respondeu algo" têm que funcionar pra QUALQUER usuário,
// não só quem tem acesso à IA — senão não tem como saber se alguém sem IA reagiu
// aos nudges de engajamento (cron/whatsapp_engajamento.php).

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

// ── 2.2 "Pare"/"quero voltar a receber" — opt-out e opt-in dos lembretes ──────
// Checado ANTES da restrição de plano e independente do Gemini: precisa funcionar
// mesmo pra quem não tem acesso à IA (é justamente quem mais recebe os nudges de
// engajamento). Só afeta os lembretes automáticos (cron/whatsapp_engajamento.php),
// não avisos essenciais como vencimento de fatura ou confirmação de pagamento.
if (_waEhPedidoDeParar($texto)) {
    _waMarcarOptOutEngajamento($pdo, $uid, true);
    $nomeUserOptOut = explode(' ', $usuario['Nome'])[0];
    $respostaOptOut = "Combinado, {$nomeUserOptOut}! Não vou mais te mandar esses lembretes automáticos de \"faz tempo que você não registra nada\". 👍\n\nSe quiser voltar a receber, é só me mandar \"voltar a receber lembretes\" que eu reativo.";
    _waReply($telefone, $respostaOptOut);
    _waSaveHistory($pdo, $uid, $texto, $respostaOptOut);
    exit;
}

if (_waEhPedidoDeVoltar($texto)) {
    _waMarcarOptOutEngajamento($pdo, $uid, false);
    $nomeUserOptIn = explode(' ', $usuario['Nome'])[0];
    $respostaOptIn = "Reativado, {$nomeUserOptIn}! 👍 Volto a te lembrar se ficar um tempo sem registrar nada.";
    _waReply($telefone, $respostaOptIn);
    _waSaveHistory($pdo, $uid, $texto, $respostaOptIn);
    exit;
}

// ── 2.3 Restrição de plano — só VIP e quem está no período de teste grátis ────

$planoEfetivo = planoEfetivoUsuario($pdo, $uid);
if (!in_array($planoEfetivo, ['vip', 'vip_trial'], true)) {
    // Ainda registra a mensagem recebida (sem isso, o cron de engajamento nunca
    // saberia que essa pessoa respondeu alguma coisa, mesmo sem ter IA liberada).
    try {
        $pdo->prepare("INSERT INTO MensagemWA (IDMensagem, FKUsuario, Role, Conteudo) VALUES (:id, :uid, 'user', :msg)")
            ->execute([':id' => gerarUuid(), ':uid' => $uid, ':msg' => mb_substr($texto ?: '[imagem]', 0, 2000)]);
    } catch (Throwable $e) {}

    $nomeUserGate = explode(' ', $usuario['Nome'])[0];
    _waReply($telefone, "Oi, {$nomeUserGate}! 👋\n\nEsse assistente por WhatsApp é um recurso exclusivo do plano *VIP* (e também fica liberado durante o seu período de teste grátis).\n\nPra continuar registrando suas contas por aqui, dá uma olhada no plano VIP:\nmeuauralis.com/planos.php\n\nO app continua 100% disponível pelo site normalmente! 🙂");
    exit;
}

// ── 3. Rate limiting — proteção contra bot/spam ───────────────────────────────
// Máximo 15 mensagens em 2 minutos (humano normal: 1-3/min). Drop silencioso.

garantirColunasRecorrenciaGeneralizada($pdo);

try {
    $stmtRate = $pdo->prepare(
        "SELECT COUNT(*) FROM MensagemWA
         WHERE FKUsuario = :uid AND Role = 'user' AND CriadoEm > DATE_SUB(NOW(), INTERVAL 2 MINUTE)"
    );
    $stmtRate->execute([':uid' => $uid]);
    if ((int)$stmtRate->fetchColumn() >= 15) exit;
} catch (Throwable $e) {}

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

$imgCtx = $imagemBase64 ? " O usuário enviou uma imagem — leia os dados financeiros dela (comprovante, nota fiscal, etc.). IMPORTANTE: antes de \"registrar\" um gasto novo a partir de um comprovante, confira a descrição (legenda da mensagem, ou o nome/beneficiário escrito no próprio comprovante) contra a lista de \"Pendentes\" no contexto abaixo — comprovante de pagamento de algo que JÁ existe como pendente é \"efetivar\" aquele registro (use o nome pra achar), NUNCA um \"registrar\" novo. Só use \"registrar\" quando o comprovante for de algo que realmente não está na lista de pendentes." : "";

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

// Resumo do mês por carteira — sem isso a IA só enxergava o agregado de todas as
// carteiras juntas e não tinha como separar quando o cliente perguntava de uma específica.
$carteirasResumoCtx = '';
if (count($carteiras) > 1) {
    try {
        $stmtSnapCart = $pdo->prepare("
            SELECT FKCarteira,
                   SUM(CASE WHEN TipoRegistro='receita' AND StatusRegistro='efetivado' THEN Valor ELSE 0 END) AS RecEfet,
                   SUM(CASE WHEN TipoRegistro='despesa' AND StatusRegistro='efetivado' THEN Valor ELSE 0 END) AS DespEfet
            FROM Registro WHERE FKUsuario = :uid
              AND COALESCE(DataVencimento, DATE(MomentoRegistro)) BETWEEN :ini AND :fim
            GROUP BY FKCarteira
        ");
        $stmtSnapCart->execute([':uid' => $uid, ':ini' => date('Y-m-01'), ':fim' => date('Y-m-t')]);
        $snapPorCarteira = [];
        foreach ($stmtSnapCart->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $snapPorCarteira[$row['FKCarteira']] = $row;
        }
        $linhasCart = array_map(function ($c) use ($snapPorCarteira) {
            $s  = $snapPorCarteira[$c['IDCarteira']] ?? ['RecEfet' => 0, 'DespEfet' => 0];
            $rc = (float)$s['RecEfet']; $ds = (float)$s['DespEfet'];
            return "{$c['NomeCarteira']}: receitas=R$" . number_format($rc, 2, ',', '.') .
                   ", despesas=R$" . number_format($ds, 2, ',', '.') .
                   ", saldo=R$" . number_format($rc - $ds, 2, ',', '.');
        }, $carteiras);
        $carteirasResumoCtx = "\nResumo do mês por carteira: " . implode('; ', $linhasCart) . '.';
    } catch (Throwable $e) {}
}

// Código de indicação + saldo de comissão (quando o modo "dinheiro" está ativo) — direto
// no contexto pra IA nunca ter que inventar isso; sem isso não havia de onde tirar essa
// informação, e ela arriscava "chutar" um código ou valor.
$indicacaoCtx = '';
try {
    if (!empty($usuario['CodigoIndicacao'])) {
        $indicacaoCtx = "\nCódigo de indicação: {$usuario['CodigoIndicacao']} (link: meuauralis.com/usuario/cadastro.php?ref={$usuario['CodigoIndicacao']})";
        if (function_exists('obterModoRecompensaIndicacao') && obterModoRecompensaIndicacao($pdo) === 'dinheiro') {
            $stmtRevWa = $pdo->prepare("SELECT IDRevendedor FROM Revendedor WHERE FKUsuario = :uid LIMIT 1");
            $stmtRevWa->execute([':uid' => $uid]);
            $revIdWa = $stmtRevWa->fetchColumn();
            if ($revIdWa) {
                $stmtSaldoWa = $pdo->prepare("
                    SELECT COALESCE(SUM(CASE WHEN Status = 'pendente' THEN ValorComissao ELSE 0 END), 0) AS pendente,
                           COALESCE(SUM(CASE WHEN Status = 'paga'     THEN ValorComissao ELSE 0 END), 0) AS pago
                    FROM ComissaoRevendedor WHERE FKRevendedor = :rid
                ");
                $stmtSaldoWa->execute([':rid' => $revIdWa]);
                $rowWa = $stmtSaldoWa->fetch(PDO::FETCH_ASSOC);
                $indicacaoCtx .= "\nSaldo de comissão: pendente=R$" . number_format((float)($rowWa['pendente'] ?? 0), 2, ',', '.') .
                                 ", já recebido=R$" . number_format((float)($rowWa['pago'] ?? 0), 2, ',', '.');
            } else {
                $indicacaoCtx .= "\nSaldo de comissão: ainda não gerou nenhuma comissão.";
            }
        }
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
3. Se a mensagem for ambígua ou você não tiver certeza do que a pessoa quer, use action "clarificar" — não tente adivinhar e errar. Isso vale especialmente pra "registrar": se o parcelamento tem datas ou valores que não são regulares (ex: "metade agora, metade só no mês que vem em outro dia"; parcelas de valores diferentes sem explicação de por quê), NÃO tente forçar num parcelamento padrão — pergunte primeiro, ou registre normal e explique que dá pra ajustar depois com "editar".
4. Uma reação casual ("kkk", "valeu", "tá bom") merece uma resposta casual curta. Não repita dados financeiros que já foram mostrados logo antes.
5. Nunca invente dados. Se não tiver a informação no contexto e precisar de DB, use "consultar".
6. Múltiplas intenções na mesma mensagem → use "acoes" array. Leia a mensagem inteira antes de responder: "paguei a luz e recebi o freela de ontem" são 2 registros; "marca a academia como paga e muda o valor do aluguel pra 900" são 1 efetivar + 1 editar; "quanto gastei em mercado esse mês e quanto ainda falta pagar" são 2 consultas (tipo "gastos" + tipo "pendentes"). Cada pedido distinto na mensagem vira um item em "acoes" — nunca resolva só o primeiro e ignore o resto. ATENÇÃO especial quando período OU tipo (receita/despesa) MUDA entre os pedidos na mesma frase — cada combinação diferente de período+tipo é uma consulta própria, nunca uma só: "gastos dessa semana e o que tenho pra receber esse mês" é 2 consultas SEPARADAS (despesa/semana + receita/mês), NUNCA uma consulta só de "pendentes do mês" — isso perde o recorte de período que a pessoa pediu especificamente pro primeiro item.
7. Formatação do WhatsApp no campo "resposta" (texto livre): use *negrito* pra valores/nomes importantes, _itálico_ pra observações secundárias, ~riscado~ só se fizer sentido (ex: algo cancelado), e "• " no início da linha pra listas. WhatsApp NÃO tem sublinhado — não tente simular com outra coisa, use negrito no lugar quando quiser destacar.
8. Se {$nomeUser} tiver mais de uma carteira: quando ele mencionar uma pelo nome (ou já estiver claro pelo contexto da conversa qual carteira), responda só com os dados DAQUELA carteira — use o "Resumo do mês por carteira" abaixo pra respostas diretas, ou "id_carteira" na action "consultar" pra consultas no banco. Nunca some tudo por padrão quando uma carteira específica foi indicada. Sem menção a nenhuma carteira, aí sim vale o agregado de todas. Se perguntarem de qual carteira é um lançamento específico (ex: "de qual carteira é a conta X"), use "consultar" tipo "buscar" — não tem como saber isso pelo contexto pré-carregado.
9. NUNCA some receita com despesa num total só — são naturezas opostas (dinheiro entrando vs saindo), um total misturado não quer dizer nada pro cliente. Ao listar pendências/gastos que incluam os dois tipos, sempre separe em dois blocos com dois totais (ex: "📉 A pagar" e "📈 A receber"), nunca um "Total" único somando tudo junto.
10. Lembretes automáticos de vencimento (enviados por um cron, não por você) também aparecem no histórico da conversa — se {$nomeUser} perguntar sobre algo que só viu num desses lembretes (ex: "essa conta X que venceu, de qual carteira é"), trate como uma pergunta válida sobre um registro real, não como algo desconhecido. Use "consultar" tipo "buscar" com o nome mencionado pra achar os detalhes.
11. Antes de "registrar" QUALQUER pagamento/recebimento (com ou sem imagem — vale pra "paguei o Vivo Easy" digitado igual vale pra um comprovante), confira se a descrição bate com algo na lista de "Pendentes" do contexto. Se bater, é "efetivar" aquele registro existente, nunca um "registrar" novo — criar um novo duplica a conta e deixa a pendente antiga esquecida lá. Só é "registrar" de verdade quando o que a pessoa pagou/recebeu não corresponde a nada da lista.
12. Se "Perfil aprendido" abaixo tiver algum padrão de categorização (associação tipo "descrição X sempre é categoria Y"), aplique automaticamente sempre que um "registrar" novo tiver descrição parecida — não pergunte a categoria de novo pra algo que já foi ensinado. Aprenda esses padrões você mesmo: se {$nomeUser} corrigir a categoria de algo ("na verdade isso é lazer", "isso sempre entra em X"), ou repetir a mesma descrição incomum (tipo um e-mail, código, nome de serviço) mais de uma vez sempre com a mesma categoria, grave essa associação em "_perfil_atualizado" pra não precisar perguntar de novo no futuro.

Contexto financeiro atual de {$nomeUser}:
- Hoje: {$hoje}
- Mês atual: receitas efetivadas={$recFmt}, despesas efetivadas={$despFmt}, saldo={$saldoFmt}, pendentes={$qtdPendente} itens={$pendFmt}{$pendentesCtx}{$ultimosCtx}
- Carteiras: {$cartsList}{$carteirasResumoCtx}
- Cofrinhos: {$cofList}
- Categorias despesa: {$catDesp}
- Categorias receita: {$catRec}{$indicacaoCtx}

ACTIONS disponíveis (responda SEMPRE com JSON válido, sem markdown):

"conversar" — resposta livre para perguntas, cálculos com dados do contexto, opiniões, reações, bate-papo. USE ISSO quando conseguir responder com o contexto acima:
{"action":"conversar","resposta":"resposta direta e natural"}

"clarificar" — quando não entende ou a mensagem tem 2+ interpretações válidas:
{"action":"clarificar","pergunta":"pergunta curta e objetiva","opcoes":["interpretação A","interpretação B"]}

"registrar" — lançar transações financeiras:
{"action":"registrar","registros":[{"tipo":"despesa","valor":0.00,"valor_total":0.00,"descricao":"max 60 chars","data":"YYYY-MM-DD","id_carteira":"uuid","id_categoria":"uuid|null","nome_carteira":"nome","nome_categoria":"nome|null","parcelas":1,"recorrente":false,"dia_vencimento":0,"tipo_recorrencia":"meses","intervalo_recorrencia":1}]}
Regras:
- primeira carteira se não mencionada; data relativa → YYYY-MM-DD exato.
- parcelas>1 para parcelamentos — cada parcela cai automaticamente no mesmo dia dos meses seguintes (ex: base dia 20 → parcela 2 também cai dia 20). Se o parcelamento tiver datas diferentes por parcela, use "clarificar" ou registre e avise que dá pra ajustar com "editar" (regra 3).
- "valor" = valor de CADA parcela, quando a pessoa já fala o valor por parcela (ex: "12x de 150" → valor=150). "valor_total" = preço cheio a dividir (ex: "comprei uma TV de 3000 em 10x" → valor_total=3000, sem valor). Use SÓ um dos dois.
- Compra parcelada "com juros": se a pessoa já disser o valor final por parcela (ex: "fica 220 por mês"), use valor=220 direto. Se ela só souber o total final com juros incluso (ex: "no total com juros dá 3300"), use valor_total=3300 — o sistema divide certinho, não faça a conta de cabeça.
- "recorrente":true para contas que se repetem SEM ser parcelamento — não usa "parcelas" nesse caso. Nunca marque recorrente E parcelas>1 ao mesmo tempo. Por padrão repete todo mês ("tipo_recorrencia" omitido = "meses", "intervalo_recorrencia" omitido = 1) no "dia_vencimento" informado (1-31). Se a pessoa disser "a cada X dias" ou "toda semana"/"a cada X semanas" (ex: "me cobra a cada 15 dias"), use "tipo_recorrencia":"dias"|"semanas" com "intervalo_recorrencia":X e OMITA "dia_vencimento" (a recorrência ancora na própria "data" do lançamento). Se disser "bimestral"/"a cada 2 meses", use "tipo_recorrencia":"meses" com "intervalo_recorrencia":2.

"efetivar" — marcar pendente(s) como pago/recebido (inclui comprovante de algo já pendente — ver regra 11):
{"action":"efetivar","descricoes":["texto parcial"]}

"desefetivar" — desfazer, marcar de volta como pendente algo que foi pago/recebido por engano:
{"action":"desefetivar","descricoes":["texto parcial"]}

"editar" — mudar nome, valor, data, tipo ou categoria de um registro já existente (ex: "era outra coisa, muda o nome pra X", "muda a data da parcela 2 pra dia 5", "esse valor tá errado, era 80"). Inclua só os campos que realmente mudam. Parcelas ficam salvas com sufixo "nome N/total" (ex: "TV 2/2") — se a pessoa falar de uma parcela específica, inclua esse número no "descricao_busca" pra achar a certa (ex: "TV 2/2" em vez de só "TV"):
{"action":"editar","descricao_busca":"texto pra achar o registro","descricao_nova":"novo nome|omitir","valor_novo":0.00,"data_nova":"YYYY-MM-DD","tipo_novo":"despesa|receita","id_categoria_nova":"uuid","nome_categoria_nova":"nome"}

"cofrinho_depositar":
{"action":"cofrinho_depositar","nome_cofrinho":"nome","valor":0.00}

"cofrinho_criar":
{"action":"cofrinho_criar","nome":"nome","meta":0.00}

"cofrinho_retirar" — tirar dinheiro de um cofrinho (ex: "tira 50 do cofrinho da viagem", "usei 100 do cofrinho do carro"):
{"action":"cofrinho_retirar","nome_cofrinho":"nome","valor":0.00}

"cofrinho_editar" — mudar nome e/ou meta de um cofrinho já existente. Inclua só os campos que realmente mudam:
{"action":"cofrinho_editar","nome_cofrinho":"nome atual","nome_novo":"novo nome|omitir","meta_nova":0.00}

"cofrinho_excluir" — apagar um cofrinho PERMANENTEMENTE (junto com todo o histórico dele). Só use com pedido explícito e inequívoco ("apaga o cofrinho X", "exclui o cofrinho X", "deleta o cofrinho X") — nunca por dedução:
{"action":"cofrinho_excluir","nome_cofrinho":"nome"}

"consultar" — USE APENAS para cálculos que exigem DB: breakdown por categoria, períodos passados, totais não presentes no contexto, ou achar um lançamento específico pelo nome:
{"action":"consultar","consulta":{"tipo":"gastos|pendentes|vencidas|saldo|ultimo|buscar|meta","periodo":"hoje|semana|mes|ano","tipo_registro":"despesa|receita|null","id_carteira":"uuid|null","termo":"nome ou parte do nome, só com tipo=buscar","categoria":"nome da categoria, só com tipo=meta"}}
- "id_carteira": preencha com o id da carteira (lista de Carteiras acima) se o cliente mencionou uma específica pelo nome. Sem menção, deixe null — soma todas.
- "pendentes" respeita o "periodo" pedido (o que vence NESSE período, pago ou não ainda). "vencidas" IGNORA período — é sempre "já passou da data e não foi pago", pra perguntas tipo "tem conta vencida?", "o que tá atrasado?", "alguma coisa em atraso?". NÃO use "pendentes" pra essas perguntas, o período pode não cobrir a data certa — use "vencidas" direto.
- "buscar": ache um ou mais lançamentos pelo nome/descrição (ex: "onde tá a conta da Vivo", "de qual carteira é o lançamento teste", "vc verificou a conta X?") — retorna carteira, categoria, data e status de cada um encontrado. Use "termo" com a palavra-chave, ignore "periodo"/"tipo_registro" nesse tipo.
- "meta": consulta metas/orçamento por categoria — quanto já foi gasto nessa categoria esse mês vs a meta definida (ex: "quanto já gastei da minha meta de lazer", "tô dentro do orçamento de mercado?"). Com "categoria" preenchido, retorna só aquela; sem "categoria" (ex: "como tão minhas metas esse mês?"), lista todas as metas configuradas com o progresso de cada uma.

"cancelar":
{"action":"cancelar"}

"ajuda" — SOMENTE se pedir lista de comandos explicitamente:
{"action":"ajuda"}

"escalar_suporte" — USE quando a situação precisa de um humano de verdade, não de uma action do sistema: pedido explícito pra falar com suporte/atendente/pessoa real; reclamação sobre cobrança, bug ou o serviço em si; alguém visivelmente frustrado/insistindo que a IA não está resolvendo. NÃO use pra dúvida comum que "clarificar" ou "conversar" já resolvem — é só pra quando ninguém dessas duas dá conta.
{"action":"escalar_suporte","motivo":"resumo curto e objetivo da situação/reclamação, em 1-2 frases","resposta_cliente":"mensagem tranquilizando a pessoa, avisando que o suporte foi acionado"}
IMPORTANTE: isso SÓ funciona de verdade com esse JSON exato. NUNCA diga em texto solto (action "conversar") frases como "já chamei o suporte" ou "já acionei a equipe" sem USAR essa action — isso engana a pessoa, porque nada é avisado de verdade. Se você disse ou vai dizer algo do tipo, use "escalar_suporte" nesse mesmo momento.

"acoes" — múltiplas intenções distintas:
{"acoes":[{acao1},{acao2}]}

CAMPO OPCIONAL "_perfil_atualizado" — só inclua se aprendeu algo novo (apelido, tom, contexto financeiro recorrente, padrão de categorização por descrição — ver regra 12). OMITA se nada novo.
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

// Rede de segurança: a IA às vezes NARRA que "já chamou o suporte" numa resposta comum
// (action "conversar") sem de fato disparar "escalar_suporte" — a pessoa fica achando
// que foi avisado e ninguém recebe nada. Pra um pedido explícito e inequívoco desses,
// não depende só do modelo lembrar de fazer certo: força a ação por regra fixa.
$jaTemEscalada = false;
foreach ($acoes as $a) {
    if (($a['action'] ?? '') === 'escalar_suporte') { $jaTemEscalada = true; break; }
}
if (!$jaTemEscalada && preg_match('/falar\s+com\s+(o\s+|a\s+)?(suporte|equipe|time|atendimento|um\s+humano|humano|uma\s+pessoa|pessoa\s+real|algu[ée]m\s+real|atendente|algu[ée]m)/iu', $texto)) {
    $acoes[] = ['action' => 'escalar_suporte', 'motivo' => 'Pediu explicitamente pra falar com humano/suporte: "' . mb_substr($texto, 0, 150) . '"'];
}

// Rede de segurança: se QUALQUER handler de action explodir com um erro não previsto,
// isso não pode travar a resposta pro cliente em silêncio total (o webhook já respondeu
// 200 pro Evolution API lá em cima, então um fatal aqui simplesmente nunca chega a chamar
// _waReply — a pessoa manda mensagem e nunca mais recebe nada, sem nenhum aviso de erro).
$respostas = [];
foreach ($acoes as $acao) {
    try {
        $respostas[] = _waDespachar($pdo, $uid, $acao, $carteiras, $cofrinhos, $hoje, $personalidade, $usuario, $telefone);
    } catch (Throwable $e) {
        $respostas[] = "❌ Deu um erro aqui do meu lado processando isso. Tenta de novo, ou me chama que eu aciono o suporte.";
    }
}

$resposta = implode("\n\n", $respostas);

// Histórico PRIMEIRO, resposta depois — de propósito. A Evolution API ecoa de volta (como
// fromMe=true) toda mensagem que a própria IA manda; se _waReply rodasse antes, existia uma
// corrida real onde esse eco podia chegar (e ser processado por _waTratarMensagemFromMe) ANTES
// dessa linha salvar o Role='model' — aí ele não achava nenhum registro recente, achava que
// era um humano digitando de verdade, e pausava a IA pra esse mesmo cliente sem motivo (foi
// exatamente o que aconteceu no teste: silêncio total em "ta ai?" pouco depois).
_waSaveHistory($pdo, $uid, $texto, $resposta);
_waReply($telefone, $resposta);

// Atualiza perfil permanente se a IA aprendeu algo novo
if (!empty($resultado['_perfil_atualizado']) && is_array($resultado['_perfil_atualizado'])) {
    _waSalvarPerfil($pdo, $uid, $resultado['_perfil_atualizado']);
}

exit;

// ── Despachante central ───────────────────────────────────────────────────────

function _waDespachar(PDO $pdo, string $uid, array $acao, array $carteiras, array $cofrinhos, string $hoje, string $personalidade, array $usuario, string $telefoneCliente): string
{
    $action = $acao['action'] ?? 'conversar';
    return match($action) {
        'registrar'          => _waRegistrar($pdo, $uid, $acao['registros'] ?? [], $carteiras, $hoje),
        'efetivar'           => _waEfetivar($pdo, $uid, $acao['descricoes'] ?? []),
        'desefetivar'        => _waDesefetivar($pdo, $uid, $acao['descricoes'] ?? []),
        'editar'             => _waEditar($pdo, $uid, $acao),
        'cofrinho_depositar' => _waCofrinhoDepositar($pdo, $uid, $acao, $cofrinhos, $carteiras, $hoje),
        'cofrinho_criar'     => _waCofinhoCriar($pdo, $uid, $acao, $carteiras, $hoje),
        'cofrinho_retirar'   => _waCofrinhoRetirar($pdo, $uid, $acao, $cofrinhos, $hoje),
        'cofrinho_editar'    => _waCofrinhoEditar($pdo, $uid, $acao, $cofrinhos),
        'cofrinho_excluir'   => _waCofrinhoExcluir($pdo, $uid, $acao, $cofrinhos),
        'consultar'          => _waConsultar($pdo, $uid, $acao['consulta'] ?? [], $hoje),
        'cancelar'           => _waCancelar($pdo, $uid),
        'ajuda'              => _waAjuda($personalidade),
        'clarificar'         => _waClarificar($acao),
        'escalar_suporte'    => _waEscalarSuporte($pdo, $uid, $acao, $usuario, $telefoneCliente),
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

    // MomentoRegistro precisa ir junto com DataVencimento — é ele que o dashboard usa pra
    // separar/agrupar por dia (dashboard.php:1239-1240). Sem setar isso, o MySQL cai no
    // default (agora/hoje), então um comprovante de uma compra de anteontem aparecia
    // sempre em "hoje", mesmo com a data certa salva em DataVencimento.
    $stmtIns = $pdo->prepare("
        INSERT INTO Registro
            (IDRegistro, Valor, Descricao, FKCarteira, FKUsuario, FKCategoria,
             TipoRegistro, MomentoRegistro, DataVencimento, StatusRegistro, Recorrente, DiaVencimento,
             GrupoParcela, ParcelaAtual, TotalParcelas)
        VALUES (:id, :val, :desc, :cart, :uid, :cat, :tipo, :momento, :data, :status, :recorrente, :dia,
                :grupo, :parc_atual, :total_parc)
    ");

    foreach ($registros as $r) {
        $descricao = mb_substr(trim($r['descricao'] ?? ''), 0, 200);
        $tipo      = in_array($r['tipo'] ?? '', ['receita', 'despesa']) ? $r['tipo'] : 'despesa';
        $dataBase  = $r['data'] ?? $hoje;
        $cart      = $r['id_carteira'] ?? $carteiras[0]['IDCarteira'];
        $cat       = !empty($r['id_categoria']) ? $r['id_categoria'] : null;
        $parcelas  = max(1, (int)($r['parcelas'] ?? 1));
        // Recorrente e parcelado não fazem sentido juntos — recorrente vence sempre que
        // ambos vierem preenchidos, igual ao toggle do app (nova_transacao.php:1641-1644).
        $recorrente = $parcelas <= 1 && !empty($r['recorrente']);

        // valor = já é o valor de CADA parcela (ex: "12x de 150" → 150).
        // valor_total = preço cheio a dividir pelas parcelas (ex: "3000 em 10x com juros,
        // fica 3300 no total" → valor_total=3300) — mesmo arredondamento do app (a 1ª
        // parcela absorve a sobra de centavos), pra não deixar a IA fazer conta de cabeça.
        if (!empty($r['valor_total']) && $parcelas > 1) {
            $valorTotal   = abs((float)$r['valor_total']);
            $valorParcela = floor(($valorTotal / $parcelas) * 100) / 100;
            $resto        = round($valorTotal - ($valorParcela * $parcelas), 2);
        } else {
            $valorParcela = abs((float)($r['valor'] ?? 0));
            $resto        = 0;
        }

        if (!$valorParcela || !$descricao) { $erros++; continue; }

        if ($recorrente) {
            // Ponto único de criação de recorrência — sempre com GrupoParcela real, nunca
            // mais uma linha solta que o motor de reabastecimento não consegue localizar
            // (era o bug: recorrente criado por aqui nascia com GrupoParcela NULL).
            $tipoRec   = in_array($r['tipo_recorrencia'] ?? '', ['dias', 'semanas', 'meses'], true) ? $r['tipo_recorrencia'] : 'meses';
            $intervRec = max(1, (int)($r['intervalo_recorrencia'] ?? 1));
            $diaVenc   = $tipoRec === 'meses'
                ? max(1, min(31, (int)($r['dia_vencimento'] ?? date('j', strtotime($dataBase)))))
                : null;

            try {
                criarSerieRecorrente($pdo, [
                    'usuario_id'            => $uid,
                    'carteira_id'           => $cart,
                    'categoria_id'          => $cat,
                    'tipo_registro'         => $tipo,
                    'valor'                 => $valorParcela,
                    'descricao'             => $descricao,
                    'data_inicio'           => $dataBase,
                    'status_inicial'        => $dataBase > $hoje ? 'pendente' : 'efetivado',
                    'tipo_recorrencia'      => $tipoRec,
                    'intervalo_recorrencia' => $intervRec,
                    'dia_vencimento'        => $diaVenc,
                ]);
            } catch (Throwable $e) { $erros++; continue; }

            $icon     = $tipo === 'receita' ? '📈' : '📉';
            $valFmt   = 'R$ ' . number_format($valorParcela, 2, ',', '.');
            $catNome  = !empty($r['nome_categoria']) ? " · " . $r['nome_categoria'] : '';
            $cartNome = $r['nome_carteira'] ?? $carteiras[0]['NomeCarteira'];
            $confirmacoes[] = "{$icon} *{$descricao}* 🔁 _" . descreverRecorrencia($tipoRec, $intervRec, $diaVenc) . "_\n   {$valFmt}{$catNome} · {$cartNome}";
            continue;
        }

        // GrupoParcela só é usado pra ligar parcelas entre si. DiaVencimento só existe pra
        // recorrência (que já saiu por "continue" acima) — sempre NULL aqui.
        $grupoParcela = $parcelas > 1 ? gerarUuid() : null;
        $diaVenc      = null;

        // calcularProximaOcorrenciaRecorrente() clampa fim de mês (dia 31 numa base +1 mês
        // que só tem 28/30 dias) — "strtotime('+N month')" direto não clampa e estoura pro
        // mês seguinte ao pretendido (ex: 31/01 vira 03/03, pulando fevereiro), o mesmo bug
        // já corrigido nesse ponto no site (nova_transacao.php) e no motor de recorrência.
        $dataBaseObj    = new DateTime($dataBase);
        $diaAncoraParc  = (int)$dataBaseObj->format('d');
        $dataParcelaObj = clone $dataBaseObj;

        for ($i = 1; $i <= $parcelas; $i++) {
            if ($i > 1) {
                $dataParcelaObj = calcularProximaOcorrenciaRecorrente($dataParcelaObj, 'meses', 1, $diaAncoraParc);
            }
            $dataParcela = $dataParcelaObj->format('Y-m-d');
            $valorEssaParcela = ($i === 1) ? round($valorParcela + $resto, 2) : $valorParcela;

            $status      = $dataParcela > $hoje ? 'pendente' : 'efetivado';
            $descParcela = $parcelas > 1 ? mb_substr($descricao, 0, 50) . " {$i}/{$parcelas}" : $descricao;

            try {
                $stmtIns->execute([
                    ':id'         => gerarUuid(),
                    ':val'        => $valorEssaParcela,
                    ':desc'       => $descParcela,
                    ':cart'       => $cart,
                    ':uid'        => $uid,
                    ':cat'        => $cat,
                    ':tipo'       => $tipo,
                    ':momento'    => $dataParcela,
                    ':data'       => $dataParcela,
                    ':status'     => $status,
                    ':recorrente' => $recorrente ? 1 : 0,
                    ':dia'        => $diaVenc,
                    ':grupo'      => $grupoParcela,
                    ':parc_atual' => $parcelas > 1 ? $i : null,
                    ':total_parc' => $parcelas > 1 ? $parcelas : null,
                ]);
            } catch (Throwable $e) { $erros++; continue 2; }
        }

        $icon    = $tipo === 'receita' ? '📈' : '📉';
        $valFmt  = 'R$ ' . number_format($valorParcela, 2, ',', '.');
        $dataFmt = date('d/m/Y', strtotime($dataBase));
        $catNome = !empty($r['nome_categoria']) ? " · " . $r['nome_categoria'] : '';
        $cartNome = $r['nome_carteira'] ?? $carteiras[0]['NomeCarteira'];
        $pendente = $dataBase > $hoje ? ' _(pendente)_' : '';

        if ($parcelas > 1) {
            $totalFmt = 'R$ ' . number_format(($valorParcela * $parcelas) + $resto, 2, ',', '.');
            // $dataParcelaObj já está na data da última parcela gerada no loop acima.
            $fimFmt   = $dataParcelaObj->format('d/m/Y');
            $confirmacoes[] = "{$icon} *{$descricao}*\n   {$valFmt}/mês × {$parcelas} = {$totalFmt}{$catNome} · {$cartNome}\n   📅 {$dataFmt} → {$fimFmt}\n   _Datas de cada parcela caem sempre no mesmo dia do mês seguinte — se alguma vencer em dia diferente, me fala qual parcela e a nova data que eu ajusto._";
        } else {
            $confirmacoes[] = "{$icon} *{$descricao}*: {$valFmt}{$catNome} · {$cartNome} · 📅 {$dataFmt}{$pendente}";
        }
    }

    if (!$confirmacoes) return "❌ Não consegui salvar os registros. Tente novamente.";

    $header  = count($confirmacoes) === 1 ? "✅ *Registrado!*" : "✅ *" . count($confirmacoes) . " registros salvos!*";
    $erroTxt = $erros ? "\n\n⚠️ {$erros} item(ns) não salvo(s)." : '';

    return $header . "\n\n" . implode("\n", $confirmacoes) . $erroTxt . "\n\n_meuauralis.com_ 👆";
}

function _waDesefetivar(PDO $pdo, string $uid, array $descricoes): string
{
    if (!$descricoes) return "❌ Não identifiquei qual registro desfazer.";

    $desfeitos = [];
    $naoEncontrados = [];

    $stmtBusca = $pdo->prepare("
        SELECT IDRegistro, Descricao, Valor, TipoRegistro, DataVencimento, MomentoRegistro
        FROM Registro
        WHERE FKUsuario = :uid AND StatusRegistro = 'efetivado' AND Descricao LIKE :desc
        ORDER BY MomentoRegistro DESC LIMIT 3
    ");
    $stmtUpd = $pdo->prepare("UPDATE Registro SET StatusRegistro = 'pendente' WHERE IDRegistro = :id");

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

            // Mais de um efetivado com esse nome — assume o mais recente (o mais provável de
            // ter sido marcado como pago por engano agora há pouco).
            $row = $rows[0];
            $stmtUpd->execute([':id' => $row['IDRegistro']]);

            $icon   = $row['TipoRegistro'] === 'receita' ? '📈' : '📉';
            $val    = 'R$ ' . number_format((float)$row['Valor'], 2, ',', '.');
            $dtFmt  = $row['DataVencimento'] ? date('d/m', strtotime($row['DataVencimento'])) : '—';
            $desfeitos[] = "{$icon} *{$row['Descricao']}*: {$val} (📅 {$dtFmt}) voltou pra pendente";
        } catch (Throwable $e) { $naoEncontrados[] = $desc; }
    }

    $msg = '';
    if ($desfeitos) {
        $msg .= "↩️ *Desfeito" . (count($desfeitos) > 1 ? "s" : "") . "!*\n\n" . implode("\n", $desfeitos);
    }
    if ($naoEncontrados) {
        $msg .= ($msg ? "\n\n" : "") . "⚠️ Não encontrei pago/recebido com: " . implode(', ', array_map(fn($d) => "*{$d}*", $naoEncontrados));
    }

    return $msg ?: "❌ Nenhum registro efetivado encontrado com esse nome.";
}

function _waEditar(PDO $pdo, string $uid, array $acao): string
{
    $desc = trim($acao['descricao_busca'] ?? '');
    if (!$desc) return "❌ Não identifiquei qual registro editar.";

    $temAlgo = array_key_exists('descricao_nova', $acao) || array_key_exists('valor_novo', $acao)
        || array_key_exists('data_nova', $acao) || array_key_exists('nome_categoria_nova', $acao)
        || array_key_exists('id_categoria_nova', $acao) || array_key_exists('tipo_novo', $acao);
    if (!$temAlgo) return "❌ Não identifiquei o que mudar em \"{$desc}\".";

    try {
        $stmtBusca = $pdo->prepare("
            SELECT IDRegistro, Descricao, Valor, TipoRegistro, DataVencimento, StatusRegistro,
                   ParcelaAtual, TotalParcelas
            FROM Registro
            WHERE FKUsuario = :uid AND StatusRegistro != 'cancelado' AND Descricao LIKE :desc
            ORDER BY MomentoRegistro DESC LIMIT 5
        ");
        $stmtBusca->execute([':uid' => $uid, ':desc' => '%' . $desc . '%']);
        $rows = $stmtBusca->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return "❌ Erro ao buscar o registro."; }

    if (!$rows) return "⚠️ Não encontrei nenhum registro com \"{$desc}\".";

    // Mais de um resultado ainda ambíguo (ex: duas parcelas com nome parecido) — melhor
    // listar e pedir pra especificar do que arriscar editar o item errado.
    if (count($rows) > 1) {
        $linhas = array_map(function ($r) {
            $dt = $r['DataVencimento'] ? date('d/m', strtotime($r['DataVencimento'])) : '—';
            $parc = $r['TotalParcelas'] ? " ({$r['ParcelaAtual']}/{$r['TotalParcelas']})" : '';
            return "• {$r['Descricao']}{$parc} — R$ " . number_format((float)$r['Valor'], 2, ',', '.') . " · 📅 {$dt}";
        }, $rows);
        return "⚠️ Encontrei mais de um com \"{$desc}\", me diz qual (ex: com a data ou o número da parcela):\n\n" . implode("\n", $linhas);
    }

    $row = $rows[0];

    $sets = [];
    $params = [':id' => $row['IDRegistro'], ':uid' => $uid];
    $mudancas = [];

    if (array_key_exists('descricao_nova', $acao) && trim((string)$acao['descricao_nova']) !== '') {
        $novaDesc = mb_substr(trim((string)$acao['descricao_nova']), 0, 200);
        $sets[] = 'Descricao = :nova_desc';
        $params[':nova_desc'] = $novaDesc;
        $mudancas[] = "nome → *{$novaDesc}*";
    }
    if (array_key_exists('valor_novo', $acao) && (float)$acao['valor_novo'] > 0) {
        $novoValor = abs((float)$acao['valor_novo']);
        $sets[] = 'Valor = :novo_valor';
        $params[':novo_valor'] = $novoValor;
        $mudancas[] = "valor → R$ " . number_format($novoValor, 2, ',', '.');
    }
    if (array_key_exists('data_nova', $acao) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$acao['data_nova'])) {
        // MomentoRegistro junto com DataVencimento — é o campo que o dashboard usa pra
        // separar por dia, senão a edição de data não move o item de dia lá.
        $sets[] = 'MomentoRegistro = :nova_data_momento';
        $sets[] = 'DataVencimento = :nova_data';
        $params[':nova_data_momento'] = $acao['data_nova'];
        $params[':nova_data'] = $acao['data_nova'];
        $mudancas[] = "data → " . date('d/m/Y', strtotime($acao['data_nova']));
    }
    if (array_key_exists('tipo_novo', $acao) && in_array($acao['tipo_novo'], ['receita', 'despesa'], true)) {
        $sets[] = 'TipoRegistro = :novo_tipo';
        $params[':novo_tipo'] = $acao['tipo_novo'];
        $mudancas[] = "tipo → {$acao['tipo_novo']}";
    }
    if (!empty($acao['id_categoria_nova'])) {
        $sets[] = 'FKCategoria = :nova_cat';
        $params[':nova_cat'] = $acao['id_categoria_nova'];
        $mudancas[] = "categoria → " . ($acao['nome_categoria_nova'] ?? '(nova)');
    }

    if (!$sets) return "❌ Não identifiquei nenhuma mudança válida pra \"{$desc}\".";

    try {
        $pdo->prepare("UPDATE Registro SET " . implode(', ', $sets) . " WHERE IDRegistro = :id AND FKUsuario = :uid")
            ->execute($params);
    } catch (Throwable $e) { return "❌ Erro ao editar o registro."; }

    return "✏️ *Editado!* {$row['Descricao']}\n\n" . implode("\n", $mudancas);
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

function _waCofrinhoRetirar(PDO $pdo, string $uid, array $acao, array $cofrinhos, string $hoje): string
{
    $nomeBusca = trim($acao['nome_cofrinho'] ?? '');
    $valor     = abs((float)($acao['valor'] ?? 0));

    if (!$nomeBusca || !$valor) return "❌ Informe o nome do cofrinho e o valor.";

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

    try {
        $stmtCofDet = $pdo->prepare("SELECT FKCarteira FROM Cofrinho WHERE IDCofrinho = :id AND FKUsuario = :uid AND Ativo = 1");
        $stmtCofDet->execute([':id' => $cofrinho['IDCofrinho'], ':uid' => $uid]);
        $cofDet = $stmtCofDet->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return "❌ Erro ao acessar o cofrinho."; }

    if (!$cofDet) return "❌ Cofrinho não encontrado ou inativo.";

    try {
        $pdo->prepare("
            INSERT INTO Registro (IDRegistro, TipoRegistro, Valor, Descricao, MomentoRegistro, DataVencimento,
                                  StatusRegistro, Recorrente, DiaVencimento, FKCarteira, FKUsuario, FKCofrinho)
            VALUES (:id, 'cofrinho_retirada', :val, :desc, NOW(), :hoje,
                    'efetivado', 0, NULL, :cart, :uid, :cof)
        ")->execute([
            ':id'   => gerarUuid(),
            ':val'  => $valor,
            ':desc' => 'Retirada via WhatsApp',
            ':hoje' => $hoje,
            ':cart' => $cofDet['FKCarteira'],
            ':uid'  => $uid,
            ':cof'  => $cofrinho['IDCofrinho'],
        ]);
    } catch (Throwable $e) { return "❌ Erro ao retirar do cofrinho."; }

    $novoSaldo = (float)$cofrinho['Saldo'] - $valor;
    return "💸 *Retirada do cofrinho \"{$cofrinho['Nome']}\"!*\n\nValor: R$ " . number_format($valor, 2, ',', '.') .
           "\nNovo saldo: R$ " . number_format($novoSaldo, 2, ',', '.');
}

function _waCofrinhoEditar(PDO $pdo, string $uid, array $acao, array $cofrinhos): string
{
    $nomeBusca = trim($acao['nome_cofrinho'] ?? '');
    if (!$nomeBusca) return "❌ Informe o nome do cofrinho que quer editar.";

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

    // Edição parcial: só troca Nome/ValorMeta, nunca mexe em Icone/Cor/DataLimite —
    // a IA não tem como saber esses campos, então preserva o que já estava configurado.
    $nomeNovo = (!empty($acao['nome_novo']) && trim((string)$acao['nome_novo']) !== '')
        ? mb_substr(trim((string)$acao['nome_novo']), 0, 100)
        : $cofrinho['Nome'];
    $metaNova = (array_key_exists('meta_nova', $acao) && $acao['meta_nova'] !== null && $acao['meta_nova'] !== '')
        ? abs((float)$acao['meta_nova'])
        : (float)$cofrinho['ValorMeta'];

    try {
        $pdo->prepare("
            UPDATE Cofrinho SET Nome = :nome, ValorMeta = :meta
            WHERE IDCofrinho = :id AND FKUsuario = :uid AND Ativo = 1
        ")->execute([
            ':nome' => $nomeNovo,
            ':meta' => $metaNova,
            ':id'   => $cofrinho['IDCofrinho'],
            ':uid'  => $uid,
        ]);
    } catch (Throwable $e) { return "❌ Erro ao editar cofrinho."; }

    $metaTxt = $metaNova > 0 ? "\n🎯 Meta: R$ " . number_format($metaNova, 2, ',', '.') : '';
    return "✏️ *Cofrinho atualizado!*\n\nNome: *{$nomeNovo}*{$metaTxt}";
}

function _waCofrinhoExcluir(PDO $pdo, string $uid, array $acao, array $cofrinhos): string
{
    $nomeBusca = trim($acao['nome_cofrinho'] ?? '');
    if (!$nomeBusca) return "❌ Informe o nome do cofrinho que quer excluir.";

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

    try {
        $pdo->beginTransaction();
        // Mesmo padrão de cofrinho/processa_cofrinho.php (ação "excluir"): apaga o histórico
        // vinculado antes do cofrinho em si, dentro da mesma transação.
        $pdo->prepare("DELETE FROM Registro WHERE FKCofrinho = :id AND FKUsuario = :uid")
            ->execute([':id' => $cofrinho['IDCofrinho'], ':uid' => $uid]);
        $pdo->prepare("DELETE FROM Cofrinho WHERE IDCofrinho = :id AND FKUsuario = :uid")
            ->execute([':id' => $cofrinho['IDCofrinho'], ':uid' => $uid]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return "❌ Erro ao excluir o cofrinho.";
    }

    return "🗑️ *Cofrinho \"{$cofrinho['Nome']}\" excluído* — junto com todo o histórico de aportes e retiradas dele. Isso não dá pra desfazer.";
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
    $tipo       = $consulta['tipo']          ?? 'gastos';
    $periodo    = $consulta['periodo']       ?? 'mes';
    $tipoReg    = $consulta['tipo_registro'] ?? null;
    $carteiraId = $consulta['id_carteira']   ?? null;

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
                      " . ($carteiraId ? "AND r.FKCarteira = :carteira" : "") . "
                    ORDER BY r.DataVencimento ASC LIMIT 20
                ");
                $p = [':uid' => $uid, ':ini' => $ini, ':fim' => $fim];
                if ($tipoReg) $p[':tipo'] = $tipoReg;
                if ($carteiraId) $p[':carteira'] = $carteiraId;
                $stmt->execute($p);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

                if (!$rows) return "Nada pendente pra {$label}. ✅";

                $formatarLinhaPend = fn($r) =>
                    "• " . date('d/m', strtotime($r['DataVencimento'])) .
                    " *{$r['Descricao']}*: R$ " . number_format($r['Valor'], 2, ',', '.') .
                    ($r['NomeCategoria'] ? " ({$r['NomeCategoria']})" : '');

                // $tipoReg já filtrado num tipo só (chamada explícita da IA) — lista simples,
                // um total só faz sentido aqui porque é tudo da mesma natureza.
                if ($tipoReg) {
                    $total  = array_sum(array_column($rows, 'Valor'));
                    $linhas = array_map($formatarLinhaPend, $rows);
                    $intro  = count($rows) === 1 ? "Só uma pendência pra {$label}:" : count($rows) . " pendências pra {$label}:";
                    return "{$intro}\n\n" . implode("\n", $linhas) .
                           "\n\n💰 Total: R$ " . number_format($total, 2, ',', '.');
                }

                // Sem filtro de tipo: nunca soma receita pendente com despesa pendente num
                // total só — são naturezas opostas, misturar não tem significado nenhum.
                $despesas = array_values(array_filter($rows, fn($r) => $r['TipoRegistro'] === 'despesa'));
                $receitas = array_values(array_filter($rows, fn($r) => $r['TipoRegistro'] === 'receita'));

                $blocos = [];
                if ($despesas) {
                    $totalDesp = array_sum(array_column($despesas, 'Valor'));
                    $blocos[] = "📉 *A pagar* (" . count($despesas) . "):\n" . implode("\n", array_map($formatarLinhaPend, $despesas)) .
                                "\nSubtotal: R$ " . number_format($totalDesp, 2, ',', '.');
                }
                if ($receitas) {
                    $totalRec = array_sum(array_column($receitas, 'Valor'));
                    $blocos[] = "📈 *A receber* (" . count($receitas) . "):\n" . implode("\n", array_map($formatarLinhaPend, $receitas)) .
                                "\nSubtotal: R$ " . number_format($totalRec, 2, ',', '.');
                }
                return "Pendências pra {$label}:\n\n" . implode("\n\n", $blocos);

            case 'vencidas':
                // Diferente de "pendentes" (que respeita o período pedido — mês, semana etc.),
                // "vencidas" é sempre "já passou da data e ainda não foi pago", direto, sem
                // depender de acertar um período que cubra a data certa. É a resposta exata
                // pra "tem conta vencida?"/"o que tá atrasado?".
                $stmt = $pdo->prepare("
                    SELECT r.Descricao, r.Valor, r.TipoRegistro, r.DataVencimento, c.NomeCategoria
                    FROM Registro r LEFT JOIN Categoria c ON c.IDCategoria = r.FKCategoria
                    WHERE r.FKUsuario = :uid AND r.StatusRegistro = 'pendente'
                      AND r.DataVencimento < :hoje
                      " . ($tipoReg ? "AND r.TipoRegistro = :tipo" : "") . "
                      " . ($carteiraId ? "AND r.FKCarteira = :carteira" : "") . "
                    ORDER BY r.DataVencimento ASC LIMIT 20
                ");
                $pv = [':uid' => $uid, ':hoje' => $hoje];
                if ($tipoReg) $pv[':tipo'] = $tipoReg;
                if ($carteiraId) $pv[':carteira'] = $carteiraId;
                $stmt->execute($pv);
                $rowsV = $stmt->fetchAll(PDO::FETCH_ASSOC);

                if (!$rowsV) return "Nenhuma conta vencida. ✅";

                $formatarLinhaVenc = fn($r) => "• " . date('d/m', strtotime($r['DataVencimento'])) .
                    " *{$r['Descricao']}*: R$ " . number_format($r['Valor'], 2, ',', '.') .
                    ($r['NomeCategoria'] ? " ({$r['NomeCategoria']})" : '');

                if ($tipoReg) {
                    $totalV  = array_sum(array_column($rowsV, 'Valor'));
                    $linhasV = array_map($formatarLinhaVenc, $rowsV);
                    $introV  = count($rowsV) === 1 ? "Só uma conta vencida:" : count($rowsV) . " contas vencidas:";
                    return "{$introV}\n\n" . implode("\n", $linhasV) . "\n\n💰 Total: R$ " . number_format($totalV, 2, ',', '.');
                }

                $despesasV = array_values(array_filter($rowsV, fn($r) => $r['TipoRegistro'] === 'despesa'));
                $receitasV = array_values(array_filter($rowsV, fn($r) => $r['TipoRegistro'] === 'receita'));
                $blocosV = [];
                if ($despesasV) {
                    $totalDespV = array_sum(array_column($despesasV, 'Valor'));
                    $blocosV[] = "📉 *Vencidas a pagar* (" . count($despesasV) . "):\n" . implode("\n", array_map($formatarLinhaVenc, $despesasV)) .
                                 "\nSubtotal: R$ " . number_format($totalDespV, 2, ',', '.');
                }
                if ($receitasV) {
                    $totalRecV = array_sum(array_column($receitasV, 'Valor'));
                    $blocosV[] = "📈 *Vencidas a receber* (" . count($receitasV) . "):\n" . implode("\n", array_map($formatarLinhaVenc, $receitasV)) .
                                 "\nSubtotal: R$ " . number_format($totalRecV, 2, ',', '.');
                }
                return "Contas vencidas:\n\n" . implode("\n\n", $blocosV);

            case 'buscar':
                $termo = trim($consulta['termo'] ?? '');
                if ($termo === '') return "Preciso de um nome ou parte do nome pra buscar.";
                // Uma conta recorrente (ex: assinatura mensal) tem várias ocorrências futuras
                // já pré-geradas no banco — "ORDER BY MomentoRegistro DESC" pegava as mais
                // distantes no futuro em vez da atual, que é o que a pessoa quer quando
                // pergunta "de qual carteira é a conta X" ou "verificou a conta X?". Pendente
                // primeiro (mais próxima/vencida primeiro), efetivado como histórico depois.
                $stmt = $pdo->prepare("
                    SELECT r.Descricao, r.Valor, r.TipoRegistro, r.DataVencimento, r.StatusRegistro,
                           c.NomeCategoria, ca.TipoCarteira AS NomeCarteira
                    FROM Registro r
                    LEFT JOIN Categoria c ON c.IDCategoria = r.FKCategoria
                    LEFT JOIN Carteira  ca ON ca.IDCarteira = r.FKCarteira
                    WHERE r.FKUsuario = :uid AND r.StatusRegistro != 'cancelado' AND r.Descricao LIKE :termo
                    ORDER BY
                        CASE WHEN r.StatusRegistro = 'pendente' THEN 0 ELSE 1 END ASC,
                        CASE WHEN r.StatusRegistro = 'pendente' THEN r.DataVencimento END ASC,
                        r.MomentoRegistro DESC
                    LIMIT 5
                ");
                $stmt->execute([':uid' => $uid, ':termo' => '%' . $termo . '%']);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

                if (!$rows) return "Não encontrei nenhum lançamento com \"{$termo}\".";

                $linhas = array_map(fn($r) =>
                    ($r['TipoRegistro'] === 'receita' ? '📈' : '📉') . " *{$r['Descricao']}* — R$ " . number_format($r['Valor'], 2, ',', '.') .
                    " · carteira: {$r['NomeCarteira']}" .
                    ($r['NomeCategoria'] ? " · {$r['NomeCategoria']}" : '') .
                    ($r['DataVencimento'] ? ' · ' . date('d/m/Y', strtotime($r['DataVencimento'])) : '') .
                    " · {$r['StatusRegistro']}",
                    $rows
                );
                $introBusca = count($rows) === 1 ? "Encontrei:" : "Encontrei " . count($rows) . ":";
                return "{$introBusca}\n\n" . implode("\n", $linhas);

            case 'saldo':
                $stmt = $pdo->prepare("
                    SELECT TipoRegistro, SUM(Valor) AS Total FROM Registro
                    WHERE FKUsuario = :uid AND StatusRegistro = 'efetivado'
                      AND COALESCE(DataVencimento, DATE(MomentoRegistro)) BETWEEN :ini AND :fim
                      " . ($carteiraId ? "AND FKCarteira = :carteira" : "") . "
                    GROUP BY TipoRegistro
                ");
                $p = [':uid' => $uid, ':ini' => $ini, ':fim' => $fim];
                if ($carteiraId) $p[':carteira'] = $carteiraId;
                $stmt->execute($p);
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
                      " . ($carteiraId ? "AND r.FKCarteira = :carteira" : "") . "
                    ORDER BY r.MomentoRegistro DESC LIMIT 1
                ");
                $p = [':uid' => $uid];
                if ($carteiraId) $p[':carteira'] = $carteiraId;
                $stmt->execute($p);
                $r = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$r) return "Nenhum registro ainda.";

                $icon    = $r['TipoRegistro'] === 'receita' ? '📈' : '📉';
                $dataFmt = $r['DataVencimento'] ? date('d/m/Y', strtotime($r['DataVencimento'])) : '—';
                $catStr  = $r['NomeCategoria'] ? " · {$r['NomeCategoria']}" : '';
                return "Último: {$icon} *{$r['Descricao']}* — R$ " . number_format($r['Valor'], 2, ',', '.') .
                       " · {$dataFmt}{$catStr} · {$r['StatusRegistro']}";

            case 'meta':
                if (function_exists('garantirTabelaMetaCategoria')) garantirTabelaMetaCategoria($pdo);
                $catBusca = trim($consulta['categoria'] ?? '');

                if ($catBusca !== '') {
                    $stmt = $pdo->prepare("
                        SELECT c.NomeCategoria, m.ValorMeta, COALESCE(SUM(r.Valor), 0) AS Gasto
                        FROM MetaCategoria m
                        JOIN Categoria c ON c.IDCategoria = m.FKCategoria
                        LEFT JOIN Registro r ON r.FKCategoria = m.FKCategoria AND r.FKUsuario = m.FKUsuario
                               AND r.TipoRegistro = 'despesa' AND r.StatusRegistro = 'efetivado'
                               AND COALESCE(r.DataVencimento, DATE(r.MomentoRegistro)) BETWEEN :ini AND :fim
                        WHERE m.FKUsuario = :uid AND c.NomeCategoria LIKE :termo
                        GROUP BY m.FKCategoria, c.NomeCategoria, m.ValorMeta
                        LIMIT 1
                    ");
                    $stmt->execute([':uid' => $uid, ':ini' => $ini, ':fim' => $fim, ':termo' => '%' . $catBusca . '%']);
                    $r = $stmt->fetch(PDO::FETCH_ASSOC);

                    if (!$r) return "Não encontrei meta de orçamento definida pra \"{$catBusca}\". Dá pra configurar em Categorias, no site.";

                    $meta  = (float)$r['ValorMeta'];
                    $gasto = (float)$r['Gasto'];
                    $pct   = $meta > 0 ? ($gasto / $meta) * 100 : 0;
                    $emoji = $pct >= 100 ? '🔴' : ($pct >= 80 ? '🟡' : '🟢');
                    return "{$emoji} Meta de *{$r['NomeCategoria']}* {$label}:\n\n" .
                           "Gasto: R$ " . number_format($gasto, 2, ',', '.') . " de R$ " . number_format($meta, 2, ',', '.') .
                           " (" . number_format($pct, 0) . "%)";
                }

                $stmt = $pdo->prepare("
                    SELECT c.NomeCategoria, m.ValorMeta, COALESCE(SUM(r.Valor), 0) AS Gasto
                    FROM MetaCategoria m
                    JOIN Categoria c ON c.IDCategoria = m.FKCategoria
                    LEFT JOIN Registro r ON r.FKCategoria = m.FKCategoria AND r.FKUsuario = m.FKUsuario
                           AND r.TipoRegistro = 'despesa' AND r.StatusRegistro = 'efetivado'
                           AND COALESCE(r.DataVencimento, DATE(r.MomentoRegistro)) BETWEEN :ini AND :fim
                    WHERE m.FKUsuario = :uid
                    GROUP BY m.FKCategoria, c.NomeCategoria, m.ValorMeta
                ");
                $stmt->execute([':uid' => $uid, ':ini' => $ini, ':fim' => $fim]);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

                if (!$rows) return "Você ainda não definiu nenhuma meta de orçamento por categoria. Dá pra configurar em Categorias, no site.";

                usort($rows, fn($a, $b) =>
                    ((float)$b['Gasto'] / max((float)$b['ValorMeta'], 0.01)) <=> ((float)$a['Gasto'] / max((float)$a['ValorMeta'], 0.01))
                );

                $linhas = array_map(function ($r) {
                    $meta  = (float)$r['ValorMeta'];
                    $gasto = (float)$r['Gasto'];
                    $pct   = $meta > 0 ? ($gasto / $meta) * 100 : 0;
                    $emoji = $pct >= 100 ? '🔴' : ($pct >= 80 ? '🟡' : '🟢');
                    return "{$emoji} *{$r['NomeCategoria']}*: R$ " . number_format($gasto, 2, ',', '.') .
                           " de R$ " . number_format($meta, 2, ',', '.') . " (" . number_format($pct, 0) . "%)";
                }, $rows);

                return "Metas de orçamento {$label}:\n\n" . implode("\n", $linhas);

            default: // gastos
                $tipoQuery = $tipoReg ?? 'despesa';
                $stmt = $pdo->prepare("
                    SELECT c.NomeCategoria, SUM(r.Valor) AS Total
                    FROM Registro r LEFT JOIN Categoria c ON c.IDCategoria = r.FKCategoria
                    WHERE r.FKUsuario = :uid AND r.TipoRegistro = :tipo
                      AND r.StatusRegistro = 'efetivado'
                      AND COALESCE(r.DataVencimento, DATE(r.MomentoRegistro)) BETWEEN :ini AND :fim
                      " . ($carteiraId ? "AND r.FKCarteira = :carteira" : "") . "
                    GROUP BY r.FKCategoria ORDER BY Total DESC LIMIT 10
                ");
                $p = [':uid' => $uid, ':tipo' => $tipoQuery, ':ini' => $ini, ':fim' => $fim];
                if ($carteiraId) $p[':carteira'] = $carteiraId;
                $stmt->execute($p);
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

function _waEscalarSuporte(PDO $pdo, string $uid, array $acao, array $usuario, string $telefoneCliente): string
{
    $motivo = trim($acao['motivo'] ?? 'Sem detalhes — a IA sinalizou que precisa de ajuda humana.');
    $respostaCliente = trim($acao['resposta_cliente'] ?? "Entendi — já chamei o suporte aqui, alguém te retorna em breve. 🙏");

    // Evita bombardear o dono se a pessoa insistir várias vezes seguidas na mesma
    // conversa — só reenvia o alerta se já fez 15+ minutos desde o último dessa pessoa.
    $podeEnviar = true;
    try {
        $stmtChk = $pdo->prepare("SELECT Valor FROM ConfiguracaoSistema WHERE Chave = 'wa_ultimo_suporte' AND FKUsuario = :uid");
        $stmtChk->execute([':uid' => $uid]);
        $ultimo = $stmtChk->fetchColumn();
        if ($ultimo && strtotime($ultimo) > strtotime('-15 minutes')) $podeEnviar = false;
    } catch (Throwable $e) {}

    if (!$podeEnviar) {
        // Já tem um alerta recente pra essa pessoa — não é erro, é o cooldown funcionando
        // de propósito. Mas responder igual "já chamei" de novo confunde quem tá do outro
        // lado achando que nada aconteceu; deixa claro que já está encaminhado.
        return "Relaxa, já avisei o suporte sobre isso agora há pouco — não precisa repetir, assim que alguém puder já te chama por aqui. 🙏";
    }

    $msgDono = "🆘 *Alerta de suporte — Auralis*\n\n" .
               "Cliente: *{$usuario['Nome']}*\n" .
               "WhatsApp: {$telefoneCliente}\n\n" .
               "Situação: {$motivo}";
    // Mesmo número/config usada pelos outros avisos de admin (novo usuário, nova venda) —
    // um só lugar (ConfiguracaoSistema.telefone_admin_notificacoes) controla pra onde TODO
    // aviso de admin vai, em vez de números hardcoded separados podendo divergir sem ninguém notar.
    $numeroAdmin = function_exists('telefoneAdminNotificacoes') ? telefoneAdminNotificacoes($pdo) : '5517996660665';

    // Isolado num try/catch próprio: mesmo se o envio pro dono falhar por algum motivo,
    // o cliente ainda tem que receber a resposta tranquilizando ele — isso não pode
    // derrubar a função inteira. Loga a falha pra dar pra investigar depois (cPanel > Error
    // Log) em vez de ficar um "sumiço" silencioso sem nenhuma pista do que aconteceu.
    $okEnvio = false;
    try {
        $okEnvio = enviarWhatsAppNotificacao($numeroAdmin, $msgDono);
        if (!$okEnvio) {
            error_log("[Auralis WA] Falha ao enviar alerta de suporte pro admin ({$numeroAdmin}) — cliente {$usuario['Nome']} ({$telefoneCliente}).");
        }
    } catch (Throwable $e) {
        error_log("[Auralis WA] Exceção ao enviar alerta de suporte: " . $e->getMessage());
    }

    // Só grava o cooldown se o envio realmente saiu — se falhou, mais vale deixar a
    // próxima tentativa (dessa mesma pessoa ou outra) tentar de novo do que travar 15min
    // à toa achando que já foi avisado quando na verdade não chegou em lugar nenhum.
    if ($okEnvio) {
        $agora = date('Y-m-d H:i:s');
        // Mesmo padrão check-then-insert-or-update do resto do arquivo (_waSalvarPerfil) —
        // ConfiguracaoSistema não tem UNIQUE KEY em (Chave, FKUsuario), então ON DUPLICATE
        // KEY simplesmente não dispara e ficaria inserindo linha nova toda vez.
        try {
            $stmtChkIns = $pdo->prepare("SELECT COUNT(*) FROM ConfiguracaoSistema WHERE Chave = 'wa_ultimo_suporte' AND FKUsuario = :uid");
            $stmtChkIns->execute([':uid' => $uid]);
            if ($stmtChkIns->fetchColumn() > 0) {
                $pdo->prepare("UPDATE ConfiguracaoSistema SET Valor = :ts WHERE Chave = 'wa_ultimo_suporte' AND FKUsuario = :uid")
                    ->execute([':ts' => $agora, ':uid' => $uid]);
            } else {
                $pdo->prepare("INSERT INTO ConfiguracaoSistema (Chave, Valor, FKUsuario) VALUES ('wa_ultimo_suporte', :ts, :uid)")
                    ->execute([':ts' => $agora, ':uid' => $uid]);
            }
        } catch (Throwable $e) {}
    }

    return $respostaCliente;
}

function _waAjuda(string $personalidade = 'parceiro'): string
{
    if ($personalidade === 'profissional') {
        return "Comandos disponíveis:\n\n" .
               "• Registrar: \"Paguei 50 de uber\"\n" .
               "• Efetivar pendente: \"Paguei o Spotify\"\n" .
               "• Desfazer pagamento: \"Não paguei o Spotify ainda\"\n" .
               "• Editar: \"O lançamento era de 80, não 50\" / \"Muda a data pra dia 5\"\n" .
               "• Parcelamento: \"Comprei em 12x de 150\" / \"3000 em 10x com juros, fica 3300\"\n" .
               "• Recorrente: \"Todo mês pago 40 de academia dia 10\"\n" .
               "• Múltiplos: \"Paguei 50 de uber e 30 de almoço\"\n" .
               "• Cofrinho: \"Deposita 200 no cofrinho Viagem\"\n" .
               "• Criar cofrinho: \"Cria cofrinho Carro com meta 6000\"\n" .
               "• Consultar: \"Quanto gastei esse mês?\"\n" .
               "• Saldo: \"Qual meu saldo?\"\n" .
               "• Pendentes: \"Tenho conta pra pagar?\"\n" .
               "• Cancelar último: \"Cancela o último lançamento\"\n" .
               "• Comprovantes: envie a foto diretamente\n" .
               "• Falar com suporte: peça diretamente a qualquer momento";
    }

    return "Opa! 👋 Pode mandar à vontade:\n\n" .
           "📝 *Registrar:*\n" .
           "• _\"Paguei 55 no corte de cabelo\"_\n" .
           "• _\"Recebi 2000 de salário hoje\"_\n" .
           "• _\"Comprei celular em 12x de 150\"_\n" .
           "• _\"3000 em 10x com juros, fica 3300\"_\n" .
           "• _\"Todo mês pago 40 de academia dia 10\"_ (recorrente)\n" .
           "• _\"Paguei 50 de uber e 30 de almoço\"_\n" .
           "• _[foto de comprovante PIX]_\n\n" .
           "✅ *Efetivar / desfazer:*\n" .
           "• _\"Paguei o Spotify\"_ / _\"Recebi o salário\"_\n" .
           "• _\"Não paguei o Spotify ainda\"_ (volta pra pendente)\n\n" .
           "✏️ *Editar um lançamento:*\n" .
           "• _\"Era outra coisa, muda o nome pra Mercado\"_\n" .
           "• _\"O valor tá errado, era 80\"_ / _\"Muda a data da parcela 2 pra dia 5\"_\n\n" .
           "🏦 *Cofrinhos:*\n" .
           "• _\"Deposita 200 no cofrinho Viagem\"_\n" .
           "• _\"Cria cofrinho Carro com meta 6000\"_\n\n" .
           "📊 *Consultar:*\n" .
           "• _\"Quanto gastei esse mês?\"_\n" .
           "• _\"Tenho conta pra pagar essa semana?\"_\n" .
           "• _\"Qual meu saldo?\"_\n\n" .
           "↩️ *Cancelar o último lançamento:*\n" .
           "• _\"Cancela o último lançamento\"_\n\n" .
           "🆘 *Precisa falar com gente de verdade?* É só pedir a qualquer momento.";
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

// Mensagem enviada pelo próprio número da empresa (fromMe=true) — dispara tanto quando é
// a IA/um cron respondendo via API quanto quando um admin digita direto no app do
// WhatsApp Business. Sem diferenciar os dois, qualquer resposta da própria IA se
// autopausaria pra sempre (ela ia "ver" o próprio eco e achar que foi um humano). A pista
// que já existe pra separar: toda resposta da IA e todo aviso automático de cron já grava
// uma linha Role='model' em MensagemWA na hora que é enviada (ver _waSaveHistory e
// registrarMensagemWaSistema) — se não tem nenhuma bem recente pra esse cliente, foi um
// humano de verdade digitando.
function _waTratarMensagemFromMe(PDO $pdo, string $remoteJid, string $textoFromMe): void
{
    $telefone = preg_replace('/\D/', '', explode('@', $remoteJid)[0]);
    if (strlen($telefone) < 10) return;

    try {
        $stmtU = $pdo->prepare("SELECT IDUsuario FROM Usuario WHERE Telefone = :tel AND StatusConta = 'ativo' LIMIT 1");
        $stmtU->execute([':tel' => $telefone]);
        $uid = $stmtU->fetchColumn();
        if (!$uid) return;

        // Comando manual pra devolver a conversa pra IA na hora, sem esperar o prazo da
        // pausa acabar — manda só "🤖" (sozinho, nada mais) na própria conversa com o
        // cliente. Curto de propósito: é a única forma de "comando" possível aqui, já que
        // não existe canal separado — o que for digitado sai direto pro cliente ver.
        if (trim($textoFromMe) === '🤖') {
            $pdo->prepare("DELETE FROM ConfiguracaoSistema WHERE Chave = 'wa_pausado_ate' AND FKUsuario = :uid")
                ->execute([':uid' => $uid]);
            return;
        }

        _waGarantirTabela($pdo);

        $stmtRecente = $pdo->prepare("
            SELECT COUNT(*) FROM MensagemWA
            WHERE FKUsuario = :uid AND Role = 'model' AND CriadoEm > DATE_SUB(NOW(), INTERVAL 15 SECOND)
        ");
        $stmtRecente->execute([':uid' => $uid]);
        if ((int)$stmtRecente->fetchColumn() > 0) return; // eco da própria IA/cron, ignora

        // Humano de verdade assumiu a conversa — pausa a IA pra esse cliente. Cada nova
        // mensagem manual "renova" o prazo (check-then-insert-or-update, mesmo padrão do
        // resto do arquivo — ConfiguracaoSistema não tem UNIQUE KEY em Chave+FKUsuario).
        // 30min e não algo maior tipo 2h: se o cliente mandar uma pergunta nova e sem
        // relação nenhuma horas depois de você já ter resolvido o assunto, ele não pode
        // ficar sem nenhuma resposta até o prazo acabar — 30min cobre bem uma troca ativa
        // de mensagens, e dá pra encerrar antes mandando "🤖" assim que terminar de atender.
        $ate = date('Y-m-d H:i:s', strtotime('+30 minutes'));
        $stmtChk = $pdo->prepare("SELECT COUNT(*) FROM ConfiguracaoSistema WHERE Chave = 'wa_pausado_ate' AND FKUsuario = :uid");
        $stmtChk->execute([':uid' => $uid]);
        if ($stmtChk->fetchColumn() > 0) {
            $pdo->prepare("UPDATE ConfiguracaoSistema SET Valor = :ate WHERE Chave = 'wa_pausado_ate' AND FKUsuario = :uid")
                ->execute([':ate' => $ate, ':uid' => $uid]);
        } else {
            $pdo->prepare("INSERT INTO ConfiguracaoSistema (Chave, Valor, FKUsuario) VALUES ('wa_pausado_ate', :ate, :uid)")
                ->execute([':ate' => $ate, ':uid' => $uid]);
        }
    } catch (Throwable $e) {}
}

// Detecta pedido de "para de me mandar mensagem" — checado fora do fluxo do Gemini
// (precisa funcionar até pra quem não tem acesso à IA). Cobre tanto o comando seco
// que qualquer disparo em massa brasileiro já ensinou o usuário a mandar ("PARAR")
// quanto frases mais naturais próximas de palavras de mensagem/lembrete, evitando
// falso positivo em frases financeiras comuns tipo "parar de gastar".
function _waEhPedidoDeParar(string $texto): bool
{
    $norm = mb_strtolower(trim($texto));
    if ($norm === '') return false;
    if (in_array($norm, ['parar', 'pare', 'stop', 'cancelar', 'sair'], true)) return true;
    return (bool)preg_match(
        '/\b(par[ae]|cancelar|não quero mais|nao quero mais)\b[^.!?]{0,25}\b(mensage|mandar|manda|receber|lembrete|notifica|zap|whats)/u',
        $norm
    );
}

// Contraparte do opt-out — precisa ser um pedido explícito (não qualquer mensagem
// aleatória), pra bater com o que a gente promete na resposta de _waEhPedidoDeParar.
function _waEhPedidoDeVoltar(string $texto): bool
{
    $norm = mb_strtolower(trim($texto));
    if ($norm === '') return false;
    return (bool)preg_match(
        '/\b(voltar|reativar|ativar)\b[^.!?]{0,25}\b(receber|mensage|lembrete|notifica)/u',
        $norm
    );
}

// Liga/desliga o "não quero mais lembrete automático" — só afeta os nudges de
// cron/whatsapp_engajamento.php, não avisos essenciais (fatura, pagamento etc).
// Desligar (opt-out) é permanente até um pedido explícito de volta — não reativamos
// sozinhos só porque a pessoa voltou a usar o app ou mandou qualquer outra mensagem.
// Reativar também zera a contagem de tentativas, pra dar um ciclo de backoff novo
// em vez de herdar o desgaste de antes do opt-out.
function _waMarcarOptOutEngajamento(PDO $pdo, string $uid, bool $optOut): void
{
    $valorOut  = $optOut ? '1' : '0';
    try {
        foreach (['wa_engajamento_opt_out' => $valorOut, 'wa_engajamento_contagem' => '0'] as $chave => $valor) {
            if ($chave === 'wa_engajamento_contagem' && $optOut) continue; // só zera contagem ao reativar
            $existe = $pdo->prepare("SELECT 1 FROM ConfiguracaoSistema WHERE Chave = :chave AND FKUsuario = :uid");
            $existe->execute([':chave' => $chave, ':uid' => $uid]);
            if ($existe->fetchColumn()) {
                $pdo->prepare("UPDATE ConfiguracaoSistema SET Valor = :v WHERE Chave = :chave AND FKUsuario = :uid")
                    ->execute([':v' => $valor, ':chave' => $chave, ':uid' => $uid]);
            } else {
                $pdo->prepare("INSERT INTO ConfiguracaoSistema (Chave, Valor, FKUsuario) VALUES (:chave, :v, :uid)")
                    ->execute([':chave' => $chave, ':v' => $valor, ':uid' => $uid]);
            }
        }
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
    global $pdo;
    $apiKey = evolutionApiKey($pdo);
    if ($apiKey === '') return null;

    $url  = 'https://evolution.meuauralis.com/chat/getBase64FromMediaMessage/Auralis';
    $body = json_encode(['message' => ['key' => $data['key'] ?? [], 'message' => $data['message'] ?? []]]);
    $ctx  = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/json\r\napikey: {$apiKey}\r\n",
        'content' => $body, 'timeout' => 15, 'ignore_errors' => true,
    ]]);
    $resp = @file_get_contents($url, false, $ctx);
    if (!$resp) return null;
    return json_decode($resp, true)['base64'] ?? null;
}
