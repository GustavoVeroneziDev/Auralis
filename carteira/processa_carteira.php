<?php
// 1. Inicia a sessão e barra intrusos
session_start();
if (!isset($_SESSION['usuario_id'])) {
    header("Location: ../usuario/login.php");
    exit;
}

// 2. Chama a conexão com o banco
require_once '../config/conexao.php';

// FUNÇÃO PARA GERAR UUID NO MYSQL
if (!function_exists('gerarUuid')) {
    function gerarUuid() {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x', mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000, mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff));
    }
}

// 3. Verifica se os dados vieram do botão de salvar
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    $tipoCarteira = trim($_POST['tipo_carteira']);
    $usuarioId = $_SESSION['usuario_id'];
    $id_carteira = isset($_POST['id_carteira']) ? trim($_POST['id_carteira']) : null;

    if (empty($tipoCarteira)) {
        die("Erro: O nome da carteira não pode ficar vazio.");
    }

    // ==========================================================
    // NOVA BLINDAGEM ANTI-DUPLICIDADE
    // ==========================================================
    try {
        $sqlCheck = "SELECT COUNT(*) FROM Carteira WHERE TipoCarteira = :tipoCarteira AND FKUsuarioDono = :usuarioId";
        if ($id_carteira) {
            $sqlCheck .= " AND IDCarteira != :idCarteira";
        }
        
        $stmtCheck = $pdo->prepare($sqlCheck);
        
        $params = [
            ':tipoCarteira' => $tipoCarteira,
            ':usuarioId' => $usuarioId
        ];
        if ($id_carteira) {
            $params[':idCarteira'] = $id_carteira;
        }
        
        $stmtCheck->execute($params);
        $jaExiste = $stmtCheck->fetchColumn();

        if ($jaExiste > 0) {
            header("Location: listar_carteiras.php?erro=duplicada");
            exit;
        }
    } catch (PDOException $e) {
        die("Erro ao verificar duplicidade: " . $e->getMessage());
    }
    // ==========================================================

    try {
        if ($id_carteira) {
            // MODO EDIÇÃO (UPDATE)
            $sql = "UPDATE Carteira SET TipoCarteira = :tipoCarteira WHERE IDCarteira = :idCarteira AND FKUsuarioDono = :usuarioId";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':tipoCarteira' => $tipoCarteira,
                ':idCarteira'   => $id_carteira,
                ':usuarioId'    => $usuarioId
            ]);
            
            // Se apenas editou o nome, volta para a lista de carteiras
            header("Location: listar_carteiras.php?sucesso=editada");
            
        } else {
            // MODO CRIAÇÃO (INSERT)
            $id_nova_carteira = gerarUuid(); // O PHP cria o ID único da carteira nova
            
            $sql = "INSERT INTO Carteira (IDCarteira, TipoCarteira, FKUsuarioDono) VALUES (:idCarteira, :tipoCarteira, :usuarioId)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':idCarteira'   => $id_nova_carteira,
                ':tipoCarteira' => $tipoCarteira,
                ':usuarioId'    => $usuarioId
            ]);
            
            // Se CRIOU uma carteira (seja pelo modal ou pelo botão), vai direto pro Dashboard!
            header("Location: ../dashboard.php?sucesso=criada");
        }

        exit;

    } catch (PDOException $e) {
        die("Erro ao salvar a carteira no banco: " . $e->getMessage());
    }
} else {
    header("Location: listar_carteiras.php");
    exit;
}
?>