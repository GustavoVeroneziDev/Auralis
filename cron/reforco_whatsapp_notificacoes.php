<?php
// cron/reforco_whatsapp_notificacoes.php
//
// Reforço da tarde: reavisa por WhatsApp quem ainda não regularizou uma conta
// que vence HOJE. Não repete pra quem já marcou como pago nem pra contas já
// vencidas de dias anteriores (essas já caem todo dia no lembrete normal).
//
// Configurar no cPanel > Trabalhos Cron, rodando 1x por dia às 16:00:
//   Minuto=0  Hora=16  Dia=*  Mês=*  Dia da semana=*
//   Comando: /usr/local/bin/php /home/gust9360/public_html/cron/reforco_whatsapp_notificacoes.php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('forbidden');
}

require_once __DIR__ . '/../config/conexao.php';
require_once __DIR__ . '/../config/funcoes.php';
garantirColunasReforcoVencimento($pdo);

try {
    $stmt = $pdo->prepare("
        SELECT
            r.IDRegistro,
            r.FKUsuario,
            r.Descricao,
            r.Valor,
            r.TipoRegistro,
            u.Telefone
        FROM Registro r
        JOIN Usuario u ON u.IDUsuario = r.FKUsuario
        WHERE r.StatusRegistro = 'pendente'
          AND r.WhatsAppReforcoEm IS NULL
          AND r.DataVencimento IS NOT NULL
          AND DATE(r.DataVencimento) = CURDATE()
          AND r.TipoRegistro IN ('receita', 'despesa')
          AND u.Telefone IS NOT NULL
          AND u.StatusConta = 'ativo'
    ");
    $stmt->execute();
    $contas = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    fwrite(STDERR, 'Erro ao buscar contas: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

$marcar = $pdo->prepare("UPDATE Registro SET WhatsAppReforcoEm = NOW() WHERE IDRegistro = :id");

$grupos = [];
foreach ($contas as $c) {
    $chave = $c['FKUsuario'] . '|' . $c['TipoRegistro'];
    $grupos[$chave][] = $c;
}

$enviados = 0;
foreach ($grupos as $grupo) {
    $telefone  = $grupo[0]['Telefone'];
    $ehDespesa = $grupo[0]['TipoRegistro'] === 'despesa';
    $sinal     = $ehDespesa ? '-' : '+';

    $totalValor = array_sum(array_map(fn($c) => (float)$c['Valor'], $grupo));
    $fmtValor   = 'R$ ' . number_format($totalValor, 2, ',', '.');

    if (count($grupo) === 1) {
        $item      = reset($grupo);
        $tipoLabel = $ehDespesa ? 'conta' : 'recebimento';
        $texto     = "*{$item['Descricao']}* ({$sinal}{$fmtValor})";
    } else {
        $tipoLabel = $ehDespesa ? 'contas' : 'recebimentos';
        $nomes = implode(', ', array_map(fn($c) => $c['Descricao'], array_slice($grupo, 0, 3)));
        $extra = count($grupo) > 3 ? ' e mais ' . (count($grupo) - 3) . '...' : '';
        $texto = count($grupo) . " {$tipoLabel}: {$nomes}{$extra} ({$sinal}{$fmtValor} total)";
    }

    $mensagem  = "Passando pra lembrar de novo 🔔 *Auralis*\n\n";
    $mensagem .= "Ainda consta pendente hoje: {$texto}";
    $mensagem .= "\n\nSe já pagou, marca como efetivado no app pra parar de aparecer aqui: meuauralis.com";

    $ok = enviarWhatsAppNotificacao($telefone, $mensagem);

    if ($ok) {
        foreach ($grupo as $c) {
            $marcar->execute([':id' => $c['IDRegistro']]);
        }
        $enviados++;
    }
}

$total = count($contas);
echo "Reforço WhatsApp: {$total} registro(s) verificado(s), {$enviados} usuário(s) notificado(s)." . PHP_EOL;
