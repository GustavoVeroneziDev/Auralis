<?php
require_once '../config/conexao.php';

// Função rápida para o PHP gerar UUIDs no padrão correto (Já que o MySQL não vai mais gerar sozinho)
function gerarUuid() {
    return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x', mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000, mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $nome           = trim($_POST['nome']);
        $documento      = trim($_POST['documento']);
        $nascimento     = trim($_POST['nascimento']);
        $telefone       = trim($_POST['telefone']);
        $email          = trim($_POST['email']);
        $senha          = $_POST['senha'];
        $confirma_senha = $_POST['confirma_senha'];

        if ($senha !== $confirma_senha) {
            die("Erro: As senhas não conferem.");
        }

        $tipoPessoa = (strlen($documento) > 14) ? 'PJ' : 'PF';
        $senhaHash = password_hash($senha, PASSWORD_DEFAULT);
        $nivelAcesso = 'Titular';
        
        // CORREÇÃO 2: Geramos o ID no PHP antes de salvar
        $id_novo_usuario = gerarUuid();

        // CORREÇÃO 3: Limpeza das aspas, injeção do campo IDUsuario e remoção do RETURNING
        $sql = "INSERT INTO Usuario (
                    IDUsuario, Nome, Documento, DataNascimento, Telefone, Email, Senha, TipoPessoa, NivelAcesso
                ) VALUES (
                    :id_usuario, :nome, :documento, :nascimento, :telefone, :email, :senha, :tipoPessoa, :nivelAcesso
                )";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':id_usuario'   => $id_novo_usuario, // Passando o ID gerado pelo PHP
            ':nome'         => $nome,
            ':documento'    => $documento,
            ':nascimento'   => $nascimento,
            ':telefone'     => $telefone,
            ':email'        => $email,
            ':senha'        => $senhaHash,
            ':tipoPessoa'   => $tipoPessoa,
            ':nivelAcesso'  => $nivelAcesso
        ]);

        if ($id_novo_usuario) {
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

            // CORREÇÃO 4: Adicionado IDCategoria (As categorias também precisam de UUID!)
            $sqlCat = "INSERT INTO Categoria (IDCategoria, NomeCategoria, TipoCategoria, IconeCategoria, FKUsuario) 
                       VALUES (:id_categoria, :nome, :tipo, :icone, :uid)";
            $stmtCat = $pdo->prepare($sqlCat);

            foreach ($kitInicial as $cat) {
                $stmtCat->execute([
                    ':id_categoria' => gerarUuid(), // Cada categoria ganha seu próprio ID
                    ':nome'  => $cat['nome'],
                    ':tipo'  => $cat['tipo'],
                    ':icone' => $cat['icone'],
                    ':uid'   => $id_novo_usuario // Vinculado ao ID que geramos lá em cima
                ]);
            }
        }

        header("Location: login.php?cadastro=sucesso");
        exit;

    } catch (PDOException $e) {
        die("Erro ao salvar no banco de dados: " . $e->getMessage());
    }
} else {
    header("Location: cadastro.php");
    exit;
}
?>