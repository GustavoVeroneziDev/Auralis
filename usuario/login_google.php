<?php
session_start();
require_once '../config/conexao.php';
require_once 'chaves_google.php';


// Função para gerar ID único caso seja um cadastro novo
if (!function_exists('gerarUuid')) {
    function gerarUuid() {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x', mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000, mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff));
    }
}

// 1. O Google nos devolveu um código de autorização?
if (isset($_GET['code'])) {
    
    // 2. Trocamos esse código por um "Access Token"
    $tokenUrl = 'https://oauth2.googleapis.com/token';
    $postData = [
        'code' => $_GET['code'],
        'client_id' => $clientID,
        'client_secret' => $clientSecret,
        'redirect_uri' => $redirectUri,
        'grant_type' => 'authorization_code'
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $tokenUrl);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $tokenResponse = curl_exec($ch);
    curl_close($ch);

    $tokenData = json_decode($tokenResponse, true);

    if (isset($tokenData['access_token'])) {
        
        // 3. Usamos o Access Token para pegar os dados (Nome e E-mail) da pessoa
        $userInfoUrl = 'https://www.googleapis.com/oauth2/v2/userinfo';
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $userInfoUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $tokenData['access_token']]);
        $userInfoResponse = curl_exec($ch);
        curl_close($ch);

        $googleUser = json_decode($userInfoResponse, true);

        if (isset($googleUser['email'])) {
            $email = $googleUser['email'];
            $nome = $googleUser['name'];

            // 4. Procura se esse e-mail já existe no Auralis
            $sql = "SELECT IDUsuario, Nome, NivelAcesso, StatusConta FROM Usuario WHERE Email = :email LIMIT 1";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':email' => $email]);
            $usuario = $stmt->fetch();

            if ($usuario) {
                // JÁ EXISTE! 
                // Se a conta estava 'pendente', ativamos agora (pois o Google já validou o e-mail dela)
                if ($usuario['StatusConta'] === 'pendente') {
                    $pdo->prepare("UPDATE Usuario SET StatusConta = 'ativo', TokenAtivacao = NULL WHERE IDUsuario = :uid")
                        ->execute([':uid' => $usuario['IDUsuario']]);
                }

                // Cria as sessões e entra
                session_regenerate_id(true);
                $_SESSION['usuario_id']   = $usuario['IDUsuario'];
                $_SESSION['usuario_nome'] = $usuario['Nome'];
                $_SESSION['nivel_acesso'] = $usuario['NivelAcesso'];
                
                header("Location: ../dashboard.php");
                exit;

            } else {
                // NÃO EXISTE! VAMOS CADASTRAR NA HORA
                $id_novo_usuario = gerarUuid();
                $senhaFicticia = password_hash(bin2hex(random_bytes(10)), PASSWORD_DEFAULT); // Senha aleatória, ele só vai logar pelo Google

                // Insere o usuário já como 'ativo' (pois confiamos no Google)
                $sqlInsert = "INSERT INTO Usuario (IDUsuario, Nome, Email, Senha, TipoPessoa, NivelAcesso, StatusConta) 
                              VALUES (:id, :nome, :email, :senha, 'PF', 'Titular', 'ativo')";
                $pdo->prepare($sqlInsert)->execute([
                    ':id' => $id_novo_usuario, ':nome' => $nome, ':email' => $email, ':senha' => $senhaFicticia
                ]);

                // Injeta as categorias iniciais
                $kitInicial = [
                    ['nome' => 'Alimentação', 'tipo' => 'despesa', 'icone' => 'bi-cart3'],
                    ['nome' => 'Moradia',     'tipo' => 'despesa', 'icone' => 'bi-house-door'],
                    ['nome' => 'Transporte',  'tipo' => 'despesa', 'icone' => 'bi-car-front'],
                    ['nome' => 'Saúde',       'tipo' => 'despesa', 'icone' => 'bi-heart-pulse'],
                    ['nome' => 'Salário',     'tipo' => 'receita', 'icone' => 'bi-cash-stack'],
                    ['nome' => 'Outros',      'tipo' => 'receita', 'icone' => 'bi-plus-circle-dotted']
                ];

                $sqlCat = "INSERT INTO Categoria (IDCategoria, NomeCategoria, TipoCategoria, IconeCategoria, FKUsuario) VALUES (:id_cat, :nome, :tipo, :icone, :uid)";
                $stmtCat = $pdo->prepare($sqlCat);
                foreach ($kitInicial as $cat) {
                    $stmtCat->execute([':id_cat' => gerarUuid(), ':nome' => $cat['nome'], ':tipo' => $cat['tipo'], ':icone' => $cat['icone'], ':uid' => $id_novo_usuario]);
                }

                // Loga o novo usuário
                session_regenerate_id(true);
                $_SESSION['usuario_id']   = $id_novo_usuario;
                $_SESSION['usuario_nome'] = $nome;
                $_SESSION['nivel_acesso'] = 'Titular';
                
                header("Location: ../dashboard.php");
                exit;
            }
        }
    }
}

// Se algo der errado na comunicação com o Google
header("Location: login.php?erro=google");
exit;
?>