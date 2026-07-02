<?php
// config/permissoes.php — camada única de permissões administrativas.
// Toda checagem de "isso é coisa de admin/supremo" deve passar por aqui,
// nunca repetir in_array(...) solto em cada arquivo.
//
// Para restringir uma nova ação ao Supremo: use ehSupremo()/exigirSupremo().
// Para liberar algo para qualquer admin: use ehAdmin()/exigirAdmin().

if (!function_exists('nivelAcessoAtual')) {
    function nivelAcessoAtual(): string
    {
        return strtolower($_SESSION['nivel_acesso'] ?? 'titular');
    }
}

if (!function_exists('ehAdmin')) {
    // Admin ou Supremo — acesso geral ao painel administrativo.
    function ehAdmin(): bool
    {
        return in_array(nivelAcessoAtual(), ['admin', 'supremo'], true);
    }
}

if (!function_exists('ehSupremo')) {
    // Somente Supremo — ações sensíveis: planos, promoção de admins, exclusão de contas.
    function ehSupremo(): bool
    {
        return nivelAcessoAtual() === 'supremo';
    }
}

if (!function_exists('exigirAdmin')) {
    function exigirAdmin(): void
    {
        if (!isset($_SESSION['usuario_id'])) {
            header("Location: /usuario/login.php");
            exit;
        }
        if (!ehAdmin()) {
            header("Location: /dashboard.php?erro=sem_permissao");
            exit;
        }
    }
}

if (!function_exists('exigirSupremo')) {
    function exigirSupremo(): void
    {
        if (!isset($_SESSION['usuario_id'])) {
            header("Location: /usuario/login.php");
            exit;
        }
        if (!ehSupremo()) {
            header("Location: /dashboard.php?erro=sem_permissao");
            exit;
        }
    }
}
