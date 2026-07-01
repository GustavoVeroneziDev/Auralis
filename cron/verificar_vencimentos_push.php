<?php
// cron/verificar_vencimentos_push.php
//
// Verifica contas pendentes vencendo HOJE e manda um Web Push (notificação
// no SO do celular/PC) pra quem ativou em Configurações > Notificações.
//
// Configurar no cPanel > Trabalhos Cron, rodando 1x por dia às 12:00:
//   Minuto=0  Hora=12  Dia=*  Mês=*  Dia da semana=*
//   Comando: /usr/local/bin/php /home/gust9360/public_html/cron/verificar_vencimentos_push.php
// (ajuste o caminho do PHP e da pasta do site conforme aparecer no seu cPanel)
//
// Requer a migration migrations/add_push_notificacoes.sql já aplicada.

// Permite rodar tanto via CLI (recomendado) quanto via URL com token, caso o
// cron do host só suporte chamar uma URL em vez de executar o PHP direto.
require_once __DIR__ . '/../config/vapid_keys.php';
if (PHP_SAPI !== 'cli') {
    $tokenRecebido = $_GET['token'] ?? '';
    if (!isset($cronToken) || !hash_equals($cronToken, $tokenRecebido)) {
        http_response_code(403);
        exit('forbidden');
    }
}

require_once __DIR__ . '/../config/conexao.php';
require_once __DIR__ . '/../config/web_push.php';

try {
    $stmt = $pdo->prepare("
        SELECT IDRegistro, FKUsuario, Descricao, Valor, TipoRegistro
        FROM Registro
        WHERE StatusRegistro = 'pendente'
          AND PushNotificadoEm IS NULL
          AND DataVencimento IS NOT NULL
          AND DATE(DataVencimento) = CURDATE()
          AND TipoRegistro IN ('receita', 'despesa')
    ");
    $stmt->execute();
    $contas = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    fwrite(STDERR, 'Erro ao buscar contas: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

$marcarProcessado = $pdo->prepare("UPDATE Registro SET PushNotificadoEm = NOW() WHERE IDRegistro = :id");
$notificados = 0;

foreach ($contas as $c) {
    $tipoLabel = ($c['TipoRegistro'] === 'receita') ? 'Recebimento' : 'Conta';
    $valorFmt  = 'R$ ' . number_format((float)$c['Valor'], 2, ',', '.');
    $titulo    = $tipoLabel . ' vence hoje';
    $corpo     = $c['Descricao'] . ' — ' . $valorFmt;

    $enviados = enviarPushParaUsuario($pdo, $c['FKUsuario'], $titulo, $corpo, '/agenda.php');
    $marcarProcessado->execute([':id' => $c['IDRegistro']]);
    if ($enviados > 0) $notificados++;
}

$resumo = 'Verificação concluída: ' . count($contas) . ' conta(s) vencendo hoje, ' . $notificados . ' notificação(ões) push enviada(s).';
echo $resumo . PHP_EOL;
