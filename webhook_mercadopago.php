<?php
// ── MODO PORTAS ABERTAS (SEM SEGURANÇA) ──
ini_set('display_errors', '1');
error_reporting(E_ALL);

// 1. Pega exatamente o que o Mercado Pago enviou
$raw = file_get_contents('php://input');

// 2. Cria a mensagem para o Log
$linha = date('[Y-m-d H:i:s] ') . "BATEU NO SERVIDOR! Payload do MP: " . $raw . PHP_EOL;

// 3. Salva no arquivo webhook_mercadopago.log dentro da pasta logs
@file_put_contents(__DIR__ . '/logs/webhook_mercadopago.log', $linha, FILE_APPEND | LOCK_EX);

// 4. Retorna 200 OK para o Mercado Pago ficar feliz e parar de dar erro
http_response_code(200);
echo "Recebido com sucesso pelo Auralis (Modo sem segurança)";
exit;
?>