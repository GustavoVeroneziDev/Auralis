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

// Agrupa por usuário + tipo — quem tem várias contas vencendo hoje recebe UMA notificação por
// tipo (despesa/receita não se misturam na mesma notificação, pra não confundir sinal).
$grupos = [];
foreach ($contas as $c) {
    $chave = $c['FKUsuario'] . '|' . $c['TipoRegistro'];
    $grupos[$chave][] = $c;
}

$usuariosNotificados = [];
foreach ($grupos as $grupo) {
    $usuarioId  = $grupo[0]['FKUsuario'];
    $ehDespesa  = $grupo[0]['TipoRegistro'] === 'despesa';
    $sinal      = $ehDespesa ? '-' : '+';
    $totalValor = array_sum(array_map(fn($c) => (float)$c['Valor'], $grupo));
    $totalFmt   = $sinal . ' R$ ' . number_format($totalValor, 2, ',', '.');

    if (count($grupo) === 1) {
        $tipoLabel = $ehDespesa ? 'Conta' : 'Recebimento';
        $titulo    = $tipoLabel . ' vence hoje';
        $corpo     = $grupo[0]['Descricao'] . ' — ' . $totalFmt;
    } else {
        $tipoLabel  = $ehDespesa ? 'contas' : 'recebimentos';
        $descricoes = array_map(fn($c) => $c['Descricao'], $grupo);
        $listaDesc  = implode(', ', array_slice($descricoes, 0, 3)) . (count($descricoes) > 3 ? '…' : '');
        $titulo     = count($grupo) . ' ' . $tipoLabel . ' vencem hoje';
        $corpo      = $listaDesc . ' — ' . $totalFmt . ' no total';
    }

    $enviados = enviarPushParaUsuario($pdo, $usuarioId, $titulo, $corpo, '/agenda.php');
    foreach ($grupo as $c) {
        $marcarProcessado->execute([':id' => $c['IDRegistro']]);
    }
    if ($enviados > 0) $usuariosNotificados[$usuarioId] = true;
}

$resumo = 'Verificação concluída: ' . count($contas) . ' conta(s) vencendo hoje, ' . count($usuariosNotificados) . ' usuário(s) notificado(s) por push.';
echo $resumo . PHP_EOL;
