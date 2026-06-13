<?php
session_start();
if (isset($_SESSION['usuario_id'])) {
    header("Location: ../dashboard.php");
    exit;
}
require_once '../config/conexao.php';
require_once '../config/funcoes.php';
?>
<?php require_once 'header.php'; ?>

<?php
// ── Dados dinâmicos dos planos ────────────────────────────────────────────
$_lp_raw = [];
try {
    $rows = $pdo->query("SELECT * FROM config_limites_plano")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) $_lp_raw[$r['plano']] = $r;
} catch (PDOException $e) {}

if (empty($_lp_raw)) {
    $_lp_raw = [
        'free' => ['transacoes_mes' => 35, 'carteiras' => 1,  'categorias' => 10, 'parcelas_max' => 3],
        'pro'  => ['transacoes_mes' => -1, 'carteiras' => 3,  'categorias' => -1, 'parcelas_max' => 48],
        'vip'  => ['transacoes_mes' => -1, 'carteiras' => -1, 'categorias' => -1, 'parcelas_max' => 48],
    ];
}

$_lp_recursos = recursosParaExibicao();

function _lp_itensLimite($row)
{
    $itens = [];
    if ($row['carteiras'] == -1)      $itens[] = ['ok', 'Carteiras ilimitadas'];
    elseif ($row['carteiras'] == 1)   $itens[] = ['ok', '1 carteira'];
    else                              $itens[] = ['ok', "Até {$row['carteiras']} carteiras"];
    if ($row['transacoes_mes'] == -1) $itens[] = ['ok', 'Registros ilimitados'];
    else                              $itens[] = ['ok', "Até {$row['transacoes_mes']} registros/mês"];
    if ($row['categorias'] == -1)     $itens[] = ['ok', 'Categorias ilimitadas'];
    else                              $itens[] = ['ok', "Até {$row['categorias']} categorias"];
    $pmax = $row['parcelas_max'] ?? 3;
    if ($pmax == -1)                  $itens[] = ['ok', 'Parcelamento ilimitado'];
    elseif ($pmax <= 3)               $itens[] = ['ok', "Parcelamento em até {$pmax}x"];
    else                              $itens[] = ['ok', "Parcelamento em até {$pmax}x (com juros)"];
    return $itens;
}

