<?php
// cron/whatsapp_engajamento.php
//
// Detecta usuários inativos há mais de 48h e envia nudge pelo WhatsApp.
// Só dispara se o usuário tem telefone cadastrado, tem pelo menos 1 registro,
// não pediu pra parar de receber (opt-out) e o intervalo desde o último nudge
// já bateu o backoff da tentativa atual (ver $BACKOFF_DIAS abaixo).
//
// Backoff escalonado + parada definitiva: pra não ficar insistindo pra sempre
// com quem nunca responde (risco real de ban no WhatsApp em escala), o intervalo
// entre lembretes cresce a cada tentativa sem resposta, e depois de
// count($BACKOFF_DIAS) tentativas sem NENHUM sinal de engajamento (nem resposta
// no zap, nem novo lançamento no app), o sistema para de mandar sozinho — só
// volta a mandar se a pessoa voltar a usar o Auralis (aí o ciclo reinicia do zero).
// Um "PARAR" respondido a qualquer momento (ver _waEhPedidoDeParar em
// webhook_whatsapp_ia.php) já corta isso na hora, independente da contagem.
//
// Configurar no cPanel > Trabalhos Cron — 1x por dia às 10h:
//   Minuto=0  Hora=10  Dia=*  Mês=*  Dia da semana=*
//   Comando: /usr/local/bin/php /home/gust9360/public_html/cron/whatsapp_engajamento.php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('forbidden');
}

require_once __DIR__ . '/../config/conexao.php';
require_once __DIR__ . '/../config/funcoes.php';

// Intervalo mínimo (em dias) antes de CADA tentativa, indexado pela contagem atual
// de tentativas sem resposta (0 = ainda não mandou nenhuma). Depois da última
// posição, não manda mais — é o "desistiu" definitivo.
$BACKOFF_DIAS = [2, 5, 10, 20];

// ── Busca candidatos ──────────────────────────────────────────────────────────
// Filtro no banco é só "inativo há 48h+, com telefone, sem opt-out" — o cálculo
// fino de quanto tempo falta pro próximo nudge (que depende da contagem de cada
// pessoa) é feito em PHP logo abaixo, já que varia por usuário.

