<?php
session_start();
require_once 'config/conexao.php';
exigirAcessoMinimo(1);

$usuario_id = $_SESSION['usuario_id'];

// ── Resgatar código promocional ──────────────────────────────────────────────
$msg_resgate  = '';
$tipo_resgate = '';
$resgatado    = $_GET['resgatado'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'resgatar_codigo') {
    $codigo = strtoupper(trim($_POST['codigo'] ?? ''));
    if (empty($codigo)) {
        $msg_resgate  = 'Digite um código válido.';
        $tipo_resgate = 'danger';
    } else {
        try {
            $stmt = $pdo->prepare("SELECT * FROM codigos_ativacao WHERE UPPER(Codigo) = :c AND Ativo = 1 LIMIT 1");
            $stmt->execute([':c' => $codigo]);
            $cod = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$cod) {
                $msg_resgate  = 'Código inválido ou inativo.';
                $tipo_resgate = 'danger';
            } elseif ($cod['DataExpiracao'] && $cod['DataExpiracao'] < date('Y-m-d')) {
                $msg_resgate  = 'Este código já expirou.';
                $tipo_resgate = 'danger';
            } elseif ($cod['MaxUsos'] !== null && $cod['UsoAtual'] >= $cod['MaxUsos']) {
                $msg_resgate  = 'Este código já atingiu o limite de usos.';
                $tipo_resgate = 'danger';
            } else {
                $jaUsou = $pdo->prepare("SELECT 1 FROM codigos_ativacao_usos WHERE FKCodigo = :cid AND FKUsuario = :uid");
                $jaUsou->execute([':cid' => $cod['IDCodigo'], ':uid' => $usuario_id]);
                if ($jaUsou->fetchColumn()) {
                    $msg_resgate  = 'Você já utilizou este código.';
                    $tipo_resgate = 'warning';
                } else {
                    // Acumula dias sobre assinatura ativa existente (igual ao resgatar.php)
                    $stmtExp = $pdo->prepare("SELECT DataExpiracao FROM Assinatura WHERE FKUsuario = :uid AND Status = 'ativa' ORDER BY DataExpiracao DESC LIMIT 1");
                    $stmtExp->execute([':uid' => $usuario_id]);
                    $assinaturaAtual = $stmtExp->fetch(PDO::FETCH_ASSOC);
                    $base = new DateTime('today');
                    if ($assinaturaAtual && $assinaturaAtual['DataExpiracao'] > date('Y-m-d')) {
                        $base = new DateTime($assinaturaAtual['DataExpiracao']);
                    }
                    $base->modify('+' . $cod['DuracaoDias'] . ' days');
                    $novaExpiracao = $base->format('Y-m-d');

                    // Nunca faz downgrade de plano
                    $hierarquia  = ['free' => 0, 'pro' => 1, 'vip' => 2];
                    $planoAtualS = $_SESSION['plano'] ?? 'free';
                    $planoRecomp = $cod['PlanoRecompensa'];
                    $planoFinal  = ($hierarquia[$planoRecomp] ?? 0) > ($hierarquia[$planoAtualS] ?? 0)
                                    ? $planoRecomp : $planoAtualS;

                    $pdo->beginTransaction();
                    $pdo->prepare("UPDATE Assinatura SET Status = 'cancelada' WHERE FKUsuario = :uid AND Status = 'ativa'")->execute([':uid' => $usuario_id]);
                    $pdo->prepare("INSERT INTO Assinatura (IDAssinatura, FKUsuario, Plano, Status, Ciclo, ValorPago, DataInicio, DataExpiracao, IDAssinaturaGW, EmailGateway, FontePagamento) VALUES (:id, :uid, :plano, 'ativa', 'codigo', 0, :inicio, :exp, NULL, NULL, 'codigo_ativacao')")
                        ->execute([':id' => gerarUuid(), ':uid' => $usuario_id, ':plano' => $planoFinal, ':inicio' => date('Y-m-d'), ':exp' => $novaExpiracao]);
                    $pdo->prepare("UPDATE Usuario SET Plano = :plano WHERE IDUsuario = :uid")->execute([':plano' => $planoFinal, ':uid' => $usuario_id]);
                    $pdo->prepare("INSERT INTO codigos_ativacao_usos (IDUso, FKCodigo, FKUsuario) VALUES (:id, :cid, :uid)")->execute([':id' => gerarUuid(), ':cid' => $cod['IDCodigo'], ':uid' => $usuario_id]);
                    $pdo->prepare("UPDATE codigos_ativacao SET UsoAtual = UsoAtual + 1 WHERE IDCodigo = :id")->execute([':id' => $cod['IDCodigo']]);
                    $pdo->commit();

                    $_SESSION['plano'] = $planoFinal;
                    unset($_SESSION['expiracao_verificada']);
                    header('Location: planos.php?resgatado=' . urlencode($planoFinal));
                    exit;
                }
            }
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $msg_resgate  = 'Erro ao processar o código. Tente novamente.';
            $tipo_resgate = 'danger';
        }
    }
}

