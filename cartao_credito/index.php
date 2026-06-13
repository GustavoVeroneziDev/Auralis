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
        $diaFech     = max(1, min(28, (int)($_POST['dia_fechamento'] ?? 1)));
        $diaVenc     = max(1, min(28, (int)($_POST['dia_vencimento'] ?? 10)));
        $carteiraDb  = trim($_POST['carteira_debito'] ?? '') ?: null;

        if (empty($nome)) {
            $erro = 'O nome do cartão é obrigatório.';
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
                    // Criação
                    $newId = gerarUuid();
                    $pdo->prepare(
                        "INSERT INTO CartaoCredito (IDCartao,FKUsuario,Nome,Bandeira,Cor,Limite,DiaFechamento,DiaVencimento,FKCarteiraDebito)
                         VALUES (:id,:uid,:n,:b,:c,:l,:df,:dv,:cd)"
                    )->execute([':id'=>$newId,':uid'=>$uid,':n'=>$nome,':b'=>$bandeira,':c'=>$cor,
                                ':l'=>$limite,':df'=>$diaFech,':dv'=>$diaVenc,':cd'=>$carteiraDb]);
                    $sucesso = 'Cartão adicionado com sucesso.';
                }
            } catch (PDOException $e) {
                $erro = 'Erro ao salvar cartão: ' . $e->getMessage();
            }
        }
    }

    if ($action === 'desativar_cartao') {
        $id = trim($_POST['id_cartao'] ?? '');
        if ($id) {
            $pdo->prepare("UPDATE CartaoCredito SET Ativo=0 WHERE IDCartao=:id AND FKUsuario=:uid")
                ->execute([':id'=>$id,':uid'=>$uid]);
            $sucesso = 'Cartão removido.';
        }
    }
}

cartao_verificarFechamentos($pdo, $uid);
$cartoes = cartao_listarAtivos($pdo, $uid);

