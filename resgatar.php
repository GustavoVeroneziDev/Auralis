<?php
session_start();
if (!isset($_SESSION['usuario_id'])) {
    header("Location: usuario/login.php?voltar=" . urlencode('/resgatar.php'));
    exit;
}

require_once 'config/conexao.php';
require_once 'config/funcoes.php';

$uid       = $_SESSION['usuario_id'];
$erro      = null;
$sucesso   = null;
$pageTitle = 'Resgatar Código — Auralis';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $codigo = strtoupper(trim($_POST['codigo'] ?? ''));

    if (!$codigo) {
        $erro = 'Digite um código de ativação.';
    } else {
        try {
            // Busca o código (case-insensitive)
            $stmt = $pdo->prepare("
                SELECT * FROM codigos_ativacao
                WHERE UPPER(Codigo) = :codigo AND Ativo = 1
            ");
            $stmt->execute([':codigo' => $codigo]);
            $cod = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$cod) {
                $erro = 'Código inválido ou inativo.';
            } elseif ($cod['DataExpiracao'] && $cod['DataExpiracao'] < date('Y-m-d')) {
                $erro = 'Este código expirou.';
            } elseif ($cod['MaxUsos'] !== null && $cod['UsoAtual'] >= $cod['MaxUsos']) {
                $erro = 'Este código já atingiu o limite de usos.';
            } else {
                // Verifica se este usuário já usou
                $stmtU = $pdo->prepare("SELECT 1 FROM codigos_ativacao_usos WHERE FKCodigo = :cid AND FKUsuario = :uid");
                $stmtU->execute([':cid' => $cod['IDCodigo'], ':uid' => $uid]);
                if ($stmtU->fetchColumn()) {
                    $erro = 'Você já utilizou este código.';
                } else {
                    // Calcula nova DataExpiracao (acumula sobre a data atual se já tiver plano ativo)
                    $stmtExp = $pdo->prepare("
                        SELECT DataExpiracao, Plano FROM Assinatura
                        WHERE FKUsuario = :uid AND Status = 'ativa'
                        ORDER BY DataExpiracao DESC LIMIT 1
                    ");
                    $stmtExp->execute([':uid' => $uid]);
                    $assinaturaAtual = $stmtExp->fetch(PDO::FETCH_ASSOC);

                    $base = new DateTime('today');
                    if ($assinaturaAtual && $assinaturaAtual['DataExpiracao'] > date('Y-m-d')) {
                        $base = new DateTime($assinaturaAtual['DataExpiracao']);
                    }
                    $base->modify('+' . $cod['DuracaoDias'] . ' days');
                    $novaExpiracao = $base->format('Y-m-d');

                    // Plano final: nunca faz downgrade
                    $hierarquia   = ['free' => 0, 'pro' => 1, 'vip' => 2];
                    $planoAtual   = $_SESSION['plano'] ?? 'free';
                    $planoRecomp  = $cod['PlanoRecompensa'];
                    $planoFinal   = ($hierarquia[$planoRecomp] ?? 0) > ($hierarquia[$planoAtual] ?? 0)
                                    ? $planoRecomp : $planoAtual;

                    $pdo->beginTransaction();

                    // Cancela assinatura ativa atual
                    $pdo->prepare("UPDATE Assinatura SET Status = 'cancelada' WHERE FKUsuario = :uid AND Status = 'ativa'")
                        ->execute([':uid' => $uid]);

                    // Cria nova assinatura
                    $pdo->prepare("
                        INSERT INTO Assinatura
                            (IDAssinatura, FKUsuario, Plano, Status, Ciclo, ValorPago,
                             DataInicio, DataExpiracao, IDAssinaturaGW, EmailGateway, FontePagamento)
                        VALUES
                            (:id, :uid, :plano, 'ativa', 'codigo', 0,
                             :inicio, :exp, NULL, NULL, 'codigo_ativacao')
                    ")->execute([
                        ':id'     => gerarUuid(),
                        ':uid'    => $uid,
                        ':plano'  => $planoFinal,
                        ':inicio' => date('Y-m-d'),
                        ':exp'    => $novaExpiracao,
                    ]);

                    // Atualiza plano do usuário
                    $pdo->prepare("UPDATE Usuario SET Plano = :plano WHERE IDUsuario = :uid")
                        ->execute([':plano' => $planoFinal, ':uid' => $uid]);

                    // Registra uso
                    $pdo->prepare("
                        INSERT INTO codigos_ativacao_usos (IDUso, FKCodigo, FKUsuario)
                        VALUES (:id, :cid, :uid)
                    ")->execute([':id' => gerarUuid(), ':cid' => $cod['IDCodigo'], ':uid' => $uid]);

                    // Incrementa contador
                    $pdo->prepare("UPDATE codigos_ativacao SET UsoAtual = UsoAtual + 1 WHERE IDCodigo = :id")
                        ->execute([':id' => $cod['IDCodigo']]);

                    $pdo->commit();

                    // Atualiza sessão
                    $_SESSION['plano'] = $planoFinal;
                    unset($_SESSION['expiracao_verificada']);

                    $planoLabel = strtoupper($planoFinal);
                    $sucesso = "🎉 Código ativado! Você ganhou {$cod['DuracaoDias']} dias de {$planoLabel} — válido até " . date('d/m/Y', strtotime($novaExpiracao)) . '.';
                }
            }
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $erro = 'Erro ao processar o código. Tente novamente.';
        }
    }
}

