<?php
// cron/whatsapp_engajamento.php
//
// Detecta usuários inativos há mais de 48h e envia nudge pelo WhatsApp.
// Só dispara se o usuário tem telefone cadastrado, tem pelo menos 1 registro
// e não recebeu esse lembrete nas últimas 48h.
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

// ── Busca candidatos ──────────────────────────────────────────────────────────

try {
    $stmt = $pdo->prepare("
        SELECT
            u.IDUsuario,
            u.Nome,
            u.Telefone,
            MAX(r.MomentoRegistro) AS UltimoRegistro,
            cs_pers.Valor          AS Personalidade,
            cs_perf.Valor          AS PerfilIA,
            cs_eng.Valor           AS UltimoEngajamento
        FROM Usuario u
        LEFT JOIN Registro r
               ON r.FKUsuario = u.IDUsuario
        LEFT JOIN ConfiguracaoSistema cs_pers
               ON cs_pers.FKUsuario = u.IDUsuario AND cs_pers.Chave = 'wa_personalidade'
        LEFT JOIN ConfiguracaoSistema cs_perf
               ON cs_perf.FKUsuario = u.IDUsuario AND cs_perf.Chave = 'wa_perfil_ia'
        LEFT JOIN ConfiguracaoSistema cs_eng
               ON cs_eng.FKUsuario  = u.IDUsuario AND cs_eng.Chave  = 'wa_ultimo_engajamento'
        WHERE u.Telefone    IS NOT NULL
          AND u.StatusConta = 'ativo'
        GROUP BY u.IDUsuario, u.Nome, u.Telefone,
                 cs_pers.Valor, cs_perf.Valor, cs_eng.Valor
        HAVING
            MAX(r.MomentoRegistro) IS NOT NULL
            AND MAX(r.MomentoRegistro) < DATE_SUB(NOW(), INTERVAL 48 HOUR)
            AND (
                cs_eng.Valor IS NULL
                OR cs_eng.Valor < DATE_SUB(NOW(), INTERVAL 48 HOUR)
            )
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

// ── Upsert do timestamp de engajamento ───────────────────────────────────────

$stmtChk = $pdo->prepare(
    "SELECT COUNT(*) FROM ConfiguracaoSistema WHERE Chave = 'wa_ultimo_engajamento' AND FKUsuario = :uid"
);
$stmtIns = $pdo->prepare(
    "INSERT INTO ConfiguracaoSistema (Chave, Valor, FKUsuario) VALUES ('wa_ultimo_engajamento', :ts, :uid)"
);
$stmtUpd = $pdo->prepare(
    "UPDATE ConfiguracaoSistema SET Valor = :ts WHERE Chave = 'wa_ultimo_engajamento' AND FKUsuario = :uid"
);

// ── Monta e envia mensagens ───────────────────────────────────────────────────

$enviados  = 0;
$agora     = date('Y-m-d H:i:s');

foreach ($candidatos as $u) {
    $telefone      = $u['Telefone'];
    $personalidade = $u['Personalidade'] ?? 'parceiro';

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

    // Mensagem adaptada à personalidade
    $mensagem = _montarMensagemEngajamento($apelido, $labelTempo, $personalidade);

    $ok = enviarWhatsAppNotificacao($telefone, $mensagem);

    if ($ok) {
        // Marca timestamp do engajamento
        try {
            $stmtChk->execute([':uid' => $u['IDUsuario']]);
            if ($stmtChk->fetchColumn() > 0) {
                $stmtUpd->execute([':ts' => $agora, ':uid' => $u['IDUsuario']]);
            } else {
                $stmtIns->execute([':ts' => $agora, ':uid' => $u['IDUsuario']]);
            }
        } catch (PDOException $e) {
            fwrite(STDERR, 'Erro ao salvar timestamp: ' . $e->getMessage() . PHP_EOL);
        }
        $enviados++;
    }

    echo "  → {$u['Nome']} ({$telefone}): " . ($ok ? "enviado ({$labelTempo} inativo)" : "falhou") . PHP_EOL;
}

echo PHP_EOL . "Engajamento: {$enviados}/" . count($candidatos) . " mensagens enviadas." . PHP_EOL;

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
