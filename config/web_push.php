<?php
// config/web_push.php — envio de notificações Web Push (aparecem na tela de
// notificações do SO, mesmo com o Auralis fechado). Requer a migration
// migrations/add_push_notificacoes.sql já aplicada e config/vapid_keys.php.

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/vapid_keys.php';

use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;

if (!function_exists('webPushCliente')) {
    function webPushCliente(): WebPush
    {
        static $webPush = null;
        if ($webPush === null) {
            global $vapidPublicKey, $vapidPrivateKey, $vapidSubject;
            $webPush = new WebPush([
                'VAPID' => [
                    'subject'    => $vapidSubject,
                    'publicKey'  => $vapidPublicKey,
                    'privateKey' => $vapidPrivateKey,
                ],
            ]);
        }
        return $webPush;
    }
}

/**
 * Envia uma notificação para todos os dispositivos em que o usuário ativou
 * "Notificações no navegador". Remove sozinho subscriptions expiradas/inválidas.
 * Retorna quantos dispositivos receberam a notificação com sucesso.
 */
if (!function_exists('enviarPushParaUsuario')) {
    function enviarPushParaUsuario(PDO $pdo, string $usuarioId, string $titulo, string $corpo, ?string $url = null): int
    {
        try {
            $stmt = $pdo->prepare("SELECT Endpoint, P256dhKey, AuthToken FROM PushSubscription WHERE FKUsuario = :uid");
            $stmt->execute([':uid' => $usuarioId]);
            $subs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            return 0; // Tabela ainda não migrada nesse ambiente
        }

        if (empty($subs)) return 0;

        $webPush = webPushCliente();
        $payload = json_encode([
            'title' => $titulo,
            'body'  => $corpo,
            'url'   => $url ?: '/agenda.php',
        ], JSON_UNESCAPED_UNICODE);

        foreach ($subs as $s) {
            $subscription = Subscription::create([
                'endpoint'        => $s['Endpoint'],
                'publicKey'       => $s['P256dhKey'],
                'authToken'       => $s['AuthToken'],
                'contentEncoding' => 'aes128gcm',
            ]);
            $webPush->queueNotification($subscription, $payload);
        }

        $enviados = 0;
        foreach ($webPush->flush() as $report) {
            if ($report->isSuccess()) {
                $enviados++;
            } elseif ($report->isSubscriptionExpired()) {
                $pdo->prepare("DELETE FROM PushSubscription WHERE FKUsuario = :uid AND Endpoint = :ep")
                    ->execute([':uid' => $usuarioId, ':ep' => $report->getEndpoint()]);
            }
        }
        return $enviados;
    }
}
