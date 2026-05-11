<?php
// ── MODO DETETIVE (LIGA A EXIBIÇÃO DE ERROS PARA A KIWIFY VER) ──────────
ini_set('display_errors', '1');
error_reporting(E_ALL);

try {
    // ── Configuração ──────────────────────────────────────────────────────────
    define('KIWIFY_TOKEN', '5sm15z80ljq');

    // IDs corretos que você preencheu
    define('PRODUTOS', [
        '85614e30-4d82-11f1-8f1e-03c7d68b7a1a'  => ['plano' => 'pro',  'ciclo' => 'mensal',  'dias' => 32],
        'ded60050-4d82-11f1-9355-f981dc8f326a'  => ['plano' => 'pro',  'ciclo' => 'anual',   'dias' => 370],
        '40ab03c0-4d83-11f1-990a-29bfa0d38fbb'  => ['plano' => 'vip',  'ciclo' => 'mensal',  'dias' => 32], 
        '7e5a07c0-4d83-11f1-8430-c7a3defb5c42'  => ['plano' => 'vip',  'ciclo' => 'anual',   'dias' => 370],
    ]);

    // ── Bootstrap ─────────────────────────────────────────────────────────────
    require_once 'config/conexao.php';

    // ── Leitura e validação do payload ────────────────────────────────────────
    $raw  = file_get_contents('php://input');
    $data = json_decode($raw, true);

    if (!$data) {
        http_response_code(400);
        exit('ERRO: Payload vazio ou JSON inválido. Verifique se a Kiwify mandou os dados.');
    }

    // ── 1. Validação de Segurança ─────────────────────────────────────────────
    // Verifica se o Token veio pela URL (?token=...) ou se a assinatura da Kiwify está presente no JSON
    $tokenRecebido = $_GET['token'] ?? '';
    $assinatura    = $data['signature'] ?? '';

    if ($tokenRecebido !== KIWIFY_TOKEN && empty($assinatura)) {
        http_response_code(401);
        exit('ERRO: Não autorizado. Token ou assinatura ausente.');
    }

    // ── 2. Leitura dos campos da Kiwify (Estrutura Correta) ───────────────────
    $order = $data['order'] ?? [];

    $evento         = $order['webhook_event_type'] ?? '';
    $emailComprador = strtolower(trim($order['Customer']['email'] ?? ''));
    $idProduto      = $order['Product']['product_id'] ?? '';
    $idAssinaturaGW = $order['subscription_id'] ?? '';
    $valorPago      = (float)($order['Commissions']['charge_amount'] ?? 0) / 100;

    if (empty($emailComprador) || empty($idProduto)) {
        http_response_code(422);
        exit('ERRO: Dados insuficientes enviados pela Kiwify (E-mail ou ID do Produto faltando).');
    }

    // Verifica se o produto é nosso
    if (!array_key_exists($idProduto, PRODUTOS)) {
        http_response_code(200); 
        exit("PRODUTO IGNORADO: O ID {$idProduto} não está na lista do Auralis.");
    }

    $config = PRODUTOS[$idProduto];

    // ── Encontra o usuário pelo e-mail ────────────────────────────────────────
    $stmtUsuario = $pdo->prepare("SELECT IDUsuario, Plano FROM Usuario WHERE Email = :email LIMIT 1");
    $stmtUsuario->execute([':email' => $emailComprador]);
    $usuario = $stmtUsuario->fetch();

    if (!$usuario) {
        _log("USUARIO_NAO_ENCONTRADO: {$emailComprador} comprou o produto {$idProduto}");
        http_response_code(200);
        exit("AVISO: Usuário {$emailComprador} não encontrado no sistema Auralis.");
    }

    $uid = $usuario['IDUsuario'];

    // ── Processa por tipo de evento ───────────────────────────────────────────
    $pdo->beginTransaction();

    switch (true) {

        // ── Pagamento aprovado / assinatura nova ──────────────────────────
        case in_array($evento, ['order_approved', 'subscription_renewed', 'subscription_activated']):
            $dataInicio    = new DateTime();
            $dataExpiracao = (new DateTime())->modify("+{$config['dias']} days");

            // Cancela assinatura ativa anterior do mesmo plano (renovação)
            $pdo->prepare("
                UPDATE Assinatura SET Status = 'cancelada'
                WHERE FKUsuario = :uid AND Plano = :plano AND Status = 'ativa'
            ")->execute([':uid' => $uid, ':plano' => $config['plano']]);

            // Cria nova assinatura
            $novoId = function_exists('gerarUuid') ? gerarUuid() : bin2hex(random_bytes(16));
            $pdo->prepare("
                INSERT INTO Assinatura
                    (IDAssinatura, FKUsuario, Plano, Status, Ciclo, ValorPago,
                     DataInicio, DataExpiracao, IDAssinaturaGW, EmailGateway, FontePagamento)
                VALUES
                    (:id, :uid, :plano, 'ativa', :ciclo, :valor,
                     :inicio, :expiracao, :gwid, :email, 'kiwify')
            ")->execute([
                ':id'        => $novoId,
                ':uid'       => $uid,
                ':plano'     => $config['plano'],
                ':ciclo'     => $config['ciclo'],
                ':valor'     => $valorPago,
                ':inicio'    => $dataInicio->format('Y-m-d H:i:s'),
                ':expiracao' => $dataExpiracao->format('Y-m-d H:i:s'),
                ':gwid'      => $idAssinaturaGW,
                ':email'     => $emailComprador,
            ]);

            // Atualiza o plano do usuário
            $pdo->prepare("UPDATE Usuario SET Plano = :plano WHERE IDUsuario = :uid")
                ->execute([':plano' => $config['plano'], ':uid' => $uid]);

            _log("PLANO_ATIVADO: {$emailComprador} -> {$config['plano']} ({$config['ciclo']})");
            $msgFinal = "SUCESSO: Plano {$config['plano']} ativado para {$emailComprador}!";
            break;

        // ── Reembolso / cancelamento ──────────────────────────────────────
        case in_array($evento, ['order_refunded', 'subscription_canceled', 'subscription_overdue']):
            $novoStatus = ($evento === 'subscription_overdue') ? 'inadimplente' : 'cancelada';

            $pdo->prepare("
                UPDATE Assinatura SET Status = :status
                WHERE FKUsuario = :uid AND Status IN ('ativa','trial')
            ")->execute([':status' => $novoStatus, ':uid' => $uid]);

            // Rebaixa para free
            $pdo->prepare("UPDATE Usuario SET Plano = 'free' WHERE IDUsuario = :uid")
                ->execute([':uid' => $uid]);

            _log("PLANO_CANCELADO: {$emailComprador} -> free (evento: {$evento})");
            $msgFinal = "SUCESSO: Plano de {$emailComprador} cancelado.";
            break;

        default:
            _log("EVENTO_IGNORADO: Recebeu evento {$evento} da Kiwify");
            $msgFinal = "IGNORADO: Evento {$evento} não processado.";
            break;
    }

    $pdo->commit();
    http_response_code(200);
    echo $msgFinal;

} catch (\Throwable $e) {
    // ── Escudo Máximo: Captura até erros fatais do PHP ─────────────────────
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    $erroMsg = "ERRO FATAL PHP: " . $e->getMessage() . " na linha " . $e->getLine() . " do arquivo " . basename($e->getFile());
    _log($erroMsg);
    echo $erroMsg;
}

// ── Helper de log ─────────────────────────────────────────────────────────
function _log(string $msg): void {
    $linha = date('[Y-m-d H:i:s] ') . $msg . PHP_EOL;
    @file_put_contents(__DIR__ . '/logs/webhook_kiwify.log', $linha, FILE_APPEND | LOCK_EX);
}
?>