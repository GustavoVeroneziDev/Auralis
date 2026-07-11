<?php
// cron/whatsapp_plano_vencendo.php
//
// Avisa por WhatsApp assinantes Pro/VIP com assinatura ativa vencendo em 2
// dias — dá tempo de renovar manualmente (Pix) ou conferir o cartão (cobrança
// automática MP) antes de cair pro plano Free. Roda 1x por dia.
//
// Configurar no cPanel > Trabalhos Cron:
//   Minuto=0  Hora=11  Dia=*  Mês=*  Dia da semana=*
//   Comando: /usr/local/bin/php /home/gust9360/public_html/cron/whatsapp_plano_vencendo.php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('forbidden');
}

require_once __DIR__ . '/../config/conexao.php';
require_once __DIR__ . '/../config/funcoes.php';

try {
    $stmt = $pdo->prepare("
        SELECT
            a.FKUsuario,
            a.Plano,
            a.DataExpiracao,
            a.IDAssinaturaGW,
            u.Nome,
            u.Telefone
        FROM Assinatura a
        JOIN Usuario u ON u.IDUsuario = a.FKUsuario
        LEFT JOIN ConfiguracaoSistema cs
               ON cs.FKUsuario = a.FKUsuario AND cs.Chave = 'wa_plano_vencendo_avisado'
        WHERE a.Status = 'ativa'
          AND u.Telefone IS NOT NULL
          AND DATE(a.DataExpiracao) = CURDATE() + INTERVAL 2 DAY
          AND (cs.Valor IS NULL OR DATE(cs.Valor) != CURDATE())
    ");
    $stmt->execute();
    $assinaturas = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    fwrite(STDERR, 'Erro ao buscar assinaturas vencendo: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

// Mesmo padrão check-then-insert-or-update do resto do arquivo — ConfiguracaoSistema
// não tem UNIQUE KEY em (Chave, FKUsuario). Guarda a data do último aviso: se o
// mesmo usuário vencer de novo daqui a meses, o (cs.Valor IS NULL OR DATE != hoje)
// da query acima libera um novo aviso normalmente.
$stmtChk = $pdo->prepare("SELECT COUNT(*) FROM ConfiguracaoSistema WHERE Chave = 'wa_plano_vencendo_avisado' AND FKUsuario = :uid");
$stmtIns = $pdo->prepare("INSERT INTO ConfiguracaoSistema (Chave, Valor, FKUsuario) VALUES ('wa_plano_vencendo_avisado', :ts, :uid)");
$stmtUpd = $pdo->prepare("UPDATE ConfiguracaoSistema SET Valor = :ts WHERE Chave = 'wa_plano_vencendo_avisado' AND FKUsuario = :uid");

$enviados = 0;
$agora    = date('Y-m-d H:i:s');

foreach ($assinaturas as $a) {
    $plano        = strtoupper($a['Plano']);
    $primeiroNome = explode(' ', $a['Nome'])[0];
    $ehPix        = strncmp((string)$a['IDAssinaturaGW'], 'pix_', 4) === 0;

    if ($ehPix) {
        $mensagem = "Oi, {$primeiroNome}! 👋\n\nSua assinatura *{$plano}* vence em *2 dias*. "
                  . "Como foi paga via Pix, a renovação não é automática — acesse a página de planos e gere um novo Pix pra continuar sem interrupção:\nmeuauralis.com/planos.php";
    } else {
        $mensagem = "Oi, {$primeiroNome}! 👋\n\nSó um aviso: sua assinatura *{$plano}* vence em *2 dias*. "
                  . "A renovação é automática no seu cartão — se estiver tudo certo por lá, não precisa fazer nada. "
                  . "Se quiser conferir ou trocar a forma de pagamento, acesse:\nmeuauralis.com/planos.php";
    }

    $ok = enviarWhatsAppNotificacao($a['Telefone'], $mensagem);

    if ($ok) {
        try {
            $stmtChk->execute([':uid' => $a['FKUsuario']]);
            if ($stmtChk->fetchColumn() > 0) {
                $stmtUpd->execute([':ts' => $agora, ':uid' => $a['FKUsuario']]);
            } else {
                $stmtIns->execute([':ts' => $agora, ':uid' => $a['FKUsuario']]);
            }
        } catch (PDOException $e) {}
        $enviados++;
    }
}

echo "Plano vencendo: " . count($assinaturas) . " assinatura(s), {$enviados} aviso(s) enviado(s)." . PHP_EOL;
