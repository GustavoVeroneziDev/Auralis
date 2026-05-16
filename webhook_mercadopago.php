<?php
/**
 * AURALIS — Webhook Mercado Pago
 * ─────────────────────────────────────────────────────────────────────────
 * URL para configurar no painel MP:
 *   Configurações de notificações → Webhooks
 *   URL: https://meuauralis.com/webhook_mercadopago.php
 *   Eventos: Pagamentos, Assinaturas (preapproval)
 *
 * IMPORTANTE: Este webhook é uma camada de segurança para renovações.
 * A ativação inicial já ocorre em sucesso_pagamento.php via consulta ativa.
 */

ini_set('display_errors', '0');
error_reporting(0);

try {
    require_once __DIR__ . '/config/conexao.php';

    $raw  = file_get_contents('php://input');
    $data = json_decode($raw, true) ?? [];

    // MP envia via query string (IPN) ou JSON body (Webhook)
    $type = $_GET['topic'] ?? $_GET['type'] ?? $data['type'] ?? $data['topic'] ?? '';
    $id   = $_GET['id']    ?? $data['data']['id'] ?? $data['id'] ?? '';

    _mpLog("EVENTO: type=[{$type}] id=[{$id}]");

    // Teste do painel MP — retorna 200 sem processar
    if (in_array($id, ['123456', '123456789']) || empty($id)) {
        _mpLog("Ping de teste reconhecido.");
        http_response_code(200); echo 'OK'; exit;
    }

    // ── Normaliza o tipo ──────────────────────────────────────────────────
    // MP usa diferentes nomes dependendo da versão da notificação
    $tiposAssinatura = ['subscription_preapproval', 'preapproval', 'subscription', 'planos e assinaturas'];
    $tiposPagamento  = ['payment'];

    if (in_array($type, $tiposAssinatura)) {
        // ── EVENTO DE ASSINATURA ──────────────────────────────────────────
        list($httpCode, $info) = mpConsultarApi("https://api.mercadopago.com/preapproval/{$id}");

        if ($httpCode !== 200 || empty($info)) {
            _mpLog("ERRO: API retornou {$httpCode} para preapproval/{$id}");
            http_response_code(200); echo 'OK'; exit;
        }

        $mpStatus = $info['status']             ?? '';
        $planId   = $info['preapproval_plan_id'] ?? '';
        $email    = $info['payer_email']          ?? '';

        _mpLog("Assinatura: status=[{$mpStatus}] plan=[{$planId}] email=[{$email}]");

        if (in_array($mpStatus, ['authorized', 'active'])) {
            $resultado = mpAtivarPlano($pdo, $email, $planId, $id);
            _mpLog($resultado ? "ATIVADO: {$email} → {$resultado}" : "FALHOU ativação para {$email}");

        } elseif (in_array($mpStatus, ['cancelled', 'paused', 'pending'])) {
            // Cancelamento ou inadimplência — rebaixa para free
            $stmtU = $pdo->prepare("SELECT IDUsuario FROM Usuario WHERE Email = :e LIMIT 1");
            $stmtU->execute([':e' => strtolower(trim($email))]);
            $usuario = $stmtU->fetch();
            if ($usuario) {
                $novoStatus = $mpStatus === 'paused' ? 'inadimplente' : 'cancelada';
                $pdo->prepare("UPDATE Assinatura SET Status = :s WHERE FKUsuario = :uid AND Status IN ('ativa','trial')")
                    ->execute([':s' => $novoStatus, ':uid' => $usuario['IDUsuario']]);
                _rebaixarParaFree($pdo, $usuario['IDUsuario']);
                _mpLog("REBAIXADO: {$email} → free (status MP: {$mpStatus})");
            }
        }

    } elseif (in_array($type, $tiposPagamento)) {
        // ── EVENTO DE PAGAMENTO ───────────────────────────────────────────
        // Para assinaturas, o pagamento está vinculado a uma preapproval
        list($httpCode, $pagamento) = mpConsultarApi("https://api.mercadopago.com/v1/payments/{$id}");

        if ($httpCode !== 200 || empty($pagamento)) {
            _mpLog("ERRO: API retornou {$httpCode} para payment/{$id}");
            http_response_code(200); echo 'OK'; exit;
        }

        $mpStatus  = $pagamento['status']                ?? '';
        $email     = $pagamento['payer']['email']         ?? '';

        _mpLog("Pagamento: status=[{$mpStatus}] email=[{$email}]");

        // Só processa se for pagamento aprovado de assinatura
        if ($mpStatus === 'approved') {
            // Tenta pegar o preapproval_id do metadado do pagamento
            $preapprovalId = $pagamento['metadata']['preapproval_id']
                ?? $pagamento['point_of_interaction']['transaction_data']['subscription_id']
                ?? '';

            if ($preapprovalId) {
                list($code2, $assinatura) = mpConsultarApi("https://api.mercadopago.com/preapproval/{$preapprovalId}");
                if ($code2 === 200 && !empty($assinatura)) {
                    $planId = $assinatura['preapproval_plan_id'] ?? '';
                    $valor  = $pagamento['transaction_amount'] ?? 0;
                    $resultado = mpAtivarPlano($pdo, $email, $planId, $preapprovalId, $valor);
                    _mpLog($resultado ? "ATIVADO via payment: {$email} → {$resultado}" : "FALHOU via payment para {$email}");
                }
            } else {
                _mpLog("Pagamento aprovado mas sem preapproval_id nos metadados. Email: {$email}");
            }
        }

    } else {
        _mpLog("Tipo [{$type}] ignorado (não é assinatura nem pagamento).");
    }

    http_response_code(200);
    echo 'OK';

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    _mpLog("ERRO FATAL: " . $e->getMessage() . " (linha " . $e->getLine() . ")");
    http_response_code(500);
    echo 'Erro';
}

function _mpLog($msg) {
    $linha = date('[Y-m-d H:i:s] ') . $msg . PHP_EOL;
    @file_put_contents(__DIR__ . '/logs/webhook_mercadopago.log', $linha, FILE_APPEND | LOCK_EX);
}
