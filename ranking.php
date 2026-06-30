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
$uid = $_SESSION['usuario_id'];

// ── Helpers ───────────────────────────────────────────────────────────────────
function _rankAvatar(array $row, int $size = 40): string {
    if (function_exists('getAvatarUrl')) {
        $fp = json_decode($row['FotoPerfil'] ?? '', true);
        if (is_array($fp) && ($fp['style'] ?? '') === 'avataaars') {
            $url = getAvatarUrl($fp);
            return "<img src=\"" . htmlspecialchars($url) . "\" width=\"{$size}\" height=\"{$size}\" "
                 . "style=\"border-radius:50%;object-fit:cover;flex-shrink:0;\">";
        }
    }
    $nome   = $row['Nome'] ?? '?';
    $partes = array_filter(explode(' ', trim($nome)));
    $ini    = implode('', array_map(fn($p) => strtoupper($p[0] ?? ''), $partes));
    $ini    = mb_substr($ini, 0, 2);
    $pal    = ['#6366f1','#8b5cf6','#ec4899','#f59e0b','#10b981','#3b82f6','#ef4444','#06b6d4'];
    $bg     = $pal[abs(crc32($nome)) % count($pal)];
    $fs     = (int)round($size * 0.38);
    return "<div style=\"width:{$size}px;height:{$size}px;border-radius:50%;background:{$bg};"
         . "display:inline-flex;align-items:center;justify-content:center;"
         . "font-size:{$fs}px;font-weight:700;color:#fff;flex-shrink:0;\">{$ini}</div>";
}

function _primeiroNome(string $nome): string {
    return explode(' ', trim($nome))[0];
}

function _formatarDias(int $dias): string {
    if ($dias < 30)  return "{$dias} dia" . ($dias !== 1 ? 's' : '');
    if ($dias < 365) { $m = (int)round($dias / 30); return "{$m} mês" . ($m > 1 ? 'es' : ''); }
    $anos = (int)floor($dias / 365);
    $meses = (int)round(($dias % 365) / 30);
    $s = "{$anos} ano" . ($anos > 1 ? 's' : '');
    if ($meses > 0) $s .= " e {$meses} mês" . ($meses > 1 ? 'es' : '');
    return $s;
}

