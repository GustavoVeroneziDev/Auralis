<?php
session_start();
if (!isset($_SESSION['usuario_id'])) { http_response_code(403); exit; }
require_once __DIR__ . '/../config/conexao.php';
require_once __DIR__ . '/../config/funcoes.php';

header('Content-Type: application/json');

$uid  = $_SESSION['usuario_id'];
$acao = $_POST['acao'] ?? '';
$id   = trim($_POST['id'] ?? '');

if (!$id || !$acao) { echo json_encode(['ok' => false, 'erro' => 'parametros invalidos']); exit; }

// Verifica que o registro pertence ao usuário (ou que ele é dono da carteira compartilhada
// onde o registro está) antes de qualquer ação.
$stmtCheck = $pdo->prepare("
    SELECT IDRegistro, StatusRegistro, TipoRegistro, Valor, Descricao, FKCarteira FROM Registro
    WHERE IDRegistro = :id
      AND (FKUsuario = :uid OR FKCarteira IN (SELECT IDCarteira FROM Carteira WHERE FKUsuarioDono = :uid2))
    LIMIT 1
");
$stmtCheck->execute([':id' => $id, ':uid' => $uid, ':uid2' => $uid]);
$reg = $stmtCheck->fetch(PDO::FETCH_ASSOC);
if (!$reg) { http_response_code(404); echo json_encode(['ok' => false, 'erro' => 'nao encontrado']); exit; }

$_whereAcao = "IDRegistro = :id AND (FKUsuario = :uid OR FKCarteira IN (SELECT IDCarteira FROM Carteira WHERE FKUsuarioDono = :uid2))";

// Se a carteira do registro for compartilhada, cada ação vira uma linha no log de
// atividade (visível pro dono em "Atividade" na página de administrar carteira) — sem
// isso, o dono nunca ficava sabendo quando um convidado excluía/efetivava algo.
$_carteiraLogAR = null;
try {
    $stmtCartAR = $pdo->prepare("SELECT Compartilhada FROM Carteira WHERE IDCarteira = :cid");
    $stmtCartAR->execute([':cid' => $reg['FKCarteira']]);
    if ((int)($stmtCartAR->fetchColumn() ?: 0) === 1) $_carteiraLogAR = $reg['FKCarteira'];
} catch (PDOException $e) {
}
$_detalheLogAR = ($reg['TipoRegistro'] === 'receita' ? 'Receita' : 'Despesa')
    . ' de R$ ' . number_format((float)$reg['Valor'], 2, ',', '.') . ' — ' . $reg['Descricao'];

// Dono pode restringir convidados de excluir livremente (Permissões, na página de
// administrar carteira) — quem não é dono da carteira compartilhada fica bloqueado aqui.
if ($acao === 'excluir' && $_carteiraLogAR && carteiraPapelDoUsuario($pdo, $_carteiraLogAR, $uid) !== 'dono' && !carteiraPermiteConvidadoExcluir($pdo, $_carteiraLogAR)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'erro' => 'sem permissao para excluir nessa carteira']);
    exit;
}

try {
    if ($acao === 'excluir') {
        $pdo->prepare("DELETE FROM Registro WHERE $_whereAcao")
            ->execute([':id' => $id, ':uid' => $uid, ':uid2' => $uid]);
        if ($_carteiraLogAR) logAtividadeCarteira($pdo, $_carteiraLogAR, $uid, 'lancamento_excluido', $_detalheLogAR);
        echo json_encode(['ok' => true]);

    } elseif ($acao === 'nao_efetivar') {
        $pdo->prepare("UPDATE Registro SET StatusRegistro = 'pendente' WHERE $_whereAcao")
            ->execute([':id' => $id, ':uid' => $uid, ':uid2' => $uid]);
        if ($_carteiraLogAR) logAtividadeCarteira($pdo, $_carteiraLogAR, $uid, 'lancamento_estornado', $_detalheLogAR);
        echo json_encode(['ok' => true]);

    } elseif ($acao === 'efetivar') {
        $pdo->prepare("UPDATE Registro SET StatusRegistro = 'efetivado' WHERE $_whereAcao")
            ->execute([':id' => $id, ':uid' => $uid, ':uid2' => $uid]);
        if ($_carteiraLogAR) logAtividadeCarteira($pdo, $_carteiraLogAR, $uid, 'lancamento_efetivado', $_detalheLogAR);

        // Verifica conquista sempendencias (nenhum pendente + ao menos 1 efetivado no mês)
        try {
            $mesAtual = date('Y-m');
            $stmtPend = $pdo->prepare("
                SELECT
                    SUM(CASE WHEN StatusRegistro = 'pendente' THEN 1 ELSE 0 END) AS pendentes,
                    SUM(CASE WHEN StatusRegistro = 'efetivado' THEN 1 ELSE 0 END) AS efetivados
                FROM Registro
                WHERE FKUsuario = :uid
                  AND TipoRegistro IN ('receita', 'despesa')
                  AND DATE_FORMAT(DataVencimento, '%Y-%m') = :mes
            ");
            $stmtPend->execute([':uid' => $uid, ':mes' => $mesAtual]);
            $pendRow = $stmtPend->fetch(PDO::FETCH_ASSOC);
            if ($pendRow && (int)$pendRow['pendentes'] === 0 && (int)$pendRow['efetivados'] > 0) {
                concederConquistaParaUsuario($pdo, $uid, 'sempendencias');
            }
        } catch (PDOException $e) {}

        echo json_encode(['ok' => true]);

    } elseif ($acao === 'transferir') {
        $dest = trim($_POST['carteira_destino'] ?? '');
        if (!$dest) { echo json_encode(['ok' => false, 'erro' => 'destino invalido']); exit; }
        $stmtCk = $pdo->prepare("SELECT IDCarteira FROM Carteira WHERE IDCarteira = :dest AND FKUsuarioDono = :uid LIMIT 1");
        $stmtCk->execute([':dest' => $dest, ':uid' => $uid]);
        if (!$stmtCk->fetch()) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'erro' => 'carteira nao encontrada']);
            exit;
        }
        $pdo->prepare("UPDATE Registro SET FKCarteira = :dest WHERE $_whereAcao")
            ->execute([':dest' => $dest, ':id' => $id, ':uid' => $uid, ':uid2' => $uid]);
        if ($_carteiraLogAR) logAtividadeCarteira($pdo, $_carteiraLogAR, $uid, 'lancamento_transferido', $_detalheLogAR);
        echo json_encode(['ok' => true]);

    } else {
        echo json_encode(['ok' => false, 'erro' => 'acao desconhecida']);
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'erro' => 'erro interno']);
}
