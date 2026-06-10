<?php
// 1. Inicia a sessão para verificar se o usuário já está ativo
session_start();

// Se o usuário JÁ estiver logado com a sessão normal, pula tudo e vai pro painel
if (isset($_SESSION['usuario_id'])) {
    header("Location: dashboard.php");
    exit;
}

// ==============================================================================
// 2. O PORTEIRO DOS COOKIES (Auto-Login)
// ==============================================================================
if (isset($_COOKIE['auralis_remember'])) {
    require_once 'config/conexao.php';
    require_once 'config/funcoes.php';

    // O nosso cookie foi salvo no formato "ID:Assinatura". Vamos separar isso.
    $cookie_parts = explode(':', $_COOKIE['auralis_remember']);

    // Verifica se o cookie não foi adulterado e tem as duas partes
    if (count($cookie_parts) === 2) {
        $usuario_id = $cookie_parts[0];
        $assinatura_fornecida = $cookie_parts[1];

        $assinatura_esperada = hash_hmac('sha256', $usuario_id, AURALIS_COOKIE_SECRET);

        // A função hash_equals previne ataques de "Timing" (Tentativa de adivinhar a chave)
        if (hash_equals($assinatura_esperada, $assinatura_fornecida)) {

            try {
                $sql = 'SELECT Nome, NivelAcesso, Plano, StatusConta FROM usuario WHERE IDUsuario = :id LIMIT 1';
                $stmt = $pdo->prepare($sql);
                $stmt->execute([':id' => $usuario_id]);
                $usuario = $stmt->fetch();

                if ($usuario && $usuario['StatusConta'] === 'ativo') {
                    session_regenerate_id(true);
                    $_SESSION['usuario_id']   = $usuario_id;
                    $_SESSION['usuario_nome'] = $usuario['Nome'];
                    $_SESSION['nivel_acesso'] = strtolower($usuario['NivelAcesso']);
                    $_SESSION['plano']        = strtolower($usuario['Plano'] ?? 'free');

                    header("Location: dashboard.php");
                    exit;
                }
            } catch (PDOException $e) {
                // Se der erro no banco, o código continua e joga ele pro login normal
            }
        }
    }

    // Cookie inválido ou conta inativa — destrói o cookie
    setcookie('auralis_remember', '', time() - 3600, '/');
}

// ==============================================================================
// 3. O Redirecionamento Padrão
// ==============================================================================
header("Location: geral/index.php");
exit;
