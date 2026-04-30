<?php
// Inicia a sessão para poder destruí-la
session_start();

// Destrói todas as variáveis de sessão
$_SESSION = array();
session_destroy();

// Redireciona o usuário de volta para a página inicial (vitrine)
header("Location: /auralis/geral/index.php");
exit;
?>