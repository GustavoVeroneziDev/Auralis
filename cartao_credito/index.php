<?php
session_start();
if (!isset($_SESSION['usuario_id'])) { header("Location: ../usuario/login.php"); exit; }

require_once '../config/conexao.php';
require_once '../config/funcoes.php';
require_once '../config/funcoes_cartao.php';

$uid      = $_SESSION['usuario_id'];
$erro     = null;
$sucesso  = null;
$pageTitle = 'Cartões de Crédito — Auralis';

// Carteiras para seleção de débito
$carteiras = [];
try {
    $s = $pdo->prepare("SELECT IDCarteira, TipoCarteira FROM Carteira WHERE FKUsuarioDono = :uid ORDER BY TipoCarteira ASC");
    $s->execute([':uid' => $uid]);
    $carteiras = $s->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}

// Categorias
$categorias = [];
try {
    $s = $pdo->prepare("SELECT IDCategoria, NomeCategoria FROM Categoria WHERE FKUsuario = :uid ORDER BY NomeCategoria ASC");
    $s->execute([':uid' => $uid]);
    $categorias = $s->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}

// ── POST HANDLER ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'salvar_cartao') {
        $id          = trim($_POST['id_cartao'] ?? '');
        $nome        = trim($_POST['nome'] ?? '');
        $bandeira    = in_array($_POST['bandeira'] ?? '', ['visa','mastercard','elo','amex','hipercard','outro'])
                       ? $_POST['bandeira'] : 'outro';
        $cor         = preg_match('/^#[0-9a-fA-F]{6}$/', $_POST['cor'] ?? '') ? $_POST['cor'] : '#7c3aed';
        $limite      = is_numeric($_POST['limite'] ?? '') ? (float)$_POST['limite'] : null;
        $diaFech     = max(1, min(31, (int)($_POST['dia_fechamento'] ?? 1)));
        $diaVenc     = max(1, min(31, (int)($_POST['dia_vencimento'] ?? 10)));
        $carteiraDb  = trim($_POST['carteira_debito'] ?? '') ?: null;

        if (empty($nome)) {
            $erro = 'O nome do cartão é obrigatório.';
        } elseif ($diaFech === $diaVenc) {
            $erro = 'O dia de fechamento e o dia de vencimento não podem ser iguais.';
        } else {
            try {
                if ($id) {
                    // Edição
                    $pdo->prepare(
                        "UPDATE CartaoCredito SET Nome=:n, Bandeira=:b, Cor=:c, Limite=:l,
                         DiaFechamento=:df, DiaVencimento=:dv, FKCarteiraDebito=:cd
                         WHERE IDCartao=:id AND FKUsuario=:uid"
                    )->execute([':n'=>$nome,':b'=>$bandeira,':c'=>$cor,':l'=>$limite,
                                ':df'=>$diaFech,':dv'=>$diaVenc,':cd'=>$carteiraDb,':id'=>$id,':uid'=>$uid]);
                    $sucesso = 'Cartão atualizado com sucesso.';
                } else {
                    // Criação — verifica limite do plano (trial tem acesso total)
                    $limites   = limitesDoPlano();
                    $emTesteCC = function_exists('obterHorasRestantesTeste') ? (obterHorasRestantesTeste() > 0) : false;
                    if (!$emTesteCC && $limites['cartoes'] !== PHP_INT_MAX) {
                        $stmtQtd = $pdo->prepare("SELECT COUNT(*) FROM CartaoCredito WHERE FKUsuario = :uid AND Ativo = 1");
                        $stmtQtd->execute([':uid' => $uid]);
                        if ((int)$stmtQtd->fetchColumn() >= $limites['cartoes']) {
                            $erro = 'Seu plano permite no máximo ' . $limites['cartoes'] . ' cartão(ões) de crédito. Faça upgrade para adicionar mais.';
                        }
                    }
                    if (!$erro) {
                        $newId = gerarUuid();
                        $pdo->prepare(
                            "INSERT INTO CartaoCredito (IDCartao,FKUsuario,Nome,Bandeira,Cor,Limite,DiaFechamento,DiaVencimento,FKCarteiraDebito)
                             VALUES (:id,:uid,:n,:b,:c,:l,:df,:dv,:cd)"
                        )->execute([':id'=>$newId,':uid'=>$uid,':n'=>$nome,':b'=>$bandeira,':c'=>$cor,
                                    ':l'=>$limite,':df'=>$diaFech,':dv'=>$diaVenc,':cd'=>$carteiraDb]);
                        $sucesso = 'Cartão adicionado com sucesso.';
                    }
                }
            } catch (PDOException $e) {
                $erro = 'Erro ao salvar cartão: ' . $e->getMessage();
            }
        }
    }

    if ($action === 'excluir_cartao') {
        $id = trim($_POST['id_cartao'] ?? '');
        if ($id) {
            try {
                $pdo->beginTransaction();
                // Remove Registro de preview (sintético, pode apagar sempre)
                $pdo->prepare(
                    "DELETE r FROM Registro r
                     JOIN FaturaCartao f ON f.FKRegistroPreview = r.IDRegistro
                     WHERE f.FKCartao = :id AND f.FKUsuario = :uid"
                )->execute([':id' => $id, ':uid' => $uid]);
                // Remove Registros de pagamento ainda pendentes (não efetivados)
                $pdo->prepare(
                    "DELETE r FROM Registro r
                     JOIN FaturaCartao f ON f.FKRegistroPagamento = r.IDRegistro
                     WHERE f.FKCartao = :id AND f.FKUsuario = :uid AND r.StatusRegistro = 'pendente'"
                )->execute([':id' => $id, ':uid' => $uid]);
                // Remove lançamentos
                $pdo->prepare(
                    "DELETE l FROM LancamentoCartao l
                     JOIN FaturaCartao f ON l.FKFatura = f.IDFatura
                     WHERE f.FKCartao = :id AND f.FKUsuario = :uid"
                )->execute([':id' => $id, ':uid' => $uid]);
                // Remove faturas
                $pdo->prepare("DELETE FROM FaturaCartao WHERE FKCartao=:id AND FKUsuario=:uid")
                    ->execute([':id' => $id, ':uid' => $uid]);
                // Remove o cartão
                $pdo->prepare("DELETE FROM CartaoCredito WHERE IDCartao=:id AND FKUsuario=:uid")
                    ->execute([':id' => $id, ':uid' => $uid]);
                $pdo->commit();
                $sucesso = 'Cartão excluído com sucesso.';
            } catch (PDOException $e) {
                $pdo->rollBack();
                $erro = 'Erro ao excluir o cartão.';
            }
        }
    }
}