$planoAtual = obterPlanoAtual();
$upgrade    = $_GET['upgrade'] ?? '';
$pageTitle  = "Planos — Auralis";

if ($upgrade === 'pro') {
    $msg_upgrade = 'Este recurso é exclusivo do <strong>Auralis PRO</strong>. Faça upgrade para desbloquear.';
} elseif ($upgrade === 'vip') {
    $msg_upgrade = 'Este recurso é exclusivo do <strong>Auralis VIP</strong>. Faça upgrade para desbloquear.';
} else {
    $msg_upgrade = '';
}

// ── Carrega limites e recursos do banco ──────────────────────────────────
$limitesRaw = [];   // valores brutos do banco (com -1 para ilimitado)
try {
    $rows = $pdo->query("SELECT * FROM config_limites_plano")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) $limitesRaw[$r['plano']] = $r;
} catch (PDOException $e) {
}

// Fallback se a tabela ainda não existir
if (empty($limitesRaw)) {
    $limitesRaw = [
        'free' => ['transacoes_mes' => 35, 'carteiras' => 1,  'cartoes' => 1,  'categorias' => 10, 'parcelas_max' => 3],
        'pro'  => ['transacoes_mes' => -1, 'carteiras' => 3,  'cartoes' => 3,  'categorias' => -1, 'parcelas_max' => 48],
        'vip'  => ['transacoes_mes' => -1, 'carteiras' => -1, 'cartoes' => -1, 'categorias' => -1, 'parcelas_max' => 48],
    ];
}

$recursos = recursosParaExibicao();   // ['slug', 'label', 'nivel_minimo']

// ── Helper: gera itens de limite para um card ─────────────────────────────
function _itensLimite($row)
{
    $itens = [];
    if ($row['carteiras'] == -1)     $itens[] = ['ok', 'Carteiras ilimitadas'];
    elseif ($row['carteiras'] == 1)  $itens[] = ['ok', '1 carteira'];
    else                             $itens[] = ['ok', "Até {$row['carteiras']} carteiras"];
    $nc = $row['cartoes'] ?? 1;
    if ($nc == -1)    $itens[] = ['ok', 'Cartões de crédito ilimitados'];
    elseif ($nc == 1) $itens[] = ['ok', '1 cartão de crédito'];
    else              $itens[] = ['ok', "Até {$nc} cartões de crédito"];
    if ($row['transacoes_mes'] == -1) $itens[] = ['ok', 'Registros ilimitados'];
    else                              $itens[] = ['ok', "Até {$row['transacoes_mes']} registros/mês"];
    if ($row['categorias'] == -1)  $itens[] = ['ok', 'Categorias ilimitadas'];
    else                           $itens[] = ['ok', "Até {$row['categorias']} categorias"];
    $pmax = $row['parcelas_max'] ?? 3;
    if ($pmax == -1)     $itens[] = ['ok', 'Lançamentos parcelados ilimitados'];
    elseif ($pmax <= 3)  $itens[] = ['ok', "Registre compras parceladas em até {$pmax}x"];
    else                 $itens[] = ['ok', "Registre compras parceladas em até {$pmax}x"];
    return $itens;
}

