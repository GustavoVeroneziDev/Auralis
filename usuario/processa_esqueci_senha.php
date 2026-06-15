<?php
// usuario/processa_esqueci_senha.php
require_once '../config/conexao.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email']);

    try {
        // Verifica se o usuário existe
        $sql = "SELECT IDUsuario, Nome FROM Usuario WHERE Email = :email LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':email' => $email]);
        $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$usuario) {
            // E-mail não cadastrado
            header("Location: esqueci_senha.php?erro=nao_encontrado");
            exit;
        }

        // Gera token de 64 caracteres e define expiração para +2 horas
        $token = bin2hex(random_bytes(32));
        $expiracao = date('Y-m-d H:i:s', strtotime('+2 hours'));

        // Salva o token no usuário
        $sqlUpdate = "UPDATE Usuario SET TokenRecuperacao = :token, TokenRecuperacaoExpiracao = :expiracao WHERE Email = :email";
        $stmtUpdate = $pdo->prepare($sqlUpdate);
        $stmtUpdate->execute([
            ':token' => $token,
            ':expiracao' => $expiracao,
            ':email' => $email
        ]);

        // Dispara o e-mail HTML elegante
        $para = $email;
        $assunto = "Recuperação de senha - Auralis";
        $link_redefinir = "https://meuauralis.com/usuario/redefinir_senha.php?token=" . $token;
        $primeiro_nome = explode(' ', $usuario['Nome'])[0];

        $mensagemHTML = "
        <!DOCTYPE html>
        <html lang='pt-BR'>
        <head>
            <meta charset='UTF-8'>
            <style>
                body { font-family: Arial, sans-serif; background-color: #f4f7f6; margin: 0; padding: 0; }
                .container { max-width: 550px; margin: 40px auto; background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 4px 10px rgba(0,0,0,0.05); }
                .header { background-color: #1a1a2e; padding: 25px; text-align: center; }
                .header img { max-height: 55px; }
                .content { padding: 40px 30px; color: #333333; line-height: 1.6; }
                .content h2 { color: #1a1a2e; font-size: 20px; margin-top: 0; }
                .btn-container { text-align: center; margin: 35px 0; }
                .btn { background-color: #0d6efd; color: #ffffff !important; padding: 14px 28px; text-decoration: none; border-radius: 6px; font-weight: bold; display: inline-block; }
                .footer { background-color: #f9fafb; padding: 20px; text-align: center; font-size: 12px; color: #888888; border-top: 1px solid #eeeeee; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <img src='https://meuauralis.com/geral/img/LogoAuralisSemEscudo.png' alt='Auralis'>
                </div>
                <div class='content'>
                    <h2>Olá, " . htmlspecialchars($primeiro_nome) . "!</h2>
                    <p>Você solicitou a redefinição de senha da sua conta no Auralis. Clique no botão abaixo para definir sua nova credencial de acesso:</p>
                    
                    <div class='btn-container'>
                        <a href='" . $link_redefinir . "' class='btn'>Redefinir Minha Senha</a>
                    </div>
                    
                    <p><strong>Atenção:</strong> Este link é válido por apenas 2 horas. Se você não solicitou essa redefinição, fique tranquilo, sua conta continua segura e você pode ignorar este e-mail.</p>
                </div>
                <div class='footer'>
                    <p>Este é um e-mail automático enviado pelo sistema. Não responda.</p>
                    <p>&copy; " . date('Y') . " Auralis. Todos os direitos reservados.</p>
                </div>
            </div>
        </body>
        </html>
        ";

        $cabecalhos  = "MIME-Version: 1.0\r\n";
        $cabecalhos .= "Content-type: text/html; charset=UTF-8\r\n";
        $cabecalhos .= "From: Auralis <suporte@meuauralis.com>\r\n";
        $cabecalhos .= "Reply-To: suporte@meuauralis.com\r\n";

        mail($para, $assunto, $mensagemHTML, $cabecalhos);

        header("Location: esqueci_senha.php?status=enviado");
        exit;

    } catch (PDOException $e) {
        header("Location: esqueci_senha.php?erro=banco");
        exit;
    }
} else {
    header("Location: esqueci_senha.php");
    exit;
}