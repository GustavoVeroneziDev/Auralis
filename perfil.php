<?php
// ==============================================================================
// PERFIL.PHP — Perfil do usuário com conquistas e insígnias
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
garantirColunaInsigniasDestaque($pdo);
garantirEstruturaCarteirasCompartilhadas($pdo);
garantirTabelaAmizade($pdo);

$uid = $_SESSION['usuario_id'];

// ── Dados do usuário ────────────────────────────────────────────────────────
$stmtU = $pdo->prepare("SELECT Nome, Email, Plano, Tema, MomentoCriacao, FotoPerfil, FotoPerfilReal FROM Usuario WHERE IDUsuario = :uid LIMIT 1");
$stmtU->execute([':uid' => $uid]);
$usuario = $stmtU->fetch(PDO::FETCH_ASSOC);
if (!$usuario) { header("Location: /dashboard.php"); exit; }

// Chave pessoal única — mesmo código usado pra indicar amigos/revendedor (link em
// Configurações) e pra convidar alguém pra carteira compartilhada, unificados num só.
$codigoPessoal = obterOuGerarCodigoIndicacao($pdo, $uid);

$primeiroNome  = explode(' ', $usuario['Nome'])[0];
$iniciais      = implode('', array_map(fn($p) => strtoupper($p[0]), array_filter(explode(' ', $usuario['Nome']))));
$iniciais      = mb_substr($iniciais, 0, 2);
$dataCriacao   = new DateTime($usuario['MomentoCriacao']);
$diasAtivo     = (int)(new DateTime())->diff($dataCriacao)->days;
$dataMembro    = $dataCriacao->format('d/m/Y');
$plano         = strtolower($usuario['Plano'] ?? 'free');

// ── Award conquista veterano_90 se aplicável ─────────────────────────────────
if ($diasAtivo >= 90) {
    concederConquista('veterano_90');
}

