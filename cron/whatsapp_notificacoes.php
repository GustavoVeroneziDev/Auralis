<?php
// cron/whatsapp_notificacoes.php
//
// Verifica contas pendentes vencendo HOJE ou vencidas (até 7 dias) e envia
// mensagem WhatsApp para usuários que cadastraram telefone.
//
// Configurar no cPanel > Trabalhos Cron, rodando 1x por dia às 09:00:
//   Minuto=0  Hora=9  Dia=*  Mês=*  Dia da semana=*
//   Comando: /usr/local/bin/php /home/gust9360/public_html/cron/whatsapp_notificacoes.php
//
// Requer migrations:
//   ALTER TABLE Usuario ADD COLUMN Telefone VARCHAR(20) NULL DEFAULT NULL AFTER Email;
//   ALTER TABLE Registro ADD COLUMN WhatsAppNotificadoEm DATETIME NULL DEFAULT NULL;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('forbidden');
}

require_once __DIR__ . '/../config/conexao.php';
require_once __DIR__ . '/../config/funcoes.php';

try {
    $stmt = $pdo->prepare("
        SELECT
            r.IDRegistro,
            r.FKUsuario,
            r.Descricao,
            r.Valor,
            r.TipoRegistro,
            r.DataVencimento,
            DATEDIFF(CURDATE(), DATE(r.DataVencimento)) AS DiasAtraso,
            u.Telefone
        FROM Registro r
        JOIN Usuario u ON u.IDUsuario = r.FKUsuario
        WHERE r.StatusRegistro = 'pendente'
          AND r.WhatsAppNotificadoEm IS NULL
          AND r.DataVencimento IS NOT NULL
          AND DATE(r.DataVencimento) BETWEEN DATE_SUB(CURDATE(), INTERVAL 7 DAY) AND CURDATE()
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

$marcar = $pdo->prepare("UPDATE Registro SET WhatsAppNotificadoEm = NOW() WHERE IDRegistro = :id");

// Agrupa por usuário + tipo para condensar múltiplos registros em uma mensagem
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

    // Separa vencendo hoje dos vencidos
    $hoje    = array_filter($grupo, fn($c) => (int)$c['DiasAtraso'] === 0);
    $vencido = array_filter($grupo, fn($c) => (int)$c['DiasAtraso'] > 0);

    $partes = [];

    if ($hoje) {
        $totalHoje = array_sum(array_map(fn($c) => (float)$c['Valor'], $hoje));
        $fmtHoje   = 'R$ ' . number_format($totalHoje, 2, ',', '.');
        if (count($hoje) === 1) {
            $item = reset($hoje);
            $tipoLabel = $ehDespesa ? 'conta' : 'recebimento';
            $partes[] = "⚠️ *{$item['Descricao']}* vence *hoje* ({$sinal}{$fmtHoje})";
        } else {
            $tipoLabel = $ehDespesa ? 'contas' : 'recebimentos';
            $nomes = implode(', ', array_map(fn($c) => $c['Descricao'], array_slice($hoje, 0, 3)));
            $extra = count($hoje) > 3 ? ' e mais ' . (count($hoje) - 3) . '...' : '';
            $partes[] = "⚠️ " . count($hoje) . " {$tipoLabel} vencem *hoje*: {$nomes}{$extra} ({$sinal}{$fmtHoje} total)";
        }
    }

    if ($vencido) {
        $totalVenc = array_sum(array_map(fn($c) => (float)$c['Valor'], $vencido));
        $fmtVenc   = 'R$ ' . number_format($totalVenc, 2, ',', '.');
        $maxAtraso = max(array_map(fn($c) => (int)$c['DiasAtraso'], $vencido));
        if (count($vencido) === 1) {
            $item = reset($vencido);
            $dias = (int)$item['DiasAtraso'];
            $tipoLabel = $ehDespesa ? 'conta' : 'recebimento';
            $plural = $dias > 1 ? 'dias' : 'dia';
            $partes[] = "🔴 *{$item['Descricao']}* está vencida há *{$dias} {$plural}* ({$sinal}{$fmtVenc})";
        } else {
            $tipoLabel = $ehDespesa ? 'contas' : 'recebimentos';
            $plural = $maxAtraso > 1 ? 'dias' : 'dia';
            $partes[] = "🔴 " . count($vencido) . " {$tipoLabel} estão vencidas (até {$maxAtraso} {$plural} de atraso) — {$sinal}{$fmtVenc} total";
        }
    }

    if (!$partes) continue;

    $mensagem  = "Olá! Um lembrete do *Auralis* 📊\n\n";
    $mensagem .= implode("\n", $partes);
    $mensagem .= "\n\nAcesse seu painel para regularizar: meuauralis.com";

    $ok = enviarWhatsAppNotificacao($telefone, $mensagem);

    foreach ($grupo as $c) {
        $marcar->execute([':id' => $c['IDRegistro']]);
    }

    if ($ok) $enviados++;
}

$total = count($contas);
echo "WhatsApp: {$total} registro(s) verificado(s), {$enviados} usuário(s) notificado(s)." . PHP_EOL;
