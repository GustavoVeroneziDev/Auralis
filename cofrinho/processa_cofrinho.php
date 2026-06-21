<?php
session_start();
if (!isset($_SESSION['usuario_id'])) {
    header("Location: ../usuario/login.php");
    exit;
}

require_once '../config/conexao.php';
require_once '../config/funcoes.php';

$uid  = $_SESSION['usuario_id'];
$acao = $_GET['acao'] ?? $_POST['acao'] ?? '';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: ../analises.php#cofrinhos");
    exit;
}

// ── Criar cofrinho ──────────────────────────────────────────────────────────
if ($acao === 'criar') {
    $nome       = trim($_POST['nome'] ?? '');
    $icone      = trim($_POST['icone'] ?? 'bi-piggy-bank');
    $cor        = trim($_POST['cor'] ?? '#f59e0b');
    $carteira   = trim($_POST['carteira'] ?? '');
    $meta       = $_POST['meta'] !== '' ? (float) $_POST['meta'] : null;
    $dataLimite = trim($_POST['data_limite'] ?? '') ?: null;

    $iconesPermitidos = ['bi-piggy-bank','bi-house','bi-car-front','bi-airplane','bi-heart','bi-stars','bi-trophy','bi-gift'];
    $coresPermitidas  = ['#f59e0b','#7c3aed','#2563eb','#16a34a','#dc2626','#0891b2','#374151'];

    if (empty($nome) || empty($carteira)
        || !in_array($icone, $iconesPermitidos)
        || !in_array($cor, $coresPermitidas)) {
        header("Location: ../analises.php?erro=cofrinho_invalido#cofrinhos");
        exit;
    }

    // Verifica que a carteira pertence ao usuário
    try {
        $stmtV = $pdo->prepare("SELECT IDCarteira FROM Carteira WHERE IDCarteira = :id AND FKUsuarioDono = :uid");
        $stmtV->execute([':id' => $carteira, ':uid' => $uid]);
        if (!$stmtV->fetch()) {
            header("Location: ../analises.php?erro=cofrinho_invalido#cofrinhos");
            exit;
        }
    } catch (PDOException $e) {
        header("Location: ../analises.php?erro=banco#cofrinhos");
        exit;
    }

    try {
        $stmt = $pdo->prepare("
            INSERT INTO Cofrinho (IDCofrinho, FKUsuario, FKCarteira, Nome, Icone, Cor, ValorMeta, DataLimite)
            VALUES (:id, :uid, :carteira, :nome, :icone, :cor, :meta, :data_limite)
        ");
        $stmt->execute([
            ':id'         => gerarUuid(),
            ':uid'        => $uid,
            ':carteira'   => $carteira,
            ':nome'       => $nome,
            ':icone'      => $icone,
            ':cor'        => $cor,
            ':meta'       => $meta,
            ':data_limite'=> $dataLimite,
        ]);
        header("Location: ../analises.php?sucesso=cofrinho_criado#cofrinhos");
    } catch (PDOException $e) {
        header("Location: ../analises.php?erro=banco#cofrinhos");
    }
    exit;
}

// ── Depositar no cofrinho ───────────────────────────────────────────────────
if ($acao === 'depositar') {
    $idCofrinho = trim($_POST['id_cofrinho'] ?? '');
    $valor      = (float) ($_POST['valor'] ?? 0);

    if (empty($idCofrinho) || $valor <= 0) {
        header("Location: ../analises.php?erro=deposito_invalido#cofrinhos");
        exit;
    }

    // Verifica que o cofrinho pertence ao usuário e está ativo
    try {
        $stmtCof = $pdo->prepare("SELECT IDCofrinho, FKCarteira FROM Cofrinho WHERE IDCofrinho = :id AND FKUsuario = :uid AND Ativo = 1");
        $stmtCof->execute([':id' => $idCofrinho, ':uid' => $uid]);
        $cofrinho = $stmtCof->fetch(PDO::FETCH_ASSOC);
        if (!$cofrinho) {
            header("Location: ../analises.php?erro=cofrinho_invalido#cofrinhos");
            exit;
        }
    } catch (PDOException $e) {
        header("Location: ../analises.php?erro=banco#cofrinhos");
        exit;
    }

    try {
        $stmt = $pdo->prepare("
            INSERT INTO Registro
              (IDRegistro, TipoRegistro, Valor, ValorJuros, Descricao, MomentoRegistro, StatusRegistro,
               Recorrente, FKCarteira, FKUsuario, FKCofrinho)
            VALUES
              (:id, 'cofrinho', :valor, 0, :descricao, NOW(), 'efetivado',
               0, :carteira, :uid, :cofrinho)
        ");
        $stmt->execute([
            ':id'       => gerarUuid(),
            ':valor'    => $valor,
            ':descricao'=> 'Depósito cofrinho',
            ':carteira' => $cofrinho['FKCarteira'],
            ':uid'      => $uid,
            ':cofrinho' => $idCofrinho,
        ]);
        header("Location: ../analises.php?sucesso=deposito_realizado#cofrinhos");
    } catch (PDOException $e) {
        header("Location: ../analises.php?erro=banco#cofrinhos");
    }
    exit;
}

// ── Encerrar cofrinho ───────────────────────────────────────────────────────
if ($acao === 'encerrar') {
    $idCofrinho = trim($_POST['id_cofrinho'] ?? '');

    if (empty($idCofrinho)) {
        header("Location: ../analises.php?erro=cofrinho_invalido#cofrinhos");
        exit;
    }

    try {
        $stmt = $pdo->prepare("UPDATE Cofrinho SET Ativo = 0 WHERE IDCofrinho = :id AND FKUsuario = :uid");
        $stmt->execute([':id' => $idCofrinho, ':uid' => $uid]);
        header("Location: ../analises.php?sucesso=cofrinho_encerrado#cofrinhos");
    } catch (PDOException $e) {
        header("Location: ../analises.php?erro=banco#cofrinhos");
    }
    exit;
}

header("Location: ../analises.php#cofrinhos");
exit;
