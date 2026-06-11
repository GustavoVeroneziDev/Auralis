<?php
session_start();
if (isset($_SESSION['usuario_id'])) {
    header("Location: ../dashboard.php");
    exit;
}
?>
<?php require_once 'header.php'; ?>

<!-- ── HERO ─────────────────────────────────────────────────────────────── -->
<section class="position-relative overflow-hidden" style="padding: clamp(4rem,10vw,7rem) 0;">
    <div style="position:absolute;top:0;left:50%;transform:translateX(-50%);width:600px;height:400px;background:var(--accent);filter:blur(180px);opacity:0.07;border-radius:50%;pointer-events:none;"></div>

    <div class="container text-center position-relative">
        <span class="badge mb-4 px-3 py-2 rounded-pill fw-semibold"
            style="background:#d4af3715;color:#d4af37;border:1px solid #d4af3740;font-size:0.8rem;letter-spacing:0.04em;">
            <i class="bi bi-stars me-1"></i> Gestão financeira inteligente para o Brasil
        </span>

        <h1 class="fw-bold text-light mb-4" style="font-size:clamp(2rem,5vw,3.5rem);line-height:1.15;max-width:780px;margin:0 auto;">
            A inteligência que o<br>
            <span style="background:linear-gradient(90deg,#d4af37,#f9e596);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;">seu dinheiro precisa.</span>
        </h1>

        <p class="text-secondary mb-5 mx-auto" style="font-size:clamp(1rem,2vw,1.2rem);max-width:580px;line-height:1.7;">
            Dashboard em tempo real, parcelamentos automáticos, agenda financeira e análises mês a mês — tudo em um só lugar. Sem planilha. Sem complicação.
        </p>

        <div class="d-flex gap-3 justify-content-center flex-wrap">
            <a href="/usuario/cadastro.php"
                class="btn btn-lg px-5 rounded-pill fw-bold shadow-lg"
                style="background:linear-gradient(135deg,#d4af37,#AA8C2C);color:#121418;font-size:1rem;">
                Começar grátis
            </a>
            <a href="#funcionalidades"
                class="btn btn-lg px-5 rounded-pill fw-semibold"
                style="background:rgba(255,255,255,.06);color:#f8fafc;border:1px solid rgba(255,255,255,.12);font-size:1rem;">
                Ver funcionalidades
            </a>
        </div>

        <!-- Mini stats -->
        <div class="d-flex justify-content-center gap-4 mt-5 flex-wrap">
            <?php foreach (
                [
                    ['bi-wallet2',       '100% gratuito',     'para começar'],
                    ['bi-shield-check',  'Seus dados',         'protegidos'],
                    ['bi-phone',         'Instalável',         'como app nativo'],
                ] as [$icon, $title, $sub]
            ): ?>
                <div class="d-flex align-items-center gap-2" style="font-size:0.875rem;">
                    <i class="bi <?php echo $icon ?> text-primary"></i>
                    <span class="text-light fw-semibold"><?php echo $title ?></span>
                    <span class="text-secondary"><?php echo $sub ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- ── FUNCIONALIDADES ───────────────────────────────────────────────────── -->