// Exportação já vem do config_recursos — nenhum extra necessário aqui
$_extras_plano = [
    'free' => [],
    'pro'  => [],
    'vip'  => [],
];

$_temas_plano = [
    'free' => ['ok', 'Temas Black & White'],
    'pro'  => ['ok', 'Temas variados'],
    'vip'  => ['ok', 'Todos os temas desbloqueados'],
];

// ── Helper: adiciona itens de recurso (✅/❌) para um card ───────────────
function _itensRecursos($planoCarta, $recursos)
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

require_once 'geral/header.php';
?>

<main class="container py-5 mt-2 flex-grow-1" style="padding-inline: var(--space-page-x); max-width: 1160px;">

    <!-- Topo -->
    <div class="text-center mb-5">
        <h1 class="fw-bold text-light mb-2">Escolha seu plano</h1>
        <p class="text-secondary" style="max-width: 520px; margin: 0 auto;">
            Comece de graça e evolua conforme suas necessidades. Sem surpresas na cobrança.
        </p>

        <?php if ($msg_upgrade): ?>
            <div class="alert mt-4 mx-auto" style="max-width:520px;background:#d4af3715;border:1px solid #d4af3740;color:#d4af37;border-radius:0.75rem;">
                <i class="bi bi-lock-fill me-2"></i> <?= $msg_upgrade ?>
            </div>
        <?php endif; ?>

        <!-- Toggle mensal / anual -->
        <div class="d-flex align-items-center justify-content-center gap-3 mt-4">
            <span class="text-secondary" style="font-size:0.9rem;">Mensal</span>
            <div class="form-check form-switch fs-4 mb-0">
                <input class="form-check-input" type="checkbox" id="toggleAnual" role="switch">
            </div>
            <span class="text-light fw-semibold" style="font-size:0.9rem;">
                Anual
                <span style="background:#16a34a22;color:#22c55e;border:1px solid #22c55e44;border-radius:999px;padding:1px 8px;font-size:0.7rem;font-weight:700;margin-left:4px;">-33%</span>
            </span>
        </div>
    </div>

    <!-- Cards -->
    <div class="row g-4 justify-content-center align-items-stretch">

        <?php
        // ── Configuração estática de cada card ─────────────────────────────
        $cards = [
            'free' => [
                'topo_bg'     => '#4b5563',
                'topo_label'  => 'PARA CONHECER O SISTEMA',
                'border'      => '#4b556366',
                'label_plano' => 'Gratuito',
                'nome'        => 'Free',
                'preco_m'     => 'R$ 0',
                'preco_a'     => null,
                'subtitulo'   => 'O essencial para começar a organizar.',
                'icone_cor'   => 'text-success',
                'icone_cor_ok' => '',
                'btn_atual'   => 'background:rgba(255,255,255,.06);color:#6b7280;',
                'btn_basico'  => 'Plano básico',
                'btn_m_href'  => null,
                'btn_a_href'  => null,
            ],
            'pro' => [
                'topo_bg'     => '#7c3aed',
                'topo_label'  => 'MAIS POPULAR',
                'border'      => '#7c3aed88',
                'label_plano' => 'PRO',
                'nome'        => 'Auralis PRO',
                'preco_m'     => 'R$ 19,90',
                'preco_a'     => 'R$ 14,99',
                'preco_a_info' => 'R$ 179,90 cobrado anualmente',
                'subtitulo'   => 'Para quem leva as finanças a sério.',
                'label_cor'   => '#a78bfa',
                'icone_cor'   => '',
                'icone_cor_ok' => 'style="color:#a78bfa;"',
                'btn_atual'   => 'background:rgba(124,58,237,.2);color:#a78bfa;border:1px solid #7c3aed66;',
                'btn_basico'  => null,
                'btn_m_text'  => 'Assinar PRO — R$ 19,90/mês',
                'btn_a_text'  => 'Assinar PRO — R$ 179,90/ano',
                'btn_m_style' => 'background:#7c3aed;color:#fff;border:none;',
                'btn_a_style' => 'background:#7c3aed;color:#fff;border:none;',
                'btn_m_href'  => 'https://www.mercadopago.com.br/subscriptions/checkout?preapproval_plan_id=9c7869b02a884962a185a44dee6c16f8',
                'btn_a_href'  => 'https://www.mercadopago.com.br/subscriptions/checkout?preapproval_plan_id=98c6343b478e4efcad77ab56fe6f5948',
                'link_pix_m'  => 'https://mpago.la/17rLRGi',
                'link_pix_a'  => 'https://mpago.la/1E55gtV',
            ],
            'vip' => [
                'topo_bg'     => 'linear-gradient(90deg,#AA8C2C,#d4af37)',
                'topo_label'  => '⭐ PARA FAMÍLIAS &amp; EMPREENDEDORES',
                'border'      => '#d4af3766',
                'label_plano' => 'VIP',
                'nome'        => 'Auralis VIP',
                'preco_m'     => 'R$ 29,90',
                'preco_a'     => 'R$ 19,99',
                'preco_a_info' => 'R$ 239,90 cobrado anualmente',
                'subtitulo'   => 'Para quem não aceita limites.',
                'label_cor'   => '#d4af37',
                'icone_cor'   => '',
                'icone_cor_ok' => 'style="color:#d4af37;"',
                'btn_atual'   => 'background:#d4af3720;color:#d4af37;border:1px solid #d4af3766;',
                'btn_basico'  => null,
                'btn_m_text'  => 'Assinar VIP — R$ 29,90/mês',
                'btn_a_text'  => 'Assinar VIP — R$ 239,90/ano',
                'btn_m_style' => 'background:linear-gradient(90deg,#AA8C2C,#d4af37);color:#fff;border:none;font-weight:800;',
                'btn_a_style' => 'background:linear-gradient(90deg,#AA8C2C,#d4af37);color:#fff;border:none;font-weight:800;',
                'btn_m_href'  => 'https://www.mercadopago.com.br/subscriptions/checkout?preapproval_plan_id=55856961da8d49d09b4ccded59a56810',
                'btn_a_href'  => 'https://www.mercadopago.com.br/subscriptions/checkout?preapproval_plan_id=3ed445df740c439884e8ebc71ddbdb69',
                'link_pix_m'  => 'https://mpago.la/1b7H1k2',
                'link_pix_a'  => 'https://mpago.la/1tZ5d8W',
            ],
        ];

        foreach ($cards as $slug => $c):
            $row          = $limitesRaw[$slug] ?? $limitesRaw['free'];
            $recursoItens = _itensRecursos($slug, $recursos);
            $insertAt     = count($recursoItens);
            foreach ($recursoItens as $ri => [, $rl]) {
                if (stripos($rl, 'mobile') !== false) { $insertAt = $ri + 1; break; }
            }
            array_splice($recursoItens, $insertAt, 0, [$_temas_plano[$slug]]);
            $itens = array_merge(_itensLimite($row), $recursoItens, $_extras_plano[$slug] ?? []);
        ?>
            <div class="col-12 col-md-4">
                <div class="card rounded-4 shadow-sm h-100 position-relative overflow-hidden"
                    style="background:var(--bg-card);border:1.5px solid <?= $c['border'] ?>;">

                    <div class="text-center py-1"
                        style="background:<?= $c['topo_bg'] ?>;font-size:0.7rem;font-weight:700;letter-spacing:0.08em;color:#fff;">
                        <?= $c['topo_label'] ?>
                    </div>

                    <div class="card-body p-4 d-flex flex-column">
                        <div class="mb-4">
                            <p class="fw-semibold mb-1 small text-uppercase tracking-wide"
                                <?= isset($c['label_cor']) ? "style=\"color:{$c['label_cor']};\"" : 'class="text-secondary"' ?>>
                                <?= htmlspecialchars($c['label_plano']) ?>
                            </p>
                            <h3 class="fw-bold text-light mb-0"><?= htmlspecialchars($c['nome']) ?></h3>
                            <div class="mt-3">
                                <span class="fw-bold text-light preco-mensal" style="font-size:2rem;"><?= $c['preco_m'] ?></span>
                                <?php if ($c['preco_a'] ?? null): ?>
                                    <span class="fw-bold text-light preco-anual d-none" style="font-size:2rem;"><?= $c['preco_a'] ?></span>
                                <?php endif; ?>
                                <span class="text-secondary">/mês</span>
                            </div>
                            <?php if ($c['preco_a'] ?? null): ?>
                                <p class="text-secondary mt-1 mb-0 preco-anual-info d-none" style="font-size:0.8rem;"><?= $c['preco_a_info'] ?></p>
                            <?php endif; ?>
                            <p class="text-secondary mt-2 mb-0" style="font-size:0.85rem;"><?= htmlspecialchars($c['subtitulo']) ?></p>
                        </div>

                        <ul class="list-unstyled flex-grow-1 mb-4" style="font-size:0.875rem;">
                            <?php foreach ($itens as [$tipo, $item]): ?>
                                <li class="d-flex align-items-center gap-2 mb-2">
                                    <i class="bi <?= $tipo === 'ok'
                                                        ? 'bi-check-circle-fill ' . $c['icone_cor']
                                                        : 'bi-x-circle text-secondary opacity-40' ?>"
                                        <?= ($tipo === 'ok' && $c['icone_cor_ok']) ? $c['icone_cor_ok'] : '' ?>></i>
                                    <span class="<?= $tipo === 'no'
                                                        ? 'text-secondary opacity-40 text-decoration-line-through'
                                                        : 'text-light' ?>">
                                        <?= htmlspecialchars($item) ?>
                                    </span>
                                </li>
                            <?php endforeach; ?>
                        </ul>

                        <?php if ($planoAtual === $slug): ?>
                            <button class="btn w-100 rounded-pill fw-semibold"
                                style="<?= $c['btn_atual'] ?>cursor:default;" disabled>
                                <?= $slug === 'vip' ? '⭐' : '✓' ?> Plano atual
                            </button>
                        <?php elseif ($c['btn_basico'] ?? null): ?>
                            <div class="btn w-100 rounded-pill fw-semibold text-secondary"
                                style="background:transparent;border:1px solid rgba(255,255,255,.1);cursor:default;">
                                <?= htmlspecialchars($c['btn_basico']) ?>
                            </div>
                        <?php else: ?>
                            <div class="d-flex flex-column gap-2">
                                <a href="<?= $c['btn_m_href'] ?>" target="_blank"
                                    class="btn w-100 rounded-pill fw-bold preco-mensal"
                                    style="<?= $c['btn_m_style'] ?>">
                                    <?= htmlspecialchars($c['btn_m_text']) ?>
                                </a>
                                <a href="<?= $c['btn_a_href'] ?>" target="_blank"
                                    class="btn w-100 rounded-pill fw-bold preco-anual d-none"
                                    style="<?= $c['btn_a_style'] ?>">
                                    <?= htmlspecialchars($c['btn_a_text']) ?>
                                </a>

                                <a href="<?= htmlspecialchars($c['link_pix_m']) ?>" target="_blank"
                                    class="btn w-100 rounded-pill fw-semibold preco-mensal"
                                    style="background:rgba(6,214,160,.12);color:#06D6A0;border:1px solid rgba(6,214,160,.35);">
                                    <i class="bi bi-qr-code me-1"></i> Pagar com Pix
                                </a>
                                <a href="<?= htmlspecialchars($c['link_pix_a']) ?>" target="_blank"
                                    class="btn w-100 rounded-pill fw-semibold preco-anual d-none"
                                    style="background:rgba(6,214,160,.12);color:#06D6A0;border:1px solid rgba(6,214,160,.35);">
                                    <i class="bi bi-qr-code me-1"></i> Pagar com Pix
                                </a>
                                <p class="text-secondary text-center mb-0" style="font-size:0.7rem;">
                                    <i class="bi bi-info-circle me-1"></i>Pix não renova automaticamente — avisamos antes de vencer.
                                </p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>

    </div>

    <!-- Garantia -->
    <div class="text-center mt-5 text-secondary" style="font-size:0.85rem;">
        <i class="bi bi-shield-check me-1"></i>
        Pagamento seguro. Cancele quando quiser. Sem fidelidade.
    </div>

    <!-- FAQ -->
    <div class="mt-5 pt-5 border-top border-secondary-subtle">
        <div class="text-center mb-4">
            <h2 class="fw-bold text-light mb-2" style="font-size:1.6rem;">Dúvidas frequentes</h2>
            <p class="text-secondary" style="font-size:0.9rem;">Tudo o que você precisa saber antes de assinar.</p>
        </div>

        <div class="accordion mx-auto" id="faqPlanos" style="max-width:680px;">
            <?php
            $faqsPlanos = [
                ['Posso cancelar quando quiser?',
                 'Sim, sem multa e sem burocracia. Ao cancelar, você mantém o acesso PRO ou VIP até o fim do período já pago. Depois, a conta volta automaticamente para o Free — sem perda de dados.'],
                ['O que acontece com meus dados se eu cancelar?',
                 'Seus dados ficam intactos. Ao voltar para o Free, registros e histórico são mantidos. Apenas novos registros ficam sujeitos ao limite do plano Free.'],
                ['O pagamento é seguro?',
                 'Sim. O processamento é feito pelo Mercado Pago — uma das maiores plataformas de pagamento da América Latina. Seus dados de cartão nunca passam pelos nossos servidores.'],
                ['Posso assinar e cancelar no mesmo mês?',
                 'Sim. Se mudar de ideia logo após assinar, basta cancelar — você continua com acesso até o fim do período pago e não será cobrado novamente.'],
                ['A assinatura renova automaticamente?',
                 'Sim, a cobrança é recorrente (mensal ou anual, conforme você escolheu). Você pode cancelar a qualquer momento pelo Mercado Pago ou entrando em contato com o suporte.'],
                ['Tem desconto para plano anual?',
                 'Sim — o plano anual sai 33% mais barato que o mensal. PRO anual: R$ 14,99/mês (R$ 179,90/ano). VIP anual: R$ 19,99/mês (R$ 239,90/ano).'],
            ];
            foreach ($faqsPlanos as $i => [$q, $a]):
                $fid = 'faqp' . $i;
            ?>
                <div class="accordion-item border-0 mb-2" style="background:var(--bg-card);border-radius:0.75rem !important;overflow:hidden;border:1px solid var(--bs-border-color) !important;">
                    <h3 class="accordion-header">
                        <button class="accordion-button collapsed fw-semibold"
                            type="button" data-bs-toggle="collapse" data-bs-target="#<?= $fid ?>"
                            style="background:var(--bg-card);color:var(--text-main);font-size:0.875rem;box-shadow:none;">
                            <?= htmlspecialchars($q) ?>
                        </button>
                    </h3>
                    <div id="<?= $fid ?>" class="accordion-collapse collapse" data-bs-parent="#faqPlanos">
                        <div class="accordion-body text-secondary" style="font-size:0.85rem;line-height:1.7;padding-top:0;">
                            <?= htmlspecialchars($a) ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- ── Resgatar código ──────────────────────────────────────────────── -->
    <div class="mt-5 pt-5 border-top border-secondary-subtle">
        <div class="text-center mb-4">
            <h2 class="fw-bold text-light mb-1" style="font-size:1.3rem;">
                <i class="bi bi-ticket-perforated-fill me-2" style="color:var(--accent);"></i>
                Tem um código promocional?
            </h2>
            <p class="text-secondary mb-0" style="font-size:0.88rem;">Insira abaixo e ganhe acesso instantâneo ao plano correspondente.</p>
        </div>

        <?php if ($msg_resgate): ?>
            <div class="alert alert-<?= $tipo_resgate ?> mx-auto text-center border-0 rounded-3" style="max-width:480px;">
                <?= $msg_resgate ?>
            </div>
        <?php endif; ?>

        <form method="POST" class="mx-auto d-flex gap-2 flex-wrap justify-content-center" style="max-width:480px;" id="formCodigo">
            <input type="hidden" name="action" value="resgatar_codigo">
            <input type="text" name="codigo" id="inputCodigo"
                   class="form-control form-control-lg bg-transparent border-secondary-subtle text-light text-center fw-bold shadow-none flex-grow-1"
                   placeholder="XXXX-XXXX-XXXX"
                   maxlength="50" autocomplete="off"
                   style="letter-spacing:0.12em;font-size:1rem;min-width:220px;"
                   oninput="this.value=this.value.toUpperCase()"
                   value="<?= htmlspecialchars($_POST['codigo'] ?? '') ?>">
            <button type="submit" class="btn fw-bold px-4 rounded-pill"
                    style="background:var(--accent);color:#000;">
                <i class="bi bi-gift-fill me-1"></i> Resgatar
            </button>
        </form>
    </div>

