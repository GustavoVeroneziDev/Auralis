<?php
require_once __DIR__ . '/../config/funcoes.php';

// Inicia a sessão para poder destruí-la
session_start();

// Destrói todas as variáveis de sessão
$_SESSION = array();
session_destroy();

// Sem isso, o cookie "lembrar-me" (30 dias) sobrevivia ao logout e a próxima
// página visitada restaurava a sessão sozinha — logout não "pegava" de verdade.
limparCookieLembrarMe();

// Redireciona o usuário de volta para a página inicial (vitrine)
header("Location: /geral/index.php");
exit;
?>