cartao_verificarFechamentos($pdo, $uid);
$cartoes = cartao_listarAtivos($pdo, $uid);

// Detecta cartões bloqueados e em trial
$_planoCC   = strtolower($_SESSION['plano'] ?? 'free');
$_testeCC   = function_exists('obterHorasRestantesTeste') ? (obterHorasRestantesTeste() > 0) : false;
$_limitesCC = limitesDoPlano();
$cartoes_bloqueados_ids = [];
$cartoes_trial_ids      = [];
if ($_planoCC === 'free' && $_limitesCC['cartoes'] !== PHP_INT_MAX) {
    for ($i = $_limitesCC['cartoes']; $i < count($cartoes); $i++) {
        $id = $cartoes[$i]['IDCartao'];
        if ($_testeCC) {
            $cartoes_trial_ids[] = $id;
        } else {
            $cartoes_bloqueados_ids[] = $id;
        }
    }
}
$_podeCriarCC = ($_limitesCC['cartoes'] === PHP_INT_MAX) || count($cartoes) < $_limitesCC['cartoes'] || $_testeCC;

// Pré-carrega dados de fatura aberta e total para cada cartão
$dadosCartoes = [];
foreach ($cartoes as $c) {
    try {
        $fatura  = cartao_obterFaturaAberta($pdo, $c['IDCartao'], $uid, $c);
        // Total apenas da fatura atual (não de parcelas futuras)
        $stmtTot = $pdo->prepare("SELECT COALESCE(SUM(Valor), 0) FROM LancamentoCartao WHERE FKFatura = :fid");
        $stmtTot->execute([':fid' => $fatura['IDFatura']]);
        $total   = (float)$stmtTot->fetchColumn();
        $diasAte = (new DateTime())->diff(new DateTime($fatura['DataFechamento']))->days;
        $jaFechou = new DateTime('today') > new DateTime($fatura['DataFechamento']);
        $dadosCartoes[$c['IDCartao']] = [
            'fatura'   => $fatura,
            'total'    => $total,
            'diasAte'  => $jaFechou ? 0 : $diasAte,
            'pct'      => $c['Limite'] ? round(($total / $c['Limite']) * 100) : null,
        ];
    } catch (Exception $e) {
        $dadosCartoes[$c['IDCartao']] = ['fatura'=>null,'total'=>0,'diasAte'=>null,'pct'=>null];
    }
}

