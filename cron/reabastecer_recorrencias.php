<?php
// cron/reabastecer_recorrencias.php
//
// Mantém um buffer de ocorrências futuras pra toda série recorrente ativa
// (Recorrente=1, RecorrenciaAtiva=1), pra qualquer intervalo (dias/semanas/meses)
// — via principal, essencial pra recorrências curtas (ex: "a cada 1 dia") não
// dependerem de a pessoa abrir o dashboard todo dia. O hook em dashboard.php
// chama a mesma função como rede de segurança pra quem ainda não configurou
// esse cron.
//
// Configurar no cPanel > Trabalhos Cron — 1x por dia:
//   Minuto=0  Hora=6  Dia=*  Mês=*  Dia da semana=*
//   Comando: /usr/local/bin/php /home/gust9360/public_html/cron/reabastecer_recorrencias.php
// (ajuste o caminho do PHP e da pasta do site conforme aparecer no seu cPanel)

require_once __DIR__ . '/../config/vapid_keys.php';
if (PHP_SAPI !== 'cli') {
    header('Content-Type: text/plain; charset=utf-8');
    $tokenRecebido = $_GET['token'] ?? '';
    if (!isset($cronToken) || !hash_equals($cronToken, $tokenRecebido)) {
        http_response_code(403);
        exit('forbidden');
    }
}

require_once __DIR__ . '/../config/conexao.php';
require_once __DIR__ . '/../config/funcoes.php';

garantirColunasRecorrenciaGeneralizada($pdo);

$gerado = reabastecerRecorrencias($pdo);

echo "Reabastecimento de recorrências: {$gerado} ocorrência(s) gerada(s)." . PHP_EOL;
