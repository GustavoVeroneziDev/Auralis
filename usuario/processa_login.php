<?php
// 1. Inicia a "memória" da sessão
session_start();

// 2. Puxa a conexão com o banco
require_once '../config/conexao.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email']);
    $senha = $_POST['senha'];
    $lembrar_me = isset($_POST['lembrar']) ? true : false;

    if (empty($email) || empty($senha)) {
        header("Location: login.php?erro=vazio");
        exit;
    }

    try {
        // CORREÇÃO 1: Aspas duplas removidas do SQL
        $sql = "SELECT IDUsuario, Nome, Senha, NivelAcesso FROM Usuario WHERE Email = :email LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':email' => $email]);
        
        $usuario = $stmt->fetch();

        if ($usuario && password_verify($senha, $usuario['Senha'])) {
            session_regenerate_id(true);

            $_SESSION['usuario_id']   = $usuario['IDUsuario'];
            $_SESSION['usuario_nome'] = $usuario['Nome'];
            $_SESSION['nivel_acesso'] = $usuario['NivelAcesso'];

            if ($lembrar_me) {
                $chave_secreta = "Auralis2026_UltraSecretKey";
                $assinatura = hash_hmac('sha256', $usuario['IDUsuario'], $chave_secreta);
                $conteudo_cookie = $usuario['IDUsuario'] . ':' . $assinatura;
                setcookie('auralis_remember', $conteudo_cookie, time() + (86400 * 30), "/");
            }

            header("Location: ../dashboard.php"); 
            exit;
        } else {
            header("Location: login.php?erro=invalido");
            exit;
        }

    } catch (PDOException $e) {
        die("Erro ao tentar fazer login: " . $e->getMessage());
    }

} else {
    header("Location: login.php");
    exit;
}
?>