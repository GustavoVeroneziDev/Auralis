<?php
// 1. Inicia a sessão e barra intrusos
session_start();
if (!isset($_SESSION['usuario_id'])) {
    header("Location: ../usuario/login.php");
    exit;
}

// 2. Chama a conexão com o banco
require_once '../config/conexao.php';

// 3. Verifica se os dados vieram do botão de salvar
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Limpa os dados recebidos
    $tipoCarteira = trim($_POST['tipo_carteira']);
    $usuarioId = $_SESSION['usuario_id'];
    
    // Verifica se veio um ID (Modo Edição) ou se está vazio (Modo Criação)
    $id_carteira = isset($_POST['id_carteira']) ? trim($_POST['id_carteira']) : null;

// Validação de segurança (já existia no seu código)
    if (empty($tipoCarteira)) {
        die("Erro: O nome da carteira não pode ficar vazio.");
    }

    // ==========================================================
    // NOVA BLINDAGEM ANTI-DUPLICIDADE
    // ==========================================================
    try {
        // Checa se o usuário já tem uma carteira com ESSE nome exato
        $sqlCheck = 'SELECT COUNT(*) FROM "Carteira" WHERE "TipoCarteira" = :tipoCarteira AND "FKUsuarioDono" = :usuarioId';
        // Se for edição, temos que ignorar a própria carteira que estamos editando
        if ($id_carteira) {
            $sqlCheck .= ' AND "IDCarteira" != :idCarteira';
        }
        
        $stmtCheck = $pdo->prepare($sqlCheck);
        
        // Passa os parâmetros de forma dinâmica
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
            // Se já tem, joga ele de volta pra lista com um erro!
            header("Location: listar_carteiras.php?erro=duplicada");
            exit;
        }
    } catch (PDOException $e) {
        die("Erro ao verificar duplicidade: " . $e->getMessage());
    }
    // ==========================================================

    try {
        if ($id_carteira) {
            // ==========================================
            // MODO EDIÇÃO (UPDATE)
            // ==========================================
            // A trava "FKUsuarioDono = :usuarioId" garante que ele só edita se a carteira for dele
            $sql = 'UPDATE "Carteira" SET "TipoCarteira" = :tipoCarteira WHERE "IDCarteira" = :idCarteira AND "FKUsuarioDono" = :usuarioId';
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':tipoCarteira' => $tipoCarteira,
                ':idCarteira'   => $id_carteira,
                ':usuarioId'    => $usuarioId
            ]);
            
        } else {
            // ==========================================
            // MODO CRIAÇÃO (INSERT)
            // ==========================================
            $sql = 'INSERT INTO "Carteira" ("TipoCarteira", "FKUsuarioDono") VALUES (:tipoCarteira, :usuarioId)';
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':tipoCarteira' => $tipoCarteira,
                ':usuarioId'    => $usuarioId
            ]);
        }

        // Se deu tudo certo, volta para a lista de carteiras
        header("Location: listar_carteiras.php");
        exit;

    } catch (PDOException $e) {
        die("Erro ao salvar a carteira no banco: " . $e->getMessage());
    }
} else {
    // Se alguém tentar acessar direto pela URL
    header("Location: listar_carteiras.php");
    exit;
}
?>