// Pré-carrega dados de fatura aberta e total para cada cartão
$dadosCartoes = [];
foreach ($cartoes as $c) {
    try {
        $fatura  = cartao_obterFaturaAberta($pdo, $c['IDCartao'], $uid, $c);
        $total   = cartao_totalFaturaAberta($pdo, $c['IDCartao'], $uid);
        $diasAte = (new DateTime())->diff(new DateTime($fatura['DataFechamento']))->days;
        $jaFechou = (new DateTime('today')) > (new DateTime($fatura['DataFechamento']));
        $dadosCartoes[$c['IDCartao']] = [
            'fatura'   => $fatura,
            'total'    => $total,
            'diasAte'  => $jaFechou ? 0 : $diasAte,
            'pct'      => $c['Limite'] ? min(100, round(($total / $c['Limite']) * 100)) : null,
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
        <div>
            <h2 class="fw-bold text-light mb-0">Cartões de Crédito</h2>
            <p class="text-secondary mb-0 small">Gerencie seus cartões e acompanhe as faturas</p>
        </div>
        <button class="btn fw-bold rounded-pill px-4"
            style="background:linear-gradient(135deg,#7c3aed,#a78bfa);color:#fff;border:none;"
            data-bs-toggle="modal" data-bs-target="#modalCartao" onclick="abrirModalNovo()">
            <i class="bi bi-plus-lg me-1"></i> Novo Cartão
        </button>
    </div>

    <?php if ($erro): ?>
        <div class="alert rounded-3 mb-4" style="background:rgba(220,38,38,.15);border:1px solid rgba(220,38,38,.4);color:#fca5a5;"><?= htmlspecialchars($erro) ?></div>
    <?php endif; ?>
    <?php if ($sucesso): ?>
        <div class="alert rounded-3 mb-4" style="background:rgba(22,163,74,.15);border:1px solid rgba(22,163,74,.4);color:#86efac;"><?= htmlspecialchars($sucesso) ?></div>
    <?php endif; ?>

    <?php if (empty($cartoes)): ?>
        <div class="text-center py-5">
            <i class="bi bi-credit-card-2-front" style="font-size:3rem;color:#374151;"></i>
            <p class="text-secondary mt-3">Você ainda não tem cartões de crédito cadastrados.</p>
            <button class="btn btn-outline-secondary rounded-pill px-4"
                data-bs-toggle="modal" data-bs-target="#modalCartao" onclick="abrirModalNovo()">
                Adicionar primeiro cartão
            </button>
        </div>
    <?php else: ?>
        <div class="row g-4">
            <?php foreach ($cartoes as $c):
                $d   = $dadosCartoes[$c['IDCartao']];
                $cor = $c['Cor'];
                $fatura = $d['fatura'];
            ?>
            <div class="col-12 col-md-6 col-lg-4">
                <div class="card rounded-4 shadow-sm h-100" style="background:var(--bg-card);border:1.5px solid <?= $cor ?>55;">

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
                                <div class="mt-2">
                                    <div class="d-flex justify-content-between small text-secondary mb-1">
                                        <span>Usado</span>
                                        <span><?= $d['pct'] ?>% de R$ <?= number_format($c['Limite'], 2, ',', '.') ?></span>
                                    </div>
                                    <div class="progress rounded-pill" style="height:5px;background:rgba(255,255,255,.08);">
                                        <div class="progress-bar rounded-pill" style="width:<?= $d['pct'] ?>%;background:<?= $cor ?>;"></div>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Datas -->
                        <?php if ($fatura): ?>
                        <div class="d-flex gap-3 mb-4">
                            <div class="flex-grow-1 p-2 rounded-3 text-center" style="background:rgba(255,255,255,.04);border:1px solid #333;">
                                <p class="text-secondary mb-0" style="font-size:0.68rem;text-transform:uppercase;letter-spacing:.06em;">Fecha em</p>
                                <p class="text-light fw-semibold mb-0 small">
                                    <?php
                                    if ($d['diasAte'] === 0) echo '<span style="color:#f87171;">Hoje</span>';
                                    elseif ($d['diasAte'] === 1) echo '<span style="color:#fbbf24;">Amanhã</span>';
                                    else echo $d['diasAte'] . ' dias';
                                    ?>
                                </p>
                                <p class="text-secondary mb-0" style="font-size:0.68rem;"><?= date('d/m', strtotime($fatura['DataFechamento'])) ?></p>
                            </div>
                            <div class="flex-grow-1 p-2 rounded-3 text-center" style="background:rgba(255,255,255,.04);border:1px solid #333;">
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
                            <form method="POST" class="m-0" onsubmit="return confirm('Remover este cartão?')">
                                <input type="hidden" name="action" value="desativar_cartao">
                                <input type="hidden" name="id_cartao" value="<?= $c['IDCartao'] ?>">
                                <button class="btn btn-sm btn-outline-danger rounded-pill px-3" title="Remover">
                                    <i class="bi bi-trash3"></i>
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</main>

<!-- MODAL: Adicionar / Editar Cartão -->
<div class="modal fade" id="modalCartao" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" style="max-width:480px;">
        <div class="modal-content border-secondary-subtle" style="background:#1a1d21;">
            <form method="POST" action="">
                <input type="hidden" name="action" value="salvar_cartao">
                <input type="hidden" name="id_cartao" id="mc_id">

                <div class="modal-header border-secondary-subtle px-4 py-3">
                    <h6 class="modal-title fw-bold text-light mb-0" id="mc_titulo">Novo Cartão</h6>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body px-4 py-3 d-flex flex-column gap-3">
                    <div>
                        <label class="form-label text-secondary small">Nome do cartão *</label>
                        <input type="text" name="nome" id="mc_nome" class="form-control bg-transparent text-light border-secondary" placeholder="Ex: Nubank, Itaú Gold…" required>
                    </div>

                    <div class="row g-2">
                        <div class="col-6">
                            <label class="form-label text-secondary small">Bandeira</label>
                            <select name="bandeira" id="mc_bandeira" class="form-select bg-transparent text-light border-secondary">
                                <?php foreach ($bandeiras as $k => $v): ?>
                                    <option value="<?= $k ?>"><?= $v ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label text-secondary small">Cor</label>
                            <select name="cor" id="mc_cor" class="form-select bg-transparent text-light border-secondary">
                                <?php foreach ($cores as $hex => $nome): ?>
                                    <option value="<?= $hex ?>"><?= $nome ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="row g-2">
                        <div class="col-6">
                            <label class="form-label text-secondary small">Dia de fechamento</label>
                            <input type="number" name="dia_fechamento" id="mc_fech" class="form-control bg-transparent text-light border-secondary" min="1" max="28" value="1">
                        </div>
                        <div class="col-6">
                            <label class="form-label text-secondary small">Dia de vencimento</label>
                            <input type="number" name="dia_vencimento" id="mc_venc" class="form-control bg-transparent text-light border-secondary" min="1" max="28" value="10">
                        </div>
                    </div>

                    <div>
                        <label class="form-label text-secondary small">Limite (opcional)</label>
                        <input type="number" name="limite" id="mc_limite" class="form-control bg-transparent text-light border-secondary" step="0.01" min="0" placeholder="R$ 0,00">
                    </div>

                    <div>
                        <label class="form-label text-secondary small">Carteira para pagamento da fatura</label>
                        <select name="carteira_debito" id="mc_carteira" class="form-select bg-transparent text-light border-secondary">
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
                        style="background:linear-gradient(135deg,#7c3aed,#a78bfa);color:#fff;border:none;">
                        Salvar
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
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

<?php require_once '../geral/footer.php'; ?>