<section id="funcionalidades" class="py-5 border-top border-secondary-subtle" style="scroll-margin-top:80px;">
    <div class="container">
        <div class="text-center mb-5">
            <h2 class="fw-bold text-light mb-3" style="font-size:clamp(1.5rem,3vw,2.2rem);">
                Tudo o que você precisa, sem o que você não usa
            </h2>
            <p class="text-secondary mx-auto" style="max-width:520px;">
                Cada funcionalidade foi pensada para resolver uma dor real de quem tenta organizar as finanças no Brasil.
            </p>
        </div>

        <div class="row g-4">
            <?php
            $features = [
                [
                    'icon'  => 'bi-speedometer2',
                    'color' => '#d4af37',
                    'title' => 'Dashboard em tempo real',
                    'desc'  => 'Saldo calculado dinamicamente — nunca um campo estático. Receitas e despesas do mês com badge de variação % vs mês anterior. Saldo projetado incluindo tudo que ainda está pendente.',
                ],
                [
                    'icon'  => 'bi-credit-card-2-front',
                    'color' => '#a78bfa',
                    'title' => 'Parcelamento inteligente',
                    'desc'  => 'Comprou em 5x? O sistema cria uma entrada por mês automaticamente. As parcelas aparecem como pendentes no mês certo, sem esforço. PRO libera até 48x e parcelamento com juros.',
                ],
                [
                    'icon'  => 'bi-arrow-repeat',
                    'color' => '#22c55e',
                    'title' => 'Contas recorrentes',
                    'desc'  => 'Netflix, aluguel, academia — cadastre uma vez e o Auralis projeta os próximos meses como pendentes, te avisando antes de cair na conta.',
                ],
                [
                    'icon'  => 'bi-calendar3',
                    'color' => '#38bdf8',
                    'title' => 'Agenda financeira',
                    'desc'  => 'Visualize todas as suas transações num calendário mensal. Clique em qualquer dia para ver o detalhe, ou use os botões de + para adicionar receitas e despesas diretamente.',
                ],
                [
                    'icon'  => 'bi-pie-chart-fill',
                    'color' => '#f59e0b',
                    'title' => 'Análises por categoria',
                    'desc'  => 'Gráfico de distribuição com % por fatia. Clique numa categoria e veja o histórico detalhado com comparativo do mês anterior — quanto gastou em Lazer em abril vs maio.',
                ],
                [
                    'icon'  => 'bi-wallet2',
                    'color' => '#fb7185',
                    'title' => 'Múltiplas carteiras',
                    'desc'  => 'Separe Conta Itaú, Carteira Física e Nubank. Cada carteira tem seu saldo calculado em tempo real. Filtro de carteira presente em todas as telas.',
                ],
                [
                    'icon'  => 'bi-paperclip',
                    'color' => '#c084fc',
                    'title' => 'Comprovantes e Anexos',
                    'desc'  => 'Anexe boletos, notas fiscais e comprovantes a qualquer registro. Visualize ou baixe diretamente pelo sistema, com segurança. Exclusivo para planos PRO e VIP.',
                    'badge' => 'PRO',
                ],
                [
                    'icon'  => 'bi-phone',
                    'color' => '#34d399',
                    'title' => 'Instalável como App',
                    'desc'  => 'O Auralis é um PWA — instale direto do navegador no celular ou PC e acesse como um app nativo, com ícone na tela inicial, sem abrir o browser.',
                ],
            ];
            foreach ($features as $i => $f):
                $delay = ($i % 4) * 0.1;
            ?>
                <div class="col-12 col-sm-6 col-lg-3 card-animado surgir-baixo" style="transition-delay:<?php echo $delay ?>s;">
                    <div class="feature-card h-100 position-relative">
                        <?php if (!empty($f['badge'])): ?>
                            <span class="position-absolute top-0 end-0 badge rounded-pill" style="background:rgba(124,58,237,0.15);color:#a78bfa;border:1px solid rgba(124,58,237,0.3);font-size:0.6rem;padding:3px 8px;margin:0.75rem;">
                                <i class="fi fi-br-crown" style="font-size:0.55rem;vertical-align:middle;"></i> <?php echo $f['badge'] ?>
                            </span>
                        <?php endif; ?>
                        <div class="mb-3 d-flex align-items-center gap-3">
                            <div class="rounded-3 d-flex align-items-center justify-content-center flex-shrink-0"
                                style="width:44px;height:44px;background:<?php echo $f['color'] ?>18;border:1px solid <?php echo $f['color'] ?>33;">
                                <i class="bi <?php echo $f['icon'] ?>" style="font-size:1.25rem;color:<?php echo $f['color'] ?>;"></i>
                            </div>
                            <h5 class="fw-semibold text-light mb-0" style="font-size:0.95rem;"><?php echo $f['title'] ?></h5>
                        </div>
                        <p class="text-secondary mb-0" style="font-size:0.845rem;line-height:1.65;"><?php echo $f['desc'] ?></p>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- ── COMO FUNCIONA ─────────────────────────────────────────────────────── -->
