<?php
session_start();
require_once '../config/conexao.php';
require_once '../config/funcoes.php';
require_once '../config/email.php';
require_once 'chaves_google.php';

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
            // CORREÇÃO 1: Adicionado o 'Plano' no SELECT
            $sql = "SELECT IDUsuario, Nome, NivelAcesso, StatusConta, Plano, Tema, NavTipo FROM Usuario WHERE Email = :email LIMIT 1";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':email' => $email]);
            $usuario = $stmt->fetch();

            if ($usuario) {
                // JÁ EXISTE! 
                if ($usuario['StatusConta'] === 'pendente') {
                    $pdo->prepare("UPDATE Usuario SET StatusConta = 'ativo', TokenAtivacao = NULL WHERE IDUsuario = :uid")
                        ->execute([':uid' => $usuario['IDUsuario']]);
                }

                // Cria as sessões e entra
                session_regenerate_id(true);
                $_SESSION['usuario_id']   = $usuario['IDUsuario'];
                $_SESSION['usuario_nome'] = $usuario['Nome'];
                $_SESSION['nivel_acesso'] = strtolower($usuario['NivelAcesso']);

                // CORREÇÃO 2: Forçar minúsculas (strtolower)
                $_SESSION['plano']        = strtolower($usuario['Plano'] ?? 'free');
                $_SESSION['tema']         = strtolower($usuario['Tema'] ?? 'dark');
                $_SESSION['nav_tipo']     = strtolower($usuario['NavTipo'] ?? 'sidebar');

                header("Location: ../dashboard.php");
                exit;
            } else {
                // NÃO EXISTE! VAMOS CADASTRAR NA HORA
                $id_novo_usuario = gerarUuid();

                $senha_google = 'GOOGLE_SSO';
                $token_recuperacao = bin2hex(random_bytes(32));
                $expiracao = date('Y-m-d H:i:s', strtotime('+24 hours'));

                $sqlInsert = "INSERT INTO Usuario (IDUsuario, Nome, Email, Senha, TipoPessoa, NivelAcesso, StatusConta, TokenRecuperacao, TokenRecuperacaoExpiracao, NavTipo)
                              VALUES (:id, :nome, :email, :senha, 'PF', 'Titular', 'ativo', :token, :expiracao, 'sidebar')";
                $pdo->prepare($sqlInsert)->execute([
                    ':id' => $id_novo_usuario,
                    ':nome' => $nome,
                    ':email' => $email,
                    ':senha' => $senha_google,
                    ':token' => $token_recuperacao,
                    ':expiracao' => $expiracao
                ]);

                // Injeta as categorias iniciais (Google SSO) - Adicionei as 13 categorias aqui também para ficar igual ao seu kit oficial!
                $kitInicial = [
                    ['nome' => 'Alimentação', 'tipo' => 'despesa', 'icone' => 'bi-cart3'],
                    ['nome' => 'Moradia',     'tipo' => 'despesa', 'icone' => 'bi-house-door'],
                    ['nome' => 'Transporte',  'tipo' => 'despesa', 'icone' => 'bi-car-front'],
                    ['nome' => 'Saúde',       'tipo' => 'despesa', 'icone' => 'bi-heart-pulse'],
                    ['nome' => 'Educação',    'tipo' => 'despesa', 'icone' => 'bi-book'],
                    ['nome' => 'Lazer',       'tipo' => 'despesa', 'icone' => 'bi-controller'],
                    ['nome' => 'Assinaturas', 'tipo' => 'despesa', 'icone' => 'bi-play-btn'],
                    ['nome' => 'Vestuário',   'tipo' => 'despesa', 'icone' => 'bi-bag'],
                    ['nome' => 'Salário',         'tipo' => 'receita', 'icone' => 'bi-cash-stack'],
                    ['nome' => 'Rendimentos',     'tipo' => 'receita', 'icone' => 'bi-graph-up-arrow'],
                    ['nome' => 'Serviços/Free',   'tipo' => 'receita', 'icone' => 'bi-laptop'],
                    ['nome' => 'Ajuste de Saldo', 'tipo' => 'receita', 'icone' => 'bi-gear'],
                    ['nome' => 'Outros',          'tipo' => 'receita', 'icone' => 'bi-plus-circle-dotted']
                ];

                $sqlCat = "INSERT INTO Categoria (IDCategoria, NomeCategoria, TipoCategoria, IconeCategoria, FKUsuario) VALUES (:id_cat, :nome, :tipo, :icone, :uid)";
                $stmtCat = $pdo->prepare($sqlCat);
                foreach ($kitInicial as $cat) {
                    $stmtCat->execute([':id_cat' => gerarUuid(), ':nome' => $cat['nome'], ':tipo' => $cat['tipo'], ':icone' => $cat['icone'], ':uid' => $id_novo_usuario]);
                }

                // E-mail de Boas Vindas
                $link_criar_senha = "https://meuauralis.com/usuario/redefinir_senha.php?token=" . $token_recuperacao;
                $primeiro_nome = explode(' ', $nome)[0];

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
                            <h2>Bem-vindo ao Auralis, " . htmlspecialchars($primeiro_nome) . "!</h2>
                            <p>Sua conta foi criada com sucesso utilizando o seu acesso do Google.</p>
                            <p>Se você quiser acessar o sistema no futuro usando apenas o seu e-mail e uma senha manual, clique no botão abaixo para definir sua senha exclusiva (Link válido por 24 horas):</p>
                            
                            <div class='btn-container'>
                                <a href='" . $link_criar_senha . "' class='btn'>Criar Senha Manual</a>
                            </div>
                            
                            <p><strong>Atenção:</strong> Se você prefere continuar entrando apenas clicando no botão do Google, basta ignorar este e-mail. Sua conta já está ativa e perfeitamente segura!</p>
                        </div>
                        <div class='footer'>
                            <p>Este é um e-mail automático enviado pelo sistema. Não responda.</p>
                            <p>&copy; " . date('Y') . " Auralis. Todos os direitos reservados.</p>
                        </div>
                    </div>
                </body>
                </html>
                ";

                enviarEmail($email, "Bem-vindo ao Auralis - Crie sua senha de acesso", $mensagemHTML);

                // Loga o novo usuário e redireciona
                session_regenerate_id(true);
                $_SESSION['usuario_id']   = $id_novo_usuario;
                $_SESSION['usuario_nome'] = $nome;
                $_SESSION['nivel_acesso'] = 'titular';

                // CORREÇÃO 3: Define a sessão de plano para utilizadores novos pelo Google
                $_SESSION['plano']        = 'free';
                $_SESSION['tema']         = 'dark';
                $_SESSION['nav_tipo']     = 'sidebar';

                header("Location: ../dashboard.php");
                exit;
            }
        }
    }
}

// Se algo der errado na comunicação com o Google
header("Location: login.php?erro=google");
exit;
