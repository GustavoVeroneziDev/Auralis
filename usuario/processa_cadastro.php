<?php
require_once '../config/conexao.php';

if (!function_exists('gerarUuid')) {
    function gerarUuid() {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x', mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000, mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff));
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    $nome           = trim($_POST['nome']);
    $email          = trim($_POST['email']);
    $senha          = $_POST['senha'];
    $confirma_senha = $_POST['confirma_senha'];

    if ($senha !== $confirma_senha) {
        header("Location: cadastro.php?erro=senhas_diferentes");
        exit;
    }

    try {
        // Verifica se o E-mail já existe
        $sqlCheck = "SELECT Email FROM Usuario WHERE Email = :email LIMIT 1";
        $stmtCheck = $pdo->prepare($sqlCheck);
        $stmtCheck->execute([':email' => $email]);
        
        if ($stmtCheck->fetch()) {
            header("Location: cadastro.php?erro=email_existe");
            exit;
        }

        $senhaHash = password_hash($senha, PASSWORD_DEFAULT);
        $id_novo_usuario = gerarUuid();
        
        // GERA O TOKEN SECRETO DE 64 CARACTERES
        $token_ativacao = bin2hex(random_bytes(32));

        // Insere com Status = 'pendente' e salva o Token
        $sql = "INSERT INTO Usuario (
                    IDUsuario, Nome, Email, Senha, TipoPessoa, NivelAcesso, StatusConta, TokenAtivacao
                ) VALUES (
                    :id_usuario, :nome, :email, :senha, 'PF', 'Titular', 'pendente', :token
                )";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':id_usuario'   => $id_novo_usuario,
            ':nome'         => $nome,
            ':email'        => $email,
            ':senha'        => $senhaHash,
            ':token'        => $token_ativacao
        ]);

        // Injeção do Kit Inicial de Categorias
        $kitInicial = [
            ['nome' => 'Alimentação', 'tipo' => 'despesa', 'icone' => 'bi-cart3'],
            ['nome' => 'Moradia',     'tipo' => 'despesa', 'icone' => 'bi-house-door'],
            ['nome' => 'Transporte',  'tipo' => 'despesa', 'icone' => 'bi-car-front'],
            ['nome' => 'Saúde',       'tipo' => 'despesa', 'icone' => 'bi-heart-pulse'],
            ['nome' => 'Lazer',       'tipo' => 'despesa', 'icone' => 'bi-controller'],
            ['nome' => 'Salário',     'tipo' => 'receita', 'icone' => 'bi-cash-stack'],
            ['nome' => 'Investimentos','tipo' => 'receita', 'icone' => 'bi-graph-up-arrow'],
            ['nome' => 'Outros',      'tipo' => 'receita', 'icone' => 'bi-plus-circle-dotted']
        ];

        $sqlCat = "INSERT INTO Categoria (IDCategoria, NomeCategoria, TipoCategoria, IconeCategoria, FKUsuario) 
                   VALUES (:id_categoria, :nome, :tipo, :icone, :uid)";
        $stmtCat = $pdo->prepare($sqlCat);

        foreach ($kitInicial as $cat) {
            $stmtCat->execute([
                ':id_categoria' => gerarUuid(),
                ':nome'  => $cat['nome'],
                ':tipo'  => $cat['tipo'],
                ':icone' => $cat['icone'],
                ':uid'   => $id_novo_usuario
            ]);
        }

        // ==============================================================================
        // DISPARO DO E-MAIL DE ATIVAÇÃO
        // ==============================================================================
        $para = $email;
        $assunto = "Ative sua conta no Auralis";
        
        $link_ativacao = "https://meuauralis.com/usuario/ativar_conta.php?token=" . $token_ativacao;
        
        $mensagem = "Olá, " . explode(' ', $nome)[0] . "!\n\n";
        $mensagem .= "Falta apenas um passo para você assumir o controle da sua vida financeira.\n";
        $mensagem .= "Clique no link abaixo para ativar sua conta no Auralis:\n\n";
        $mensagem .= $link_ativacao . "\n\n";
        $mensagem .= "Se você não se cadastrou no Auralis, apenas ignore este e-mail.";

        $cabecalhos = "From: nao-responda@meuauralis.com\r\n" .
                      "Reply-To: suporte@meuauralis.com\r\n" .
                      "X-Mailer: PHP/" . phpversion();

        // Envia o e-mail
        mail($para, $assunto, $mensagem, $cabecalhos);

        // Manda o usuário para a tela de aviso!
        header("Location: aviso_ativacao.php");
        exit;

    } catch (PDOException $e) {
        header("Location: cadastro.php?erro=banco");
        exit;
    }
} else {
    header("Location: cadastro.php");
    exit;
}
?>