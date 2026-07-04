<?php
// cron/verificar_assinaturas_pix_vencendo.php
//
// Assinaturas pagas via link de pagamento fixo (planos.php "Pagar com Pix")
// não renovam sozinhas — diferente do cartão, que é cobrado automaticamente
// pelo MP. Esse cron avisa a pessoa 3 dias e 1 dia antes de vencer, pra ela
// renovar manualmente a tempo. Roda 1x por dia.
//
// Configurar no cPanel > Trabalhos Cron, junto com o de vencimentos de contas:
//   Minuto=0  Hora=12  Dia=*  Mês=*  Dia da semana=*
//   Comando: /usr/local/bin/php /home/gust9360/public_html/cron/verificar_assinaturas_pix_vencendo.php

require_once __DIR__ . '/../config/vapid_keys.php';
if (PHP_SAPI !== 'cli') {
    $tokenRecebido = $_GET['token'] ?? '';
    if (!isset($cronToken) || !hash_equals($cronToken, $tokenRecebido)) {
        http_response_code(403);
        exit('forbidden');
    }
}

require_once __DIR__ . '/../config/conexao.php';
require_once __DIR__ . '/../config/funcoes.php';

try {
    $stmt = $pdo->prepare("
        SELECT FKUsuario, Plano, DataExpiracao,
               DATEDIFF(DataExpiracao, CURDATE()) as DiasRestantes
        FROM Assinatura
        WHERE Status = 'ativa'
          AND FontePagamento = 'mercadopago'
          AND LEFT(IDAssinaturaGW, 4) = 'pix_'
          AND (DATE(DataExpiracao) = CURDATE() + INTERVAL 3 DAY
            OR DATE(DataExpiracao) = CURDATE() + INTERVAL 1 DAY)
    ");
    $stmt->execute();
    $assinaturas = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    fwrite(STDERR, 'Erro ao buscar assinaturas Pix vencendo: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

$notificados = 0;
foreach ($assinaturas as $a) {
    $dias   = (int) $a['DiasRestantes'];
    $plano  = strtoupper($a['Plano']);
    $titulo = $dias <= 1
        ? "Sua assinatura {$plano} vence amanhã"
        : "Sua assinatura {$plano} vence em {$dias} dias";
    $corpo  = "Como você pagou via Pix, a renovação não é automática. "
            . "Acesse a página de planos e gere um novo Pix pra continuar com o {$plano} sem interrupção.";

    // dedupeJanelaDias=1 evita notificar duas vezes no mesmo dia se o cron rodar mais de uma vez
    criarNotificacaoSistema($pdo, $a['FKUsuario'], $titulo, $corpo, 1);
    $notificados++;
}

echo "Verificação concluída: " . count($assinaturas) . " assinatura(s) Pix vencendo, {$notificados} lembrete(s) enviado(s).\n";
