<?php
// ==============================================================================
// RANKING.PHP — Placar geral de usuários
// ==============================================================================
session_start();
if (!isset($_SESSION['usuario_id'])) {
    header("Location: /usuario/login.php");
    exit;
}
require_once 'config/conexao.php';
require_once 'config/funcoes.php';

verificarExpiracao($pdo);
garantirColunaFotoPerfilReal($pdo);
garantirTabelaAmizade($pdo);
$uid = $_SESSION['usuario_id'];

// ── Helpers ───────────────────────────────────────────────────────────────────
// _rankAvatar() foi extraído pra config/funcoes.php como renderAvatarUsuario() —
// reaproveitado também no modal de perfil público.
function _rankAvatar(array $row, int $size = 40): string
{
    return renderAvatarUsuario($row, $size);
}

function _primeiroNome(string $nome): string
{
    return explode(' ', trim($nome))[0];
}

function _formatarDias(int $dias): string
{
    if ($dias < 30)  return "{$dias} dia" . ($dias !== 1 ? 's' : '');
    if ($dias < 365) {
        $m = (int)round($dias / 30);
        return "{$m} mês" . ($m > 1 ? 'es' : '');
    }
    $anos = (int)floor($dias / 365);
    $meses = (int)round(($dias % 365) / 30);
    $s = "{$anos} ano" . ($anos > 1 ? 's' : '');
    if ($meses > 0) $s .= " e {$meses} mês" . ($meses > 1 ? 'es' : '');
    return $s;
}

// ── Posição do usuário fora do top 10 ────────────────────────────────────────
// Retorna ['pos' => N, 'total' => X] onde pos >= 11
function _minhaPosicao(PDO $pdo, string $sqlMeuTotal, string $sqlQuantosAcima, string $uid): array
{
    try {
        $st = $pdo->prepare($sqlMeuTotal);
        $st->execute([':uid' => $uid]);
        $meuTotal = (int)$st->fetchColumn();

        $sa = $pdo->prepare($sqlQuantosAcima);
        $sa->execute([':total' => $meuTotal, ':uid' => $uid]);
        $acima = (int)$sa->fetchColumn();

        return ['pos' => $acima + 1, 'total' => $meuTotal];
    } catch (Throwable $e) {
        return ['pos' => 0, 'total' => 0];
    }
}

