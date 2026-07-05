<?php
/**
 * conquistas_regras.php
 *
 * Arquivo central de regras das conquistas automáticas.
 *
 * O painel admin cuida apenas da parte visual (nome, ícone, cor, raridade).
 * A lógica de QUANDO e POR QUÊ uma conquista é concedida fica aqui.
 *
 * ── Como adicionar uma conquista nova ───────────────────────────────────────
 *
 *  1. Cadastre a conquista no painel admin com o slug desejado.
 *  2. Encontre o bloco do tipo correto abaixo e adicione o threshold:
 *       [valor_mínimo => 'slug-da-conquista']
 *
 * ── Como criar um novo tipo de gatilho ─────────────────────────────────────
 *
 *  1. Adicione um novo bloco aqui seguindo o padrão dos existentes.
 *  2. Adicione o 'case' correspondente dentro de verificarConquistasAutomaticas()
 *     em config/funcoes.php — é o único arquivo que precisa de alteração de código.
 *
 * ── Tipos disponíveis ───────────────────────────────────────────────────────
 *
 *  registros    → Nº de lançamentos financeiros criados pelo usuário
 *                 Disparado em: nova_transacao.php
 *
 *  dias_membro  → Nº de dias com conta ativa na plataforma
 *                 Disparado em: geral/header.php (uma vez por sessão)
 *
 *  comprovantes → Nº de lançamentos distintos com comprovante anexado
 *                 Disparado em: nova_transacao.php após upload
 *
 *  categorias   → Nº de categorias distintas usadas em lançamentos
 *                 Disparado em: nova_transacao.php após criação
 *
 * ── Conquistas de evento único (sem threshold) ──────────────────────────────
 *
 *  metabatida   → concedida em processa_cofrinho.php quando SaldoCofrinho >= ValorMeta
 *  sempendencias→ concedida em acao_registro.php quando todos os pendentes do mês são efetivados
 *  carteira_comp→ concedida via verificarConquistaCarteiraCompartilhada() (config/funcoes.php)
 *                 quando o usuário é dono ou convidado aceito de uma carteira compartilhada
 *                 com >= 2 pessoas. Disparado em carteira/listar_carteiras.php: a cada
 *                 carregamento da página (retroativo) e ao aceitar um convite (pros dois lados).
 */

return [

    // ── Registros via indicação ─────────────────────────────────────────────
    // Concedida automaticamente quando o usuário atinge cada marco de pessoas
    // cadastradas usando o seu link de indicação.
    'registros' => [
        'descricao'  => 'Lançamentos financeiros registrados pelo usuário',
        'thresholds' => [
            1    => 'registro1',
            50   => 'registro50',
            100  => 'registro100',
            250  => 'registro250',
            500  => 'registro500',
            1000 => 'registro1000',
        ],
    ],

    // ── Dias como membro ────────────────────────────────────────────────────
    'dias_membro' => [
        'descricao'  => 'Dias com conta ativa na plataforma',
        'thresholds' => [
            30  => 'diasdeuso',
            90  => 'veterano_90',
            180 => 'usoveterano',
        ],
    ],

    // ── Lançamentos com comprovante ─────────────────────────────────────────
    'comprovantes' => [
        'descricao'  => 'Lançamentos distintos com comprovante anexado',
        'thresholds' => [
            10 => 'cacarecibo',
        ],
    ],

    // ── Categorias distintas utilizadas ─────────────────────────────────────
    'categorias' => [
        'descricao'  => 'Categorias diferentes usadas em lançamentos',
        'thresholds' => [
            5 => 'diverso',
        ],
    ],

];