<section class="py-5 border-top border-secondary-subtle">
    <div class="container">
        <div class="text-center mb-5">
            <h2 class="fw-bold text-light mb-3" style="font-size:clamp(1.5rem,3vw,2.2rem);">Como funciona</h2>
            <p class="text-secondary">Do cadastro ao controle total em menos de 5 minutos.</p>
        </div>

        <div class="row g-4 justify-content-center">
            <?php
            $steps = [
                ['01', '#d4af37', 'bi-person-plus-fill', 'Crie sua conta grátis',     'Cadastro em segundos. Pode entrar com Google também.'],
                ['02', '#a78bfa', 'bi-wallet-fill',      'Configure suas carteiras',   'Crie uma carteira para cada conta bancária ou forma de pagamento.'],
                ['03', '#22c55e', 'bi-plus-circle-fill', 'Registre suas transações',  'Receitas, despesas, parcelamentos e contas recorrentes.'],
                ['04', '#f59e0b', 'bi-graph-up-arrow',   'Acompanhe as análises',     'Veja para onde vai cada centavo e compare mês a mês na agenda.'],
            ];
            foreach ($steps as $i => [$num, $color, $icon, $title, $desc]):
            ?>
                <div class="col-12 col-sm-6 col-lg-3 text-center card-animado surgir-baixo" style="transition-delay:<?php echo $i * 0.15 ?>s;">
                    <div class="feature-card h-100">
                        <div class="rounded-circle mx-auto mb-3 d-flex align-items-center justify-content-center fw-bold"
                            style="width:52px;height:52px;background:<?php echo $color ?>18;border:2px solid <?php echo $color ?>44;color:<?php echo $color ?>;font-size:0.8rem;">
                            <?php echo $num ?>
                        </div>
                        <i class="bi <?php echo $icon ?> mb-2 d-block" style="font-size:1.5rem;color:<?php echo $color ?>;"></i>
                        <h6 class="fw-semibold text-light mb-2"><?php echo $title ?></h6>
                        <p class="text-secondary mb-0" style="font-size:0.8rem;"><?php echo $desc ?></p>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- ── PLANOS ────────────────────────────────────────────────────────────── -->
