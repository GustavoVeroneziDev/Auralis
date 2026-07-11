<?php
// cron/whatsapp_trial_acabando.php
//
// Avisa por WhatsApp quem está no teste grátis (plano free, primeiras 50h de
// conta) e está a menos de 24h de o teste acabar. Roda 1x por dia.
//
// Configurar no cPanel > Trabalhos Cron:
//   Minuto=0  Hora=11  Dia=*  Mês=*  Dia da semana=*
//   Comando: /usr/local/bin/php /home/gust9360/public_html/cron/whatsapp_trial_acabando.php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('forbidden');
}

require_once __DIR__ . '/../config/conexao.php';
require_once __DIR__ . '/../config/funcoes.php';

$horasTrial = limitesDoPlano('free')['horas_teste'] ?? 50;

try {
    $stmt = $pdo->prepare("
        SELECT
            u.IDUsuario,
            u.Nome,
            u.Telefone,
            TIMESTAMPDIFF(HOUR, u.MomentoCriacao, NOW()) AS HorasDecorridas
        FROM Usuario u
        LEFT JOIN ConfiguracaoSistema cs
               ON cs.FKUsuario = u.IDUsuario AND cs.Chave = 'wa_trial_avisado'
        WHERE u.Plano = 'free'
          AND u.StatusConta = 'ativo'
          AND u.Telefone IS NOT NULL
          AND cs.Valor IS NULL
          AND TIMESTAMPDIFF(HOUR, u.MomentoCriacao, NOW()) BETWEEN :minH AND :maxH
    ");
    $stmt->execute([
        ':minH' => $horasTrial - 24,
        ':maxH' => $horasTrial - 1,
    ]);
    $candidatos = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    fwrite(STDERR, 'Erro ao buscar usuários em trial: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

// Mesmo padrão check-then-insert-or-update do resto do arquivo (whatsapp_engajamento.php) —
// ConfiguracaoSistema não tem UNIQUE KEY em (Chave, FKUsuario).
$stmtChk = $pdo->prepare("SELECT COUNT(*) FROM ConfiguracaoSistema WHERE Chave = 'wa_trial_avisado' AND FKUsuario = :uid");
$stmtIns = $pdo->prepare("INSERT INTO ConfiguracaoSistema (Chave, Valor, FKUsuario) VALUES ('wa_trial_avisado', :ts, :uid)");

$enviados = 0;
$agora    = date('Y-m-d H:i:s');

foreach ($candidatos as $u) {
    $horasRestantes = max(1, $horasTrial - (int)$u['HorasDecorridas']);
    $primeiroNome   = explode(' ', $u['Nome'])[0];

    $mensagem = "Oi, {$primeiroNome}! 👋\n\n"
              . "Seu *teste grátis do Auralis* termina em menos de {$horasRestantes}h. "
              . "Depois disso sua conta continua funcionando no plano *Free*, só com menos recursos liberados (sem Agenda, Análises e outros extras).\n\n"
              . "Se estiver curtindo, dá uma olhada nos planos Pro e VIP pra manter tudo liberado sem interrupção:\nmeuauralis.com/planos.php\n\n"
              . "Qualquer dúvida, só chamar por aqui! 🙂";

    $ok = enviarWhatsAppNotificacao($u['Telefone'], $mensagem);

    if ($ok) {
        try {
            $stmtIns->execute([':ts' => $agora, ':uid' => $u['IDUsuario']]);
        } catch (PDOException $e) {}
        $enviados++;
    }
}

echo "Trial: " . count($candidatos) . " candidato(s), {$enviados} aviso(s) enviado(s)." . PHP_EOL;
