<?php
// cron/reforco_vencimentos_push.php
//
// Reforço da tarde: reavisa por Web Push quem recebeu o aviso da manhã (ou não)
// e ainda não regularizou uma conta que vence HOJE. Não repete pra quem já
// marcou como pago nem pra contas já vencidas de dias anteriores (essas já
// caem todo dia no lembrete de WhatsApp normal).
//
// Configurar no cPanel > Trabalhos Cron, rodando 1x por dia às 16:00:
//   Minuto=0  Hora=16  Dia=*  Mês=*  Dia da semana=*
//   Comando: /usr/local/bin/php /home/gust9360/public_html/cron/reforco_vencimentos_push.php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('forbidden');
}

require_once __DIR__ . '/../config/conexao.php';
require_once __DIR__ . '/../config/funcoes.php';
require_once __DIR__ . '/../config/web_push.php';
garantirColunasReforcoVencimento($pdo);

try {
    $stmt = $pdo->prepare("
        SELECT IDRegistro, FKUsuario, Descricao, Valor, TipoRegistro
        FROM Registro
        WHERE StatusRegistro = 'pendente'
          AND PushReforcoEm IS NULL
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

// WHERE ...IS NULL torna a marcação atômica por linha — protege contra o cron rodar
// duplicado (ex: entrada repetida no cPanel) e mandar a mesma notificação duas vezes.
$marcarProcessado = $pdo->prepare("UPDATE Registro SET PushReforcoEm = NOW() WHERE IDRegistro = :id AND PushReforcoEm IS NULL");

$grupos = [];
foreach ($contas as $c) {
    $chave = $c['FKUsuario'] . '|' . $c['TipoRegistro'];
    $grupos[$chave][] = $c;
}

$usuariosNotificados = [];
foreach ($grupos as $grupo) {
    $itens = [];
    foreach ($grupo as $c) {
        $marcarProcessado->execute([':id' => $c['IDRegistro']]);
        if ($marcarProcessado->rowCount() > 0) $itens[] = $c;
    }
    if (!$itens) continue;

    $usuarioId  = $itens[0]['FKUsuario'];
    $ehDespesa  = $itens[0]['TipoRegistro'] === 'despesa';
    $sinal      = $ehDespesa ? '-' : '+';
    $totalValor = array_sum(array_map(fn($c) => (float)$c['Valor'], $itens));
    $totalFmt   = '(' . $sinal . ' R$ ' . number_format($totalValor, 2, ',', '.') . ')';

    if (count($itens) === 1) {
        $tipoLabel = $ehDespesa ? 'Conta' : 'Recebimento';
        $titulo    = $tipoLabel . ' ainda pendente hoje';
        $corpo     = $itens[0]['Descricao'] . ' ' . $totalFmt;
    } else {
        $tipoLabel  = $ehDespesa ? 'contas' : 'recebimentos';
        $descricoes = array_map(fn($c) => $c['Descricao'], $itens);
        $listaDesc  = implode(', ', array_slice($descricoes, 0, 3)) . (count($descricoes) > 3 ? '…' : '');
        $titulo     = count($itens) . ' ' . $tipoLabel . ' ainda pendentes hoje';
        $corpo      = $listaDesc . ' ' . $totalFmt . ' no total';
    }

    $enviados = enviarPushParaUsuario($pdo, $usuarioId, $titulo, $corpo, '/agenda.php');
    if ($enviados > 0) $usuariosNotificados[$usuarioId] = true;
}

$resumo = 'Reforço push: ' . count($contas) . ' conta(s) ainda pendente(s) hoje, ' . count($usuariosNotificados) . ' usuário(s) notificado(s).';
echo $resumo . PHP_EOL;
