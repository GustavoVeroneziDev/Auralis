<?php
require_once '../config/conexao.php';
require_once '../config/funcoes.php';

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

        // Gera código de indicação único para este usuário
        $codigoIndicacao = gerarCodigoIndicacao($pdo);

        // Resolve quem indicou (via ?ref= na URL, passado como campo oculto)
        $refCode      = strtoupper(trim($_POST['ref_code'] ?? ''));
        $fkIndicadoPor = null;
        if ($refCode) {
            $stmtRef = $pdo->prepare(
                "SELECT IDUsuario FROM Usuario WHERE CodigoIndicacao = :c AND StatusConta = 'ativo' LIMIT 1"
            );
            $stmtRef->execute([':c' => $refCode]);
            $indicadorId = $stmtRef->fetchColumn();
            if ($indicadorId) {
                $fkIndicadoPor = $indicadorId;
            }
        }

        // Insere com Status = 'pendente' e salva o Token
        $sql = "INSERT INTO Usuario (
                    IDUsuario, Nome, Email, Senha, TipoPessoa, NivelAcesso, StatusConta, TokenAtivacao,
                    CodigoIndicacao, FKIndicadoPor, NavTipo
                ) VALUES (
                    :id_usuario, :nome, :email, :senha, 'PF', 'Titular', 'pendente', :token,
                    :codigo, :fk_ind, 'sidebar'
                )";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':id_usuario'   => $id_novo_usuario,
            ':nome'         => $nome,
            ':email'        => $email,
            ':senha'        => $senhaHash,
            ':token'        => $token_ativacao,
            ':codigo'       => $codigoIndicacao,
            ':fk_ind'       => $fkIndicadoPor,
        ]);

        // Injeção do Kit Inicial de Categorias (Starter Pack Premium)
        $kitInicial = [
            // DESPESAS
            ['nome' => 'Alimentação', 'tipo' => 'despesa', 'icone' => 'bi-cart3'],
            ['nome' => 'Moradia',     'tipo' => 'despesa', 'icone' => 'bi-house-door'],
            ['nome' => 'Transporte',  'tipo' => 'despesa', 'icone' => 'bi-car-front'],
            ['nome' => 'Saúde',       'tipo' => 'despesa', 'icone' => 'bi-heart-pulse'],
            ['nome' => 'Educação',    'tipo' => 'despesa', 'icone' => 'bi-book'],
            ['nome' => 'Lazer',       'tipo' => 'despesa', 'icone' => 'bi-controller'],
            ['nome' => 'Assinaturas', 'tipo' => 'despesa', 'icone' => 'bi-play-btn'],
            ['nome' => 'Vestuário',   'tipo' => 'despesa', 'icone' => 'bi-bag'],
            
            // RECEITAS
            ['nome' => 'Salário',       'tipo' => 'receita', 'icone' => 'bi-cash-stack'],
            ['nome' => 'Rendimentos',   'tipo' => 'receita', 'icone' => 'bi-graph-up-arrow'],
            ['nome' => 'Serviços/Free', 'tipo' => 'receita', 'icone' => 'bi-laptop'],
            ['nome' => 'Outros',        'tipo' => 'receita', 'icone' => 'bi-plus-circle-dotted']
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
        // DISPARO DO E-MAIL DE ATIVAÇÃO (HTML)
        // ==============================================================================
        $para = $email;
        $assunto = "Confirme sua conta no Auralis";
        
        // ── INTELIGÊNCIA DE DOMÍNIO (BETA OU MAIN) ──
        $protocolo = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? "https://" : "http://";
        $dominioAtual = $_SERVER['HTTP_HOST'];
        
        // Monta os links com base em onde o usuário está acessando no momento
        $link_ativacao = $protocolo . $dominioAtual . "/usuario/ativar_conta.php?token=" . $token_ativacao;
        $link_logo     = $protocolo . $dominioAtual . "/geral/img/LogoAuralisSemEscudo.png";
        
        $primeiro_nome = explode(' ', $nome)[0];
        
        // Template HTML do E-mail com a Logo
        $mensagemHTML = "
        <!DOCTYPE html>
        <html lang='pt-BR'>
        <head>
            <meta charset='UTF-8'>
            <style>
                body { font-family: Arial, sans-serif; background-color: #f4f7f6; margin: 0; padding: 0; }
                .container { max-width: 600px; margin: 40px auto; background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 4px 10px rgba(0,0,0,0.05); }
                .header { background-color: #1a1a2e; padding: 25px; text-align: center; }
                .header img { max-height: 60px; }
                .content { padding: 40px 30px; color: #333333; line-height: 1.6; }
                .content h2 { color: #1a1a2e; font-size: 20px; margin-top: 0; }
                .btn-container { text-align: center; margin: 35px 0; }
                .btn { background-color: #0d6efd; color: #ffffff; padding: 14px 28px; text-decoration: none; border-radius: 6px; font-weight: bold; display: inline-block; }
                .footer { background-color: #f9fafb; padding: 20px; text-align: center; font-size: 12px; color: #888888; border-top: 1px solid #eeeeee; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <img src='" . $link_logo . "' alt='Auralis'>
                </div>
                <div class='content'>
                    <h2>Olá, " . htmlspecialchars($primeiro_nome) . "!</h2>
                    <p>Falta apenas um passo para você assumir o controle da sua vida financeira. Para garantir a segurança dos seus dados e liberar seu acesso ao painel de controle, confirme seu endereço de e-mail.</p>
                    
                    <div class='btn-container'>
                        <a href='" . $link_ativacao . "' class='btn'>Ativar Minha Conta</a>
                    </div>
                    
                    <p>Se o botão não funcionar, copie e cole o link abaixo no seu navegador:</p>
                    <p style='font-size: 13px; color: #0d6efd; word-break: break-all;'>" . $link_ativacao . "</p>
                </div>
                <div class='footer'>
                    <p>Este é um e-mail automático, por favor não responda.</p>
                    <p>&copy; " . date('Y') . " Auralis. Todos os direitos reservados.</p>
                </div>
            </div>
        </body>
        </html>
        ";

        // Headers essenciais para envio de HTML
        $cabecalhos  = "MIME-Version: 1.0\r\n";
        $cabecalhos .= "Content-type: text/html; charset=UTF-8\r\n";
        $cabecalhos .= "From: Auralis <suporte@meuauralis.com>\r\n";
        $cabecalhos .= "Reply-To: suporte@meuauralis.com\r\n";
        $cabecalhos .= "X-Mailer: PHP/" . phpversion() . "\r\n";

        // Envia o e-mail HTML
        mail($para, $assunto, $mensagemHTML, $cabecalhos);

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