<?php
session_start();
if (!isset($_SESSION['usuario_id'])) {
    header("Location: ../usuario/login.php");
    exit;
}

require_once '../config/conexao.php';
require_once '../config/funcoes.php';

$uid  = $_SESSION['usuario_id'];
$acao = $_POST['acao'] ?? $_GET['acao'] ?? '';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: ../analises.php#cofrinhos");
    exit;
}

$voltar = "../analises.php";

// ── Criar cofrinho ──────────────────────────────────────────────────────────
if ($acao === 'criar') {
    $nome       = trim($_POST['nome'] ?? '');
    $icone      = trim($_POST['icone'] ?? 'bi-piggy-bank');
    $cor        = trim($_POST['cor'] ?? '#f59e0b');
    $carteira   = trim($_POST['carteira'] ?? '');
    $meta       = (isset($_POST['meta']) && $_POST['meta'] !== '') ? (float) $_POST['meta'] : null;
    $dataLimite = trim($_POST['data_limite'] ?? '') ?: null;

    $iconesPermitidos = ['bi-piggy-bank','bi-house','bi-car-front','bi-airplane','bi-heart','bi-stars','bi-trophy','bi-gift'];
    $coresPermitidas  = ['#f59e0b','#7c3aed','#2563eb','#16a34a','#dc2626','#0891b2','#374151'];

    if (empty($nome) || empty($carteira)
        || !in_array($icone, $iconesPermitidos)
        || !in_array($cor, $coresPermitidas)) {
        header("Location: {$voltar}?erro=cofrinho_invalido#cofrinhos");
        exit;
    }

    try {
        $stmtV = $pdo->prepare("SELECT IDCarteira FROM Carteira WHERE IDCarteira = :id AND FKUsuarioDono = :uid");
        $stmtV->execute([':id' => $carteira, ':uid' => $uid]);
        if (!$stmtV->fetch()) {
            header("Location: {$voltar}?erro=cofrinho_invalido#cofrinhos");
            exit;
        }
    } catch (PDOException $e) {
        header("Location: {$voltar}?erro=banco#cofrinhos");
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
        header("Location: {$voltar}?sucesso=cofrinho_criado#cofrinhos");
    } catch (PDOException $e) {
        header("Location: {$voltar}?erro=banco#cofrinhos");
    }
    exit;
}

