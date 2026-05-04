<?php
require_once '../config/conexao.php';

if (!function_exists('gerarUuid')) {
    function gerarUuid() {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x', mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000, mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff));
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Pegamos APENAS o essencial agora
    $nome           = trim($_POST['nome']);
    $email          = trim($_POST['email']);
    $senha          = $_POST['senha'];
    $confirma_senha = $_POST['confirma_senha'];

    if ($senha !== $confirma_senha) {
        header("Location: cadastro.php?erro=senhas_diferentes");
        exit;
    }

    try {
        // ==============================================================================
        // BLINDAGEM EXPRESS - Verifica APENAS se o E-mail já existe
        // ==============================================================================
        $sqlCheck = "SELECT Email FROM Usuario WHERE Email = :email LIMIT 1";
        $stmtCheck = $pdo->prepare($sqlCheck);
        $stmtCheck->execute([':email' => $email]);
        
        if ($stmtCheck->fetch()) {
            header("Location: cadastro.php?erro=email_existe");
            exit;
        }
        // ==============================================================================

        $senhaHash = password_hash($senha, PASSWORD_DEFAULT);
        $id_novo_usuario = gerarUuid();

        // O SQL agora insere "NULL" nos campos que deixamos para o futuro e força 'PF' no TipoPessoa
        $sql = "INSERT INTO Usuario (
                    IDUsuario, Nome, Email, Senha, TipoPessoa, NivelAcesso, Documento, DataNascimento, Telefone
                ) VALUES (
                    :id_usuario, :nome, :email, :senha, 'PF', 'Titular', NULL, NULL, NULL
                )";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':id_usuario'   => $id_novo_usuario,
            ':nome'         => $nome,
            ':email'        => $email,
            ':senha'        => $senhaHash
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

        // Deu tudo certo, manda pro login!
        header("Location: login.php?cadastro=sucesso");
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