<?php
// ── MODO RAIO-X: LIGADO ──────────────────────────────────────────────────
ini_set('display_errors', '1');
error_reporting(E_ALL);

try {
    // ── 1. CREDENCIAIS ────────────────────────────────────────────────────────
    // COLE SEU ACCESS TOKEN DE PRODUÇÃO AQUI EMBAIXO
    define('MP_ACCESS_TOKEN', 'APP_USR-3265675594930667-051414-05a766f55f35ec3d0b8749d7f65c0206-3401629357');

    define('PRODUTOS', [
        'Auralis PRO - Mensal' => ['plano' => 'pro',  'ciclo' => 'mensal',  'dias' => 32],
        'Auralis PRO - Anual'  => ['plano' => 'pro',  'ciclo' => 'anual',   'dias' => 370],
        'Auralis VIP - Mensal' => ['plano' => 'vip',  'ciclo' => 'mensal',  'dias' => 32],
        'Auralis VIP - Anual'  => ['plano' => 'vip',  'ciclo' => 'anual',   'dias' => 370],
    ]);

    require_once 'config/conexao.php';

    // ── LEITURA HÍBRIDA (Aceita tanto IPN quanto Webhook) ─────────────────────
    $raw  = file_get_contents('php://input');
    $data = json_decode($raw, true);
    
    $type = $_GET['topic'] ?? $_GET['type'] ?? $data['type'] ?? $data['topic'] ?? '';
    $id   = $_GET['id'] ?? $data['data']['id'] ?? $data['id'] ?? '';

    _log("--- NOVO EVENTO RECEBIDO ---");
    _log("Tipo: {$type} | ID: {$id}");

    // ── PROTEÇÃO CONTRA O TESTE DO PAINEL ─────────────────────────────────────
    // O MP usa "123456" ou "123456789" no botão "Experimentar". 
    // Se tentarmos consultar isso na API, vai dar erro 400. Então aprovamos direto o teste.
    if ($id === '123456' || $id === '123456789') {
        _log("Sucesso: Ping de teste do painel do Mercado Pago reconhecido e validado.");
        http_response_code(200); 
        echo "OK";
        exit;
    }

    if (empty($id)) {
        _log("IGNORADO: Nenhum ID recebido.");
        http_response_code(200); exit('OK');
    }

    _log("Passo 1: Consultando MP para o ID real: {$id}");

    // ── 2. CONSULTA AO MERCADO PAGO ───────────────────────────────────────────
    $url = "";
    if ($type === 'payment') {
        $url = "https://api.mercadopago.com/v1/payments/{$id}";
    } elseif (in_array($type, ['subscription_preapproval', 'preapproval', 'subscription', 'planos e assinaturas'])) {
        $url = "https://api.mercadopago.com/preapproval/{$id}";
    } else {
        _log("IGNORADO: Evento irrelevante ({$type}).");
        http_response_code(200); exit('OK');
    }

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer " . MP_ACCESS_TOKEN]);
    $responseMP = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        _log("ERRO FATAL MP: Falha ao consultar API. Código HTTP: {$httpCode}. Resposta: {$responseMP}");
        http_response_code(400); exit('ERRO MP');
    }

    $info = json_decode($responseMP, true);
    $status = $info['status'] ?? ''; 
    $emailComprador = strtolower(trim($info['payer']['email'] ?? ''));
    $idProduto = trim($info['description'] ?? $info['reason'] ?? $info['preapproval_plan_id'] ?? '');

    _log("Passo 2: Dados extraídos -> Status: [{$status}], Email: [{$emailComprador}], Produto: [{$idProduto}]");

    // ── 3. VALIDAÇÕES ─────────────────────────────────────────────────────────
    if (empty($emailComprador) || empty($idProduto)) {
        _log("IGNORADO: Faltam dados cruciais (Email ou Produto vazios).");
        http_response_code(200); exit('OK');
    }

    if (!array_key_exists($idProduto, PRODUTOS)) {
        _log("PRODUTO IGNORADO: O produto '{$idProduto}' não é um plano válido no código PHP.");
        http_response_code(200); exit('OK');
    }

    $config = PRODUTOS[$idProduto];

    // ── 4. ATUALIZAÇÃO NO BANCO ───────────────────────────────────────────────
    $stmtUsuario = $pdo->prepare("SELECT IDUsuario, Plano FROM Usuario WHERE Email = :email LIMIT 1");
    $stmtUsuario->execute([':email' => $emailComprador]);
    $usuario = $stmtUsuario->fetch();

    if (!$usuario) {
        _log("ERRO USUARIO: Email '{$emailComprador}' não existe na tabela Usuario.");
        http_response_code(200); exit('OK');
    }

    $uid = $usuario['IDUsuario'];
    $pdo->beginTransaction();

    if (in_array($status, ['approved', 'authorized'])) {
        $dataInicio = new DateTime();
        $dataExpiracao = (new DateTime())->modify("+{$config['dias']} days");

        $pdo->prepare("UPDATE Assinatura SET Status = 'cancelada' WHERE FKUsuario = :uid AND Plano = :plano AND Status = 'ativa'")->execute([':uid' => $uid, ':plano' => $config['plano']]);

        $novoId = function_exists('gerarUuid') ? gerarUuid() : bin2hex(random_bytes(16));
        $pdo->prepare("INSERT INTO Assinatura (IDAssinatura, FKUsuario, Plano, Status, Ciclo, ValorPago, DataInicio, DataExpiracao, IDAssinaturaGW, EmailGateway, FontePagamento) VALUES (:id, :uid, :plano, 'ativa', :ciclo, 0, :inicio, :expiracao, :gwid, :email, 'mercadopago')")->execute([
            ':id' => $novoId, ':uid' => $uid, ':plano' => $config['plano'], ':ciclo' => $config['ciclo'], ':inicio' => $dataInicio->format('Y-m-d H:i:s'), ':expiracao' => $dataExpiracao->format('Y-m-d H:i:s'), ':gwid' => $id, ':email' => $emailComprador,
        ]);

        $pdo->prepare("UPDATE Usuario SET Plano = :plano WHERE IDUsuario = :uid")->execute([':plano' => $config['plano'], ':uid' => $uid]);

        _log("SUCESSO FINAL: Plano {$config['plano']} ativado para {$emailComprador}!");
    } else {
        _log("STATUS PENDENTE/CANCELADO: Nenhuma ativação feita. Status: {$status}");
    }

    $pdo->commit();
    http_response_code(200); echo "Sucesso";

} catch (\Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) { $pdo->rollBack(); }
    _log("ERRO FATAL PHP: " . $e->getMessage() . " na linha " . $e->getLine());
    http_response_code(500); echo "Erro";
}

function _log(string $msg): void {
    $linha = date('[Y-m-d H:i:s] ') . $msg . PHP_EOL;
    @file_put_contents(__DIR__ . '/logs/webhook_mercadopago.log', $linha, FILE_APPEND | LOCK_EX);
}
?>