try {
    $stmt = $pdo->prepare("
        SELECT
            u.IDUsuario,
            u.Nome,
            u.Telefone,
            MAX(r.MomentoRegistro)     AS UltimoRegistro,
            cs_pers.Valor              AS Personalidade,
            cs_perf.Valor              AS PerfilIA,
            cs_eng.Valor               AS UltimoEngajamento,
            cs_cont.Valor              AS Contagem,
            (SELECT MAX(m.CriadoEm) FROM MensagemWA m WHERE m.FKUsuario = u.IDUsuario AND m.Role = 'user') AS UltimaRespostaWA
        FROM Usuario u
        LEFT JOIN Registro r
               ON r.FKUsuario = u.IDUsuario
        LEFT JOIN ConfiguracaoSistema cs_pers
               ON cs_pers.FKUsuario = u.IDUsuario AND cs_pers.Chave = 'wa_personalidade'
        LEFT JOIN ConfiguracaoSistema cs_perf
               ON cs_perf.FKUsuario = u.IDUsuario AND cs_perf.Chave = 'wa_perfil_ia'
        LEFT JOIN ConfiguracaoSistema cs_eng
               ON cs_eng.FKUsuario  = u.IDUsuario AND cs_eng.Chave  = 'wa_ultimo_engajamento'
        LEFT JOIN ConfiguracaoSistema cs_cont
               ON cs_cont.FKUsuario = u.IDUsuario AND cs_cont.Chave = 'wa_engajamento_contagem'
        LEFT JOIN ConfiguracaoSistema cs_out
               ON cs_out.FKUsuario  = u.IDUsuario AND cs_out.Chave  = 'wa_engajamento_opt_out'
        WHERE u.Telefone    IS NOT NULL
          AND u.StatusConta = 'ativo'
          AND (cs_out.Valor IS NULL OR cs_out.Valor != '1')
        GROUP BY u.IDUsuario, u.Nome, u.Telefone,
                 cs_pers.Valor, cs_perf.Valor, cs_eng.Valor, cs_cont.Valor
        HAVING
            MAX(r.MomentoRegistro) IS NOT NULL
            AND MAX(r.MomentoRegistro) < DATE_SUB(NOW(), INTERVAL 48 HOUR)
    ");
    $stmt->execute();
    $candidatos = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    fwrite(STDERR, 'Erro ao buscar candidatos: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

if (!$candidatos) {
    echo "Engajamento: nenhum usuário inativo para notificar." . PHP_EOL;
    exit(0);
}

// ── Upsert de config genérico (contagem + timestamp) ─────────────────────────

function _engajamentoSalvarConfig(PDO $pdo, string $uid, string $chave, string $valor): void
{
    try {
        $chk = $pdo->prepare("SELECT 1 FROM ConfiguracaoSistema WHERE Chave = :chave AND FKUsuario = :uid");
        $chk->execute([':chave' => $chave, ':uid' => $uid]);
        if ($chk->fetchColumn()) {
            $pdo->prepare("UPDATE ConfiguracaoSistema SET Valor = :v WHERE Chave = :chave AND FKUsuario = :uid")
                ->execute([':v' => $valor, ':chave' => $chave, ':uid' => $uid]);
        } else {
            $pdo->prepare("INSERT INTO ConfiguracaoSistema (Chave, Valor, FKUsuario) VALUES (:chave, :v, :uid)")
                ->execute([':chave' => $chave, ':v' => $valor, ':uid' => $uid]);
        }
    } catch (PDOException $e) {
        fwrite(STDERR, "Erro ao salvar {$chave}: " . $e->getMessage() . PHP_EOL);
    }
}

// ── Monta e envia mensagens ───────────────────────────────────────────────────

$enviados = 0;
$pulados  = 0;
$agora    = date('Y-m-d H:i:s');

foreach ($candidatos as $u) {
    $telefone      = $u['Telefone'];
    $personalidade = $u['Personalidade'] ?? 'parceiro';
    $contagem      = (int)($u['Contagem'] ?? 0);

    // Sinal de engajamento desde o último nudge (respondeu no zap OU voltou a
    // lançar algo no app) reinicia a contagem — a pessoa provou que ainda tá ali,
    // merece o ciclo de tentativas do zero em vez de herdar o desgaste anterior.
    $ultimoEng = $u['UltimoEngajamento'] ?? null;
    if ($ultimoEng) {
        $respondeuZap = !empty($u['UltimaRespostaWA']) && $u['UltimaRespostaWA'] > $ultimoEng;
        $voltouAoApp  = !empty($u['UltimoRegistro']) && $u['UltimoRegistro'] > $ultimoEng;
        if ($respondeuZap || $voltouAoApp) $contagem = 0;
    }

    // Já esgotou as tentativas do backoff sem nenhum sinal de vida — para de insistir
    // sozinho. Só volta a mandar se a pessoa reaparecer (o que reseta a contagem acima).
    if ($contagem >= count($BACKOFF_DIAS)) {
        $pulados++;
        continue;
    }

    // Intervalo mínimo pra ESSA tentativa específica ainda não passou
    $diasNecessarios = $BACKOFF_DIAS[$contagem];
    if ($ultimoEng && strtotime($ultimoEng) > strtotime("-{$diasNecessarios} days")) {
        $pulados++;
        continue;
    }

    // Extrai apelido do perfil da IA, fallback pro primeiro nome
    $apelido = explode(' ', $u['Nome'])[0];
    if (!empty($u['PerfilIA'])) {
        $perfil  = json_decode($u['PerfilIA'], true);
        if (!empty($perfil['apelido'])) $apelido = $perfil['apelido'];
    }

    // Calcula há quantas horas foi o último registro
    $horasInativo = (int)round((time() - strtotime($u['UltimoRegistro'])) / 3600);
    $labelTempo   = $horasInativo >= 72
        ? round($horasInativo / 24) . ' dias'
        : $horasInativo . 'h';

    // "Me manda que eu registro pra você" só é verdade pra quem tem acesso à IA do
    // WhatsApp (VIP/teste grátis — mesmo gate de webhook_whatsapp_ia.php). Pra quem já
    // caiu pro Free (ou é Pro), essa inatividade vira gancho de upsell pro VIP em vez de
    // uma promessa que ia esbarrar no paywall se a pessoa respondesse.
    $planoEfetivo = planoEfetivoUsuario($pdo, $u['IDUsuario']);
    $temAcessoIA  = in_array($planoEfetivo, ['vip', 'vip_trial'], true);
    $mensagem     = $temAcessoIA
        ? _montarMensagemEngajamento($apelido, $labelTempo, $personalidade)
        : _montarMensagemUpsellEngajamento($apelido, $labelTempo, $personalidade);

    $ok = enviarWhatsAppNotificacao($telefone, $mensagem);

    if ($ok) {
        _engajamentoSalvarConfig($pdo, $u['IDUsuario'], 'wa_ultimo_engajamento', $agora);
        _engajamentoSalvarConfig($pdo, $u['IDUsuario'], 'wa_engajamento_contagem', (string)($contagem + 1));
        $enviados++;
    }

    $tentativaLabel = ($contagem + 1) . '/' . count($BACKOFF_DIAS);
    echo "  → {$u['Nome']} ({$telefone}): " . ($ok ? "enviado (tentativa {$tentativaLabel}, {$labelTempo} inativo)" : "falhou") . PHP_EOL;
}

echo PHP_EOL . "Engajamento: {$enviados} enviado(s), {$pulados} pulado(s) (fora do backoff ou já no limite de tentativas) de " . count($candidatos) . " inativo(s)." . PHP_EOL;

// ── Função de mensagem ────────────────────────────────────────────────────────

function _montarMensagemEngajamento(string $apelido, string $labelTempo, string $personalidade): string
{
    $variacoes = $personalidade === 'profissional'
        ? [
            "Olá, {$apelido}. Não identificamos registros há {$labelTempo} no Auralis. Deseja lançar alguma movimentação? Basta responder aqui.",
            "Olá, {$apelido}. Há {$labelTempo} sem registros no Auralis. Posso registrar algo para você agora?",
            "Bom dia, {$apelido}. Percebemos {$labelTempo} sem movimentações. Se houver algo a registrar, é só responder.",
        ]
        : [
            "Fala, {$apelido}! 👋 Vi que faz {$labelTempo} sem nenhum registro no Auralis. Aconteceu alguma coisa nesses dias? Me manda aqui que eu anoto na hora! 📝",
            "Ei, {$apelido}! 😄 Tô aqui te esperando há {$labelTempo}. Não rolou nenhuma despesa, receita, nada? Me conta que eu registro pra você!",
            "Oi, {$apelido}! 👀 Sumiu! Faz {$labelTempo} sem movimento por aqui. Tem alguma coisa pra anotar? Pode mandar que cuido de tudo 💪",
            "Olha, {$apelido}, to desconfiado de você 😂 Faz {$labelTempo} sem nenhum lançamento. Impossível não ter gastado nada! Me conta 👇",
        ];

    // Sorteia uma variação para não parecer sempre a mesma mensagem
    return $variacoes[array_rand($variacoes)];
}

// Variante pra quem não tem acesso à IA do WhatsApp (Free ou Pro) — mesma inatividade,
// mas em vez de prometer "eu registro pra você" (não funciona sem VIP), usa o momento
// pra apresentar o recurso como incentivo de upgrade.
function _montarMensagemUpsellEngajamento(string $apelido, string $labelTempo, string $personalidade): string
{
    $variacoes = $personalidade === 'profissional'
        ? [
            "Olá, {$apelido}. Notamos {$labelTempo} sem registros no Auralis. No plano VIP, você pode enviar seus lançamentos diretamente por aqui, sem precisar abrir o aplicativo. Conheça: meuauralis.com/planos.php",
            "Olá, {$apelido}. Há {$labelTempo} sem movimentações registradas. O plano VIP permite lançar despesas e receitas por mensagem, direto pelo WhatsApp. Mais detalhes: meuauralis.com/planos.php",
        ]
        : [
            "Fala, {$apelido}! 👋 Faz {$labelTempo} sem nada novo no Auralis. Sabia que no *VIP* eu registro tudo pra você na hora, só de mandar mensagem aqui? Dá uma olhada: meuauralis.com/planos.php 💜",
            "Ei, {$apelido}! Tô sentindo sua falta há {$labelTempo} 😅 No plano *VIP* eu viro seu assistente aqui no zap — você me manda, eu registro, sem abrir o app. Vale a pena conferir: meuauralis.com/planos.php",
            "Oi, {$apelido}! 👀 Faz {$labelTempo} sem movimento. Ativando o *VIP* eu passo a lançar suas contas direto por aqui, na conversa mesmo. Dá uma olhada: meuauralis.com/planos.php",
        ];

    return $variacoes[array_rand($variacoes)];
}
