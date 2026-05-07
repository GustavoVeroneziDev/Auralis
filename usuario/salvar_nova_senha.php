<?php
// usuario/salvar_nova_senha.php
require_once '../config/conexao.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['token'] ?? '';
    $senha = $_POST['senha'] ?? '';
    $confirma_senha = $_POST['confirma_senha'] ?? '';

    if ($senha !== $confirma_senha) {
        header("Location: redefinir_senha.php?token=" . urlencode($token) . "&erro=senhas_diferentes");
        exit;
    }

    try {
        // Verifica novamente se o token é válido e ativo
        $sql = "SELECT IDUsuario FROM Usuario WHERE TokenRecuperacao = :token AND TokenRecuperacaoExpiracao > NOW() LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':token' => $token]);
        $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$usuario) {
            header("Location: esqueci_senha.php?erro=token_invalido");
            exit;
        }

        // Gera o novo hash da senha e limpa os campos de token (evita reuso)
        $novoHash = password_hash($senha, PASSWORD_DEFAULT);

        $sqlUpdate = "UPDATE Usuario SET 
                        Senha = :senha, 
                        TokenRecuperacao = NULL, 
                        TokenRecuperacaoExpiracao = NULL 
                      WHERE IDUsuario = :id";
                      
        $stmtUpdate = $pdo->prepare($sqlUpdate);
        $stmtUpdate->execute([
            ':senha' => $novoHash,
            ':id' => $usuario['IDUsuario']
        ]);

        // Sucesso! Manda o usuário para a tela de login já com uma mensagem amigável
        header("Location: login.php?sucesso=senha_redefinida");
        exit;

    } catch (PDOException $e) {
        header("Location: esqueci_senha.php?erro=banco");
        exit;
    }
} else {
    header("Location: login.php");
    exit;
}