$bandeiras = ['visa'=>'Visa','mastercard'=>'Mastercard','elo'=>'Elo','amex'=>'Amex','hipercard'=>'Hipercard','outro'=>'Outro'];
$cores = ['#7c3aed'=>'Roxo','#2563eb'=>'Azul','#16a34a'=>'Verde','#dc2626'=>'Vermelho','#d97706'=>'Âmbar','#0891b2'=>'Ciano','#374151'=>'Cinza'];

require_once '../geral/header.php';
?>

<main class="container py-4 mt-2 flex-grow-1" style="padding-inline:var(--space-page-x);max-width:1100px;">

    <div class="d-flex align-items-center justify-content-between mb-4 border-bottom border-secondary-subtle pb-3 flex-wrap gap-3">
        <div class="d-flex gap-2 align-items-center">
            <a href="../dashboard.php" class="btn btn-outline-secondary btn-sm rounded-pill px-3 d-flex align-items-center">
                <i class="bi bi-arrow-left me-1"></i> Voltar
            </a>
            <?php if ($_podeCriarCC): ?>
                <button class="btn btn-sm fw-bold rounded-pill px-4"
                    style="background:var(--color-card-text);color:#fff;border:none;"
                    data-bs-toggle="modal" data-bs-target="#modalCartao" onclick="abrirModalNovo()">
                    <i class="bi bi-plus-lg me-1"></i> Novo Cartão
                </button>
            <?php else: ?>
                <span class="d-inline-flex align-items-center gap-1 px-3 py-1 rounded-pill fw-semibold"
                      style="background:rgba(124,58,237,0.15);color:#a78bfa;border:1px solid rgba(124,58,237,0.35);font-size:0.8rem;">
                    <i class="bi bi-lock-fill"></i> Limite atingido &mdash; PRO
                </span>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($erro): ?>
        <div class="alert rounded-3 mb-4" style="background:rgba(220,38,38,.15);border:1px solid rgba(220,38,38,.4);color:#fca5a5;"><?= htmlspecialchars($erro) ?></div>
    <?php endif; ?>
    <?php if ($sucesso): ?>
        <div class="alert rounded-3 mb-4" style="background:rgba(22,163,74,.15);border:1px solid rgba(22,163,74,.4);color:#86efac;"><?= htmlspecialchars($sucesso) ?></div>
    <?php endif; ?>

    <?php if (!empty($cartoes_bloqueados_ids)): ?>
        <div class="alert d-flex align-items-start gap-3 rounded-3 border-0 mb-4" style="background:var(--color-pending-bg);border:1px solid var(--color-today-bg) !important;">
            <i class="bi bi-lock-fill mt-1 flex-shrink-0" style="color:var(--accent);"></i>
            <div>
                <strong class="text-light">Cartões bloqueados</strong>
                <p class="mb-1 text-secondary" style="font-size:0.85rem;">
                    Você tem <?= count($cartoes_bloqueados_ids) ?> cartão(ões) além do limite do plano Free (<?= $_limitesCC['cartoes'] ?> no total). Eles ficam visíveis mas não podem ser usados em novas transações.
                </p>
                <a href="\planos.php?upgrade=pro" class="btn btn-sm rounded-pill fw-semibold" style="background:var(--color-pending-bg);color:var(--color-pending-text);border:1px solid var(--color-today-bg);font-size:0.8rem;">
                    <i class="bi bi-star-fill me-1"></i> Assinar PRO — até <?= limitesDoPlano('pro')['cartoes'] ?> cartões
                </a>
            </div>
        </div>
    <?php endif; ?>

    <div class="row g-4">
        <?php if (empty($cartoes)): ?>
            <div class="col-12 text-center py-4">
                <i class="bi bi-credit-card-2-front" style="font-size:2.5rem;color:var(--text-muted);"></i>
                <p class="text-secondary mt-3 mb-0">Você ainda não tem cartões de crédito cadastrados.</p>
            </div>
        <?php endif; ?>

        <?php foreach ($cartoes as $c):
            $d        = $dadosCartoes[$c['IDCartao']];
            $cor      = $c['Cor'];
            $fatura   = $d['fatura'];
            $_cTrial  = in_array($c['IDCartao'], $cartoes_trial_ids);
            $_cBlock  = in_array($c['IDCartao'], $cartoes_bloqueados_ids);
        ?>
        <div class="col-12 col-md-6 col-lg-4">
            <div class="card rounded-4 shadow-sm h-100 position-relative"
                 style="background:var(--bg-card);border:1.5px solid <?= $cor ?>55;<?= $_cBlock ? 'opacity:0.55;' : '' ?>">

                <?php if ($_cTrial): ?>
                    <span class="position-absolute top-0 end-0 m-2 d-flex align-items-center gap-1 px-2 py-1"
                          style="background:rgba(124,58,237,0.18);color:#a78bfa;border:1px solid rgba(124,58,237,0.4);border-radius:999px;font-size:0.6rem;font-weight:700;z-index:10;">
                        <i class="bi bi-star-fill"></i> PRO (teste)
                    </span>
                <?php elseif ($_cBlock): ?>
                    <span class="position-absolute top-0 end-0 m-2 d-flex align-items-center gap-1 px-2 py-1"
                          style="background:rgba(124,58,237,0.18);color:#a78bfa;border:1px solid rgba(124,58,237,0.4);border-radius:999px;font-size:0.6rem;font-weight:700;z-index:10;">
                        <i class="bi bi-lock-fill"></i> PRO
                    </span>
                <?php endif; ?>

                <!-- Visual do cartão -->
                <div class="rounded-top-4 p-4 position-relative overflow-hidden"
                    style="background:linear-gradient(135deg,<?= $cor ?>cc,<?= $cor ?>66);min-height:120px;">
                    <div style="position:absolute;top:-20px;right:-20px;width:120px;height:120px;background:rgba(255,255,255,.07);border-radius:50%;"></div>
                    <div style="position:absolute;bottom:-30px;right:20px;width:80px;height:80px;background:rgba(255,255,255,.05);border-radius:50%;"></div>
                    <div class="d-flex justify-content-between align-items-start position-relative">
                        <div>
                            <p class="fw-bold text-white mb-0" style="font-size:1.1rem;text-shadow:0 1px 3px rgba(0,0,0,.4);"><?= htmlspecialchars($c['Nome']) ?></p>
                            <p class="text-white opacity-75 mb-0 small"><?= $bandeiras[$c['Bandeira']] ?? 'Cartão' ?></p>
                        </div>
                        <i class="bi bi-credit-card-2-front text-white opacity-75" style="font-size:1.6rem;"></i>
                    </div>
                </div>

                <div class="card-body p-4">
                    <!-- Fatura atual -->
                    <div class="mb-3">
                        <p class="text-secondary small mb-1">Fatura aberta</p>
                        <p class="fw-bold text-light mb-0" style="font-size:1.5rem;">
                            R$ <?= number_format($d['total'], 2, ',', '.') ?>
                        </p>
                        <?php if ($c['Limite']): ?>
                            <?php $excedido = $d['pct'] !== null && $d['pct'] > 100; ?>
                            <div class="mt-2">
                                <div class="d-flex justify-content-between small mb-1" style="color:<?= $excedido ? '#fca5a5' : '' ?>">
                                    <span class="text-secondary">Usado</span>
                                    <span>
                                        <?= $d['pct'] ?>% de R$ <?= number_format($c['Limite'], 2, ',', '.') ?>
                                        <?php if ($excedido): ?>
                                            <i class="bi bi-exclamation-triangle-fill ms-1" style="color:#ef4444;" title="Limite excedido"></i>
                                        <?php endif; ?>
                                    </span>
                                </div>
                                <div class="progress rounded-pill" style="height:5px;background:var(--bg-hover);">
                                    <div class="progress-bar rounded-pill" style="width:<?= min(100, $d['pct']) ?>%;background:<?= $excedido ? '#ef4444' : $cor ?>;"></div>
                                </div>
                                <?php if ($excedido): ?>
                                    <p class="mb-0 mt-1" style="font-size:.7rem;color:#fca5a5;">Limite excedido</p>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Datas -->
                    <?php if ($fatura): ?>
                    <div class="d-flex gap-3 mb-4">
                        <div class="flex-grow-1 p-2 rounded-3 text-center" style="background:var(--bg-hover);border:1px solid var(--bs-border-color);">
                            <p class="text-secondary mb-0" style="font-size:0.68rem;text-transform:uppercase;letter-spacing:.06em;">Fecha em</p>
                            <p class="text-light fw-semibold mb-0 small">
                                <?php
                                if ($d['diasAte'] === 0) echo '<span style="color:var(--color-expense-text);">Hoje</span>';
                                elseif ($d['diasAte'] === 1) echo '<span style="color:var(--accent);">Amanhã</span>';
                                else echo $d['diasAte'] . ' dias';
                                ?>
                            </p>
                            <p class="text-secondary mb-0" style="font-size:0.68rem;"><?= date('d/m', strtotime($fatura['DataFechamento'])) ?></p>
                        </div>
                        <div class="flex-grow-1 p-2 rounded-3 text-center" style="background:var(--bg-hover);border:1px solid var(--bs-border-color);">
                            <p class="text-secondary mb-0" style="font-size:0.68rem;text-transform:uppercase;letter-spacing:.06em;">Vence em</p>
                            <p class="text-light fw-semibold mb-0 small"><?= date('d/m', strtotime($fatura['DataVencimento'])) ?></p>
                            <p class="text-secondary mb-0" style="font-size:0.68rem;">dia <?= $c['DiaVencimento'] ?></p>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Ações -->
                    <div class="d-flex gap-2">
                        <a href="fatura.php?cartao=<?= urlencode($c['IDCartao']) ?>"
                           class="btn btn-sm flex-grow-1 rounded-pill fw-semibold"
                           style="background:<?= $cor ?>22;color:<?= $cor ?>;border:1px solid <?= $cor ?>55;">
                            <i class="bi bi-list-ul me-1"></i> Ver Fatura
                        </a>
                        <button class="btn btn-sm btn-outline-secondary rounded-pill px-3"
                            onclick="abrirModalEditar(<?= htmlspecialchars(json_encode($c)) ?>)"
                            data-bs-toggle="modal" data-bs-target="#modalCartao" title="Editar">
                            <i class="bi bi-pencil-square"></i>
                        </button>
                        <button class="btn btn-sm btn-outline-danger rounded-pill px-3" title="Excluir"
                            data-bs-toggle="modal" data-bs-target="#modalExcluirCartao"
                            onclick="abrirModalExcluir('<?= $c['IDCartao'] ?>', '<?= htmlspecialchars($c['Nome'], ENT_QUOTES) ?>')">
                            <i class="bi bi-trash3"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>

        <?php if ($_podeCriarCC): ?>
        <div class="col-12 col-md-6 col-lg-4">
            <div class="card rounded-4 h-100 d-flex align-items-center justify-content-center"
                 style="background:transparent;border:2px dashed var(--bs-border-color);cursor:pointer;min-height:220px;"
                 data-bs-toggle="modal" data-bs-target="#modalCartao" onclick="abrirModalNovo()">
                <div class="text-center text-secondary py-4">
                    <div class="d-flex align-items-center justify-content-center mb-3"
                         style="width:56px;height:56px;border-radius:50%;background:rgba(212,175,55,0.12);border:2px solid rgba(212,175,55,0.25);margin:0 auto;">
                        <i class="bi bi-plus-lg" style="font-size:1.4rem;color:var(--accent);"></i>
                    </div>
                    <p class="mb-0 fw-semibold" style="font-size:0.9rem;">Adicionar Novo Cartão</p>
                </div>
            </div>
        </div>
        <?php else: ?>
        <div class="col-12 col-md-6 col-lg-4">
            <a href="/planos.php?upgrade=pro" class="text-decoration-none">
                <div class="card h-100 rounded-4 d-flex align-items-center justify-content-center transition-hover"
                     style="min-height:220px;background:var(--color-card-bg);border:1px dashed var(--color-card-border);">
                    <div class="card-body text-center d-flex flex-column align-items-center justify-content-center p-4">
                        <div class="rounded-circle d-flex align-items-center justify-content-center mb-3"
                             style="width:50px;height:50px;background:var(--color-card-bg);">
                            <i class="bi bi-lock-fill fs-3" style="color:var(--color-card-text);"></i>
                        </div>
                        <h6 class="fw-semibold mb-1" style="color:var(--color-card-text);">Limite do plano Free</h6>
                        <p class="text-secondary mb-0" style="font-size:0.75rem;">Assine o PRO para até <?= $_limitesCC['cartoes'] ?> cartão(ões)</p>
                    </div>
                </div>
            </a>
        </div>
        <?php endif; ?>
    </div>