// ── Editar cofrinho ─────────────────────────────────────────────────────────
if ($acao === 'editar') {
    $idCofrinho = trim($_POST['id_cofrinho'] ?? '');
    $nome       = trim($_POST['nome'] ?? '');
    $icone      = trim($_POST['icone'] ?? 'bi-piggy-bank');
    $cor        = trim($_POST['cor'] ?? '#f59e0b');
    $meta       = (isset($_POST['meta']) && $_POST['meta'] !== '') ? (float) $_POST['meta'] : null;
    $dataLimite = trim($_POST['data_limite'] ?? '') ?: null;

    $iconesPermitidos = ['bi-piggy-bank','bi-house','bi-car-front','bi-airplane','bi-heart','bi-stars','bi-trophy','bi-gift'];
    $coresPermitidas  = ['#f59e0b','#7c3aed','#2563eb','#16a34a','#dc2626','#0891b2','#374151'];

    if (empty($idCofrinho) || empty($nome)
        || !in_array($icone, $iconesPermitidos)
        || !in_array($cor, $coresPermitidas)) {
        header("Location: {$voltar}?erro=cofrinho_invalido#cofrinhos");
        exit;
    }

    try {
        $stmt = $pdo->prepare("
            UPDATE Cofrinho SET Nome=:nome, Icone=:icone, Cor=:cor, ValorMeta=:meta, DataLimite=:data_limite
            WHERE IDCofrinho=:id AND FKUsuario=:uid
        ");
        $stmt->execute([
            ':id'         => $idCofrinho,
            ':uid'        => $uid,
            ':nome'       => $nome,
            ':icone'      => $icone,
            ':cor'        => $cor,
            ':meta'       => $meta,
            ':data_limite'=> $dataLimite,
        ]);
        header("Location: {$voltar}?sucesso=cofrinho_editado#cofrinhos");
    } catch (PDOException $e) {
        header("Location: {$voltar}?erro=banco#cofrinhos");
    }
    exit;
}

// ── Depositar no cofrinho ───────────────────────────────────────────────────
if ($acao === 'depositar') {
    $idCofrinho = trim($_POST['id_cofrinho'] ?? '');
    $valor      = round((float) ($_POST['valor'] ?? 0), 2);
    $descricao  = trim($_POST['descricao'] ?? '') ?: null;

    if (empty($idCofrinho) || $valor <= 0) {
        header("Location: {$voltar}?erro=deposito_invalido#cofrinhos");
        exit;
    }

    try {
        $stmtCof = $pdo->prepare("SELECT IDCofrinho, FKCarteira FROM Cofrinho WHERE IDCofrinho=:id AND FKUsuario=:uid AND Ativo=1");
        $stmtCof->execute([':id' => $idCofrinho, ':uid' => $uid]);
        $cofrinho = $stmtCof->fetch(PDO::FETCH_ASSOC);
        if (!$cofrinho) {
            header("Location: {$voltar}?erro=cofrinho_invalido#cofrinhos");
            exit;
        }
    } catch (PDOException $e) {
        header("Location: {$voltar}?erro=banco#cofrinhos");
        exit;
    }

    try {
        $hoje = date('Y-m-d');
        $stmt = $pdo->prepare("
            INSERT INTO Registro
              (IDRegistro, TipoRegistro, Valor, Descricao, MomentoRegistro, DataVencimento,
               StatusRegistro, Recorrente, DiaVencimento, FKCarteira, FKUsuario, FKCofrinho)
            VALUES
              (:id, 'cofrinho', :valor, :descricao, NOW(), :hoje,
               'efetivado', 0, NULL, :carteira, :uid, :cofrinho)
        ");
        $stmt->execute([
            ':id'       => gerarUuid(),
            ':valor'    => $valor,
            ':descricao'=> $descricao ?? 'Depósito',
            ':hoje'     => $hoje,
            ':carteira' => $cofrinho['FKCarteira'],
            ':uid'      => $uid,
            ':cofrinho' => $idCofrinho,
        ]);
        header("Location: {$voltar}?sucesso=deposito_realizado#cofrinhos");
    } catch (PDOException $e) {
        header("Location: {$voltar}?erro=banco&detail=" . urlencode($e->getMessage()) . "#cofrinhos");
    }
    exit;
}

// ── Retirar do cofrinho ─────────────────────────────────────────────────────
if ($acao === 'retirar') {
    $idCofrinho = trim($_POST['id_cofrinho'] ?? '');
    $valor      = round((float) ($_POST['valor'] ?? 0), 2);
    $descricao  = trim($_POST['descricao'] ?? '') ?: null;

    if (empty($idCofrinho) || $valor <= 0) {
        header("Location: {$voltar}?erro=retirada_invalida#cofrinhos");
        exit;
    }

    try {
        $stmtCof = $pdo->prepare("SELECT IDCofrinho, FKCarteira FROM Cofrinho WHERE IDCofrinho=:id AND FKUsuario=:uid AND Ativo=1");
        $stmtCof->execute([':id' => $idCofrinho, ':uid' => $uid]);
        $cofrinho = $stmtCof->fetch(PDO::FETCH_ASSOC);
        if (!$cofrinho) {
            header("Location: {$voltar}?erro=cofrinho_invalido#cofrinhos");
            exit;
        }
    } catch (PDOException $e) {
        header("Location: {$voltar}?erro=banco#cofrinhos");
        exit;
    }

    try {
        $hoje = date('Y-m-d');
        $stmt = $pdo->prepare("
            INSERT INTO Registro
              (IDRegistro, TipoRegistro, Valor, Descricao, MomentoRegistro, DataVencimento,
               StatusRegistro, Recorrente, DiaVencimento, FKCarteira, FKUsuario, FKCofrinho)
            VALUES
              (:id, 'cofrinho_retirada', :valor, :descricao, NOW(), :hoje,
               'efetivado', 0, NULL, :carteira, :uid, :cofrinho)
        ");
        $stmt->execute([
            ':id'       => gerarUuid(),
            ':valor'    => $valor,
            ':descricao'=> $descricao ?? 'Retirada',
            ':hoje'     => $hoje,
            ':carteira' => $cofrinho['FKCarteira'],
            ':uid'      => $uid,
            ':cofrinho' => $idCofrinho,
        ]);
        header("Location: {$voltar}?sucesso=retirada_realizada#cofrinhos");
    } catch (PDOException $e) {
        header("Location: {$voltar}?erro=banco#cofrinhos");
    }
    exit;
}

// ── Reajustar saldo do cofrinho ─────────────────────────────────────────────
if ($acao === 'reajustar') {
    $idCofrinho   = trim($_POST['id_cofrinho'] ?? '');
    $novoValor    = round((float) ($_POST['novo_valor'] ?? 0), 2);
    $valorAtualDB = round((float) ($_POST['valor_atual'] ?? 0), 2);

    if (empty($idCofrinho) || $novoValor < 0) {
        header("Location: {$voltar}?erro=reajuste_invalido#cofrinhos");
        exit;
    }

    try {
        $stmtCof = $pdo->prepare("SELECT IDCofrinho, FKCarteira FROM Cofrinho WHERE IDCofrinho=:id AND FKUsuario=:uid AND Ativo=1");
        $stmtCof->execute([':id' => $idCofrinho, ':uid' => $uid]);
        $cofrinho = $stmtCof->fetch(PDO::FETCH_ASSOC);
        if (!$cofrinho) {
            header("Location: {$voltar}?erro=cofrinho_invalido#cofrinhos");
            exit;
        }
    } catch (PDOException $e) {
        header("Location: {$voltar}?erro=banco#cofrinhos");
        exit;
    }

    $delta = round($novoValor - $valorAtualDB, 2);
    if (abs($delta) < 0.01) {
        header("Location: {$voltar}?sucesso=sem_alteracao#cofrinhos");
        exit;
    }

    $tipo      = $delta > 0 ? 'cofrinho' : 'cofrinho_retirada';
    $valorAbs  = abs($delta);
    $descricao = 'Reajuste';

    try {
        $hoje = date('Y-m-d');
        $stmt = $pdo->prepare("
            INSERT INTO Registro
              (IDRegistro, TipoRegistro, Valor, Descricao, MomentoRegistro, DataVencimento,
               StatusRegistro, Recorrente, DiaVencimento, FKCarteira, FKUsuario, FKCofrinho)
            VALUES
              (:id, :tipo, :valor, :descricao, NOW(), :hoje,
               'efetivado', 0, NULL, :carteira, :uid, :cofrinho)
        ");
        $stmt->execute([
            ':id'       => gerarUuid(),
            ':tipo'     => $tipo,
            ':valor'    => $valorAbs,
            ':descricao'=> $descricao,
            ':hoje'     => $hoje,
            ':carteira' => $cofrinho['FKCarteira'],
            ':uid'      => $uid,
            ':cofrinho' => $idCofrinho,
        ]);
        header("Location: {$voltar}?sucesso=reajuste_feito#cofrinhos");
    } catch (PDOException $e) {
        header("Location: {$voltar}?erro=banco#cofrinhos");
    }
    exit;
}

// ── Excluir cofrinho (permanente) ───────────────────────────────────────────
if ($acao === 'excluir') {
    $idCofrinho = trim($_POST['id_cofrinho'] ?? '');

    if (empty($idCofrinho)) {
        header("Location: {$voltar}?erro=cofrinho_invalido#cofrinhos");
        exit;
    }

    try {
        $pdo->beginTransaction();
        // Apaga os registros de cofrinho e cofrinho_retirada vinculados
        $stmtReg = $pdo->prepare("DELETE FROM Registro WHERE FKCofrinho=:id AND FKUsuario=:uid");
        $stmtReg->execute([':id' => $idCofrinho, ':uid' => $uid]);
        // Apaga o cofrinho
        $stmtCof = $pdo->prepare("DELETE FROM Cofrinho WHERE IDCofrinho=:id AND FKUsuario=:uid");
        $stmtCof->execute([':id' => $idCofrinho, ':uid' => $uid]);
        $pdo->commit();
        header("Location: {$voltar}?sucesso=cofrinho_excluido#cofrinhos");
    } catch (PDOException $e) {
        $pdo->rollBack();
        header("Location: {$voltar}?erro=banco#cofrinhos");
    }
    exit;
}

header("Location: {$voltar}#cofrinhos");
exit;
