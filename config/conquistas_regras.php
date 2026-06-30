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
 *  registros   → Nº de pessoas que se cadastraram via link de indicação do usuário
 *                Disparado em: ativar_conta.php e ao processar conversões
 *
 * ── Tipos planejados (comentados até serem implementados) ───────────────────
 *
 *  dias_membro → Nº de dias como membro ativo na plataforma
 *  carteiras   → Nº de carteiras criadas pelo usuário
 */

return [

    // ── Registros via indicação ─────────────────────────────────────────────
    // Concedida automaticamente quando o usuário atinge cada marco de pessoas
    // cadastradas usando o seu link de indicação.
    'registros' => [
        'descricao'  => 'Pessoas cadastradas via link de indicação',
        'thresholds' => [
            1    => 'registro1',
            50   => 'registro50',
            100  => 'registro100',
            250  => 'registro250',
            500  => 'registro500',
            1000 => 'registro1000',
        ],
    ],

    // ── Dias como membro (descomente quando implementar) ────────────────────
    // 'dias_membro' => [
    //     'descricao'  => 'Dias com conta ativa na plataforma',
    //     'thresholds' => [
    //         7   => 'membro-semana',
    //         30  => 'membro-mes',
    //         180 => 'membro-semestre',
    //         365 => 'membro-ano',
    //     ],
    // ],

    // ── Carteiras criadas (descomente quando implementar) ───────────────────
    // 'carteiras' => [
    //     'descricao'  => 'Carteiras criadas pelo usuário',
    //     'thresholds' => [
    //         1  => 'primeira-carteira',
    //         5  => 'cinco-carteiras',
    //         10 => 'dez-carteiras',
    //     ],
    // ],

];