</main>

<!-- MODAL: Adicionar / Editar Cartão -->
<div class="modal fade" id="modalCartao" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" style="max-width:480px;">
        <div class="modal-content border-secondary-subtle">
            <form method="POST" action="">
                <input type="hidden" name="action" value="salvar_cartao">
                <input type="hidden" name="id_cartao" id="mc_id">

                <div class="modal-header border-secondary-subtle px-4 py-3">
                    <h6 class="modal-title fw-bold text-light mb-0" id="mc_titulo">Novo Cartão</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body px-4 py-3 d-flex flex-column gap-3">
                    <div>
                        <label class="form-label text-secondary small">Nome do cartão *</label>
                        <input type="text" name="nome" id="mc_nome" class="form-control bg-transparent text-light border-secondary" placeholder="Ex: Nubank, Itaú Gold…" required>
                    </div>

                    <div class="row g-2">
                        <div class="col-6">
                            <label class="form-label text-secondary small">Bandeira</label>
                            <select name="bandeira" id="mc_bandeira" class="form-select border-secondary">
                                <?php foreach ($bandeiras as $k => $v): ?>
                                    <option value="<?= $k ?>"><?= $v ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label text-secondary small">Cor</label>
                            <select name="cor" id="mc_cor" class="form-select border-secondary">
                                <?php foreach ($cores as $hex => $nome): ?>
                                    <option value="<?= $hex ?>"><?= $nome ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="row g-2">
                        <div class="col-6">
                            <label class="form-label text-secondary small">Dia de fechamento</label>
                            <input type="number" name="dia_fechamento" id="mc_fech" class="form-control bg-transparent text-light border-secondary" min="1" max="31" value="1" required>
                            <div class="form-text text-secondary" style="font-size:0.7rem;">Entre 1 e 31</div>
                        </div>
                        <div class="col-6">
                            <label class="form-label text-secondary small">Dia de vencimento</label>
                            <input type="number" name="dia_vencimento" id="mc_venc" class="form-control bg-transparent text-light border-secondary" min="1" max="31" value="10" required>
                            <div class="form-text text-secondary" style="font-size:0.7rem;">Entre 1 e 31</div>
                        </div>
                    </div>
                    <div id="mc_aviso_datas" class="alert alert-warning py-1 px-2 d-none" style="font-size:0.75rem;">
                        <i class="bi bi-exclamation-triangle me-1"></i>
                        Dia de fechamento e vencimento não podem ser iguais.
                    </div>

                    <div>
                        <label class="form-label text-secondary small">Limite (opcional)</label>
                        <input type="number" name="limite" id="mc_limite" class="form-control bg-transparent text-light border-secondary" step="0.01" min="0" placeholder="R$ 0,00">
                    </div>

                    <div>
                        <label class="form-label text-secondary small">Carteira para pagamento da fatura</label>
                        <select name="carteira_debito" id="mc_carteira" class="form-select border-secondary">
                            <option value="">— Não definido —</option>
                            <?php foreach ($carteiras as $cart): ?>
                                <option value="<?= $cart['IDCarteira'] ?>"><?= htmlspecialchars($cart['TipoCarteira']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="text-secondary mb-0 mt-1" style="font-size:0.75rem;">Quando a fatura fechar, um lembrete de pagamento será criado nessa carteira.</p>
                    </div>
                </div>

                <div class="modal-footer border-secondary-subtle d-flex justify-content-between p-3">
                    <button type="button" class="btn btn-sm btn-link text-secondary text-decoration-none" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-sm fw-bold rounded-pill px-4"
                        style="background:var(--color-card-text);color:#fff;border:none;">
                        Salvar
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- MODAL: Excluir Cartão -->
<div class="modal fade" id="modalExcluirCartao" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" style="max-width:420px;">
        <div class="modal-content border-secondary-subtle shadow-lg rounded-4">
            <div class="modal-header border-secondary-subtle p-3">
                <h6 class="modal-title fw-bold text-light mb-0">
                    <i class="bi bi-trash3 me-2 text-danger"></i> Excluir Cartão
                </h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="excluir_cartao">
                <input type="hidden" name="id_cartao" id="excluir_cartao_id">
                <div class="modal-body p-4 text-center">
                    <p class="text-secondary mb-1">Você está prestes a excluir o cartão</p>
                    <p class="text-light fw-bold fs-5 mb-3" id="excluir_cartao_nome">—</p>
                    <div class="rounded-3 p-3 text-start" style="background:var(--color-expense-bg);border:1px solid var(--color-expense-border);">
                        <p class="text-secondary small mb-0">
                            <i class="bi bi-exclamation-triangle text-warning me-1"></i>
                            Todos os <strong class="text-light">lançamentos</strong> e <strong class="text-light">faturas</strong> deste cartão serão removidos permanentemente. Esta ação não pode ser desfeita.
                        </p>
                    </div>
                </div>
                <div class="modal-footer border-secondary-subtle d-flex justify-content-between p-3">
                    <button type="button" class="btn btn-sm btn-link text-secondary text-decoration-none" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-sm btn-danger fw-bold px-4 rounded-pill">
                        <i class="bi bi-trash3 me-1"></i> Confirmar Exclusão
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function abrirModalExcluir(id, nome) {
    document.getElementById('excluir_cartao_id').value  = id;
    document.getElementById('excluir_cartao_nome').textContent = nome;
}

function validarDiasCartao() {
    const fech  = parseInt(document.getElementById('mc_fech').value, 10);
    const venc  = parseInt(document.getElementById('mc_venc').value, 10);
    const aviso = document.getElementById('mc_aviso_datas');
    const btn   = document.querySelector('#modalCartao .btn-primary');
    if (fech === venc) {
        aviso.classList.remove('d-none');
        btn.disabled = true;
    } else {
        aviso.classList.add('d-none');
        btn.disabled = false;
    }
}
document.addEventListener('DOMContentLoaded', function() {
    document.getElementById('mc_fech').addEventListener('input', validarDiasCartao);
    document.getElementById('mc_venc').addEventListener('input', validarDiasCartao);
});

function abrirModalNovo() {
    document.getElementById('mc_id').value      = '';
    document.getElementById('mc_nome').value    = '';
    document.getElementById('mc_bandeira').value = 'outro';
    document.getElementById('mc_cor').value     = '#7c3aed';
    document.getElementById('mc_fech').value    = '1';
    document.getElementById('mc_venc').value    = '10';
    document.getElementById('mc_limite').value  = '';
    document.getElementById('mc_carteira').value = '';
    document.getElementById('mc_titulo').textContent = 'Novo Cartão';
    document.getElementById('mc_aviso_datas').classList.add('d-none');
}
function abrirModalEditar(c) {
    document.getElementById('mc_id').value       = c.IDCartao;
    document.getElementById('mc_nome').value     = c.Nome;
    document.getElementById('mc_bandeira').value = c.Bandeira;
    document.getElementById('mc_cor').value      = c.Cor;
    document.getElementById('mc_fech').value     = c.DiaFechamento;
    document.getElementById('mc_venc').value     = c.DiaVencimento;
    document.getElementById('mc_limite').value   = c.Limite ?? '';
    document.getElementById('mc_carteira').value = c.FKCarteiraDebito ?? '';
    document.getElementById('mc_titulo').textContent = 'Editar Cartão';
}
</script>

<style>
#modalCartao .form-select option {
    background-color: var(--bg-card);
    color: var(--text-main);
}
#modalCartao .form-select option:hover,
#modalCartao .form-select option:checked {
    background-color: var(--bg-hover);
    color: var(--text-main);
}
</style>
<?php require_once '../geral/footer.php'; ?>
