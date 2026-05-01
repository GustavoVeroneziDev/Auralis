<?php
// config/funcoes.php

// =========================================================================
// SISTEMA DE PERMISSÕES E ACESSO AURALIS
// 0 = Deslogado | 1 = Usuário | 2 = Admin | 3 = Supremo
// =========================================================================

function obterNivelAcesso() {
    // 1. Se não existe sessão, é um visitante fantasma (Nível 0)
    if (!isset($_SESSION['usuario_id'])) {
        return 0; 
    }
    
    
    // 3. Lê o nível salvo no banco/sessão para os meros mortais
    $nivel_banco = strtolower($_SESSION['nivel_acesso'] ?? 'titular');
    
    if ($nivel_banco === 'admin') {
        return 2; // Administrador (Nível 2)
    }
    
    if ($nivel_banco === 'supremo') {
        return 3; // Supremo (Nível 3)
    }
    
    // 4. Se passou por tudo e está logado, é um usuário comum (Nível 1)
    return 1; 
}

// Função para colocar no topo das páginas e "chutar" quem não tem permissão
function exigirAcessoMinimo($nivelNecessario) {
    $nivelAtual = obterNivelAcesso();
    
    if ($nivelAtual < $nivelNecessario) {
        if ($nivelAtual === 0) {
            // Se for nível 0, manda pro login
            header("Location: /usuario/login.php?erro=autenticacao");
        } else {
            // Se estiver logado mas tentar entrar onde não deve, manda pro painel
            header("Location: /dashboard.php?erro=sem_permissao");
        }
        exit;
    }
}
?>