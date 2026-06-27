<?php require_once 'header.php'; ?>

<!-- ── HERO ─────────────────────────────────────────────────────────────── -->
<header class="py-5 text-center border-bottom border-secondary-subtle position-relative overflow-hidden">
    <div style="position:absolute;top:-60px;left:50%;transform:translateX(-50%);width:400px;height:400px;background:var(--accent);filter:blur(160px);opacity:0.1;border-radius:50%;pointer-events:none;"></div>
    <div class="container py-4 position-relative">
        <span class="badge mb-4 px-3 py-2 rounded-pill fw-semibold"
            style="background:#d4af3715;color:#d4af37;border:1px solid #d4af3740;font-size:0.8rem;">
            Auralis — Aurum + Analysis
        </span>
        <h1 class="fw-bold text-light mb-3" style="font-size:clamp(2rem,5vw,3rem);">
            A análise de <span style="background:linear-gradient(90deg,#d4af37,#f9e596);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;">ouro</span> das suas finanças
        </h1>
        <p class="text-secondary mx-auto" style="max-width:620px;font-size:1.05rem;line-height:1.75;">
            O Auralis nasceu de uma frustração real: os aplicativos de finanças existentes são ou
            muito simples demais ou complexos demais. Criamos o meio-termo certo — poderoso por baixo,
            simples por cima.
        </p>
    </div>
</header>

