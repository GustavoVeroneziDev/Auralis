<?php
// 1. Inicia a sessão com o mesmo cookie de 30 dias usado no resto do sistema
session_set_cookie_params([
    'lifetime' => 86400 * 30,
    'path'     => '/',
    'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

require_once 'config/conexao.php';

// 2. Restaura a sessão pelo cookie "lembrar-me" se não tiver uma sessão ativa
// (mesma função usada em toda página protegida — ver config/sessao.php)
renovarOuRestaurarSessao($pdo);

// 3. Se está logado (sessão normal ou recém-restaurada), vai pro painel
if (isset($_SESSION['usuario_id'])) {
    header("Location: dashboard.php");
    exit;
}

// 4. Ninguém logado — vitrine pública
header("Location: geral/index.php");
exit;