// ── Stats ───────────────────────────────────────────────────────────────────
$stmtStats = $pdo->prepare("
    SELECT
        (SELECT COUNT(*) FROM Registro    WHERE FKUsuario = :uid  AND TipoRegistro IN ('receita','despesa')) AS transacoes,
        (SELECT COUNT(*) FROM Carteira    WHERE FKUsuarioDono = :uid2 ) AS carteiras,
        (SELECT COUNT(*) FROM Categoria   WHERE FKUsuario = :uid3 ) AS categorias
");
$stmtStats->execute([':uid' => $uid, ':uid2' => $uid, ':uid3' => $uid]);
$stats = $stmtStats->fetch(PDO::FETCH_ASSOC);

// ── Amigos ──────────────────────────────────────────────────────────────────
$pedidosRecebidos = [];
$amigosAceitos    = [];
try {
    $stmtPed = $pdo->prepare("
        SELECT a.IDAmizade, u.IDUsuario, u.Nome, u.FotoPerfil, u.FotoPerfilReal
        FROM Amizade a
        JOIN Usuario u ON u.IDUsuario = a.FKUsuarioSolicitante
        WHERE a.FKUsuarioDestinatario = :uid AND a.Status = 'pendente'
        ORDER BY a.CriadoEm DESC
    ");
    $stmtPed->execute([':uid' => $uid]);
    $pedidosRecebidos = $stmtPed->fetchAll(PDO::FETCH_ASSOC);

    $stmtAmigos = $pdo->prepare("
        SELECT u.IDUsuario, u.Nome, u.FotoPerfil, u.FotoPerfilReal
        FROM Amizade a
        JOIN Usuario u ON u.IDUsuario = (CASE WHEN a.FKUsuarioSolicitante = :uid THEN a.FKUsuarioDestinatario ELSE a.FKUsuarioSolicitante END)
        WHERE (a.FKUsuarioSolicitante = :uid2 OR a.FKUsuarioDestinatario = :uid3) AND a.Status = 'aceito'
        ORDER BY a.RespondidoEm DESC
    ");
    $stmtAmigos->execute([':uid' => $uid, ':uid2' => $uid, ':uid3' => $uid]);
    $amigosAceitos = $stmtAmigos->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
}

// "Amigo do Meu Amigo" — sugestões por amizade em comum. Não é disputa, é descoberta:
// gente com pelo menos 1 amigo em comum que você ainda não é amigo nem tem convite
// pendente. Cada linha da junção abaixo representa exatamente 1 amigo em comum, então
// COUNT(*) já é a contagem certa — quem não tem nenhum simplesmente não aparece.
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
        LIMIT 30
    ");
    $stmtAC->execute([
        ':uid1' => $uid, ':uid2' => $uid, ':uid3' => $uid, ':uid4' => $uid,
        ':uid5' => $uid, ':uid6' => $uid, ':uid7' => $uid,
    ]);
    $r_amigosComum = $stmtAC->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
}

// ── Conquistas ───────────────────────────────────────────────────────────────
$conquistas = [];
try {
    $stmtC = $pdo->prepare("
        SELECT c.IDConquista, c.Slug, c.Nome, c.Descricao, c.Icone,
               COALESCE(c.ImagemUrl, '') AS ImagemUrl,
               c.Cor, c.Raridade, c.Ordem,
               uc.DataConquista
        FROM conquista c
        LEFT JOIN usuario_conquista uc
               ON uc.FKConquista = c.IDConquista AND uc.FKUsuario = :uid
        WHERE c.Ativo = 1
        ORDER BY
            CASE WHEN uc.DataConquista IS NOT NULL THEN 0 ELSE 1 END ASC,
            CASE c.Raridade
                WHEN 'mitico'   THEN 1
                WHEN 'lendario' THEN 2
                WHEN 'epico'    THEN 3
                WHEN 'raro'     THEN 4
                WHEN 'incomum'  THEN 5
                WHEN 'comum'    THEN 6
                ELSE 7
            END ASC
    ");
    $stmtC->execute([':uid' => $uid]);
    $conquistas = $stmtC->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { /* colunas ainda não migradas em produção */ }

$totalConquistas     = count($conquistas);
$totalDesbloqueadas  = count(array_filter($conquistas, fn($c) => $c['DataConquista'] !== null));

// 3 espaços fixos de insígnia em destaque no herói do perfil
$insigniasDestaque = obterInsigniasDestaque($pdo, $uid);
$conquistasPorId   = array_column($conquistas, null, 'IDConquista');

// ── Mapa de raridade → label/cor ────────────────────────────────────────────
$raridadeInfo = [
    'comum'    => ['label' => 'Comum',    'cor' => '#808080'],
    'incomum'  => ['label' => 'Incomum',  'cor' => '#3eb23e'],
    'raro'     => ['label' => 'Raro',     'cor' => '#0070dd'],
    'epico'    => ['label' => 'Épico',    'cor' => '#a335ee'],
    'lendario' => ['label' => 'Lendário', 'cor' => '#ff8000'],
    'mitico'   => ['label' => 'Mítico',   'cor' => '#f3d3fd'],
];

// ── Cor da inicial do avatar baseada no plano ────────────────────────────────
$avatarCor = match($plano) {
    'vip'   => '#d4af37',
    'pro'   => '#7c3aed',
    default => '#6366f1',
};

// Avatar DiceBear (personagem) — nunca é apagado quando uma foto real é enviada, só
// perde a preferência de exibição pra ela (ver $urlAvatarExibicao mais abaixo).
$avatarConfig = [];
$avatarPreviewUrl = '';
if (!empty($usuario['FotoPerfil'])) {
    $dec = json_decode($usuario['FotoPerfil'], true);
    if (is_array($dec) && ($dec['style'] ?? '') === 'avataaars') {
        $avatarConfig     = $dec;
        $avatarPreviewUrl = getAvatarUrl($dec);
    }
}
$temPersonagemSalvo = $avatarPreviewUrl !== '';
$temFotoReal        = !empty($usuario['FotoPerfilReal']);
// O que mostrar no herói/menu: foto real > personagem > iniciais (fallback já existente).
$urlAvatarExibicao  = $temFotoReal ? $usuario['FotoPerfilReal'] : $avatarPreviewUrl;
$avatarDefaults = [
    'skinColor' => 'd08b5b', 'hair' => 'shortCurly', 'hairColor' => '2c1b18',
    'eyes' => 'default', 'eyebrows' => 'default', 'mouth' => 'smile',
    'clothing' => 'hoodie', 'clothingColor' => '3c4f5c',
    'accessories' => '', 'facialHair' => '', 'facialHairColor' => '2c1b18',
    'backgroundColor' => 'transparent',
];
$cfg = array_merge($avatarDefaults, $avatarConfig);

$pageTitle = 'Perfil';
require_once 'geral/header.php';
?>
<style>
.perfil-avatar {
    width: 80px; height: 80px;
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.6rem; font-weight: 700;
    flex-shrink: 0;
    border: 3px solid;
}
.perfil-hero {
    background: var(--bg-card);
    border: 1px solid var(--bs-border-color);
    border-radius: 16px;
    padding: 2rem;
}
.stat-card {
    background: var(--bg-card);
    border: 1px solid var(--bs-border-color);
    border-radius: 12px;
    padding: 1.25rem 1rem;
    text-align: center;
}
.stat-value { font-size: 1.75rem; font-weight: 700; line-height: 1; }
.stat-label { font-size: 0.75rem; color: var(--text-muted); margin-top: 4px; }

.conquista-card {
    background: var(--bg-card);
    border: 1px solid var(--bs-border-color);
    border-radius: 14px;
    padding: 1.25rem;
    display: flex;
    align-items: flex-start;
    gap: 1rem;
    height: 136px;
    overflow: hidden;
    cursor: pointer;
    transition: transform .15s, box-shadow .15s;
}
.conquista-card:not(.bloqueada):hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(0,0,0,.25);
}
.conquista-card.bloqueada {
    opacity: .4;
    filter: grayscale(1);
}
.conquista-icon-wrap {
    width: 72px; height: 72px;
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.75rem;
    flex-shrink: 0;
    overflow: hidden;
    position: relative;
}

/* ── Frames por raridade ────────────────────────────── */
.badge-comum {
    background: rgba(128,128,128,0.08);
    border: 1.5px solid rgba(128,128,128,0.35);
}
.badge-incomum {
    background: rgba(62,178,62,0.1);
    border: 1.5px solid rgba(62,178,62,0.55);
    box-shadow: 0 0 8px rgba(62,178,62,0.2);
}
.badge-raro {
    background: rgba(0,112,221,0.1);
    border: 1.5px solid rgba(0,112,221,0.65);
    box-shadow: 0 0 10px rgba(0,112,221,0.25);
}
.badge-epico {
    background: linear-gradient(135deg,rgba(163,53,238,0.16),rgba(163,53,238,0.06));
    border: 2px solid rgba(163,53,238,0.75);
    box-shadow: 0 0 14px rgba(163,53,238,0.4), inset 0 0 8px rgba(163,53,238,0.08);
}
.badge-lendario {
    background: linear-gradient(135deg,rgba(255,128,0,0.18),rgba(255,128,0,0.06));
    border: 2px solid rgba(255,128,0,0.88);
    box-shadow: 0 0 16px rgba(255,128,0,0.5), inset 0 0 10px rgba(255,128,0,0.1);
    animation: glow-lendario 2.8s ease-in-out infinite;
}
@keyframes glow-lendario {
    0%,100% { box-shadow: 0 0 16px rgba(255,128,0,0.50), inset 0 0 10px rgba(255,128,0,0.10); }
    50%      { box-shadow: 0 0 28px rgba(255,128,0,0.75), inset 0 0 14px rgba(255,128,0,0.20); }
}
.badge-mitico {
    background: linear-gradient(135deg,rgba(243,211,253,0.15),rgba(243,211,253,0.04));
    border: 2px solid rgba(243,211,253,0.80);
    box-shadow: 0 0 20px rgba(243,211,253,0.5), inset 0 0 12px rgba(243,211,253,0.08);
    animation: glow-mitico 2.2s ease-in-out infinite;
}
@keyframes glow-mitico {
    0%,100% { box-shadow: 0 0 20px rgba(243,211,253,0.50), inset 0 0 12px rgba(243,211,253,0.08); }
    50%      { box-shadow: 0 0 38px rgba(243,211,253,0.88), inset 0 0 20px rgba(243,211,253,0.18); }
}
.conquista-card.bloqueada .conquista-icon-wrap {
    animation: none !important;
    box-shadow: none !important;
}

.conquista-nome {
    font-weight: 600;
    font-size: 0.9rem;
    margin-bottom: 2px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.conquista-desc {
    font-size: 0.78rem;
    color: var(--text-muted);
    line-height: 1.45;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
.conquista-footer { margin-top: 6px; font-size: 0.72rem; }
.raridade-pill {
    display: inline-flex; align-items: center;
    border-radius: 999px;
    padding: 2px 8px;
    font-size: 0.62rem;
    font-weight: 700;
    letter-spacing: .05em;
}
</style>

<main class="container-fluid py-4 mt-2 flex-grow-1" style="max-width:900px;padding-inline:var(--space-page-x);">

    <?php
    $_msgsSucessoPerfil = [
        'personagem'      => 'Personagem salvo com sucesso!',
        'foto'            => 'Foto atualizada!',
        'foto_removida'   => 'Foto removida — voltando a mostrar seu personagem.',
        'insignia_salva'  => 'Insígnia em destaque atualizada!',
    ];
    $_msgsErroPerfil = [
        'foto_invalida'     => 'Não recebi nenhuma foto. Tenta de novo.',
        'foto_grande'       => 'Essa foto passa de 5 MB — escolhe uma menor.',
        'foto_tipo'         => 'Formato não aceito — use JPG, PNG ou WebP.',
        'foto_upload'       => 'Não consegui salvar a foto. Tenta de novo.',
        'insignia_invalida' => 'Não deu pra destacar essa conquista.',
        'banco'             => 'Erro ao salvar. Tenta de novo.',
    ];
    $_sucessoPerfil = $_msgsSucessoPerfil[$_GET['sucesso'] ?? ''] ?? null;
    $_erroPerfil     = $_msgsErroPerfil[$_GET['erro'] ?? ''] ?? null;
    // Acabou de salvar o personagem agora — mostra a seção mesmo que ela já fosse
    // colapsar por padrão, senão a âncora #personagem cairia em cima de algo escondido.
    $_forcarMostrarPersonagem = ($_GET['sucesso'] ?? '') === 'personagem';
    ?>
    <?php if ($_sucessoPerfil): ?>
    <div class="alert alert-success rounded-3 py-2 px-3 d-flex align-items-center gap-2 mb-3" style="font-size:0.9rem;">
        <i class="bi bi-check-circle-fill"></i> <?= htmlspecialchars($_sucessoPerfil) ?>
    </div>
    <?php endif; ?>
    <?php if ($_erroPerfil): ?>
    <div class="alert alert-danger rounded-3 py-2 px-3 d-flex align-items-center gap-2 mb-3" style="font-size:0.9rem;">
        <i class="bi bi-exclamation-triangle-fill"></i> <?= htmlspecialchars($_erroPerfil) ?>
    </div>
    <?php endif; ?>

    <!-- Hero -->
    <div class="perfil-hero mb-4 d-flex align-items-center gap-4 flex-wrap">
        <div class="dropdown flex-shrink-0">
            <button type="button" class="btn p-0 border-0 position-relative" data-bs-toggle="dropdown" aria-expanded="false"
                    style="background:transparent;" title="Editar personagem ou colocar foto">
                <?php if ($urlAvatarExibicao): ?>
                <div style="width:80px;height:80px;border-radius:50%;overflow:hidden;border:3px solid <?= htmlspecialchars($avatarCor) ?>88;background:#<?= htmlspecialchars($cfg['backgroundColor'] !== 'transparent' ? $cfg['backgroundColor'] : '1e2028') ?>;">
                    <img src="<?= htmlspecialchars($urlAvatarExibicao) ?>" alt="Avatar" style="width:100%;height:100%;object-fit:cover;">
                </div>
                <?php else: ?>
                <div class="perfil-avatar"
                     style="background:<?= htmlspecialchars($avatarCor) ?>22;color:<?= htmlspecialchars($avatarCor) ?>;border-color:<?= htmlspecialchars($avatarCor) ?>55;">
                    <?= htmlspecialchars($iniciais) ?>
                </div>
                <?php endif; ?>
                <span class="d-flex align-items-center justify-content-center position-absolute"
                      style="width:26px;height:26px;bottom:-2px;right:-2px;border-radius:50%;background:var(--accent);border:2px solid var(--bg-card);">
                    <i class="bi bi-pencil-fill" style="font-size:0.7rem;color:#fff;"></i>
                </span>
            </button>
            <ul class="dropdown-menu shadow-lg border-secondary-subtle" style="background:var(--bg-card);">
                <li><h6 class="dropdown-header">Sua imagem</h6></li>
                <li>
                    <button type="button" class="dropdown-item d-flex align-items-center gap-2" onclick="mostrarEditorPersonagem()">
                        <i class="bi bi-person-bounding-box" style="color:#ec4899;"></i> Editar personagem
                    </button>
                </li>
                <li>
                    <button type="button" class="dropdown-item d-flex align-items-center gap-2" data-bs-toggle="modal" data-bs-target="#modalFotoPerfil">
                        <i class="bi bi-image" style="color:#60a5fa;"></i> Colocar foto
                    </button>
                </li>
                <?php if ($temFotoReal): ?>
                <li><hr class="dropdown-divider"></li>
                <li>
                    <form method="POST" action="/usuario/processa_foto_perfil.php" onsubmit="return confirm('Remover sua foto? Volta a mostrar o personagem.');">
                        <input type="hidden" name="action" value="remover_foto">
                        <button type="submit" class="dropdown-item d-flex align-items-center gap-2 text-danger">
                            <i class="bi bi-trash3"></i> Remover foto (voltar ao personagem)
                        </button>
                    </form>
                </li>
                <?php endif; ?>
            </ul>
        </div>
        <div class="flex-grow-1">
            <h2 class="fw-bold mb-1" style="font-size:1.4rem;"><?= htmlspecialchars($usuario['Nome']) ?></h2>
            <div class="text-secondary small mb-2"><?= htmlspecialchars($usuario['Email']) ?></div>
            <div class="d-flex align-items-center gap-2 flex-wrap">
                <?php if ($plano === 'vip'): ?>
                    <span class="badge" style="background:#d4af3722;color:#d4af37;border:1px solid #d4af3755;font-size:0.72rem;">
                        <i class="bi bi-gem me-1"></i>VIP
                    </span>
                <?php elseif ($plano === 'pro'): ?>
                    <span class="badge" style="background:#7c3aed22;color:#a78bfa;border:1px solid #7c3aed55;font-size:0.72rem;">
                        <i class="bi bi-crown-fill me-1"></i>PRO
                    </span>
                <?php else: ?>
                    <span class="badge" style="background:#ffffff11;color:#9ca3af;border:1px solid #ffffff22;font-size:0.72rem;">FREE</span>
                <?php endif; ?>
                <span class="text-muted small">
                    <i class="bi bi-calendar3 me-1"></i>Membro desde <?= $dataMembro ?>
                </span>
                <span class="text-muted small">
                    <i class="bi bi-clock-history me-1"></i><?= $diasAtivo ?> dia<?= $diasAtivo !== 1 ? 's' : '' ?> no Auralis
                </span>
            </div>

            <!-- 3 espaços fixos de insígnia em destaque — clique numa conquista lá embaixo
                 e escolha um espaço pra colocá-la aqui. -->
            <div class="d-flex align-items-center gap-2 mt-2">
                <?php foreach ($insigniasDestaque as $_insigniaId):
                    $_confInsignia = $_insigniaId ? ($conquistasPorId[$_insigniaId] ?? null) : null;
                ?>
                    <?php if ($_confInsignia): ?>
                        <div class="conquista-icon-wrap badge-<?= htmlspecialchars($_confInsignia['Raridade'] ?? 'comum') ?>"
                             style="width:42px;height:42px;font-size:1rem;" title="<?= htmlspecialchars($_confInsignia['Nome']) ?>">
                            <?php if (!empty($_confInsignia['ImagemUrl'])): ?>
                                <img src="<?= htmlspecialchars($_confInsignia['ImagemUrl']) ?>" alt="<?= htmlspecialchars($_confInsignia['Nome']) ?>" style="width:78%;height:78%;object-fit:contain;border-radius:50%;">
                            <?php else: ?>
                                <i class="bi <?= htmlspecialchars($_confInsignia['Icone']) ?>" style="color:<?= htmlspecialchars($_confInsignia['Cor']) ?>;font-size:0.95rem;"></i>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <a href="#listaConquistas" class="d-flex align-items-center justify-content-center rounded-circle text-decoration-none flex-shrink-0"
                           style="width:42px;height:42px;border:1.5px dashed var(--bs-border-color);color:var(--text-muted);" title="Escolher insígnia">
                            <i class="bi bi-plus" style="font-size:1.1rem;"></i>
                        </a>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        </div>
        <a href="/configuracoes.php" class="btn btn-sm btn-outline-secondary rounded-pill">
            <i class="bi bi-gear me-1"></i> Configurações
        </a>
    </div>

    <!-- Chave pessoal única — mesmo código do "link de indicação" em Configurações, só que
         em formato de código pra colar em outros lugares (convite de carteira compartilhada
         hoje; indicar amigo/revendedor e, no futuro, amizades usam essa mesma chave). Visual
         azul de propósito, diferente do dourado usado no widget de indicação. -->
    <div class="rounded-4 p-4 mb-4" style="background:rgba(96,165,250,.05);border:1px solid rgba(96,165,250,.18);">
        <div class="d-flex align-items-center gap-3 mb-3 flex-wrap">
            <div class="rounded-3 d-flex align-items-center justify-content-center flex-shrink-0"
                style="width:40px;height:40px;background:rgba(96,165,250,.12);border:1px solid rgba(96,165,250,.25);">
                <i class="bi bi-key-fill" style="color:#60a5fa;font-size:1.1rem;"></i>
            </div>
            <div>
                <div class="fw-semibold text-light">Sua chave pessoal</div>
                <div class="text-secondary" style="font-size:.78rem;">Use pra convidar alguém pra uma carteira compartilhada ou pra indicar o Auralis — é o mesmo código.</div>
            </div>
        </div>
        <div class="d-flex align-items-center gap-2 flex-wrap">
            <code id="pfCodigoConvite" class="px-3 py-2 rounded-3 flex-grow-1"
                style="background:rgba(0,0,0,.3);color:#60a5fa;font-size:.95rem;font-weight:700;letter-spacing:.05em;display:block;">
                <?= htmlspecialchars($codigoPessoal) ?>
            </code>
            <button onclick="pfCopiarCodigoConvite()" id="pfBtnCopiarConvite"
                class="btn btn-sm rounded-pill px-3 flex-shrink-0"
                style="background:rgba(96,165,250,.15);color:#60a5fa;border:1px solid rgba(96,165,250,.3);">
                <i class="bi bi-clipboard me-1"></i> Copiar código
            </button>
            <button onclick="pfCompartilharCodigoConvite()" id="pfBtnCompartilharConvite"
                class="btn btn-sm rounded-pill px-3 flex-shrink-0 d-none"
                style="background:rgba(96,165,250,.15);color:#60a5fa;border:1px solid rgba(96,165,250,.3);">
                <i class="bi bi-share-fill me-1"></i> Compartilhar
            </button>
        </div>
    </div>

    <!-- Stats -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-4">
            <div class="stat-card">
                <div class="stat-value" style="color:var(--accent);"><?= number_format((int)$stats['transacoes']) ?></div>
                <div class="stat-label"><i class="bi bi-receipt me-1"></i>Transações</div>
            </div>
        </div>
        <div class="col-6 col-md-4">
            <div class="stat-card">
                <div class="stat-value" style="color:#22c55e;"><?= (int)$stats['carteiras'] ?></div>
                <div class="stat-label"><i class="bi bi-wallet2 me-1"></i>Carteiras</div>
            </div>
        </div>
        <div class="col-6 col-md-4">
            <div class="stat-card">
                <div class="stat-value" style="color:#06b6d4;"><?= (int)$stats['categorias'] ?></div>
                <div class="stat-label"><i class="bi bi-tag me-1"></i>Categorias</div>
            </div>
        </div>
    </div>

    <!-- ── Amigos ──────────────────────────────────────────────────────────── -->
    <div id="amigos" class="mb-4">
        <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
            <h5 class="fw-bold mb-0"><i class="bi bi-people-fill me-2" style="color:#60a5fa;"></i>Amigos</h5>
            <span class="text-muted small"><?= count($amigosAceitos) ?> amigo<?= count($amigosAceitos) !== 1 ? 's' : '' ?></span>
        </div>

        <?php if (!empty($r_amigosComum)): ?>
        <button type="button" class="btn btn-sm w-100 mb-3 rounded-4 d-flex align-items-center gap-2 py-2 px-3"
            style="background:rgba(167,139,250,.08);border:1px solid rgba(167,139,250,.25);color:#a78bfa;"
            data-bs-toggle="modal" data-bs-target="#modalAmigoDoMeuAmigo">
            <i class="bi bi-people-fill"></i>
            <span class="fw-semibold flex-grow-1 text-start">Amigo do Meu Amigo</span>
            <span class="badge rounded-pill" style="background:#a78bfa22;color:#a78bfa;"><?= count($r_amigosComum) ?></span>
            <i class="bi bi-chevron-right small"></i>
        </button>
        <?php endif; ?>

        <?php if (!empty($pedidosRecebidos)): ?>
        <div class="rounded-4 p-3 mb-3" style="background:rgba(96,165,250,.05);border:1px solid rgba(96,165,250,.18);">
            <p class="text-secondary small mb-2 fw-semibold">Pedidos de amizade</p>
            <div class="d-flex flex-column gap-2">
                <?php foreach ($pedidosRecebidos as $p): ?>
                <div class="d-flex align-items-center gap-2" id="pedidoAmizade_<?= htmlspecialchars($p['IDAmizade']) ?>">
                    <?= renderAvatarUsuario($p, 36) ?>
                    <span class="flex-grow-1 fw-semibold" style="font-size:.88rem;"><?= htmlspecialchars(explode(' ', $p['Nome'])[0]) ?></span>
                    <button type="button" class="btn btn-sm rounded-pill px-3" style="background:var(--accent);color:#000;font-weight:600;"
                        onclick="perfilResponderPedido('<?= htmlspecialchars($p['IDAmizade'], ENT_QUOTES) ?>','aceitar')">Aceitar</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill px-3"
                        onclick="perfilResponderPedido('<?= htmlspecialchars($p['IDAmizade'], ENT_QUOTES) ?>','recusar')">Recusar</button>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if (empty($amigosAceitos)): ?>
        <div class="text-center py-4 text-muted rounded-4" style="background:var(--bg-card);border:1px solid var(--bs-border-color);">
            <i class="bi bi-people" style="font-size:1.8rem;opacity:.3;display:block;margin-bottom:.5rem;"></i>
            <p class="mb-0 small">Nenhum amigo ainda — clique em alguém no Ranking pra adicionar.</p>
        </div>
        <?php else: ?>
        <div class="rounded-4 overflow-hidden" style="background:var(--bg-card);border:1px solid var(--bs-border-color);">
            <?php foreach ($amigosAceitos as $i => $a): ?>
            <div class="d-flex align-items-center gap-2 px-3 py-2 amigo-linha" style="<?= $i > 0 ? 'border-top:1px solid var(--bs-border-color);' : '' ?>"
                 oncontextmenu="return perfilAbrirMenuAmigo(event, '<?= htmlspecialchars($a['IDUsuario'], ENT_QUOTES) ?>', '<?= htmlspecialchars(addslashes($a['Nome']), ENT_QUOTES) ?>')">
                <?= renderAvatarUsuario($a, 34) ?>
                <span class="fw-semibold flex-grow-1" style="font-size:.85rem;"><?= htmlspecialchars($a['Nome']) ?></span>
                <button type="button" class="btn btn-sm btn-link text-secondary p-1" title="Mais opções"
                        onclick="perfilAbrirMenuAmigo(event, '<?= htmlspecialchars($a['IDUsuario'], ENT_QUOTES) ?>', '<?= htmlspecialchars(addslashes($a['Nome']), ENT_QUOTES) ?>')">
                    <i class="bi bi-three-dots-vertical"></i>
                </button>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Menu de contexto do amigo (botão direito ou "⋮") -->
        <div id="menuAmigoContexto" class="d-none rounded-3 shadow-lg overflow-hidden"
             style="position:fixed;z-index:2000;background:var(--bg-card);border:1px solid var(--bs-border-color);min-width:180px;">
            <button type="button" class="dropdown-item d-flex align-items-center gap-2 py-2 px-3 text-danger w-100 border-0 bg-transparent text-start"
                    onclick="perfilRemoverAmigo()">
                <i class="bi bi-person-dash-fill"></i> Remover amigo
            </button>
        </div>
    </div>

    <!-- ── Modal: Amigo do Meu Amigo — sugestões por amizade em comum ────────── -->
    <div class="modal fade" id="modalAmigoDoMeuAmigo" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-secondary-subtle shadow-lg rounded-4" style="background:var(--bg-card);">
                <div class="modal-header border-bottom border-secondary-subtle">
                    <div>
                        <h6 class="modal-title fw-bold text-light mb-0"><i class="bi bi-people-fill me-2" style="color:#a78bfa;"></i>Amigo do Meu Amigo</h6>
                        <p class="text-secondary mb-0" style="font-size:.78rem;">Essa galera já é praticamente sua também</p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-3" style="max-height:65vh;overflow-y:auto;">
                    <?php if (empty($r_amigosComum)): ?>
                        <div class="text-center py-4 text-secondary">
                            <i class="bi bi-people fs-1 opacity-25 d-block mb-3" style="color:#a78bfa;"></i>
                            <p class="mb-0 small">Ninguém em comum por enquanto — adicione alguns amigos e a galera deles vai aparecer aqui.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($r_amigosComum as $i => $entry): ?>
                        <div class="d-flex align-items-center gap-2 py-2" id="sugestaoAmigo_<?= htmlspecialchars($entry['IDUsuario']) ?>"
                             style="<?= $i > 0 ? 'border-top:1px solid var(--bs-border-color);' : '' ?>">
                            <?= renderAvatarUsuario($entry, 40) ?>
                            <div class="flex-grow-1 min-w-0">
                                <div class="fw-semibold text-truncate" style="font-size:.88rem;"><?= htmlspecialchars(explode(' ', $entry['Nome'])[0]) ?></div>
                                <div class="text-secondary" style="font-size:.72rem;">
                                    <?= (int)$entry['total'] ?> amigo<?= (int)$entry['total'] > 1 ? 's' : '' ?> em comum
                                </div>
                            </div>
                            <button type="button" class="btn btn-sm rounded-pill px-3 flex-shrink-0"
                                style="background:var(--accent);color:#000;font-weight:600;"
                                onclick="perfilAdicionarSugerido('<?= htmlspecialchars($entry['IDUsuario'], ENT_QUOTES) ?>', this)">
                                Adicionar
                            </button>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- ── Meu Personagem ─────────────────────────────────────────────────────
         Some da tela principal assim que já existe um personagem salvo — quem quiser
         editar clica na imagem lá em cima ("Editar personagem"), que revela essa seção
         de novo. Pra quem ainda não tem nenhum (conta nova), fica visível direto, sem
         precisar descobrir onde clicar. -->
    <div id="personagem" class="mb-4<?= ($temPersonagemSalvo && !$_forcarMostrarPersonagem) ? ' d-none' : '' ?>">
        <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
            <h5 class="fw-bold mb-0"><i class="bi bi-person-bounding-box me-2" style="color:#ec4899;"></i>Meu Personagem</h5>
            <?php if ($temPersonagemSalvo): ?>
            <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill" onclick="esconderEditorPersonagem()">
                <i class="bi bi-x-lg me-1"></i> Fechar
            </button>
            <?php endif; ?>
        </div>

        <div class="row g-0 rounded-4 overflow-hidden" style="background:var(--bg-card);border:1px solid var(--bs-border-color);">

            <!-- Preview -->
            <div class="col-12 col-md-4 d-flex flex-column align-items-center justify-content-center p-4 gap-3"
                 style="border-right:1px solid var(--bs-border-color);">
                <div id="avatarWrapper"
                     style="width:260px;height:260px;border-radius:50%;overflow:hidden;border:4px solid var(--card-border-color);background:#<?= htmlspecialchars($cfg['backgroundColor'] !== 'transparent' ? $cfg['backgroundColor'] : '00000000') ?>;">
                    <img id="avatarPreview"
                         src="<?= htmlspecialchars($avatarPreviewUrl ?: getAvatarUrl($cfg)) ?>"
                         alt="Seu personagem" style="width:100%;height:100%;object-fit:cover;">
                </div>
                <form method="POST" action="/usuario/processa_avatar.php" id="avatarForm" class="w-100" style="max-width:260px;">
                    <input type="hidden" name="skinColor"       id="f_skinColor">
                    <input type="hidden" name="hair"            id="f_hair">
                    <input type="hidden" name="hairColor"       id="f_hairColor">
                    <input type="hidden" name="eyes"            id="f_eyes">
                    <input type="hidden" name="eyebrows"        id="f_eyebrows">
                    <input type="hidden" name="mouth"           id="f_mouth">
                    <input type="hidden" name="clothing"        id="f_clothing">
                    <input type="hidden" name="clothingColor"   id="f_clothingColor">
                    <input type="hidden" name="accessories"     id="f_accessories">
                    <input type="hidden" name="facialHair"      id="f_facialHair">
                    <input type="hidden" name="facialHairColor" id="f_facialHairColor">
                    <input type="hidden" name="backgroundColor" id="f_backgroundColor">
                    <button type="submit" class="btn w-100 fw-bold rounded-pill py-2"
                            style="background:var(--accent);color:#fff;">
                        <i class="bi bi-save me-1"></i> Salvar personagem
                    </button>
                </form>
            </div>

            <!-- Controles (scrollável) -->
            <div class="col-12 col-md-8 p-4" style="max-height:520px;overflow-y:auto;">

                <!-- Pele -->
                <div class="mb-4">
                    <label class="av-label">TOM DE PELE</label>
                    <div class="d-flex flex-wrap gap-2">
                        <?php foreach (['614335','ae5d29','d08b5b','edb98a','f8d25c','ffdbb4','fd9841','ffffff'] as $c): ?>
                        <button type="button" class="swatch-btn" data-key="skinColor" data-val="<?= $c ?>" style="background:#<?= $c ?>;"></button>
                        <?php endforeach; ?>
                    </div>
                </div>
                <hr class="av-sep">

                <!-- Cabelo -->
                <div class="mb-4">
                    <label class="av-label">CABELO</label>
                    <div class="cycle-row">
                        <button type="button" class="cycle-btn" id="hair_prev"><i class="bi bi-chevron-left"></i></button>
                        <span class="cycle-label" id="hair_label">–</span>
                        <button type="button" class="cycle-btn" id="hair_next"><i class="bi bi-chevron-right"></i></button>
                    </div>
                </div>

                <div class="mb-4" id="row_hairColor">
                    <label class="av-label">COR DO CABELO</label>
                    <div class="d-flex flex-wrap gap-2">
                        <?php foreach (['2c1b18','4a312c','b58143','c93305','e8e1e1','f59797','724133','a55728','d6b370'] as $c): ?>
                        <button type="button" class="swatch-btn" data-key="hairColor" data-val="<?= $c ?>" style="background:#<?= $c ?>;"></button>
                        <?php endforeach; ?>
                    </div>
                </div>
                <hr class="av-sep">

                <!-- Olhos -->
                <div class="mb-4">
                    <label class="av-label">OLHOS</label>
                    <div class="cycle-row">
                        <button type="button" class="cycle-btn" id="eyes_prev"><i class="bi bi-chevron-left"></i></button>
                        <span class="cycle-label" id="eyes_label">–</span>
                        <button type="button" class="cycle-btn" id="eyes_next"><i class="bi bi-chevron-right"></i></button>
                    </div>
                </div>

                <div class="mb-4">
                    <label class="av-label">SOBRANCELHAS</label>
                    <div class="cycle-row">
                        <button type="button" class="cycle-btn" id="eyebrows_prev"><i class="bi bi-chevron-left"></i></button>
                        <span class="cycle-label" id="eyebrows_label">–</span>
                        <button type="button" class="cycle-btn" id="eyebrows_next"><i class="bi bi-chevron-right"></i></button>
                    </div>
                </div>

                <div class="mb-4">
                    <label class="av-label">BOCA</label>
                    <div class="cycle-row">
                        <button type="button" class="cycle-btn" id="mouth_prev"><i class="bi bi-chevron-left"></i></button>
                        <span class="cycle-label" id="mouth_label">–</span>
                        <button type="button" class="cycle-btn" id="mouth_next"><i class="bi bi-chevron-right"></i></button>
                    </div>
                </div>
                <hr class="av-sep">

                <!-- Roupa -->
                <div class="mb-4">
                    <label class="av-label">ROUPA</label>
                    <div class="cycle-row">
                        <button type="button" class="cycle-btn" id="clothing_prev"><i class="bi bi-chevron-left"></i></button>
                        <span class="cycle-label" id="clothing_label">–</span>
                        <button type="button" class="cycle-btn" id="clothing_next"><i class="bi bi-chevron-right"></i></button>
                    </div>
                </div>

                <div class="mb-4">
                    <label class="av-label">COR DA ROUPA</label>
                    <div class="d-flex flex-wrap gap-2">
                        <?php foreach (['262e33','3c4f5c','65c9ff','929598','a7ffc4','b1e2ff','e6e6e6','ff5c5c','ff488e','ffafb9','ffd670','ffffed'] as $c): ?>
                        <button type="button" class="swatch-btn" data-key="clothingColor" data-val="<?= $c ?>" style="background:#<?= $c ?>;"></button>
                        <?php endforeach; ?>
                    </div>
                </div>
                <hr class="av-sep">

                <!-- Acessórios -->
                <div class="mb-4">
                    <label class="av-label">ACESSÓRIOS</label>
                    <div class="d-flex flex-wrap gap-2">
                        <?php foreach (['' => 'Nenhum','prescription01' => 'Óculos 1','prescription02' => 'Óculos 2','round' => 'Redondo','sunglasses' => 'Sol','kurt' => 'Kurt','wayfarers' => 'Wayfarer'] as $v => $lbl): ?>
                        <button type="button" class="chip-btn" data-key="accessories" data-val="<?= htmlspecialchars($v) ?>"><?= htmlspecialchars($lbl) ?></button>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Barba -->
                <div class="mb-4">
                    <label class="av-label">BARBA / BIGODE</label>
                    <div class="d-flex flex-wrap gap-2">
                        <?php foreach (['' => 'Nenhuma','beardLight' => 'Leve','beardMedium' => 'Média','beardMajestic' => 'Cheia','moustacheFancy' => 'Bigode','moustacheMagnum' => 'Big Bigode'] as $v => $lbl): ?>
                        <button type="button" class="chip-btn" data-key="facialHair" data-val="<?= htmlspecialchars($v) ?>"><?= htmlspecialchars($lbl) ?></button>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="mb-4" id="row_facialHairColor" style="display:none;">
                    <label class="av-label">COR DA BARBA</label>
                    <div class="d-flex flex-wrap gap-2">
                        <?php foreach (['2c1b18','4a312c','b58143','c93305','e8e1e1','f59797','724133','a55728','d6b370'] as $c): ?>
                        <button type="button" class="swatch-btn" data-key="facialHairColor" data-val="<?= $c ?>" style="background:#<?= $c ?>;"></button>
                        <?php endforeach; ?>
                    </div>
                </div>
                <hr class="av-sep">

                <!-- Fundo -->
                <div class="mb-2">
                    <label class="av-label">FUNDO</label>
                    <div class="d-flex flex-wrap gap-2">
                        <?php foreach (['b6e3f4','c0aede','d1d4f9','ffd5dc','ffeba4','transparent'] as $c): ?>
                        <button type="button"
                                class="swatch-btn <?= $c === 'transparent' ? 'swatch-transparent' : '' ?>"
                                data-key="backgroundColor" data-val="<?= $c ?>"
                                style="<?= $c !== 'transparent' ? "background:#{$c};" : '' ?>"></button>
                        <?php endforeach; ?>
                    </div>
                </div>

            </div><!-- /controles -->
        </div>
    </div>

    <!-- ── Modal: colocar foto real de perfil ───────────────────────────────
         Separado do editor de personagem de propósito — o personagem nunca é apagado
         por enviar uma foto, só perde a preferência de exibição enquanto ela existir. -->
    <div class="modal fade" id="modalFotoPerfil" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-secondary-subtle shadow-lg rounded-4" style="background:var(--bg-card);">
                <form method="POST" action="/usuario/processa_foto_perfil.php" enctype="multipart/form-data">
                    <div class="modal-header border-bottom border-secondary-subtle">
                        <h6 class="modal-title fw-bold text-light mb-0"><i class="bi bi-image me-2" style="color:#60a5fa;"></i>Colocar foto</h6>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body p-4">
                        <p class="text-secondary small mb-3">JPG, PNG ou WebP, até 5 MB. Seu personagem continua salvo — se remover a foto depois, ele volta a aparecer.</p>
                        <input type="file" name="foto" accept="image/jpeg,image/png,image/webp" class="form-control bg-transparent text-light border-secondary" required>
                    </div>
                    <div class="modal-footer border-top border-secondary-subtle">
                        <button type="button" class="btn btn-link text-secondary text-decoration-none" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn fw-bold rounded-pill px-4" style="background:#60a5fa;color:#06111f;">
                            <i class="bi bi-upload me-1"></i> Enviar
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- ── Modal: detalhe da conquista (abre ao clicar num card) ────────────
         Também é daqui que se escolhe em qual dos 3 espaços de destaque colocar uma
         conquista já desbloqueada. -->
    <div class="modal fade" id="modalDetalheConquista" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-secondary-subtle shadow-lg rounded-4 text-center" style="background:var(--bg-card);">
                <div class="modal-header border-0 pb-0">
                    <button type="button" class="btn-close ms-auto" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body px-4 pb-4 pt-0 d-flex flex-column align-items-center">
                    <div id="detConquistaIconWrap" class="conquista-icon-wrap mb-3" style="width:110px;height:110px;font-size:2.75rem;">
                        <i id="detConquistaIcone"></i>
                        <img id="detConquistaImagem" style="display:none;width:78%;height:78%;object-fit:contain;border-radius:50%;">
                    </div>
                    <h5 class="fw-bold text-light mb-1" id="detConquistaNome"></h5>
                    <p class="text-secondary mb-3" id="detConquistaDesc" style="max-width:320px;"></p>
                    <div class="d-flex align-items-center gap-2 mb-2">
                        <span class="raridade-pill" id="detConquistaRaridade"></span>
                        <span class="text-muted small" id="detConquistaStatus"></span>
                    </div>
                    <div id="detConquistaInsignias" class="d-none w-100 mt-2 pt-3" style="border-top:1px solid var(--bs-border-color);">
                        <p class="text-secondary small mb-2">Destacar essa conquista no perfil:</p>
                        <div class="d-flex justify-content-center gap-2" id="detConquistaSlots"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <form method="POST" action="/usuario/processa_insignias.php" id="formInsignia" class="d-none">
        <input type="hidden" name="slot" id="insigniaSlot">
        <input type="hidden" name="conquista_id" id="insigniaConquistaId">
    </form>

    <!-- Conquistas -->
    <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
        <div>
            <h5 class="fw-bold mb-0"><i class="bi bi-trophy-fill me-2" style="color:var(--accent);"></i>Conquistas</h5>
            <div class="text-muted small mt-1">
                <?= $totalDesbloqueadas ?> de <?= $totalConquistas ?> desbloqueada<?= $totalDesbloqueadas !== 1 ? 's' : '' ?>
            </div>
        </div>
        <?php if ($totalConquistas > 0): ?>
        <div class="d-flex gap-1" id="filtroConquistas">
            <button type="button" class="conquista-filtro-pill active" data-filtro="tenho">Tenho</button>
            <button type="button" class="conquista-filtro-pill" data-filtro="todas">Todas</button>
            <button type="button" class="conquista-filtro-pill" data-filtro="nao-tenho">Não tenho</button>
        </div>
        <?php endif; ?>
    </div>

    <?php if (empty($conquistas)): ?>
    <div class="text-center py-5 text-muted">
        <i class="bi bi-trophy" style="font-size:2.5rem;opacity:.3;display:block;margin-bottom:1rem;"></i>
        <p class="mb-0">Nenhuma conquista cadastrada ainda.</p>
    </div>
    <?php else: ?>
    <div class="row g-3" id="listaConquistas">
        <?php foreach ($conquistas as $c):
            $desbloqueada = $c['DataConquista'] !== null;
            $raridade     = $raridadeInfo[$c['Raridade']] ?? $raridadeInfo['comum'];

            // Data relativa de desbloqueio
            $dataDesbloq = '';
            if ($desbloqueada) {
                $diff = (new DateTime())->diff(new DateTime($c['DataConquista']));
                if ($diff->days === 0)      $dataDesbloq = 'hoje';
                elseif ($diff->days === 1)  $dataDesbloq = 'ontem';
                elseif ($diff->days < 30)   $dataDesbloq = "há {$diff->days} dias";
                elseif ($diff->days < 365)  $dataDesbloq = 'há ' . (int)($diff->days / 30) . ' mês' . ((int)($diff->days / 30) > 1 ? 'es' : '');
                else                        $dataDesbloq = 'há ' . (int)($diff->days / 365) . ' ano' . ((int)($diff->days / 365) > 1 ? 's' : '');
            }
        ?>
        <div class="col-12 col-md-6 item-conquista" data-desbloqueada="<?= $desbloqueada ? '1' : '0' ?>">
            <div class="conquista-card <?= $desbloqueada ? '' : 'bloqueada' ?>"
                 onclick='abrirDetalheConquista(<?= htmlspecialchars(json_encode([
                     "id"            => $c["IDConquista"],
                     "nome"          => $c["Nome"],
                     "descricao"     => $c["Descricao"],
                     "icone"         => $c["Icone"],
                     "imagem"        => $c["ImagemUrl"],
                     "cor"           => $c["Cor"],
                     "raridade"      => $c["Raridade"] ?? "comum",
                     "raridadeLabel" => $raridade["label"],
                     "raridadeCor"   => $raridade["cor"],
                     "desbloqueada"  => $desbloqueada,
                     "dataTexto"     => $dataDesbloq,
                 ]), ENT_QUOTES) ?>)'>
                <!-- Ícone -->
                <div class="conquista-icon-wrap badge-<?= htmlspecialchars($c['Raridade'] ?? 'comum') ?>">
                    <?php if (!$desbloqueada): ?>
                        <i class="bi bi-lock-fill" style="color:rgba(156,163,175,0.5);font-size:1.4rem;"></i>
                    <?php elseif (!empty($c['ImagemUrl'] ?? '')): ?>
                        <img src="<?= htmlspecialchars($c['ImagemUrl']) ?>" alt="<?= htmlspecialchars($c['Nome']) ?>" style="width:78%;height:78%;object-fit:contain;border-radius:50%;">
                    <?php else: ?>
                        <i class="bi <?= htmlspecialchars($c['Icone']) ?>" style="color:<?= htmlspecialchars($c['Cor']) ?>;"></i>
                    <?php endif; ?>
                </div>

                <!-- Info -->
                <div class="flex-grow-1 min-w-0">
                    <div class="conquista-nome"><?= htmlspecialchars($c['Nome']) ?></div>
                    <div class="conquista-desc"><?= htmlspecialchars($c['Descricao']) ?></div>
                    <div class="conquista-footer d-flex align-items-center gap-2 flex-wrap">
                        <span class="raridade-pill"
                              style="background:<?= $raridade['cor'] ?>22;color:<?= $raridade['cor'] ?>;border:1px solid <?= $raridade['cor'] ?>44;">
                            <?= $raridade['label'] ?>
                        </span>
                        <?php if ($desbloqueada): ?>
                            <span class="text-muted"><i class="bi bi-check2-circle me-1" style="color:<?= htmlspecialchars($c['Cor']) ?>;"></i><?= $dataDesbloq ?></span>
                        <?php else: ?>
                            <span class="text-muted"><i class="bi bi-lock me-1"></i>Bloqueada</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

</main>

<style>
.av-label { display:block; font-size:0.75rem; font-weight:700; letter-spacing:.08em; color:var(--text-secondary); margin-bottom:8px; }
.av-sep   { border-color:var(--card-border-color); margin:0 0 1.25rem; }
.swatch-btn {
    width:34px; height:34px; border-radius:50%;
    border:3px solid transparent; cursor:pointer; flex-shrink:0;
    transition:transform .15s, border-color .15s;
}
.swatch-btn:hover  { transform:scale(1.15); }
.swatch-btn.active { border-color:var(--accent); transform:scale(1.18); }
.swatch-transparent {
    background: repeating-conic-gradient(#888 0% 25%, #fff 0% 50%) 0 0 / 10px 10px !important;
}
.cycle-row  { display:flex; align-items:center; gap:10px; }
.cycle-btn  {
    background:var(--bg-main); border:1px solid var(--card-border-color);
    color:var(--text-main); border-radius:8px;
    width:34px; height:34px; display:flex; align-items:center; justify-content:center;
    cursor:pointer; flex-shrink:0; transition:background .15s;
}
.cycle-btn:hover { background:var(--card-border-color); }
.cycle-label {
    flex:1; text-align:center; font-size:0.85rem; font-weight:600; color:var(--text-main);
    background:var(--bg-main); border:1px solid var(--card-border-color);
    border-radius:8px; padding:5px 10px;
}
.chip-btn {
    background:var(--bg-main); border:1px solid var(--card-border-color);
    color:var(--text-main); border-radius:999px; padding:3px 12px;
    font-size:0.8rem; cursor:pointer; transition:background .15s, border-color .15s, color .15s;
}
.chip-btn:hover  { border-color:var(--accent); }
.chip-btn.active { background:var(--accent); border-color:var(--accent); color:#fff; font-weight:600; }

.conquista-filtro-pill {
    background:transparent; border:1px solid var(--card-border-color);
    color:var(--text-secondary); border-radius:999px; padding:4px 12px;
    font-size:0.72rem; font-weight:600; cursor:pointer; white-space:nowrap;
    transition:background .15s, border-color .15s, color .15s;
}
.conquista-filtro-pill:hover  { border-color:var(--accent); color:var(--text-main); }
.conquista-filtro-pill.active { background:var(--accent); border-color:var(--accent); color:#fff; }
</style>

<script>
(function () {
    var cfg = <?= json_encode($cfg) ?>;

    var OPTS = {
        hair: [
            {v:'shortCurly',           l:'Cacheado curto'},
            {v:'shortFlat',            l:'Liso curto'},
            {v:'shortWaved',           l:'Ondulado curto'},
            {v:'shortRound',           l:'Arredondado curto'},
            {v:'sides',                l:'Raspado nas laterais'},
            {v:'theCaesar',            l:'César'},
            {v:'theCaesarAndSidePart', l:'César com repartido'},
            {v:'frizzle',              l:'Frizzy'},
            {v:'shaggy',               l:'Despenteado'},
            {v:'shaggyMullet',         l:'Mullet'},
            {v:'dreads01',             l:'Dreads 1'},
            {v:'dreads02',             l:'Dreads 2'},
            {v:'bob',                  l:'Bob'},
            {v:'bun',                  l:'Coque'},
            {v:'curly',                l:'Cacheado longo'},
            {v:'curvy',                l:'Ondulado longo'},
            {v:'straight01',           l:'Liso longo'},
            {v:'straight02',           l:'Liso longo 2'},
            {v:'straightAndStrand',    l:'Liso com mecha'},
            {v:'fro',                  l:'Afro'},
            {v:'froBand',              l:'Afro com faixa'},
            {v:'bigHair',              l:'Volume'},
            {v:'dreads',               l:'Dreads longos'},
            {v:'frida',                l:'Frida'},
            {v:'miaWallace',           l:'Mia Wallace'},
            {v:'longButNotTooLong',    l:'Médio'},
            {v:'shavedSides',          l:'Raspado com comprido'},
            {v:'hat',                  l:'Boné'},
            {v:'hijab',                l:'Hijab'},
            {v:'turban',               l:'Turbante'},
            {v:'winterHat1',           l:'Touca 1'},
            {v:'winterHat02',          l:'Touca 2'},
            {v:'winterHat03',          l:'Touca 3'},
            {v:'winterHat04',          l:'Touca 4'},
        ],
        eyes: [
            {v:'default',   l:'Normal'},
            {v:'happy',     l:'Feliz'},
            {v:'wink',      l:'Piscada'},
            {v:'hearts',    l:'Coração'},
            {v:'squint',    l:'Semicerrado'},
            {v:'surprised', l:'Surpreso'},
            {v:'side',      l:'De lado'},
            {v:'closed',    l:'Fechado'},
            {v:'cry',       l:'Chorando'},
            {v:'xDizzy',    l:'Tonto'},
            {v:'eyeRoll',   l:'Revirado'},
            {v:'winkWacky', l:'Piscada louca'},
        ],
        eyebrows: [
            {v:'default',              l:'Normal'},
            {v:'defaultNatural',       l:'Natural'},
            {v:'raisedExcited',        l:'Levantado'},
            {v:'raisedExcitedNatural', l:'Levantado natural'},
            {v:'angry',                l:'Zangado'},
            {v:'angryNatural',         l:'Zangado natural'},
            {v:'upDown',               l:'Assimétrico'},
            {v:'upDownNatural',        l:'Assimétrico natural'},
            {v:'flatNatural',          l:'Plano'},
            {v:'frownNatural',         l:'Franzido'},
            {v:'sadConcerned',         l:'Triste'},
            {v:'sadConcernedNatural',  l:'Triste natural'},
            {v:'unibrowNatural',       l:'Unidos'},
        ],
        mouth: [
            {v:'smile',      l:'Sorriso'},
            {v:'default',    l:'Normal'},
            {v:'serious',    l:'Sério'},
            {v:'tongue',     l:'Língua'},
            {v:'twinkle',    l:'Encantado'},
            {v:'eating',     l:'Comendo'},
            {v:'sad',        l:'Triste'},
            {v:'concerned',  l:'Preocupado'},
            {v:'grimace',    l:'Grimace'},
            {v:'screamOpen', l:'Gritando'},
            {v:'disbelief',  l:'Descrença'},
            {v:'vomit',      l:'Enjoado'},
        ],
        clothing: [
            {v:'hoodie',          l:'Moletom'},
            {v:'shirtCrewNeck',   l:'Camiseta'},
            {v:'shirtVNeck',      l:'Camiseta V'},
            {v:'shirtScoopNeck',  l:'Decote oval'},
            {v:'blazerAndShirt',  l:'Blazer + Camisa'},
            {v:'blazerAndSweater',l:'Blazer + Suéter'},
            {v:'collarAndSweater',l:'Suéter'},
            {v:'overall',         l:'Macacão'},
            {v:'graphicShirt',    l:'Camiseta Estampada'},
        ],
    };

    var NO_HAIR_COLOR = ['noHair','hat','hijab','turban','winterHat1','winterHat2','winterHat3','winterHat4','eyepatch'];

    function idxOf(arr, val) {
        for (var i = 0; i < arr.length; i++) if (arr[i].v === val) return i;
        return 0;
    }

    function buildUrl() {
        var p = [];
        p.push('skinColor[]='    + cfg.skinColor);
        if (cfg.hair) p.push('top[]=' + cfg.hair);
        p.push('hairColor[]='    + cfg.hairColor);
        p.push('eyes[]='         + cfg.eyes);
        p.push('eyebrows[]='     + cfg.eyebrows);
        p.push('mouth[]='        + cfg.mouth);
        p.push('clothing[]='     + cfg.clothing);
        p.push('clothesColor[]=' + cfg.clothingColor);
        if (cfg.accessories) {
            p.push('accessories[]='         + cfg.accessories);
            p.push('accessoriesProbability=100');
        } else {
            p.push('accessoriesProbability=0');
        }
        if (cfg.facialHair) {
            p.push('facialHair[]='          + cfg.facialHair);
            p.push('facialHairColor[]='     + cfg.facialHairColor);
            p.push('facialHairProbability=100');
        } else {
            p.push('facialHairProbability=0');
        }
        if (cfg.backgroundColor !== 'transparent') {
            p.push('backgroundColor[]=' + cfg.backgroundColor);
        }
        return 'https://api.dicebear.com/9.x/avataaars/svg?' + p.join('&');
    }

    function sync() {
        // Preview
        document.getElementById('avatarPreview').src = buildUrl();
        var bg = cfg.backgroundColor === 'transparent' ? 'transparent' : '#' + cfg.backgroundColor;
        document.getElementById('avatarWrapper').style.background = bg;

        // Hidden inputs
        ['skinColor','hair','hairColor','eyes','eyebrows','mouth','clothing','clothingColor',
         'accessories','facialHair','facialHairColor','backgroundColor'].forEach(function(k) {
            var el = document.getElementById('f_' + k);
            if (el) el.value = cfg[k] || '';
        });

        // Mostrar/ocultar cor do cabelo
        var rHair = document.getElementById('row_hairColor');
        if (rHair) rHair.style.display = NO_HAIR_COLOR.indexOf(cfg.hair) !== -1 ? 'none' : '';

        // Mostrar/ocultar cor da barba
        var rFacial = document.getElementById('row_facialHairColor');
        if (rFacial) rFacial.style.display = cfg.facialHair ? '' : 'none';

        // Swatches
        document.querySelectorAll('.swatch-btn').forEach(function(b) {
            b.classList.toggle('active', b.dataset.val === cfg[b.dataset.key]);
        });
        document.querySelectorAll('.chip-btn').forEach(function(b) {
            b.classList.toggle('active', b.dataset.val === cfg[b.dataset.key]);
        });
    }

    // Controles de ciclo
    Object.keys(OPTS).forEach(function(name) {
        var opts   = OPTS[name];
        var label  = document.getElementById(name + '_label');
        var btnP   = document.getElementById(name + '_prev');
        var btnN   = document.getElementById(name + '_next');

        function render() {
            var i = idxOf(opts, cfg[name]);
            label.textContent = opts[i].l + ' (' + (i + 1) + '/' + opts.length + ')';
        }

        btnP.addEventListener('click', function() {
            var i = idxOf(opts, cfg[name]);
            cfg[name] = opts[(i - 1 + opts.length) % opts.length].v;
            render(); sync();
        });
        btnN.addEventListener('click', function() {
            var i = idxOf(opts, cfg[name]);
            cfg[name] = opts[(i + 1) % opts.length].v;
            render(); sync();
        });
        render();
    });

    // Swatches e chips
    document.querySelectorAll('.swatch-btn').forEach(function(b) {
        b.addEventListener('click', function() { cfg[b.dataset.key] = b.dataset.val; sync(); });
    });
    document.querySelectorAll('.chip-btn').forEach(function(b) {
        b.addEventListener('click', function() { cfg[b.dataset.key] = b.dataset.val; sync(); });
    });

    sync();
})();

// ── Filtro de Conquistas (tenho / todas / não tenho) ────────────────────────
(function () {
    var pills = document.querySelectorAll('.conquista-filtro-pill');
    var itens = document.querySelectorAll('#listaConquistas .item-conquista');
    if (!pills.length) return;

    function aplicarFiltro(filtro) {
        itens.forEach(function (item) {
            var tem = item.dataset.desbloqueada === '1';
            var mostrar = filtro === 'todas' || (filtro === 'tenho' && tem) || (filtro === 'nao-tenho' && !tem);
            item.style.display = mostrar ? '' : 'none';
        });
    }

    pills.forEach(function (pill) {
        pill.addEventListener('click', function () {
            pills.forEach(function (p) { p.classList.remove('active'); });
            pill.classList.add('active');
            aplicarFiltro(pill.dataset.filtro);
        });
    });

    aplicarFiltro('tenho'); // Estado inicial: só as que já tem
})();

// ── Modal de detalhe da conquista + 3 espaços de destaque ───────────────────
window.INSIGNIAS_ATUAIS = <?= json_encode($insigniasDestaque) ?>;

function abrirDetalheConquista(c) {
    var wrap = document.getElementById('detConquistaIconWrap');
    wrap.className = 'conquista-icon-wrap mb-3 badge-' + (c.desbloqueada ? c.raridade : 'comum');

    var icone  = document.getElementById('detConquistaIcone');
    var imagem = document.getElementById('detConquistaImagem');
    if (!c.desbloqueada) {
        icone.className = 'bi bi-lock-fill';
        icone.style.color = 'rgba(156,163,175,0.5)';
        icone.style.display = '';
        imagem.style.display = 'none';
    } else if (c.imagem) {
        imagem.src = c.imagem;
        imagem.style.display = '';
        icone.style.display = 'none';
    } else {
        icone.className = 'bi ' + c.icone;
        icone.style.color = c.cor;
        icone.style.display = '';
        imagem.style.display = 'none';
    }

    document.getElementById('detConquistaNome').textContent = c.nome;
    document.getElementById('detConquistaDesc').textContent = c.descricao;

    var pill = document.getElementById('detConquistaRaridade');
    pill.textContent = c.raridadeLabel;
    pill.style.background = c.raridadeCor + '22';
    pill.style.color = c.raridadeCor;
    pill.style.border = '1px solid ' + c.raridadeCor + '44';

    var status = document.getElementById('detConquistaStatus');
    status.innerHTML = c.desbloqueada
        ? '<i class="bi bi-check2-circle me-1" style="color:' + c.cor + ';"></i>' + c.dataTexto
        : '<i class="bi bi-lock me-1"></i>Bloqueada';

    var insigniasBox = document.getElementById('detConquistaInsignias');
    if (c.desbloqueada) {
        insigniasBox.classList.remove('d-none');
        montarSlotsInsignia(c.id);
    } else {
        insigniasBox.classList.add('d-none');
    }

    bootstrap.Modal.getOrCreateInstance(document.getElementById('modalDetalheConquista')).show();
}

function montarSlotsInsignia(conquistaId) {
    var cont = document.getElementById('detConquistaSlots');
    cont.innerHTML = '';
    for (var i = 0; i < 3; i++) {
        var jaEEssa = window.INSIGNIAS_ATUAIS[i] === conquistaId;
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'btn btn-sm rounded-pill px-3';
        btn.style.cssText = jaEEssa
            ? 'background:var(--accent);color:#000;border:1px solid var(--accent);font-weight:700;'
            : 'background:rgba(255,255,255,.05);color:var(--text-muted);border:1px solid var(--card-border-color);';
        btn.textContent = 'Espaço ' + (i + 1) + (jaEEssa ? ' ✓' : '');
        (function(slot, remover) {
            btn.onclick = function() { salvarInsignia(slot, remover ? null : conquistaId); };
        })(i, jaEEssa);
        cont.appendChild(btn);
    }
}

function salvarInsignia(slot, conquistaIdOuNull) {
    document.getElementById('insigniaSlot').value = slot;
    document.getElementById('insigniaConquistaId').value = conquistaIdOuNull || '';
    document.getElementById('formInsignia').submit();
}

// ── Menu da imagem de perfil (editar personagem / colocar foto) ─────────────
function mostrarEditorPersonagem() {
    var sec = document.getElementById('personagem');
    if (!sec) return;
    sec.classList.remove('d-none');
    sec.scrollIntoView({ behavior: 'smooth', block: 'start' });
}
function esconderEditorPersonagem() {
    var sec = document.getElementById('personagem');
    if (sec) sec.classList.add('d-none');
}

// ── Amigos: aceitar/recusar pedido direto do perfil ──────────────────────────
function perfilResponderPedido(idAmizade, acao) {
    var fd = new FormData();
    fd.append('amizade_id', idAmizade);
    fd.append('acao', acao);
    fetch('/usuario/amizade_responder.php', { method: 'POST', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.ok) location.reload();
        });
}

// ── Amigo do Meu Amigo: adicionar direto da lista de sugestões ──────────────
function perfilAdicionarSugerido(userId, btn) {
    btn.disabled = true;
    var fd = new FormData();
    fd.append('destinatario_id', userId);
    fetch('/usuario/amizade_solicitar.php', { method: 'POST', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.ok) {
                btn.textContent = data.status === 'amigos' ? 'Amigos ✓' : 'Pedido enviado';
                btn.classList.remove('rounded-pill');
                btn.classList.add('rounded-pill', 'btn-outline-secondary');
                btn.style.background = 'transparent';
                btn.style.color = '';
                btn.style.fontWeight = '';
            } else {
                btn.disabled = false;
            }
        })
        .catch(function () { btn.disabled = false; });
}

// ── Amigos: menu de contexto (botão direito ou "⋮") pra remover ─────────────
var PERFIL_AMIGO_ALVO = null;

function perfilAbrirMenuAmigo(e, uid, nome) {
    e.preventDefault();
    e.stopPropagation();
    PERFIL_AMIGO_ALVO = { uid: uid, nome: nome };

    var menu = document.getElementById('menuAmigoContexto');
    menu.classList.remove('d-none');

    var x = Math.min(e.clientX, window.innerWidth - 200);
    var y = Math.min(e.clientY, window.innerHeight - 60);
    menu.style.left = x + 'px';
    menu.style.top  = y + 'px';
    return false;
}

document.addEventListener('click', function () {
    var menu = document.getElementById('menuAmigoContexto');
    if (menu) menu.classList.add('d-none');
});

function perfilRemoverAmigo() {
    if (!PERFIL_AMIGO_ALVO) return;
    document.getElementById('menuAmigoContexto').classList.add('d-none');
    if (!confirm('Remover ' + PERFIL_AMIGO_ALVO.nome + ' da sua lista de amigos?')) return;

    var fd = new FormData();
    fd.append('amigo_id', PERFIL_AMIGO_ALVO.uid);
    fetch('/usuario/amizade_remover.php', { method: 'POST', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.ok) location.reload();
        });
}

// ── Código de convite (movido de Configurações pra cá) ───────────────────────
function pfCopiarCodigoConvite() {
    var texto = document.getElementById('pfCodigoConvite').textContent.trim();
    navigator.clipboard.writeText(texto).then(function() {
        var btn = document.getElementById('pfBtnCopiarConvite');
        var orig = btn.innerHTML;
        btn.innerHTML = '<i class="bi bi-check2 me-1"></i> Copiado!';
        setTimeout(function() { btn.innerHTML = orig; }, 2000);
    });
}
function pfCompartilharCodigoConvite() {
    var codigo = document.getElementById('pfCodigoConvite').textContent.trim();
    if (navigator.share) {
        navigator.share({ title: 'Código de convite — Auralis', text: 'Ei, meu código é ' + codigo }).catch(function() {});
    }
}
document.addEventListener('DOMContentLoaded', function() {
    if (navigator.share) {
        var btn = document.getElementById('pfBtnCompartilharConvite');
        if (btn) btn.classList.remove('d-none');
    }
});
</script>

<?php require_once 'geral/footer.php'; ?>