<main class="container py-5">

    <!-- ── O QUE É O AURALIS ─────────────────────────────────────────────── -->
    <div class="row align-items-center py-5 g-5 card-animado surgir-baixo">
        <div class="col-lg-6">
            <h2 class="fw-bold text-light mb-4">O nome não foi por acaso</h2>
            <p class="text-secondary mb-4" style="line-height:1.8;">
                <strong class="text-light">Aurum</strong> é ouro em latim — representa o valor, a estabilidade e o nível de sofisticação que entregamos. <strong class="text-light">Analysis</strong> é a inteligência que transforma dados financeiros em decisões claras.
            </p>
            <p class="text-secondary" style="line-height:1.8;">
                Juntos formam o Auralis: um sistema que vê seu dinheiro como você merece — com clareza, contexto e sem achismos.
            </p>
        </div>
        <div class="col-lg-6">
            <div class="row g-3">
                <?php foreach (
                    [
                        ['bi-heptagon-fill',   '#d4af37', 'Padrão Premium',      'Interface dark mode com identidade dourada — funcional e elegante.'],
                        ['bi-graph-up-arrow',  '#a78bfa', 'Análise Real',        'Comparativos mês a mês por categoria. Não só números, mas contexto.'],
                        ['bi-shield-lock-fill', '#22c55e', 'Segurança Nativa',    'PDO com Prepared Statements em 100% das queries. UUID em todas as PKs.'],
                        ['bi-phone',           '#38bdf8', 'Mobile-First',        'Parece um app nativo no celular. Responsivo e fluido em qualquer tela.'],
                    ] as [$icon, $color, $title, $desc]
                ): ?>
                    <div class="col-6">
                        <div class="p-3 rounded-3 h-100" style="background:var(--bg-card);border:1px solid rgba(255,255,255,.06);">
                            <i class="bi <?php echo $icon ?> mb-2 d-block" style="color:<?php echo $color ?>;font-size:1.25rem;"></i>
                            <div class="fw-semibold text-light mb-1" style="font-size:0.875rem;"><?php echo $title ?></div>
                            <div class="text-secondary" style="font-size:0.775rem;line-height:1.5;"><?php echo $desc ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- ── O QUE O SISTEMA FAZ HOJE ──────────────────────────────────────── -->
    <div class="py-5 border-top border-secondary-subtle card-animado surgir-baixo">
        <div class="text-center mb-5">
            <h2 class="fw-bold text-light mb-3">O que o sistema entrega hoje</h2>
            <p class="text-secondary mx-auto" style="max-width:500px;">Funcionalidades reais, rodando em produção agora.</p>
        </div>

        <div class="row g-4">
            <?php
            $features = [
                [
                    'bi-speedometer2',
                    '#d4af37',
                    'Dashboard Inteligente',
                    'Saldo calculado dinamicamente (nunca um campo estático). Receitas e despesas do mês com badge de variação percentual vs mês anterior. Saldo projetado incluindo tudo que ainda está pendente.'
                ],
                [
                    'bi-credit-card-2-front',
                    '#a78bfa',
                    'Parcelamento Automático',
                    'Registre uma compra em N vezes e o sistema cria uma entrada por mês automaticamente com a descrição "Produto (1/5)", "(2/5)", etc. Parcelas futuras aparecem como pendentes na previsão de gastos.'
                ],
                [
                    'bi-arrow-repeat',
                    '#22c55e',
                    'Contas Recorrentes',
                    'Cadastre uma vez — Netflix, aluguel, academia — e o Auralis projeta os próximos meses como pendentes. Elas aparecem na barra de previsão de gastos antes de cair.'
                ],
                [
                    'bi-calendar3',
                    '#38bdf8',
                    'Agenda Financeira',
                    'Calendário mensal com drag-and-drop — arraste qualquer transação para outro dia e a data é atualizada na hora. Clique num dia para ver detalhes ou use os botões de + para registrar diretamente.'
                ],
                [
                    'bi-hourglass-split',
                    '#f59e0b',
                    'Previsão de Gastos do Mês',
                    'Barra de "Aguardando confirmação" mostra a pagar, a receber e o saldo projetado. Você sabe o que vai acontecer antes de acontecer.'
                ],
                [
                    'bi-pie-chart-fill',
                    '#fb7185',
                    'Análises por Categoria',
                    'Gráfico de rosca com % em cada fatia. Clique numa categoria e veja o detalhamento com comparativo ao mês anterior — quanto gastou em Lazer em abril vs maio.'
                ],
                [
                    'bi-wallet2',
                    '#c084fc',
                    'Múltiplas Carteiras',
                    'Conta Itaú, Nubank, Carteira Física — cada uma com saldo real calculado pela soma das transações. Filtro de carteira presente em todas as telas.'
                ],
                [
                    'bi-credit-card-fill',
                    '#a78bfa',
                    'Cartão de Crédito',
                    'Cadastre cartões com limite, bandeira e datas de fechamento/vencimento. Faturas calculadas automaticamente mês a mês. Lançamentos aparecem na agenda com ícone próprio e link para a fatura completa.'
                ],
                [
                    'bi-piggy-bank',
                    '#f59e0b',
                    'Cofrinhos & Metas',
                    'Crie caixinhas de poupança vinculadas a uma carteira. Defina meta de valor, data limite e acompanhe o progresso com barra visual. Depósitos debitam automaticamente do saldo da carteira.'
                ],
                [
                    'bi-bell-fill',
                    '#38bdf8',
                    'Notificações & Pesquisas',
                    'Sistema de comunicados internos com suporte a pesquisas de múltipla escolha, checkbox e texto livre. Usuários recebem alertas em tempo real pelo sininho — admin envia para grupos específicos ou todos.'
                ],
                [
                    'bi-file-earmark-arrow-down',
                    '#22c55e',
                    'Exportação CSV e PDF',
                    'Exporte o extrato completo de qualquer mês como CSV (compatível com Excel) ou PDF formatado e pronto para impressão. Filtros por carteira e tipo de transação.'
                ],
                [
                    'bi-paperclip',
                    '#a78bfa',
                    'Comprovantes e Anexos',
                    'Anexe boletos, notas fiscais e comprovantes (imagens ou PDF) a qualquer registro. Visualize ou baixe diretamente pelo sistema, com segurança. Exclusivo PRO e VIP.'
                ],
                [
                    'bi-pencil-square',
                    '#fbbf24',
                    'Ajuste de Saldo',
                    'Saldo do sistema diferente da conta real? Informe o valor correto e o Auralis cria um ajuste de receita ou despesa automaticamente, mantendo o histórico íntegro.'
                ],
                [
                    'bi-phone',
                    '#34d399',
                    'App Instalável (PWA)',
                    'O Auralis é um Progressive Web App — instale direto do navegador no celular ou PC e acesse como um app nativo, com ícone na tela inicial, sem precisar abrir o browser.'
                ],
                [
                    'bi-stars',
                    '#d4af37',
                    'Sistema de Planos',
                    'Free, PRO e VIP com ativação automática via Mercado Pago. Assinatura detectada em tempo real sem depender de webhook — ativação imediata ao retornar do pagamento.'
                ],
            ];
            foreach ($features as $i => [$icon, $color, $title, $desc]):
                $delay = ($i % 3) * 0.1;
            ?>
                <div class="col-12 col-md-6 col-lg-4 card-animado surgir-baixo" style="transition-delay:<?php echo $delay ?>s;">
                    <div class="feature-card h-100">
                        <div class="d-flex align-items-center gap-3 mb-3">
                            <div class="rounded-3 flex-shrink-0 d-flex align-items-center justify-content-center"
                                style="width:40px;height:40px;background:<?php echo $color ?>18;border:1px solid <?php echo $color ?>30;">
                                <i class="bi <?php echo $icon ?>" style="color:<?php echo $color ?>;font-size:1.1rem;"></i>
                            </div>
                            <h6 class="fw-semibold text-light mb-0"><?php echo $title ?></h6>
                        </div>
                        <p class="text-secondary mb-0" style="font-size:0.85rem;line-height:1.65;"><?php echo $desc ?></p>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>


    <!-- ── EQUIPE ─────────────────────────────────────────────────────────── -->
    <div class="py-5 border-top border-secondary-subtle card-animado surgir-baixo">
        <div class="text-center mb-5">
            <h2 class="fw-bold text-light mb-2">Quem faz o Auralis acontecer</h2>
            <p class="text-secondary">Uma equipe focada em construir a referência em gestão financeira pessoal no Brasil.</p>
        </div>

        <div class="row g-4 justify-content-center">
            <div class="col-12 col-md-5">
                <div class="feature-card text-center h-100">
                    <i class="bi bi-person-bounding-box mb-3 d-block" style="font-size:2.5rem;color:#d4af37;"></i>
                    <h4 class="fw-bold text-light mb-1">Gunter Eleandro</h4>
                    <span class="badge mb-3 px-3 py-1 rounded-pill" style="background:#d4af3718;color:#d4af37;border:1px solid #d4af3744;font-size:0.7rem;">CEO & Fundador</span>
                    <p class="text-secondary mb-0" style="font-size:0.875rem;line-height:1.65;">
                        Responsável pela visão do produto, estratégia de longo prazo, branding e por toda a identidade visual premium do Auralis.
                    </p>
                </div>
            </div>
            <div class="col-12 col-md-5">
                <div class="feature-card text-center h-100">
                    <i class="bi bi-terminal-dash mb-3 d-block" style="font-size:2.5rem;color:#a78bfa;"></i>
                    <h4 class="fw-bold text-light mb-1">Gustavo Veronezi</h4>
                    <span class="badge mb-3 px-3 py-1 rounded-pill" style="background:#7c3aed18;color:#a78bfa;border:1px solid #7c3aed44;font-size:0.7rem;">CTO & COO</span>
                    <p class="text-secondary mb-0" style="font-size:0.875rem;line-height:1.65;">
                        Arquitetura, backend, frontend, infraestrutura e toda a lógica do sistema — parcelamentos, recorrências, análises comparativas e integração com Mercado Pago.
                    </p>
                </div>
            </div>
        </div>
    </div>

    <!-- ── ROADMAP ────────────────────────────────────────────────────────── -->
    <div class="py-5 border-top border-secondary-subtle card-animado surgir-baixo">
        <div class="text-center mb-5">
            <h2 class="fw-bold text-light mb-2">O que vem por aí</h2>
            <p class="text-secondary">Próximas evoluções já planejadas.</p>
        </div>
        <div class="row g-3 justify-content-center">
            <?php foreach (
                [
                    ['bi-people-fill',         '#22c55e', 'Compartilhamento Familiar',   'Até 4 membros por grupo, com permissões de leitura ou escrita por carteira.'],
                    ['bi-bank',                '#d4af37', 'Open Finance',                 'Importação automática de extratos bancários via OFX — sem digitar nada manualmente.'],
                    ['bi-robot',               '#a78bfa', 'Assistente Financeiro IA',     'Análise automática dos seus padrões de gasto com sugestões personalizadas de economia.'],
                    ['bi-graph-up',            '#38bdf8', 'Relatórios Avançados',         'Relatórios mensais e anuais com evolução patrimonial, comparativos e projeções de longo prazo.'],
                ] as [$icon, $color, $title, $desc]
            ): ?>
                <div class="col-12 col-sm-6 col-lg-4">
                    <div class="d-flex gap-3 align-items-start p-3 rounded-3" style="background:var(--bg-card);border:1px solid rgba(255,255,255,.06);">
                        <div class="rounded-2 flex-shrink-0 d-flex align-items-center justify-content-center"
                            style="width:36px;height:36px;background:<?php echo $color ?>15;border:1px solid <?php echo $color ?>30;">
                            <i class="bi <?php echo $icon ?>" style="color:<?php echo $color ?>;font-size:0.9rem;"></i>
                        </div>
                        <div>
                            <div class="text-light fw-semibold mb-1" style="font-size:0.85rem;"><?php echo $title ?></div>
                            <div class="text-secondary" style="font-size:0.775rem;line-height:1.5;"><?php echo $desc ?></div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

</main>

<!-- ── CTA ───────────────────────────────────────────────────────────────── -->
<section class="py-5 bg-body-tertiary border-top border-secondary-subtle text-center">
    <div class="container py-4">
        <h2 class="fw-bold text-light mb-3">Pronto para assumir o controle?</h2>
        <p class="text-secondary mb-5 mx-auto" style="max-width:440px;">
            Crie sua conta grátis e transforme a maneira como você lida com o dinheiro.
        </p>
        <a href="/usuario/cadastro.php"
            class="btn btn-lg px-5 rounded-pill fw-bold shadow-sm"
            style="background:linear-gradient(135deg,#d4af37,#AA8C2C);color:#121418;">
            Criar Minha Conta Grátis
        </a>
    </div>
</section>

<?php require_once 'footer.php'; ?>