// ── Queries de ranking ────────────────────────────────────────────────────────
function _rankQuery(PDO $pdo, string $sql): array
{
    try {
        $st = $pdo->prepare($sql);
        $st->execute();
        return $st->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

$r_registros = _rankQuery($pdo, "
    SELECT u.IDUsuario, u.Nome, u.FotoPerfil, u.FotoPerfilReal, COUNT(*) AS total
    FROM Registro r
    JOIN Usuario u ON u.IDUsuario = r.FKUsuario
    WHERE r.TipoRegistro IN ('receita','despesa') AND u.StatusConta = 'ativo'
    GROUP BY u.IDUsuario, u.Nome, u.FotoPerfil, u.FotoPerfilReal
    ORDER BY total DESC, u.MomentoCriacao ASC
    LIMIT 10
");

$r_conquistas = _rankQuery($pdo, "
    SELECT u.IDUsuario, u.Nome, u.FotoPerfil, u.FotoPerfilReal, COUNT(*) AS total
    FROM usuario_conquista uc
    JOIN Usuario u ON u.IDUsuario = uc.FKUsuario
    WHERE u.StatusConta = 'ativo'
    GROUP BY u.IDUsuario, u.Nome, u.FotoPerfil, u.FotoPerfilReal
    ORDER BY total DESC, u.MomentoCriacao ASC
    LIMIT 10
");

$r_comprovantes = _rankQuery($pdo, "
    SELECT u.IDUsuario, u.Nome, u.FotoPerfil, u.FotoPerfilReal, COUNT(*) AS total
    FROM Comprovante c
    JOIN Usuario u ON u.IDUsuario = c.FKUsuario
    WHERE u.StatusConta = 'ativo'
    GROUP BY u.IDUsuario, u.Nome, u.FotoPerfil, u.FotoPerfilReal
    ORDER BY total DESC, u.MomentoCriacao ASC
    LIMIT 10
");

$r_veteranos = _rankQuery($pdo, "
    SELECT IDUsuario, Nome, FotoPerfil, FotoPerfilReal,
           DATEDIFF(NOW(), MomentoCriacao) AS total
    FROM Usuario
    WHERE StatusConta = 'ativo'
    ORDER BY MomentoCriacao ASC
    LIMIT 10
");

$r_categorias = _rankQuery($pdo, "
    SELECT u.IDUsuario, u.Nome, u.FotoPerfil, u.FotoPerfilReal, COUNT(*) AS total
    FROM Categoria cat
    JOIN Usuario u ON u.IDUsuario = cat.FKUsuario
    WHERE u.StatusConta = 'ativo'
    GROUP BY u.IDUsuario, u.Nome, u.FotoPerfil, u.FotoPerfilReal
    ORDER BY total DESC, u.MomentoCriacao ASC
    LIMIT 10
");

// ── Verifica se usuário está fora do top 10 e busca sua posição ───────────────
function _usuarioNaLista(array $lista, string $uid): bool
{
    foreach ($lista as $r) if ((string)$r['IDUsuario'] === $uid) return true;
    return false;
}

$pos_registros   = !_usuarioNaLista($r_registros,   $uid) ? _minhaPosicao(
    $pdo,
    "SELECT COUNT(*) FROM Registro WHERE FKUsuario = :uid AND TipoRegistro IN ('receita','despesa')",
    "SELECT COUNT(*) FROM (
        SELECT r.FKUsuario FROM Registro r JOIN Usuario u ON u.IDUsuario=r.FKUsuario
        WHERE r.TipoRegistro IN ('receita','despesa') AND u.StatusConta='ativo' AND r.FKUsuario != :uid
        GROUP BY r.FKUsuario HAVING COUNT(*) > :total
     ) t",
    $uid
) : null;

$pos_conquistas  = !_usuarioNaLista($r_conquistas,  $uid) ? _minhaPosicao(
    $pdo,
    "SELECT COUNT(*) FROM usuario_conquista WHERE FKUsuario = :uid",
    "SELECT COUNT(*) FROM (
        SELECT uc.FKUsuario FROM usuario_conquista uc JOIN Usuario u ON u.IDUsuario=uc.FKUsuario
        WHERE u.StatusConta='ativo' AND uc.FKUsuario != :uid
        GROUP BY uc.FKUsuario HAVING COUNT(*) > :total
     ) t",
    $uid
) : null;

$pos_comprovantes = !_usuarioNaLista($r_comprovantes, $uid) ? _minhaPosicao(
    $pdo,
    "SELECT COUNT(*) FROM Comprovante WHERE FKUsuario = :uid",
    "SELECT COUNT(*) FROM (
        SELECT c.FKUsuario FROM Comprovante c JOIN Usuario u ON u.IDUsuario=c.FKUsuario
        WHERE u.StatusConta='ativo' AND c.FKUsuario != :uid
        GROUP BY c.FKUsuario HAVING COUNT(*) > :total
     ) t",
    $uid
) : null;

$pos_veteranos = !_usuarioNaLista($r_veteranos, $uid) ? _minhaPosicao(
    $pdo,
    "SELECT DATEDIFF(NOW(), MomentoCriacao) FROM Usuario WHERE IDUsuario = :uid",
    "SELECT COUNT(*) FROM Usuario
     WHERE StatusConta='ativo' AND IDUsuario != :uid
       AND DATEDIFF(NOW(), MomentoCriacao) > :total",
    $uid
) : null;

$pos_categorias = !_usuarioNaLista($r_categorias, $uid) ? _minhaPosicao(
    $pdo,
    "SELECT COUNT(*) FROM Categoria WHERE FKUsuario = :uid",
    "SELECT COUNT(*) FROM (
        SELECT cat.FKUsuario FROM Categoria cat JOIN Usuario u ON u.IDUsuario=cat.FKUsuario
        WHERE u.StatusConta='ativo' AND cat.FKUsuario != :uid
        GROUP BY cat.FKUsuario HAVING COUNT(*) > :total
     ) t",
    $uid
) : null;

// ── "Amigo do Meu Amigo" — sugestões por amizade em comum ────────────────────
// Não é uma disputa/ranking de verdade (não faz sentido "sua posição" aqui), é uma
// lista de descoberta: gente que tem pelo menos 1 amigo em comum com você e que
// você ainda não é amigo nem já tem convite pendente. Cada linha da junção abaixo
// representa exatamente 1 amigo em comum, então COUNT(*) já é a contagem certa —
// nem precisa de HAVING, quem não tem nenhum amigo em comum simplesmente não
// aparece no resultado.
$r_amigosComum = [];
try {
    $stmtAC = $pdo->prepare("
        SELECT candidato.IDUsuario, candidato.Nome, candidato.FotoPerfil, candidato.FotoPerfilReal,
               COUNT(*) AS total
        FROM (
            SELECT CASE WHEN FKUsuarioSolicitante = :uid1 THEN FKUsuarioDestinatario ELSE FKUsuarioSolicitante END AS FKAmigo
            FROM Amizade
            WHERE Status = 'aceito' AND (FKUsuarioSolicitante = :uid2 OR FKUsuarioDestinatario = :uid3)
        ) meus_amigos
        JOIN Amizade a2
             ON a2.Status = 'aceito'
            AND (a2.FKUsuarioSolicitante = meus_amigos.FKAmigo OR a2.FKUsuarioDestinatario = meus_amigos.FKAmigo)
        JOIN Usuario candidato
             ON candidato.IDUsuario = CASE WHEN a2.FKUsuarioSolicitante = meus_amigos.FKAmigo
                                            THEN a2.FKUsuarioDestinatario ELSE a2.FKUsuarioSolicitante END
        WHERE candidato.IDUsuario != :uid4
          AND candidato.StatusConta = 'ativo'
          AND candidato.IDUsuario NOT IN (
              SELECT CASE WHEN FKUsuarioSolicitante = :uid5 THEN FKUsuarioDestinatario ELSE FKUsuarioSolicitante END
              FROM Amizade
              WHERE Status IN ('aceito','pendente') AND (FKUsuarioSolicitante = :uid6 OR FKUsuarioDestinatario = :uid7)
          )
        GROUP BY candidato.IDUsuario, candidato.Nome, candidato.FotoPerfil, candidato.FotoPerfilReal
        ORDER BY total DESC, candidato.Nome ASC
        LIMIT 20
    ");
    $stmtAC->execute([
        ':uid1' => $uid, ':uid2' => $uid, ':uid3' => $uid, ':uid4' => $uid,
        ':uid5' => $uid, ':uid6' => $uid, ':uid7' => $uid,
    ]);
    $r_amigosComum = $stmtAC->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
}

require_once 'geral/header.php';
?>

<main class="container-fluid py-4 mt-2 flex-grow-1" style="max-width:900px;padding-inline:var(--space-page-x);min-height:100vh;">

    <!-- ── Cabeçalho ───────────────────────────────────────────────────────── -->
    <div class="mb-4">
        <div class="d-flex align-items-center gap-3 mb-1">
            <div class="rounded-3 d-flex align-items-center justify-content-center flex-shrink-0"
                style="width:46px;height:46px;background:linear-gradient(135deg,#f59e0b22,#f59e0b44);border:1px solid #f59e0b44;">
                <i class="bi bi-trophy-fill" style="color:#f59e0b;font-size:1.3rem;"></i>
            </div>
            <div>
                <h1 class="fw-bold mb-0" style="font-size:1.6rem;color:var(--text-main);">Ranking</h1>
                <p class="text-secondary mb-0" style="font-size:.85rem;">Top 10 usuários em diferentes categorias</p>
            </div>
        </div>
    </div>

    <!-- ── Tabs ────────────────────────────────────────────────────────────── -->
    <ul class="nav nav-pills gap-2 mb-4 flex-nowrap overflow-auto pb-1" id="rankingTabs" role="tablist"
        style="scrollbar-width:none;">
        <?php
        $tabs = [
            ['id' => 'registros',   'icon' => 'bi-lightning-charge-fill', 'cor' => '#6366f1', 'label' => 'Lançamentos'],
            ['id' => 'conquistas',  'icon' => 'bi-award-fill',            'cor' => '#f59e0b', 'label' => 'Conquistas'],
            ['id' => 'comprovantes', 'icon' => 'bi-receipt',               'cor' => '#10b981', 'label' => 'Comprovantes'],
            ['id' => 'veteranos',   'icon' => 'bi-calendar-heart-fill',   'cor' => '#ec4899', 'label' => 'Veteranos'],
            ['id' => 'categorias',  'icon' => 'bi-tags-fill',             'cor' => '#60a5fa', 'label' => 'Categorias'],
            ['id' => 'amigos-comum', 'icon' => 'bi-people-fill',          'cor' => '#a78bfa', 'label' => 'Amigo do Meu Amigo'],
        ];
        foreach ($tabs as $i => $t): ?>
            <li class="nav-item flex-shrink-0" role="presentation">
                <button class="nav-link d-flex align-items-center gap-2 fw-semibold px-3 py-2 <?= $i === 0 ? 'active' : '' ?>"
                    id="tab-<?= $t['id'] ?>"
                    data-bs-toggle="pill"
                    data-bs-target="#pane-<?= $t['id'] ?>"
                    type="button" role="tab"
                    style="font-size:.85rem;border-radius:999px;border:1px solid var(--card-border-color);
                           background:var(--bg-card);color:var(--text-main);white-space:nowrap;">
                    <i class="<?= $t['icon'] ?>" style="color:<?= $t['cor'] ?>;"></i>
                    <?= $t['label'] ?>
                </button>
            </li>
        <?php endforeach; ?>
    </ul>

    <!-- ── Conteúdo ────────────────────────────────────────────────────────── -->
    <div class="tab-content">
        <?php
        $panes = [
            [
                'id' => 'registros',
                'dados' => $r_registros,
                'posMe' => $pos_registros,
                'titulo' => 'Mais Lançamentos',
                'subtitulo' => 'Quem mais registrou receitas e despesas',
                'icon' => 'bi-lightning-charge-fill',
                'cor' => '#6366f1',
                'unidade' => 'lançamento',
                'unidades' => 'lançamentos'
            ],

            [
                'id' => 'conquistas',
                'dados' => $r_conquistas,
                'posMe' => $pos_conquistas,
                'titulo' => 'Colecionadores',
                'subtitulo' => 'Quem desbloqueou mais conquistas',
                'icon' => 'bi-award-fill',
                'cor' => '#f59e0b',
                'unidade' => 'conquista',
                'unidades' => 'conquistas'
            ],

            [
                'id' => 'comprovantes',
                'dados' => $r_comprovantes,
                'posMe' => $pos_comprovantes,
                'titulo' => 'Caça-Recibos',
                'subtitulo' => 'Quem mais enviou comprovantes',
                'icon' => 'bi-receipt',
                'cor' => '#10b981',
                'unidade' => 'comprovante',
                'unidades' => 'comprovantes'
            ],

            [
                'id' => 'veteranos',
                'dados' => $r_veteranos,
                'posMe' => $pos_veteranos,
                'titulo' => 'Veteranos',
                'subtitulo' => 'Os membros mais antigos da plataforma',
                'icon' => 'bi-calendar-heart-fill',
                'cor' => '#ec4899',
                'unidade' => 'dia',
                'unidades' => 'dias',
                'formato' => 'dias'
            ],

            [
                'id' => 'categorias',
                'dados' => $r_categorias,
                'posMe' => $pos_categorias,
                'titulo' => 'Mais Organizados',
                'subtitulo' => 'Quem criou mais categorias personalizadas',
                'icon' => 'bi-tags-fill',
                'cor' => '#60a5fa',
                'unidade' => 'categoria',
                'unidades' => 'categorias'
            ],
        ];

        foreach ($panes as $pi => $pane):
            $dados   = $pane['dados'];
            $isFirst = $pi === 0;
        ?>
            <div class="tab-pane fade <?= $isFirst ? 'show active' : '' ?>" id="pane-<?= $pane['id'] ?>" role="tabpanel">

                <!-- Sub-cabeçalho do pane -->
                <div class="d-flex align-items-center gap-2 mb-4">
                    <i class="<?= $pane['icon'] ?> fs-5" style="color:<?= $pane['cor'] ?>;"></i>
                    <div>
                        <h2 class="fw-bold mb-0" style="font-size:1.15rem;color:var(--text-main);"><?= $pane['titulo'] ?></h2>
                        <p class="text-secondary mb-0" style="font-size:.78rem;"><?= $pane['subtitulo'] ?></p>
                    </div>
                </div>

                <?php if (empty($dados)): ?>
                    <div class="text-center py-5 text-secondary">
                        <i class="<?= $pane['icon'] ?> fs-1 opacity-25 d-block mb-3" style="color:<?= $pane['cor'] ?>;"></i>
                        <p class="mb-0">Nenhum dado disponível ainda.</p>
                    </div>
                <?php else: ?>

                    <!-- ── Top 3 (Pódio) ──────────────────────────────────────── -->
                    <?php
                    $top3 = array_slice($dados, 0, 3);
                    $ordered = [null, null, null];
                    if (isset($top3[0])) $ordered[1] = $top3[0]; // 1º no centro
                    if (isset($top3[1])) $ordered[0] = $top3[1]; // 2º na esquerda
                    if (isset($top3[2])) $ordered[2] = $top3[2]; // 3º na direita

                    $medalColors = ['#94a3b8', '#f59e0b', '#cd7f32'];
                    $medalLabels = ['2º', '1º', '3º'];
                    $podiumHeights = ['70px', '90px', '55px'];
                    $podiumBgs = [
                        'linear-gradient(135deg,rgba(148,163,184,.15),rgba(148,163,184,.05))',
                        'linear-gradient(135deg,rgba(245,158,11,.18),rgba(245,158,11,.06))',
                        'linear-gradient(135deg,rgba(205,127,50,.15),rgba(205,127,50,.05))',
                    ];
                    $avatarSizes = [44, 54, 40];
                    ?>
                    <div class="row g-3 mb-3 align-items-end">
                        <?php foreach ($ordered as $oi => $entry):
                            if (!$entry) continue;
                            $posOrig  = $oi === 0 ? 2 : ($oi === 1 ? 1 : 3);
                            $isMe     = (int)$entry['IDUsuario'] === $uid;
                            $cor      = $medalColors[$oi];
                            $label    = $medalLabels[$oi];
                            $alt      = $pane['formato'] ?? 'count';
                            $val      = (int)$entry['total'];
                            $valLabel = ($alt === 'dias') ? _formatarDias($val) : number_format($val) . ' ' . ($val === 1 ? $pane['unidade'] : $pane['unidades']);
                        ?>
                            <?php
                            $boxShadow = $isMe ? "box-shadow:0 0 0 2px {$cor}66;" : '';
                            $cardStyle = "background:{$podiumBgs[$oi]};border:1px solid {$cor}44;{$boxShadow}min-height:{$podiumHeights[$oi]};transition:transform .2s;";
                            ?>
                            <div class="col-4">
                                <div class="rounded-4 p-3 text-center position-relative"
                                    style="<?= $cardStyle ?><?= $isMe ? '' : 'cursor:pointer;' ?>"
                                    onmouseenter="this.style.transform='translateY(-3px)'"
                                    onmouseleave="this.style.transform=''"
                                    <?= $isMe ? '' : 'onclick="abrirPerfilPublico(\'' . htmlspecialchars($entry['IDUsuario'], ENT_QUOTES) . '\')"' ?>>

                                    <!-- Medalha -->
                                    <div class="d-flex justify-content-center mb-2">
                                        <?php if ($posOrig === 1): ?>
                                            <span style="font-size:1.5rem;">🥇</span>
                                        <?php elseif ($posOrig === 2): ?>
                                            <span style="font-size:1.3rem;">🥈</span>
                                        <?php else: ?>
                                            <span style="font-size:1.2rem;">🥉</span>
                                        <?php endif; ?>
                                    </div>

                                    <!-- Avatar -->
                                    <div class="d-flex justify-content-center mb-2"
                                        <?= $isMe ? "title=\"Você\"" : "" ?>>
                                        <?= _rankAvatar($entry, $avatarSizes[$oi]) ?>
                                        <?php if ($isMe): ?>
                                            <div style="position:absolute;top:6px;right:8px;">
                                                <span style="font-size:.6rem;background:var(--accent);color:#000;border-radius:999px;padding:1px 6px;font-weight:700;">Você</span>
                                            </div>
                                        <?php endif; ?>
                                    </div>

                                    <!-- Nome -->
                                    <p class="fw-bold mb-0 text-truncate" style="font-size:.8rem;color:var(--text-main);" title="<?= htmlspecialchars(_primeiroNome($entry['Nome'])) ?>">
                                        <?= htmlspecialchars(_primeiroNome($entry['Nome'])) ?>
                                    </p>

                                    <!-- Valor -->
                                    <p class="mb-0" style="font-size:.72rem;color:<?= $cor ?>;font-weight:600;margin-top:2px;">
                                        <?= $valLabel ?>
                                    </p>

                                    <!-- Posição badge -->
                                    <div style="position:absolute;bottom:-10px;left:50%;transform:translateX(-50%);">
                                        <span style="background:<?= $cor ?>;color:#000;font-size:.65rem;font-weight:800;
                                             border-radius:999px;padding:2px 8px;white-space:nowrap;box-shadow:0 2px 6px rgba(0,0,0,.4);">
                                            <?= $label ?> lugar
                                        </span>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- ── 4º ao 10º ──────────────────────────────────────────── -->
                    <?php $resto = array_slice($dados, 3); ?>
                    <?php if (!empty($resto)): ?>
                        <div class="rounded-4 overflow-hidden mt-4" style="border:1px solid var(--card-border-color);">
                            <?php foreach ($resto as $ri => $entry):
                                $pos   = $ri + 4;
                                $isMe  = (int)$entry['IDUsuario'] === $uid;
                                $alt   = $pane['formato'] ?? 'count';
                                $val   = (int)$entry['total'];
                                $valLabel = ($alt === 'dias') ? _formatarDias($val) : number_format($val) . ' ' . ($val === 1 ? $pane['unidade'] : $pane['unidades']);
                            ?>
                                <div class="d-flex align-items-center gap-3 px-4 py-3"
                                    style="<?= $ri > 0 ? 'border-top:1px solid var(--card-border-color);' : '' ?>
                                background:<?= $isMe ? 'rgba(var(--bs-primary-rgb),.06)' : 'transparent' ?>;
                                <?= $isMe ? 'border-left:3px solid var(--accent) !important;' : 'cursor:pointer;' ?>"
                                    <?= $isMe ? '' : 'onclick="abrirPerfilPublico(\'' . htmlspecialchars($entry['IDUsuario'], ENT_QUOTES) . '\')"' ?>>

                                    <!-- Posição -->
                                    <div style="width:28px;text-align:center;flex-shrink:0;">
                                        <span style="font-size:.85rem;font-weight:700;color:var(--text-secondary);">#<?= $pos ?></span>
                                    </div>

                                    <!-- Avatar -->
                                    <?= _rankAvatar($entry, 36) ?>

                                    <!-- Nome -->
                                    <div class="flex-grow-1 min-w-0">
                                        <span class="fw-semibold text-truncate d-block" style="font-size:.88rem;color:var(--text-main);">
                                            <?= htmlspecialchars(_primeiroNome($entry['Nome'])) ?>
                                            <?php if ($isMe): ?>
                                                <span style="font-size:.65rem;background:var(--accent);color:#000;border-radius:999px;padding:1px 5px;font-weight:700;margin-left:4px;">Você</span>
                                            <?php endif; ?>
                                        </span>
                                    </div>

                                    <!-- Valor -->
                                    <span style="font-size:.82rem;font-weight:600;color:<?= $pane['cor'] ?>;white-space:nowrap;">
                                        <?= $valLabel ?>
                                    </span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <!-- ── Sua posição fora do top 10 ────────────────────────── -->
                    <?php if ($pane['posMe'] !== null && $pane['posMe']['pos'] > 0): ?>
                        <?php
                        $mePos   = $pane['posMe']['pos'];
                        $meTotal = $pane['posMe']['total'];
                        $alt     = $pane['formato'] ?? 'count';
                        $meTotalLabel = ($alt === 'dias') ? _formatarDias($meTotal) : number_format($meTotal) . ' ' . ($meTotal === 1 ? $pane['unidade'] : $pane['unidades']);
                        ?>
                        <div class="mt-3 rounded-4 d-flex align-items-center gap-3 px-4 py-3"
                            style="border:1px dashed var(--card-border-color);background:rgba(255,255,255,.03);">
                            <div style="flex-shrink:0;">
                                <span style="font-size:.85rem;font-weight:700;color:var(--text-secondary);">#<?= $mePos ?></span>
                            </div>
                            <?php
                            // Busca dados do usuário atual
                            $stmtMe = $pdo->prepare("SELECT Nome, FotoPerfil, FotoPerfilReal FROM Usuario WHERE IDUsuario = :uid LIMIT 1");
                            $stmtMe->execute([':uid' => $uid]);
                            $meRow = $stmtMe->fetch() ?: ['Nome' => $_SESSION['nome'] ?? 'Você', 'FotoPerfil' => null, 'FotoPerfilReal' => null];
                            echo _rankAvatar($meRow, 36);
                            ?>
                            <div class="flex-grow-1">
                                <span class="fw-semibold" style="font-size:.88rem;color:var(--text-main);">
                                    <?= htmlspecialchars(_primeiroNome($meRow['Nome'])) ?>
                                    <span style="font-size:.65rem;background:var(--accent);color:#000;border-radius:999px;padding:1px 5px;font-weight:700;margin-left:4px;">Você</span>
                                </span>
                                <p class="text-secondary mb-0" style="font-size:.72rem;">Continue para subir no ranking!</p>
                            </div>
                            <span style="font-size:.82rem;font-weight:600;color:<?= $pane['cor'] ?>;white-space:nowrap;">
                                <?= $meTotalLabel ?>
                            </span>
                        </div>
                    <?php endif; ?>

                <?php endif; // empty check
                ?>
            </div>
        <?php endforeach; ?>

        <!-- ── Amigo do Meu Amigo — não é disputa, é descoberta ────────────────── -->
        <div class="tab-pane fade" id="pane-amigos-comum" role="tabpanel">
            <div class="d-flex align-items-center gap-2 mb-4">
                <i class="bi bi-people-fill fs-5" style="color:#a78bfa;"></i>
                <div>
                    <h2 class="fw-bold mb-0" style="font-size:1.15rem;color:var(--text-main);">Amigo do Meu Amigo</h2>
                    <p class="text-secondary mb-0" style="font-size:.78rem;">Essa galera já é praticamente sua também</p>
                </div>
            </div>

            <?php if (empty($r_amigosComum)): ?>
                <div class="text-center py-5 text-secondary">
                    <i class="bi bi-people fs-1 opacity-25 d-block mb-3" style="color:#a78bfa;"></i>
                    <p class="mb-0">Ninguém em comum por enquanto — adicione alguns amigos e a galera deles vai aparecer aqui.</p>
                </div>
            <?php else: ?>
                <div class="rounded-4 overflow-hidden" style="border:1px solid var(--card-border-color);">
                    <?php foreach ($r_amigosComum as $ri => $entry): ?>
                        <div class="d-flex align-items-center gap-3 px-4 py-3"
                            style="<?= $ri > 0 ? 'border-top:1px solid var(--card-border-color);' : '' ?>cursor:pointer;"
                            onclick="abrirPerfilPublico('<?= htmlspecialchars($entry['IDUsuario'], ENT_QUOTES) ?>')">

                            <?= _rankAvatar($entry, 36) ?>

                            <div class="flex-grow-1 min-w-0">
                                <span class="fw-semibold text-truncate d-block" style="font-size:.88rem;color:var(--text-main);">
                                    <?= htmlspecialchars(_primeiroNome($entry['Nome'])) ?>
                                </span>
                            </div>

                            <span style="font-size:.78rem;font-weight:600;color:#a78bfa;white-space:nowrap;">
                                <?= (int)$entry['total'] ?> amigo<?= (int)$entry['total'] > 1 ? 's' : '' ?> em comum
                            </span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ── Modal: Perfil público (aberto ao clicar em alguém no ranking) ────── -->
    <div class="modal fade" id="modalPerfilPublico" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-secondary-subtle shadow-lg rounded-4" style="background:var(--bg-card);">
                <div class="modal-header border-bottom border-secondary-subtle">
                    <h6 class="modal-title fw-bold text-light mb-0"><i class="bi bi-person-badge me-2" style="color:var(--accent);"></i>Perfil</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <div id="ppfLoading" class="text-center py-5">
                        <div class="spinner-border text-secondary" role="status"></div>
                    </div>
                    <div id="ppfConteudo" class="d-none">

                        <!-- Mini perfil -->
                        <div class="d-flex align-items-center gap-3 mb-3">
                            <div id="ppfAvatar"></div>
                            <div class="flex-grow-1 min-w-0">
                                <h5 class="fw-bold mb-1 text-truncate" id="ppfNome"></h5>
                                <div class="d-flex align-items-center gap-2 flex-wrap">
                                    <span id="ppfPlanoBadge"></span>
                                    <span class="text-muted small" id="ppfTempo"></span>
                                </div>
                            </div>
                        </div>

                        <!-- Ação de amizade -->
                        <div id="ppfAcaoAmizade" class="mb-3"></div>

                        <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill w-100 mb-3" id="ppfBtnCompleto" onclick="ppfAlternarCompleto()">
                            <i class="bi bi-chevron-down me-1"></i> Ver perfil completo
                        </button>

                        <!-- Perfil completo (escondido até clicar acima) -->
                        <div id="ppfCompleto" class="d-none">
                            <div class="row g-2 mb-3">
                                <div class="col-4">
                                    <div class="rounded-3 text-center py-2" style="background:var(--bg-main);border:1px solid var(--bs-border-color);">
                                        <div class="fw-bold" style="font-size:1.2rem;color:var(--accent);" id="ppfStatTransacoes"></div>
                                        <div class="text-muted" style="font-size:.68rem;">Transações</div>
                                    </div>
                                </div>
                                <div class="col-4">
                                    <div class="rounded-3 text-center py-2" style="background:var(--bg-main);border:1px solid var(--bs-border-color);">
                                        <div class="fw-bold" style="font-size:1.2rem;color:#06b6d4;" id="ppfStatCategorias"></div>
                                        <div class="text-muted" style="font-size:.68rem;">Categorias</div>
                                    </div>
                                </div>
                                <div class="col-4">
                                    <div class="rounded-3 text-center py-2" style="background:var(--bg-main);border:1px solid var(--bs-border-color);">
                                        <div class="fw-bold" style="font-size:1.2rem;color:#10b981;" id="ppfStatComprovantes"></div>
                                        <div class="text-muted" style="font-size:.68rem;">Comprovantes</div>
                                    </div>
                                </div>
                            </div>

                            <div class="d-flex align-items-center justify-content-between mb-2">
                                <h6 class="fw-bold mb-0" style="font-size:.9rem;"><i class="bi bi-trophy-fill me-1" style="color:var(--accent);"></i>Conquistas</h6>
                                <span class="text-muted small" id="ppfConquistasResumo"></span>
                            </div>
                            <div class="d-flex flex-wrap gap-2" id="ppfConquistasGrid" style="max-height:260px;overflow-y:auto;"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

</main>

<style>
    .ppf-conquista-badge {
        width: 46px; height: 46px; border-radius: 50%;
        display: flex; align-items: center; justify-content: center;
        font-size: 1.1rem; flex-shrink: 0; position: relative;
    }
    .ppf-conquista-badge.bloqueada { opacity: .35; filter: grayscale(1); }

    .nav-pills .nav-link.active {
        background: var(--accent) !important;
        color: #000 !important;
    }

    .nav-pills .nav-link.active i {
        color: #000 !important;
    }

    .nav-pills .nav-link:not(.active):hover {
        background: rgba(255, 255, 255, .06) !important;
    }

    .nav::-webkit-scrollbar {
        display: none;
    }
</style>

<script>
var PPF_RARIDADE_COR = {
    comum: '#808080', incomum: '#3eb23e', raro: '#0070dd',
    epico: '#a335ee', lendario: '#ff8000', mitico: '#f3d3fd'
};
var PPF_UID_ATUAL = null;

function abrirPerfilPublico(userId) {
    PPF_UID_ATUAL = userId;
    var modalEl = document.getElementById('modalPerfilPublico');
    document.getElementById('ppfLoading').classList.remove('d-none');
    document.getElementById('ppfConteudo').classList.add('d-none');
    document.getElementById('ppfCompleto').classList.add('d-none');
    document.getElementById('ppfBtnCompleto').innerHTML = '<i class="bi bi-chevron-down me-1"></i> Ver perfil completo';
    bootstrap.Modal.getOrCreateInstance(modalEl).show();

    fetch('usuario/perfil_publico_ajax.php?id=' + encodeURIComponent(userId))
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (!data.ok) return;
            document.getElementById('ppfLoading').classList.add('d-none');
            document.getElementById('ppfConteudo').classList.remove('d-none');

            document.getElementById('ppfAvatar').innerHTML = data.avatarHtml;
            document.getElementById('ppfNome').textContent = data.nome;

            var badges = { vip: ['VIP', '#d4af37', 'bi-gem'], pro: ['PRO', '#a78bfa', 'bi-crown-fill'] };
            var b = badges[data.plano];
            document.getElementById('ppfPlanoBadge').innerHTML = b
                ? '<span class="badge" style="background:' + b[1] + '22;color:' + b[1] + ';border:1px solid ' + b[1] + '55;font-size:.68rem;"><i class="bi ' + b[2] + ' me-1"></i>' + b[0] + '</span>'
                : '<span class="badge" style="background:#ffffff11;color:#9ca3af;border:1px solid #ffffff22;font-size:.68rem;">FREE</span>';

            document.getElementById('ppfTempo').innerHTML =
                '<i class="bi bi-calendar3 me-1"></i>Membro desde ' + data.dataMembro +
                ' &middot; ' + data.diasAtivo + ' dia' + (data.diasAtivo !== 1 ? 's' : '') + ' no Auralis';

            ppfMontarAcaoAmizade(data.id, data.amizade);

            document.getElementById('ppfStatTransacoes').textContent = data.stats.transacoes;
            document.getElementById('ppfStatCategorias').textContent = data.stats.categorias;
            document.getElementById('ppfStatComprovantes').textContent = data.stats.comprovantes;
            document.getElementById('ppfConquistasResumo').textContent = data.totalDesbloqueadas + ' de ' + data.totalConquistas;

            var grid = document.getElementById('ppfConquistasGrid');
            grid.innerHTML = '';
            data.conquistas.forEach(function (c) {
                var cor = c.desbloqueada ? c.cor : '#9ca3af';
                var wrap = document.createElement('div');
                wrap.className = 'ppf-conquista-badge' + (c.desbloqueada ? '' : ' bloqueada');
                wrap.title = c.nome + ' — ' + c.descricao;
                wrap.style.background = (PPF_RARIDADE_COR[c.raridade] || '#808080') + '1a';
                wrap.style.border = '1.5px solid ' + (PPF_RARIDADE_COR[c.raridade] || '#808080') + '55';
                if (!c.desbloqueada) {
                    wrap.innerHTML = '<i class="bi bi-lock-fill" style="color:rgba(156,163,175,.6);font-size:1rem;"></i>';
                } else if (c.imagem) {
                    wrap.innerHTML = '<img src="' + c.imagem + '" style="width:78%;height:78%;object-fit:contain;border-radius:50%;">';
                } else {
                    wrap.innerHTML = '<i class="bi ' + c.icone + '" style="color:' + cor + ';"></i>';
                }
                grid.appendChild(wrap);
            });
        })
        .catch(function () {
            document.getElementById('ppfLoading').innerHTML = '<p class="text-secondary mb-0">Não consegui carregar esse perfil.</p>';
        });
}

function ppfMontarAcaoAmizade(userId, amizade) {
    var box = document.getElementById('ppfAcaoAmizade');
    if (amizade.status === 'amigos') {
        box.innerHTML = '<span class="badge bg-success-subtle text-success px-3 py-2 rounded-pill"><i class="bi bi-check2-circle me-1"></i>Vocês são amigos</span>';
    } else if (amizade.status === 'pendente_enviado') {
        box.innerHTML =
            '<button type="button" class="btn btn-sm btn-outline-secondary rounded-pill" onclick="ppfCancelarPedido(\'' + amizade.idAmizade + '\')">' +
            '<i class="bi bi-clock-history me-1"></i> Pedido enviado — cancelar</button>';
    } else if (amizade.status === 'pendente_recebido') {
        box.innerHTML =
            '<div class="d-flex gap-2">' +
            '<button type="button" class="btn btn-sm rounded-pill flex-grow-1" style="background:var(--accent);color:#000;font-weight:600;" onclick="ppfResponderPedido(\'' + amizade.idAmizade + '\',\'aceitar\')"><i class="bi bi-check2 me-1"></i>Aceitar</button>' +
            '<button type="button" class="btn btn-sm btn-outline-secondary rounded-pill flex-grow-1" onclick="ppfResponderPedido(\'' + amizade.idAmizade + '\',\'recusar\')">Recusar</button>' +
            '</div>';
    } else {
        box.innerHTML =
            '<button type="button" class="btn btn-sm w-100 rounded-pill" style="background:var(--accent);color:#000;font-weight:600;" onclick="ppfAdicionarAmigo(\'' + userId + '\')">' +
            '<i class="bi bi-person-plus-fill me-1"></i> Adicionar amigo</button>';
    }
}

function ppfAdicionarAmigo(userId) {
    var fd = new FormData();
    fd.append('destinatario_id', userId);
    fetch('usuario/amizade_solicitar.php', { method: 'POST', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.ok) ppfMontarAcaoAmizade(userId, { status: data.status, idAmizade: data.idAmizade });
        });
}

function ppfResponderPedido(idAmizade, acao) {
    var fd = new FormData();
    fd.append('amizade_id', idAmizade);
    fd.append('acao', acao);
    fetch('usuario/amizade_responder.php', { method: 'POST', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.ok) ppfMontarAcaoAmizade(PPF_UID_ATUAL, { status: data.status });
        });
}

function ppfCancelarPedido(idAmizade) {
    var fd = new FormData();
    fd.append('amizade_id', idAmizade);
    fetch('usuario/amizade_cancelar.php', { method: 'POST', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.ok) ppfMontarAcaoAmizade(PPF_UID_ATUAL, { status: data.status });
        });
}

function ppfAlternarCompleto() {
    var box = document.getElementById('ppfCompleto');
    var btn = document.getElementById('ppfBtnCompleto');
    var abrindo = box.classList.contains('d-none');
    box.classList.toggle('d-none');
    btn.innerHTML = abrindo
        ? '<i class="bi bi-chevron-up me-1"></i> Fechar perfil completo'
        : '<i class="bi bi-chevron-down me-1"></i> Ver perfil completo';
}
</script>

<?php require_once 'geral/footer.php'; ?>