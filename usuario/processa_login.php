<?php
// 1. Inicia a "memória" da sessão
session_start();

// 2. Puxa a conexão com o banco
require_once '../config/conexao.php';
require_once '../config/funcoes.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email']);
    $senha = $_POST['senha'];
    $lembrar_me = isset($_POST['lembrar']) ? true : false;

    if (empty($email) || empty($senha)) {
        header("Location: login.php?erro=vazio");
        exit;
    }

    try {
        // CORREÇÃO: Adicionado o StatusConta na busca
        $sql = "SELECT IDUsuario, Nome, Email, Senha, NivelAcesso, StatusConta, Plano, Tema FROM Usuario WHERE Email = :email LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':email' => $email]);
        
        $usuario = $stmt->fetch();

        // Se o usuário existir e a senha bater...
        if ($usuario && password_verify($senha, $usuario['Senha'])) {
            
            // =========================================================
            // A PORTA DE SEGURANÇA (VERIFICAÇÃO DE E-MAIL)
            // =========================================================
            if (isset($usuario['StatusConta']) && $usuario['StatusConta'] === 'pendente') {
                // Barra a entrada e devolve para o login com aviso!
                header("Location: login.php?erro=pendente");
                exit;
            }
            // =========================================================

            session_regenerate_id(true);

            $_SESSION['usuario_id']   = $usuario['IDUsuario'];
            $_SESSION['usuario_nome'] = $usuario['Nome'];
            $_SESSION['nivel_acesso'] = strtolower($usuario['NivelAcesso']);
            $_SESSION['plano'] = strtolower($usuario['Plano'] ?? 'free');
            $_SESSION['tema']  = strtolower($usuario['Tema'] ?? 'dark');

            if ($lembrar_me) {
                $assinatura = hash_hmac('sha256', $usuario['IDUsuario'], AURALIS_COOKIE_SECRET);
                $conteudo_cookie = $usuario['IDUsuario'] . ':' . $assinatura;
                // Cookie válido por 30 dias
                setcookie('auralis_remember', $conteudo_cookie, time() + (86400 * 30), "/");
            }

            header("Location: ../dashboard.php"); 
            exit;
        } else {
            // E-mail ou senha errados
            header("Location: login.php?erro=invalido");
            exit;
        }

    } catch (PDOException $e) {
        // Redireciona com erro amigável ao invés de morrer a página com erro de banco
        header("Location: login.php?erro=banco");
        exit;
    }

} else {
    header("Location: login.php");
    exit;
}
?>