</main>

<script>
    const toggle = document.getElementById('toggleAnual');
    const mensal = document.querySelectorAll('.preco-mensal');
    const anual = document.querySelectorAll('.preco-anual');
    const anualInfo = document.querySelectorAll('.preco-anual-info');

    toggle.addEventListener('change', function() {
        const isAnual = this.checked;
        mensal.forEach(el => el.classList.toggle('d-none', isAnual));
        anual.forEach(el => el.classList.toggle('d-none', !isAnual));
        anualInfo.forEach(el => el.classList.toggle('d-none', !isAnual));
    });
</script>

<?php if ($resgatado): ?>
<script src="https://cdn.jsdelivr.net/npm/canvas-confetti@1.9.3/dist/confetti.browser.min.js"></script>
<script>
(function() {
    var isVip = <?= json_encode($resgatado === 'vip') ?>;
    var colors = isVip
        ? ['#d4af37', '#ffb800', '#fff7cc', '#f5e642', '#ffffff']
        : ['#7c3aed', '#a78bfa', '#c4b5fd', '#ffffff', '#d4af37'];

    function burst(x, angle, spread) {
        confetti({
            particleCount: 80,
            angle: angle,
            spread: spread,
            origin: { x: x, y: 0.8 },
            colors: colors,
            scalar: 1.1,
            drift: 0,
            gravity: 0.9,
            ticks: 220
        });
    }

    // Primeiro burst imediato
    burst(0.25, 120, 70);
    burst(0.75, 60, 70);

    // Chuva central após 400ms
    setTimeout(function() {
        confetti({
            particleCount: 120,
            spread: 100,
            origin: { x: 0.5, y: 0.55 },
            colors: colors,
            scalar: 1.2,
            ticks: 280
        });
    }, 400);

    // Lados finais
    setTimeout(function() {
        burst(0.1, 110, 50);
        burst(0.9, 70, 50);
    }, 750);
})();
</script>
<?php endif; ?>

<?php require_once 'geral/footer.php'; ?>