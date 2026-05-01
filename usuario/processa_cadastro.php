<?php
require_once '../config/conexao.php';

// Função rápida para o PHP gerar UUIDs
if (!function_exists('gerarUuid')) {
    function gerarUuid() {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x', mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000, mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff));
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    $nome           = trim($_POST['nome']);
    $documento      = trim($_POST['documento']);
    $nascimento     = trim($_POST['nascimento']);
    $telefone       = trim($_POST['telefone']);
    $email          = trim($_POST['email']);
    $senha          = $_POST['senha'];
    $confirma_senha = $_POST['confirma_senha'];

    if ($senha !== $confirma_senha) {
        header("Location: cadastro.php?erro=senhas_diferentes");
        exit;
    }

    try {
        // ==============================================================================
        // A BLINDAGEM (UX) - Verifica se os dados únicos já existem antes de tentar salvar
        // ==============================================================================
        $sqlCheck = "SELECT Email, Documento, Telefone FROM Usuario WHERE Email = :email OR Documento = :documento OR Telefone = :telefone LIMIT 1";
        $stmtCheck = $pdo->prepare($sqlCheck);
        $stmtCheck->execute([
            ':email'     => $email,
            ':documento' => $documento,
            ':telefone'  => $telefone
        ]);
        
        $duplicado = $stmtCheck->fetch(PDO::FETCH_ASSOC);

        if ($duplicado) {
            if ($duplicado['Email'] === $email) {
                header("Location: cadastro.php?erro=email_existe");
                exit;
            }
            if ($duplicado['Documento'] === $documento) {
                header("Location: cadastro.php?erro=doc_existe");
                exit;
            }
            if ($duplicado['Telefone'] === $telefone) {
                header("Location: cadastro.php?erro=tel_existe");
                exit;
            }
        }
        // ==============================================================================

        $tipoPessoa = (strlen($documento) > 14) ? 'PJ' : 'PF';
        $senhaHash = password_hash($senha, PASSWORD_DEFAULT);
        $nivelAcesso = 'Titular';
        $id_novo_usuario = gerarUuid();

        $sql = "INSERT INTO Usuario (
                    IDUsuario, Nome, Documento, DataNascimento, Telefone, Email, Senha, TipoPessoa, NivelAcesso
                ) VALUES (
                    :id_usuario, :nome, :documento, :nascimento, :telefone, :email, :senha, :tipoPessoa, :nivelAcesso
                )";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':id_usuario'   => $id_novo_usuario,
            ':nome'         => $nome,
            ':documento'    => $documento,
            ':nascimento'   => $nascimento,
            ':telefone'     => $telefone,
            ':email'        => $email,
            ':senha'        => $senhaHash,
            ':tipoPessoa'   => $tipoPessoa,
            ':nivelAcesso'  => $nivelAcesso
        ]);

        // Injeção do Kit Inicial
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

        // Deu tudo certo, manda pro login com sucesso!
        header("Location: login.php?cadastro=sucesso");
        exit;

    } catch (PDOException $e) {
        // Se der algum erro bizarro de banco que não previmos, ele volta com um erro genérico bonitinho em vez de tela branca.
        header("Location: cadastro.php?erro=banco");
        exit;
    }
} else {
    header("Location: cadastro.php");
    exit;
}
?>