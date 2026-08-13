<?php
// config/sessao.php — bootstrap de sessão com renovação automática.
//
// Usar no lugar de um session_start() cru em qualquer página que exige login
// (require_once 'config/sessao.php'; a partir da raiz, ou '../config/sessao.php'
// de dentro de uma subpasta de 1 nível). NÃO usar em login.php/processa_login.php/
// logout.php/cadastro — essas páginas estabelecem ou destroem a sessão, não devem
// rodar a lógica de restaurar sessão automaticamente.
//
// Sem isso, ficar logado dependia só do cookie padrão de sessão do PHP (que
// morre ao fechar o navegador/app) e do session.gc_maxlifetime do servidor —
// em hospedagem compartilhada costuma limpar sessão inativa em poucos minutos.
// Agora: cookie de sessão com vida de 30 dias, renovado a cada acesso
// (renovarOuRestaurarSessao em config/funcoes.php), e o cookie "lembrar-me"
// (que já existia mas só era checado em index.php) restaura a sessão sozinho
// quando ela não existe mais.

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 86400 * 30,
        'path'     => '/',
        'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

require_once __DIR__ . '/conexao.php';

renovarOuRestaurarSessao($pdo);
