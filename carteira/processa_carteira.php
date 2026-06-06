<?php
session_start();
if (!isset($_SESSION['usuario_id'])) {
    header("Location: ../usuario/login.php");
    exit;
}

require_once '../config/conexao.php';

if (!function_exists('gerarUuid')) {
    function gerarUuid()
    {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x', mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000, mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff));
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $tipoCarteira = trim($_POST['tipo_carteira']);
    $usuarioId = $_SESSION['usuario_id'];
    $id_carteira = isset($_POST['id_carteira']) && $_POST['id_carteira'] !== '' ? trim($_POST['id_carteira']) : null;

    if (empty($tipoCarteira)) {
        header("Location: listar_carteiras.php?erro=vazio");
        exit;
    }

    try {
        $sqlCheck = "SELECT COUNT(*) FROM Carteira WHERE TipoCarteira = :tipoCarteira AND FKUsuarioDono = :usuarioId";
        if ($id_carteira) {
            $sqlCheck .= " AND IDCarteira != :idCarteira";
        }

        $stmtCheck = $pdo->prepare($sqlCheck);

        $params = [':tipoCarteira' => $tipoCarteira, ':usuarioId' => $usuarioId];
        if ($id_carteira) $params[':idCarteira'] = $id_carteira;

        $stmtCheck->execute($params);
        if ($stmtCheck->fetchColumn() > 0) {
            header("Location: listar_carteiras.php?erro=duplicada");
            exit;
        }
    } catch (PDOException $e) {
        header("Location: listar_carteiras.php?erro=banco");
        exit;
    }

    try {
        if ($id_carteira) {
            // Edição
            $sql = "UPDATE Carteira SET TipoCarteira = :tipoCarteira WHERE IDCarteira = :idCarteira AND FKUsuarioDono = :usuarioId";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':tipoCarteira' => $tipoCarteira, ':idCarteira' => $id_carteira, ':usuarioId' => $usuarioId]);

            header("Location: listar_carteiras.php?sucesso=editada");
        } else {
            // Criação
            $sql = "INSERT INTO Carteira (IDCarteira, TipoCarteira, FKUsuarioDono) VALUES (:idCarteira, :tipoCarteira, :usuarioId)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':idCarteira' => gerarUuid(), ':tipoCarteira' => $tipoCarteira, ':usuarioId' => $usuarioId]);

            // Retorna para o lugar certo dependendo de onde o modal foi aberto
            if (isset($_POST['origem']) && $_POST['origem'] === 'listar_carteiras') {
                header("Location: listar_carteiras.php?sucesso=criada");
            } else {
                header("Location: ../dashboard.php?sucesso=criada");
            }
        }
        exit;
    } catch (PDOException $e) {
        header("Location: listar_carteiras.php?erro=banco");
        exit;
    }
} else {
    header("Location: listar_carteiras.php");
    exit;
}
