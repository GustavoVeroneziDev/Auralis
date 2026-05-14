<?php
// ── MODO DETETIVE LIGADO ──────────────────────────────────────────────────
ini_set('display_errors', '1');
error_reporting(E_ALL);

try {
    // ── Configuração Mercado Pago ─────────────────────────────────────────────
    // Pegue o seu "Access Token" de Produção no painel de desenvolvedor do MP
    define('MP_ACCESS_TOKEN', 'APP_USR-3265675594930667-051414-05a766f55f35ec3d0b8749d7f65c0206-3401629357');

    // Mapeamento dos Planos
    // No Mercado Pago, você usará o Título do Link de Pagamento ou o ID do Plano de Assinatura.
    // Exemplo: Se você nomear o link como "Auralis PRO - Mensal", coloque exatamente esse nome aqui.
    define('PRODUTOS', [
        'Auralis PRO - Mensal' => ['plano' => 'pro',  'ciclo' => 'mensal',  'dias' => 32],
        'Auralis PRO - Anual'  => ['plano' => 'pro',  'ciclo' => 'anual',   'dias' => 370],
        'Auralis VIP - Mensal' => ['plano' => 'vip',  'ciclo' => 'mensal',  'dias' => 32],
        'Auralis VIP - Anual'  => ['plano' => 'vip',  'ciclo' => 'anual',   'dias' => 370],
    ]);

    require_once 'config/conexao.php';

    // ── 1. Recebe o "Aviso" do Mercado Pago ──────────────────────────────────
    $raw  = file_get_contents('php://input');
    $data = json_decode($raw, true);

    if (!$data) {
        http_response_code(400);
        exit('ERRO: Payload vazio.');
    }

    // O MP manda o tipo (payment ou preapproval) e o ID
    $type = $data['type'] ?? $data['topic'] ?? '';
    $id = $data['data']['id'] ?? '';

    if (empty($id)) {
        http_response_code(200); // Retorna 200 pro MP parar de tentar
        exit('IGNORADO: Nenhum ID recebido.');
    }

    // ── 2. Busca os dados oficiais no Mercado Pago (Segurança Máxima) ────────
    $url = "";
    if ($type === 'payment') {
        $url = "https://api.mercadopago.com/v1/payments/{$id}";
    } elseif (in_array($type, ['subscription_preapproval', 'preapproval', 'subscription'])) {
        $url = "https://api.mercadopago.com/preapproval/{$id}";
    } else {
        http_response_code(200);
        exit("IGNORADO: Tipo de evento '{$type}' não é pagamento nem assinatura.");
    }

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer " . MP_ACCESS_TOKEN]);
    $responseMP = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        http_response_code(400);
        exit("ERRO: Falha ao consultar o Mercado Pago para o ID {$id}.");
    }

    $info = json_decode($responseMP, true);

    // ── 3. Extrai as Informações do Pagamento ────────────────────────────────
    $status = $info['status'] ?? ''; // 'approved', 'cancelled', 'refunded'
    $emailComprador = strtolower(trim($info['payer']['email'] ?? ''));
    $valorPago = (float)($info['transaction_amount'] ?? $info['auto_recurring']['transaction_amount'] ?? 0);

    // O identificador do produto no MP pode ser a descrição ou o reason (motivo)
    $idProduto = trim($info['description'] ?? $info['reason'] ?? $info['preapproval_plan_id'] ?? '');

    if (empty($emailComprador) || empty($idProduto)) {
        http_response_code(200);
        exit("IGNORADO: Faltam dados cruciais (Email ou Produto/Descrição vazios).");
    }

    if (!array_key_exists($idProduto, PRODUTOS)) {
        http_response_code(200);
        exit("PRODUTO IGNORADO: A descrição '{$idProduto}' não está mapeada no Auralis.");
    }

    $config = PRODUTOS[$idProduto];

    // ── 4. Encontra o usuário pelo e-mail ────────────────────────────────────
    $stmtUsuario = $pdo->prepare("SELECT IDUsuario, Plano FROM Usuario WHERE Email = :email LIMIT 1");
    $stmtUsuario->execute([':email' => $emailComprador]);
    $usuario = $stmtUsuario->fetch();

    if (!$usuario) {
        _log("USUARIO_NAO_ENCONTRADO: {$emailComprador} pagou '{$idProduto}'");
        http_response_code(200);
        exit("AVISO: Usuário não cadastrado no Auralis.");
    }

    $uid = $usuario['IDUsuario'];
    $pdo->beginTransaction();

    // ── 5. Processa o Status ─────────────────────────────────────────────────
    if (in_array($status, ['approved', 'authorized'])) {
        $dataInicio    = new DateTime();
        $dataExpiracao = (new DateTime())->modify("+{$config['dias']} days");

        // Cancela anterior ativa
        $pdo->prepare("UPDATE Assinatura SET Status = 'cancelada' WHERE FKUsuario = :uid AND Plano = :plano AND Status = 'ativa'")
            ->execute([':uid' => $uid, ':plano' => $config['plano']]);

        // Insere a nova
        $novoId = function_exists('gerarUuid') ? gerarUuid() : bin2hex(random_bytes(16));
        $pdo->prepare("
            INSERT INTO Assinatura
                (IDAssinatura, FKUsuario, Plano, Status, Ciclo, ValorPago, DataInicio, DataExpiracao, IDAssinaturaGW, EmailGateway, FontePagamento)
            VALUES
                (:id, :uid, :plano, 'ativa', :ciclo, :valor, :inicio, :expiracao, :gwid, :email, 'mercadopago')
        ")->execute([
            ':id'        => $novoId,
            ':uid'       => $uid,
            ':plano'     => $config['plano'],
            ':ciclo'     => $config['ciclo'],
            ':valor'     => $valorPago,
            ':inicio'    => $dataInicio->format('Y-m-d H:i:s'),
            ':expiracao' => $dataExpiracao->format('Y-m-d H:i:s'),
            ':gwid'      => $id,
            ':email'     => $emailComprador,
        ]);

        $pdo->prepare("UPDATE Usuario SET Plano = :plano WHERE IDUsuario = :uid")
            ->execute([':plano' => $config['plano'], ':uid' => $uid]);

        _log("PLANO_ATIVADO: {$emailComprador} -> {$config['plano']} ({$config['ciclo']})");
        $msgFinal = "SUCESSO: Plano {$config['plano']} ativado para {$emailComprador}!";

    } elseif (in_array($status, ['cancelled', 'refunded', 'charged_back', 'rejected'])) {
        $pdo->prepare("UPDATE Assinatura SET Status = 'cancelada' WHERE FKUsuario = :uid AND Status IN ('ativa','trial')")
            ->execute([':uid' => $uid]);

        $pdo->prepare("UPDATE Usuario SET Plano = 'free' WHERE IDUsuario = :uid")
            ->execute([':uid' => $uid]);

        _log("PLANO_CANCELADO: {$emailComprador} -> free (status: {$status})");
        $msgFinal = "SUCESSO: Plano de {$emailComprador} cancelado.";
    } else {
        $msgFinal = "IGNORADO: Status {$status} em andamento, não requer ação ainda.";
    }

    $pdo->commit();
    http_response_code(200);
    echo $msgFinal;

} catch (\Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    $erroMsg = "ERRO FATAL PHP: " . $e->getMessage() . " na linha " . $e->getLine();
    _log($erroMsg);
    echo $erroMsg;
}

// ── Helper de log ─────────────────────────────────────────────────────────
function _log(string $msg): void {
    $linha = date('[Y-m-d H:i:s] ') . $msg . PHP_EOL;
    @file_put_contents(__DIR__ . '/logs/webhook_mercadopago.log', $linha, FILE_APPEND | LOCK_EX);
}
?>