function _lp_itensRecursos($planoCarta, $recursos)
{
    $colMap = ['free' => 'disponivel_free', 'pro' => 'disponivel_pro', 'vip' => 'disponivel_vip'];
    $col    = $colMap[$planoCarta] ?? 'disponivel_pro';
    $itens  = [];
    foreach ($recursos as $r) {
        $disponivel = (bool)($r[$col] ?? false);
        $itens[]    = [$disponivel ? 'ok' : 'no', $r['label']];
    }
    return $itens;
}
?>

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
                    'badge' => 'PRO',
                ],
                [
                    'icon'  => 'bi-pie-chart-fill',
                    'color' => '#f59e0b',
                    'title' => 'Análises por categoria',
                    'desc'  => 'Gráfico de distribuição com % por fatia. Clique numa categoria e veja o histórico detalhado com comparativo do mês anterior — quanto gastou em Lazer em abril vs maio.',
                    'badge' => 'PRO',
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
<section class="py-5 border-top border-secondary-subtle" id="planos">
    <div class="container">
        <div class="text-center mb-5">
            <h2 class="fw-bold text-light mb-3" style="font-size:clamp(1.5rem,3vw,2.2rem);">Preços simples e honestos</h2>
            <p class="text-secondary">Comece grátis. Evolua quando fizer sentido.</p>

            <!-- Toggle mensal / anual -->
            <div class="d-flex align-items-center justify-content-center gap-3 mt-4">
                <span class="text-secondary" style="font-size:0.9rem;">Mensal</span>
                <div class="form-check form-switch fs-4 mb-0">
                    <input class="form-check-input" type="checkbox" id="toggleAnualLp" role="switch">
                </div>
                <span class="text-light fw-semibold" style="font-size:0.9rem;">
                    Anual
                    <span style="background:#16a34a22;color:#22c55e;border:1px solid #22c55e44;border-radius:999px;padding:1px 8px;font-size:0.7rem;font-weight:700;margin-left:4px;">-33%</span>
                </span>
            </div>
        </div>

        <div class="row g-4 justify-content-center align-items-stretch">

            <?php
            $lpCards = [
                'free' => [
                    'topo_bg'      => '#4b5563',
                    'topo_label'   => 'PARA CONHECER O SISTEMA',
                    'border'       => '#4b556366',
                    'label_plano'  => 'Gratuito',
                    'nome'         => 'Free',
                    'preco_m'      => 'R$ 0',
                    'preco_a'      => null,
                    'sub'          => 'O essencial para começar a organizar.',
                    'label_cor'    => null,
                    'icone_cor_ok' => '',
                    'btn_m_text'   => 'Começar grátis',
                    'btn_a_text'   => 'Começar grátis',
                    'btn_m_style'  => 'background:rgba(255,255,255,.06);color:#f8fafc;border:1px solid rgba(255,255,255,.1);',
                    'btn_a_style'  => 'background:rgba(255,255,255,.06);color:#f8fafc;border:1px solid rgba(255,255,255,.1);',
                    'btn_m_href'   => '/usuario/cadastro.php',
                    'btn_a_href'   => '/usuario/cadastro.php',
                    'delay'        => '0s',
                ],
                'pro' => [
                    'topo_bg'      => '#7c3aed',
                    'topo_label'   => 'MAIS POPULAR',
                    'border'       => '#7c3aed88',
                    'label_plano'  => 'PRO',
                    'nome'         => 'Auralis PRO',
                    'preco_m'      => 'R$ 19,90',
                    'preco_a'      => 'R$ 14,99',
                    'preco_a_info' => 'R$ 179,90 cobrado anualmente',
                    'sub'          => 'Para quem leva as finanças a sério.',
                    'label_cor'    => '#a78bfa',
                    'icone_cor_ok' => 'style="color:#a78bfa;"',
                    'btn_m_text'   => 'Assinar PRO — R$ 19,90/mês',
                    'btn_a_text'   => 'Assinar PRO — R$ 179,90/ano',
                    'btn_m_style'  => 'background:#7c3aed;color:#fff;border:none;',
                    'btn_a_style'  => 'background:#7c3aed;color:#fff;border:none;',
                    'btn_m_href'   => '/planos.php',
                    'btn_a_href'   => '/planos.php?periodo=anual',
                    'delay'        => '.15s',
                ],
                'vip' => [
                    'topo_bg'      => 'linear-gradient(90deg,#AA8C2C,#d4af37)',
                    'topo_label'   => '⭐ PARA FAMÍLIAS &amp; EMPREENDEDORES',
                    'border'       => '#d4af3766',
                    'label_plano'  => 'VIP',
                    'nome'         => 'Auralis VIP',
                    'preco_m'      => 'R$ 29,90',
                    'preco_a'      => 'R$ 19,99',
                    'preco_a_info' => 'R$ 239,90 cobrado anualmente',
                    'sub'          => 'Para quem não aceita limites.',
                    'label_cor'    => '#d4af37',
                    'icone_cor_ok' => 'style="color:#d4af37;"',
                    'btn_m_text'   => 'Assinar VIP — R$ 29,90/mês',
                    'btn_a_text'   => 'Assinar VIP — R$ 239,90/ano',
                    'btn_m_style'  => 'background:linear-gradient(90deg,#AA8C2C,#d4af37);color:#fff;border:none;font-weight:800;',
                    'btn_a_style'  => 'background:linear-gradient(90deg,#AA8C2C,#d4af37);color:#fff;border:none;font-weight:800;',
                    'btn_m_href'   => '/planos.php',
                    'btn_a_href'   => '/planos.php?periodo=anual',
                    'delay'        => '.3s',
                ],
            ];

            foreach ($lpCards as $slug => $c):
                $row   = $_lp_raw[$slug] ?? $_lp_raw['free'];
                $itens = array_merge(_lp_itensLimite($row), _lp_itensRecursos($slug, $_lp_recursos));
            ?>
                <div class="col-12 col-md-4 card-animado surgir-baixo" style="transition-delay:<?= $c['delay'] ?>;">
                    <div class="card rounded-4 shadow-sm h-100 position-relative overflow-hidden"
                        style="background:var(--bg-card);border:1.5px solid <?= $c['border'] ?>;">

                        <div class="text-center py-1"
                            style="background:<?= $c['topo_bg'] ?>;font-size:0.7rem;font-weight:700;letter-spacing:0.08em;color:#fff;">
                            <?= $c['topo_label'] ?>
                        </div>

                        <div class="card-body p-4 d-flex flex-column">
                            <div class="mb-4">
                                <p class="fw-semibold mb-1 small text-uppercase"
                                    <?= $c['label_cor'] ? "style=\"color:{$c['label_cor']};letter-spacing:.08em;\"" : 'class="text-secondary" style="letter-spacing:.08em;"' ?>>
                                    <?= htmlspecialchars($c['label_plano']) ?>
                                </p>
                                <h3 class="fw-bold text-light mb-0" style="font-size:1.4rem;"><?= htmlspecialchars($c['nome']) ?></h3>
                                <div class="mt-3">
                                    <span class="fw-bold text-light preco-mensal-lp" style="font-size:2rem;"><?= $c['preco_m'] ?></span>
                                    <?php if ($c['preco_a'] ?? null): ?>
                                        <span class="fw-bold text-light preco-anual-lp d-none" style="font-size:2rem;"><?= $c['preco_a'] ?></span>
                                    <?php endif; ?>
                                    <?php if ($slug !== 'free'): ?><span class="text-secondary">/mês</span><?php endif; ?>
                                </div>
                                <?php if ($c['preco_a'] ?? null): ?>
                                    <p class="text-secondary mt-1 mb-0 preco-anual-info-lp d-none" style="font-size:0.78rem;"><?= $c['preco_a_info'] ?></p>
                                <?php endif; ?>
                                <p class="text-secondary mt-2 mb-0" style="font-size:0.82rem;"><?= htmlspecialchars($c['sub']) ?></p>
                            </div>

                            <ul class="list-unstyled flex-grow-1 mb-4" style="font-size:0.85rem;">
                                <?php foreach ($itens as [$tipo, $item]): ?>
                                    <li class="d-flex align-items-center gap-2 mb-2">
                                        <i class="bi <?= $tipo === 'ok'
                                                            ? 'bi-check-circle-fill ' . ($c['icone_cor_ok'] ? '' : 'text-success')
                                                            : 'bi-x-circle text-secondary opacity-40' ?>"
                                            <?= ($tipo === 'ok' && $c['icone_cor_ok']) ? $c['icone_cor_ok'] : '' ?>></i>
                                        <span class="<?= $tipo === 'no' ? 'text-secondary opacity-40 text-decoration-line-through' : 'text-light' ?>">
                                            <?= htmlspecialchars($item) ?>
                                        </span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>

                            <div class="mt-auto">
                                <a href="<?= $c['btn_m_href'] ?>"
                                    class="btn w-100 rounded-pill fw-bold preco-mensal-lp"
                                    style="<?= $c['btn_m_style'] ?>">
                                    <?= htmlspecialchars($c['btn_m_text']) ?>
                                </a>
                                <a href="<?= $c['btn_a_href'] ?>"
                                    class="btn w-100 rounded-pill fw-bold preco-anual-lp d-none"
                                    style="<?= $c['btn_a_style'] ?>">
                                    <?= htmlspecialchars($c['btn_a_text']) ?>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>

        </div>

        <p class="text-center text-secondary mt-4 mb-0" style="font-size:0.82rem;">
            <i class="bi bi-shield-check me-1"></i>
            Pagamento seguro. Cancele quando quiser. Sem fidelidade.
            <a href="/planos.php" class="text-secondary ms-2" style="text-decoration:underline dotted;">Ver página completa de planos →</a>
        </p>
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

<script>
(function () {
    const toggle    = document.getElementById('toggleAnualLp');
    if (!toggle) return;
    const mensal    = document.querySelectorAll('.preco-mensal-lp');
    const anual     = document.querySelectorAll('.preco-anual-lp');
    const anualInfo = document.querySelectorAll('.preco-anual-info-lp');
    toggle.addEventListener('change', function () {
        const isAnual = this.checked;
        mensal   .forEach(el => el.classList.toggle('d-none',  isAnual));
        anual    .forEach(el => el.classList.toggle('d-none', !isAnual));
        anualInfo.forEach(el => el.classList.toggle('d-none', !isAnual));
    });
})();
</script>

<?php require_once 'footer.php'; ?>