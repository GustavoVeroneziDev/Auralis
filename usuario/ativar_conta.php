<?php
require_once '../config/conexao.php';

if (isset($_GET['token']) && !empty($_GET['token'])) {
    $token = $_GET['token'];

    try {
        // Busca se existe alguém com esse token específico
        $sql = "SELECT IDUsuario FROM Usuario WHERE TokenAtivacao = :token AND StatusConta = 'pendente' LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':token' => $token]);
        $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($usuario) {
            // Achou! Ativa a conta e apaga o token (para não ser usado de novo)
            $sqlUpdate = "UPDATE Usuario SET StatusConta = 'ativo', TokenAtivacao = NULL WHERE IDUsuario = :uid";
            $stmtUpdate = $pdo->prepare($sqlUpdate);
            $stmtUpdate->execute([':uid' => $usuario['IDUsuario']]);

            // Manda pro login com sucesso
            header("Location: login.php?ativacao=sucesso");
            exit;
        } else {
            // Token não encontrado ou conta já ativada
            header("Location: login.php?ativacao=invalido");
            exit;
        }
    } catch (PDOException $e) {
        die("Erro ao processar ativação.");
    }
} else {
    header("Location: login.php");
    exit;
}
?>