<section class="py-5 border-top border-secondary-subtle">
    <div class="container">
        <div class="text-center mb-5">
            <h2 class="fw-bold text-light mb-3" style="font-size:clamp(1.5rem,3vw,2.2rem);">Preços simples e honestos</h2>
            <p class="text-secondary">Comece grátis. Evolua quando fizer sentido.</p>
        </div>

        <div class="row g-4 justify-content-center">
            <!-- Free -->
            <div class="col-12 col-md-4 card-animado surgir-baixo">
                <div class="feature-card h-100 text-center">
                    <p class="text-secondary fw-semibold small text-uppercase mb-1" style="letter-spacing:.08em;">Gratuito</p>
                    <div class="fw-bold text-light mb-1" style="font-size:2rem;">R$ 0</div>
                    <p class="text-secondary mb-4" style="font-size:0.8rem;">Para começar a organizar</p>
                    <ul class="list-unstyled text-start mb-4" style="font-size:0.85rem;">
                        <?php foreach (
                            [
                                '1 carteira',
                                'Até 35 registros/mês',
                                'Até 10 categorias',
                                'Parcelamento em até 3x',
                                'Dashboard com variação mensal',
                                'Agenda financeira',
                                'App instalável (PWA)',
                            ] as $item
                        ): ?>
                            <li class="mb-2 d-flex gap-2"><i class="bi bi-check-circle-fill text-success mt-1 flex-shrink-0"></i><span class="text-light"><?php echo $item ?></span></li>
                        <?php endforeach; ?>
                    </ul>
                    <a href="/usuario/cadastro.php" class="btn w-100 rounded-pill fw-semibold"
                        style="background:rgba(255,255,255,.06);color:#f8fafc;border:1px solid rgba(255,255,255,.1);">
                        Começar grátis
                    </a>
                </div>
            </div>
            <!-- PRO -->
            <div class="col-12 col-md-4 card-animado surgir-baixo" style="transition-delay:.15s;">
                <div class="h-100 rounded-4 overflow-hidden" style="border:1.5px solid #7c3aed88;background:var(--bg-card);">
                    <div class="text-center py-1" style="background:#7c3aed;font-size:0.7rem;font-weight:700;letter-spacing:.08em;color:#fff;">MAIS POPULAR</div>
                    <div class="p-4 text-center d-flex flex-column" style="height:calc(100% - 30px);">
                        <p class="fw-semibold small text-uppercase mb-1" style="color:#a78bfa;letter-spacing:.08em;"><i class="fi fi-br-crown" style="font-size:0.75rem;vertical-align:middle;"></i> PRO</p>
                        <div class="fw-bold text-light mb-0" style="font-size:2rem;">R$ 19,90</div>
                        <p class="text-secondary mb-4" style="font-size:0.8rem;">/mês · Para quem leva a sério</p>
                        <ul class="list-unstyled text-start mb-4" style="font-size:0.85rem;">
                            <?php foreach (
                                [
                                    'Até 3 carteiras',
                                    'Registros ilimitados',
                                    'Categorias ilimitadas',
                                    'Parcelamento em até 48x (com juros)',
                                    'Histórico comparativo (12 meses)',
                                    'Comprovantes e Anexos',
                                    'Suporte prioritário',
                                ] as $item
                            ): ?>
                                <li class="mb-2 d-flex gap-2"><i class="bi bi-check-circle-fill mt-1 flex-shrink-0" style="color:#a78bfa;"></i><span class="text-light"><?php echo $item ?></span></li>
                            <?php endforeach; ?>
                        </ul>
                        <a href="/usuario/cadastro.php" class="btn w-100 rounded-pill fw-bold mt-auto"
                            style="background:#7c3aed;color:#fff;border:none;">
                            Assinar PRO
                        </a>
                    </div>
                </div>
            </div>
            <!-- VIP -->
            <div class="col-12 col-md-4 card-animado surgir-baixo" style="transition-delay:.3s;">
                <div class="h-100 rounded-4 overflow-hidden" style="border:1.5px solid #d4af3766;background:var(--bg-card);">
                    <div class="text-center py-1" style="background:linear-gradient(90deg,#AA8C2C,#d4af37);font-size:0.7rem;font-weight:700;letter-spacing:.08em;color:#121418;"><i class="fi fi-ss-gem" style="font-size:0.7rem;vertical-align:middle;"></i> VIP</div>
                    <div class="p-4 text-center d-flex flex-column" style="height:calc(100% - 30px);">
                        <p class="fw-semibold small text-uppercase mb-1" style="color:#d4af37;letter-spacing:.08em;"><i class="fi fi-ss-gem" style="font-size:0.75rem;vertical-align:middle;"></i> VIP</p>
                        <div class="fw-bold text-light mb-0" style="font-size:2rem;">R$ 29,90</div>
                        <p class="text-secondary mb-4" style="font-size:0.8rem;">/mês · Para famílias e empreendedores</p>
                        <ul class="list-unstyled text-start mb-4" style="font-size:0.85rem;">
                            <?php foreach (
                                [
                                    'Carteiras ilimitadas',
                                    'Registros ilimitados',
                                    'Categorias ilimitadas',
                                    'Parcelamento em até 48x (com juros)',
                                    'Histórico ilimitado',
                                    'Comprovantes e Anexos',
                                    'Suporte VIP dedicado',
                                ] as $item
                            ): ?>
                                <li class="mb-2 d-flex gap-2"><i class="bi bi-check-circle-fill mt-1 flex-shrink-0" style="color:#d4af37;"></i><span class="text-light"><?php echo $item ?></span></li>
                            <?php endforeach; ?>
                        </ul>
                        <a href="/usuario/cadastro.php" class="btn w-100 rounded-pill fw-bold mt-auto"
                            style="background:linear-gradient(90deg,#AA8C2C,#d4af37);color:#121418;border:none;font-weight:800;">
                            Assinar VIP
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ── CTA FINAL ─────────────────────────────────────────────────────────── -->
<section class="py-5 border-top border-secondary-subtle text-center position-relative overflow-hidden">
    <div style="position:absolute;bottom:-80px;left:50%;transform:translateX(-50%);width:500px;height:300px;background:var(--accent);filter:blur(160px);opacity:0.06;border-radius:50%;pointer-events:none;"></div>
    <div class="container position-relative py-4">
        <h2 class="fw-bold text-light mb-3" style="font-size:clamp(1.5rem,3vw,2.25rem);">Pronto para assumir o controle?</h2>
        <p class="text-secondary mb-5 mx-auto" style="max-width:460px;">
            Crie sua conta grátis e veja em minutos para onde vai cada centavo do seu dinheiro.
        </p>
        <a href="/usuario/cadastro.php"
            class="btn btn-lg px-5 rounded-pill fw-bold shadow-lg"
            style="background:linear-gradient(135deg,#d4af37,#AA8C2C);color:#121418;font-size:1.05rem;">
            Criar minha conta grátis
        </a>
        <p class="text-secondary mt-3 mb-0" style="font-size:0.8rem;">
            <i class="bi bi-shield-check me-1"></i>Sem cartão de crédito. Cancele quando quiser.
        </p>
    </div>
</section>

<?php require_once 'footer.php'; ?>