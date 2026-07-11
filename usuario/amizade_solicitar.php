<?php
// usuario/amizade_solicitar.php
// Envia um pedido de amizade. Se a outra pessoa já tinha te mandado um pedido
// pendente, aceita automaticamente em vez de criar um segundo pedido cruzado.

session_start();
if (!isset($_SESSION['usuario_id'])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'erro' => 'Sem permissão']);
    exit;
}

require_once '../config/conexao.php';
require_once '../config/funcoes.php';

header('Content-Type: application/json; charset=utf-8');

$uid          = $_SESSION['usuario_id'];
$destinatario = trim($_POST['destinatario_id'] ?? '');

if (empty($destinatario) || $destinatario === $uid) {
    echo json_encode(['ok' => false, 'erro' => 'ID inválido']);
    exit;
}

garantirTabelaAmizade($pdo);

try {
    $atual = obterStatusAmizade($pdo, $uid, $destinatario);

    if ($atual['status'] === 'amigos' || $atual['status'] === 'pendente_enviado') {
        echo json_encode(['ok' => true, 'status' => $atual['status'], 'idAmizade' => $atual['idAmizade']]);
        exit;
    }

    if ($atual['status'] === 'pendente_recebido') {
        // A outra pessoa já tinha te chamado — aceita direto em vez de duplicar
        $pdo->prepare("UPDATE Amizade SET Status = 'aceito', RespondidoEm = NOW() WHERE IDAmizade = :id")
            ->execute([':id' => $atual['idAmizade']]);
        echo json_encode(['ok' => true, 'status' => 'amigos', 'idAmizade' => $atual['idAmizade']]);
        exit;
    }

    $novoId = gerarUuid();
    $pdo->prepare("
        INSERT INTO Amizade (IDAmizade, FKUsuarioSolicitante, FKUsuarioDestinatario, Status)
        VALUES (:id, :solicitante, :destinatario, 'pendente')
    ")->execute([
        ':id'           => $novoId,
        ':solicitante'  => $uid,
        ':destinatario' => $destinatario,
    ]);

    echo json_encode(['ok' => true, 'status' => 'pendente_enviado', 'idAmizade' => $novoId]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'erro' => 'Erro ao processar pedido']);
}