// ── Posição do usuário fora do top 10 ────────────────────────────────────────
// Retorna ['pos' => N, 'total' => X] onde pos >= 11
function _minhaPosicao(PDO $pdo, string $sqlMeuTotal, string $sqlQuantosAcima, string $uid): array {
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
function _rankQuery(PDO $pdo, string $sql): array {
    try {
        $st = $pdo->prepare($sql);
        $st->execute();
        return $st->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

$r_registros = _rankQuery($pdo, "
    SELECT u.IDUsuario, u.Nome, u.FotoPerfil, COUNT(*) AS total
    FROM Registro r
    JOIN Usuario u ON u.IDUsuario = r.FKUsuario
    WHERE r.TipoRegistro IN ('receita','despesa') AND u.StatusConta = 'ativo'
    GROUP BY u.IDUsuario, u.Nome, u.FotoPerfil
    ORDER BY total DESC, u.MomentoCriacao ASC
    LIMIT 10
");

$r_conquistas = _rankQuery($pdo, "
    SELECT u.IDUsuario, u.Nome, u.FotoPerfil, COUNT(*) AS total
    FROM usuario_conquista uc
    JOIN Usuario u ON u.IDUsuario = uc.FKUsuario
    WHERE u.StatusConta = 'ativo'
    GROUP BY u.IDUsuario, u.Nome, u.FotoPerfil
    ORDER BY total DESC, u.MomentoCriacao ASC
    LIMIT 10
");

$r_comprovantes = _rankQuery($pdo, "
    SELECT u.IDUsuario, u.Nome, u.FotoPerfil, COUNT(*) AS total
    FROM Comprovante c
    JOIN Usuario u ON u.IDUsuario = c.FKUsuario
    WHERE u.StatusConta = 'ativo'
    GROUP BY u.IDUsuario, u.Nome, u.FotoPerfil
    ORDER BY total DESC, u.MomentoCriacao ASC
    LIMIT 10
");

$r_veteranos = _rankQuery($pdo, "
    SELECT IDUsuario, Nome, FotoPerfil,
           DATEDIFF(NOW(), MomentoCriacao) AS total
    FROM Usuario
    WHERE StatusConta = 'ativo'
    ORDER BY MomentoCriacao ASC
    LIMIT 10
");

$r_categorias = _rankQuery($pdo, "
    SELECT u.IDUsuario, u.Nome, u.FotoPerfil, COUNT(*) AS total
    FROM Categoria cat
    JOIN Usuario u ON u.IDUsuario = cat.FKUsuario
    WHERE u.StatusConta = 'ativo'
    GROUP BY u.IDUsuario, u.Nome, u.FotoPerfil
    ORDER BY total DESC, u.MomentoCriacao ASC
    LIMIT 10
");

// ── Verifica se usuário está fora do top 10 e busca sua posição ───────────────
function _usuarioNaLista(array $lista, string $uid): bool {
    foreach ($lista as $r) if ((string)$r['IDUsuario'] === $uid) return true;
    return false;
}

$pos_registros   = !_usuarioNaLista($r_registros,   $uid) ? _minhaPosicao($pdo,
    "SELECT COUNT(*) FROM Registro WHERE FKUsuario = :uid AND TipoRegistro IN ('receita','despesa')",
    "SELECT COUNT(*) FROM (
        SELECT r.FKUsuario FROM Registro r JOIN Usuario u ON u.IDUsuario=r.FKUsuario
        WHERE r.TipoRegistro IN ('receita','despesa') AND u.StatusConta='ativo' AND r.FKUsuario != :uid
        GROUP BY r.FKUsuario HAVING COUNT(*) > :total
     ) t", $uid) : null;

$pos_conquistas  = !_usuarioNaLista($r_conquistas,  $uid) ? _minhaPosicao($pdo,
    "SELECT COUNT(*) FROM usuario_conquista WHERE FKUsuario = :uid",
    "SELECT COUNT(*) FROM (
        SELECT uc.FKUsuario FROM usuario_conquista uc JOIN Usuario u ON u.IDUsuario=uc.FKUsuario
        WHERE u.StatusConta='ativo' AND uc.FKUsuario != :uid
        GROUP BY uc.FKUsuario HAVING COUNT(*) > :total
     ) t", $uid) : null;

$pos_comprovantes = !_usuarioNaLista($r_comprovantes, $uid) ? _minhaPosicao($pdo,
    "SELECT COUNT(*) FROM Comprovante WHERE FKUsuario = :uid",
    "SELECT COUNT(*) FROM (
        SELECT c.FKUsuario FROM Comprovante c JOIN Usuario u ON u.IDUsuario=c.FKUsuario
        WHERE u.StatusConta='ativo' AND c.FKUsuario != :uid
        GROUP BY c.FKUsuario HAVING COUNT(*) > :total
     ) t", $uid) : null;

$pos_veteranos = !_usuarioNaLista($r_veteranos, $uid) ? _minhaPosicao($pdo,
    "SELECT DATEDIFF(NOW(), MomentoCriacao) FROM Usuario WHERE IDUsuario = :uid",
    "SELECT COUNT(*) FROM Usuario
     WHERE StatusConta='ativo' AND IDUsuario != :uid
       AND DATEDIFF(NOW(), MomentoCriacao) > :total", $uid) : null;

$pos_categorias = !_usuarioNaLista($r_categorias, $uid) ? _minhaPosicao($pdo,
    "SELECT COUNT(*) FROM Categoria WHERE FKUsuario = :uid",
    "SELECT COUNT(*) FROM (
        SELECT cat.FKUsuario FROM Categoria cat JOIN Usuario u ON u.IDUsuario=cat.FKUsuario
        WHERE u.StatusConta='ativo' AND cat.FKUsuario != :uid
        GROUP BY cat.FKUsuario HAVING COUNT(*) > :total
     ) t", $uid) : null;

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
            ['id'=>'registros',   'icon'=>'bi-lightning-charge-fill', 'cor'=>'#6366f1', 'label'=>'Lançamentos'],
            ['id'=>'conquistas',  'icon'=>'bi-award-fill',            'cor'=>'#f59e0b', 'label'=>'Conquistas'],
            ['id'=>'comprovantes','icon'=>'bi-receipt',               'cor'=>'#10b981', 'label'=>'Comprovantes'],
            ['id'=>'veteranos',   'icon'=>'bi-calendar-heart-fill',   'cor'=>'#ec4899', 'label'=>'Veteranos'],
            ['id'=>'categorias',  'icon'=>'bi-tags-fill',             'cor'=>'#60a5fa', 'label'=>'Categorias'],
        ];
        foreach ($tabs as $i => $t): ?>
        <li class="nav-item flex-shrink-0" role="presentation">
            <button class="nav-link d-flex align-items-center gap-2 fw-semibold px-3 py-2 <?= $i===0?'active':'' ?>"
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
            ['id'=>'registros',    'dados'=>$r_registros,    'posMe'=>$pos_registros,
             'titulo'=>'Mais Lançamentos', 'subtitulo'=>'Quem mais registrou receitas e despesas',
             'icon'=>'bi-lightning-charge-fill','cor'=>'#6366f1',
             'unidade'=>'lançamento','unidades'=>'lançamentos'],

            ['id'=>'conquistas',   'dados'=>$r_conquistas,   'posMe'=>$pos_conquistas,
             'titulo'=>'Colecionadores', 'subtitulo'=>'Quem desbloqueou mais conquistas',
             'icon'=>'bi-award-fill','cor'=>'#f59e0b',
             'unidade'=>'conquista','unidades'=>'conquistas'],

            ['id'=>'comprovantes', 'dados'=>$r_comprovantes, 'posMe'=>$pos_comprovantes,
             'titulo'=>'Caça-Recibos', 'subtitulo'=>'Quem mais enviou comprovantes',
             'icon'=>'bi-receipt','cor'=>'#10b981',
             'unidade'=>'comprovante','unidades'=>'comprovantes'],

            ['id'=>'veteranos',    'dados'=>$r_veteranos,    'posMe'=>$pos_veteranos,
             'titulo'=>'Veteranos', 'subtitulo'=>'Os membros mais antigos da plataforma',
             'icon'=>'bi-calendar-heart-fill','cor'=>'#ec4899',
             'unidade'=>'dia','unidades'=>'dias','formato'=>'dias'],

            ['id'=>'categorias',   'dados'=>$r_categorias,   'posMe'=>$pos_categorias,
             'titulo'=>'Mais Organizados', 'subtitulo'=>'Quem criou mais categorias personalizadas',
             'icon'=>'bi-tags-fill','cor'=>'#60a5fa',
             'unidade'=>'categoria','unidades'=>'categorias'],
        ];

        foreach ($panes as $pi => $pane):
            $dados   = $pane['dados'];
            $isFirst = $pi === 0;
        ?>
        <div class="tab-pane fade <?= $isFirst?'show active':'' ?>" id="pane-<?= $pane['id'] ?>" role="tabpanel">

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

                $medalColors = ['#94a3b8','#f59e0b','#cd7f32'];
                $medalLabels = ['2º','1º','3º'];
                $podiumHeights = ['70px','90px','55px'];
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
                    <div class="col-4">
                            <div class="rounded-4 p-3 text-center position-relative"
                                style="background:<?= $podiumBgs[$oi] ?>; 
                                     border:1px solid <?= $cor ?>44; 
                                     <?= $isMe ? 'echo_box_shadow' : '' ?>
                                     min-height:<?= $podiumHeights[$oi] ?>; 
                                     transition:transform .2s;"
                             onmouseenter="this.style.transform='translateY(-3px)'"
                             onmouseleave="this.style.transform=''">

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
                                <?= $isMe ? 'border-left:3px solid var(--accent) !important;' : '' ?>">

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
                    $stmtMe = $pdo->prepare("SELECT Nome, FotoPerfil FROM Usuario WHERE IDUsuario = :uid LIMIT 1");
                    $stmtMe->execute([':uid' => $uid]);
                    $meRow = $stmtMe->fetch() ?: ['Nome' => $_SESSION['nome'] ?? 'Você', 'FotoPerfil' => null];
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

            <?php endif; // empty check ?>
        </div>
        <?php endforeach; ?>
    </div>

</main>

<style>
    .nav-pills .nav-link.active {
        background: var(--accent) !important;
        color: #000 !important;
    }
    .nav-pills .nav-link.active i {
        color: #000 !important;
    }
    .nav-pills .nav-link:not(.active):hover {
        background: rgba(255,255,255,.06) !important;
    }
    .nav::-webkit-scrollbar { display: none; }
</style>

<?php require_once 'geral/footer.php'; ?>