require_once 'geral/header.php';
?>

<main class="flex-grow-1 d-flex align-items-center justify-content-center py-5" style="min-height:70vh;">
    <div style="width:100%;max-width:440px;padding-inline:1rem;">

        <div class="text-center mb-4">
            <div class="mx-auto mb-3 d-flex align-items-center justify-content-center rounded-circle"
                 style="width:64px;height:64px;background:rgba(212,175,55,.12);border:1.5px solid rgba(212,175,55,.3);">
                <i class="bi bi-gift-fill" style="font-size:1.6rem;color:#d4af37;"></i>
            </div>
            <h3 class="fw-bold text-light mb-1">Resgatar código</h3>
            <p class="text-secondary small mb-0">Digite o código de ativação para liberar seu benefício</p>
        </div>

        <?php if ($sucesso): ?>
            <div class="rounded-4 p-4 mb-4 text-center" style="background:rgba(22,163,74,.12);border:1.5px solid rgba(22,163,74,.3);">
                <i class="bi bi-check-circle-fill mb-2" style="font-size:2rem;color:#4ade80;display:block;"></i>
                <p class="text-light fw-semibold mb-0"><?= htmlspecialchars($sucesso) ?></p>
            </div>
            <a href="/dashboard.php" class="btn w-100 rounded-pill fw-bold py-2"
               style="background:linear-gradient(135deg,#d4af37,#AA8C2C);color:#000;border:none;">
                Ir para o Dashboard
            </a>
        <?php else: ?>

            <?php if ($erro): ?>
                <div class="rounded-3 px-4 py-3 mb-4 d-flex align-items-center gap-2"
                     style="background:rgba(239,68,68,.12);border:1px solid rgba(239,68,68,.3);color:#fca5a5;">
                    <i class="bi bi-exclamation-circle-fill flex-shrink-0"></i>
                    <span><?= htmlspecialchars($erro) ?></span>
                </div>
            <?php endif; ?>

            <form method="POST" class="card rounded-4 border-secondary-subtle p-4" style="background:#1a1d21;">
                <div class="mb-4">
                    <label class="form-label text-secondary small fw-semibold text-uppercase" style="letter-spacing:.06em;">
                        Código de ativação
                    </label>
                    <input type="text" name="codigo"
                           class="form-control form-control-lg text-center fw-bold bg-transparent text-light border-secondary rounded-3"
                           style="letter-spacing:.12em;font-size:1.1rem;"
                           placeholder="EX: COMEÇANDOBEM"
                           value="<?= htmlspecialchars(strtoupper($_POST['codigo'] ?? '')) ?>"
                           autocomplete="off" autofocus maxlength="50">
                </div>
                <button type="submit" class="btn w-100 rounded-pill fw-bold py-2"
                        style="background:linear-gradient(135deg,#d4af37,#AA8C2C);color:#000;border:none;font-size:1rem;">
                    <i class="bi bi-gift me-1"></i> Resgatar
                </button>
            </form>

            <p class="text-center text-secondary mt-3" style="font-size:0.78rem;">
                Códigos são case-insensitive e de uso único por conta.
            </p>
        <?php endif; ?>
    </div>
</main>

<?php require_once 'geral/footer.php'; ?>
