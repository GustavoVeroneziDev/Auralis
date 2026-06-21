<?php
session_start();
if (!isset($_SESSION['usuario_id'])) {
    echo json_encode(['ok' => false, 'erro' => 'nao_autenticado']);
    exit;
}
require_once '../config/conexao.php';
require_once '../config/funcoes.php';

header('Content-Type: application/json');

$uid  = $_SESSION['usuario_id'];
$acao = $_POST['acao'] ?? '';

// ── Auto-migrate: garante que TipoRegistro suporta os tipos de transferência ──
try {
    $chkEnum = $pdo->query("SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='Registro' AND COLUMN_NAME='TipoRegistro'");
    $enumType = (string)$chkEnum->fetchColumn();
    if (strpos($enumType, 'transferencia_saida') === false) {
        $pdo->exec("ALTER TABLE Registro MODIFY COLUMN TipoRegistro ENUM('receita','despesa','cofrinho','cofrinho_retirada','transferencia_saida','transferencia_entrada') NOT NULL DEFAULT 'despesa'");
    }
} catch (PDOException $e) {
    echo json_encode(['ok' => false, 'erro' => 'migrate_falhou', 'detail' => $e->getMessage()]);
    exit;
}

// ── acao=criar ────────────────────────────────────────────────────────────────
if ($acao === 'criar') {
    $de     = trim($_POST['de']    ?? '');
    $para   = trim($_POST['para']  ?? '');
    $valor  = (float) str_replace(',', '.', $_POST['valor'] ?? '0');
    $desc   = trim($_POST['desc']  ?? 'Transferência entre carteiras');
    $data   = trim($_POST['data']  ?? date('Y-m-d'));
    $status = in_array($_POST['status'] ?? '', ['pendente','efetivado']) ? $_POST['status'] : 'efetivado';

    if ($de === $para)         { echo json_encode(['ok'=>false,'erro'=>'carteiras_iguais']); exit; }
    if ($valor <= 0)           { echo json_encode(['ok'=>false,'erro'=>'valor_invalido']);   exit; }
    if (empty($de) || empty($para)) { echo json_encode(['ok'=>false,'erro'=>'carteira_invalida']); exit; }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data)) { echo json_encode(['ok'=>false,'erro'=>'data_invalida']); exit; }

    // Confirma que ambas as carteiras pertencem ao usuário
    try {
        $chkCarts = $pdo->prepare("SELECT COUNT(*) FROM Carteira WHERE IDCarteira IN (:de,:para) AND FKUsuarioDono = :uid");
        $chkCarts->execute([':de' => $de, ':para' => $para, ':uid' => $uid]);
        if ((int)$chkCarts->fetchColumn() !== 2) {
            echo json_encode(['ok'=>false,'erro'=>'carteira_invalida']);
            exit;
        }
    } catch (PDOException $e) {
        echo json_encode(['ok'=>false,'erro'=>'db_erro','detail'=>$e->getMessage()]);
        exit;
    }

    $grupo   = gerarUuid();
    $idSaida = gerarUuid();
    $idEntr  = gerarUuid();

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            INSERT INTO Registro
              (IDRegistro, TipoRegistro, Valor, Descricao, MomentoRegistro, DataVencimento,
               StatusRegistro, Recorrente, DiaVencimento, FKCarteira, FKUsuario, GrupoParcela)
            VALUES
              (:id, :tipo, :valor, :desc, :data, :data,
               :status, 0, NULL, :carteira, :uid, :grupo)
        ");

        $stmt->execute([
            ':id'       => $idSaida,
            ':tipo'     => 'transferencia_saida',
            ':valor'    => $valor,
            ':desc'     => $desc,
            ':data'     => $data,
            ':status'   => $status,
            ':carteira' => $de,
            ':uid'      => $uid,
            ':grupo'    => $grupo,
        ]);

        $stmt->execute([
            ':id'       => $idEntr,
            ':tipo'     => 'transferencia_entrada',
            ':valor'    => $valor,
            ':desc'     => $desc,
            ':data'     => $data,
            ':status'   => $status,
            ':carteira' => $para,
            ':uid'      => $uid,
            ':grupo'    => $grupo,
        ]);

        $pdo->commit();
        echo json_encode(['ok' => true]);
    } catch (PDOException $e) {
        $pdo->rollBack();
        echo json_encode(['ok'=>false,'erro'=>'insert_falhou','detail'=>$e->getMessage()]);
    }
    exit;
}

// ── acao=excluir ──────────────────────────────────────────────────────────────
if ($acao === 'excluir') {
    $grupo = trim($_POST['grupo'] ?? '');
    if (empty($grupo)) { echo json_encode(['ok'=>false,'erro'=>'grupo_invalido']); exit; }

    try {
        $chk = $pdo->prepare("SELECT COUNT(*) FROM Registro WHERE GrupoParcela = :g AND FKUsuario = :uid AND TipoRegistro IN ('transferencia_saida','transferencia_entrada')");
        $chk->execute([':g' => $grupo, ':uid' => $uid]);
        if ((int)$chk->fetchColumn() < 1) {
            echo json_encode(['ok'=>false,'erro'=>'transferencia_nao_encontrada']);
            exit;
        }

        $pdo->prepare("DELETE FROM Registro WHERE GrupoParcela = :g AND FKUsuario = :uid AND TipoRegistro IN ('transferencia_saida','transferencia_entrada')")
            ->execute([':g' => $grupo, ':uid' => $uid]);

        echo json_encode(['ok' => true]);
    } catch (PDOException $e) {
        echo json_encode(['ok'=>false,'erro'=>'delete_falhou','detail'=>$e->getMessage()]);
    }
    exit;
}

echo json_encode(['ok'=>false,'erro'=>'acao_invalida']);
