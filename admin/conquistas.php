<?php
// ==============================================================================
// ADMIN/CONQUISTAS.PHP — CRUD de conquistas e insígnias
// ==============================================================================
session_start();
if (!isset($_SESSION['usuario_id'])) { header("Location: /usuario/login.php"); exit; }
require_once '../config/conexao.php';
require_once '../config/funcoes.php';

$nivelSessao = strtolower($_SESSION['nivel_acesso'] ?? '');
if (!in_array($nivelSessao, ['admin', 'supremo'])) {
    header("Location: /dashboard.php?erro=sem_permissao"); exit;
}

$sucesso = $erro = null;
$uploadDir = $_SERVER['DOCUMENT_ROOT'] . '/geral/img/conquistas/';

// Garante que o diretório de uploads existe
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

// ==============================================================================
// POST ACTIONS
// ==============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ── Criar ou editar ────────────────────────────────────────────────────────
    if ($action === 'criar' || $action === 'editar') {
        $cid      = trim($_POST['conquista_id'] ?? '');
        $slug     = preg_replace('/[^a-z0-9_]/', '', strtolower(trim($_POST['slug'] ?? '')));
        $nome     = trim($_POST['nome'] ?? '');
        $descricao= trim($_POST['descricao'] ?? '');
        $icone    = trim($_POST['icone'] ?? 'bi-trophy');
        $cor      = preg_match('/^#[0-9a-fA-F]{6}$/', $_POST['cor'] ?? '') ? $_POST['cor'] : '#d4af37';
        $raridade     = in_array($_POST['raridade'] ?? '', ['comum','incomum','raro','epico','lendario','mitico']) ? $_POST['raridade'] : 'comum';
        $tipoGatilho  = in_array($_POST['tipo_gatilho'] ?? '', ['manual','registros']) ? $_POST['tipo_gatilho'] : 'manual';
        $valorGatilho = $tipoGatilho !== 'manual' ? max(1, (int)($_POST['valor_gatilho'] ?? 1)) : null;
        $ordem        = max(1, min(255, (int)($_POST['ordem'] ?? 99)));
        $ativo        = isset($_POST['ativo']) ? 1 : 0;

        if (empty($slug) || empty($nome)) {
            $erro = "Slug e nome são obrigatórios.";
        } else {
            try {
                // ── Imagem ────────────────────────────────────────────────
                $imagemUrl = trim($_POST['imagem_url_manual'] ?? '') ?: null;

                // Upload de arquivo tem prioridade sobre URL manual
                if (!empty($_FILES['imagem_arquivo']['name'])) {
                    $file    = $_FILES['imagem_arquivo'];
                    $ext     = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                    $allowed = ['jpg','jpeg','png','gif','svg','webp'];
                    if (!in_array($ext, $allowed)) {
                        throw new RuntimeException("Formato de imagem não suportado. Use: " . implode(', ', $allowed));
                    }
                    if ($file['size'] > 2 * 1024 * 1024) {
                        throw new RuntimeException("Imagem muito grande. Máximo: 2 MB.");
                    }
                    $filename = $slug . '.' . $ext;
                    $destino  = $uploadDir . $filename;
                    if (!move_uploaded_file($file['tmp_name'], $destino)) {
                        throw new RuntimeException("Falha ao salvar o arquivo.");
                    }
                    $imagemUrl = '/geral/img/conquistas/' . $filename;
                }

                if ($action === 'criar') {
                    $pdo->prepare("
                        INSERT INTO conquista (IDConquista, Slug, Nome, Descricao, Icone, ImagemUrl, Cor, Raridade, TipoGatilho, ValorGatilho, Ordem, Ativo)
                        VALUES (:id, :slug, :nome, :desc, :icone, :img, :cor, :rar, :tgat, :vgat, :ord, :ativo)
                    ")->execute([
                        ':id'    => gerarUuid(),
                        ':slug'  => $slug,
                        ':nome'  => $nome,
                        ':desc'  => $descricao,
                        ':icone' => $icone,
                        ':img'   => $imagemUrl,
                        ':cor'   => $cor,
                        ':rar'   => $raridade,
                        ':tgat'  => $tipoGatilho,
                        ':vgat'  => $valorGatilho,
                        ':ord'   => $ordem,
                        ':ativo' => $ativo,
                    ]);
                    $sucesso = "Conquista criada com sucesso!";
                } else {
                    if (empty($cid)) throw new RuntimeException("ID inválido.");
                    // Só atualiza ImagemUrl se algo foi enviado; caso contrário mantém o existente
                    if ($imagemUrl !== null) {
                        $pdo->prepare("
                            UPDATE conquista
                            SET Slug=:slug, Nome=:nome, Descricao=:desc, Icone=:icone,
                                ImagemUrl=:img, Cor=:cor, Raridade=:rar, TipoGatilho=:tgat, ValorGatilho=:vgat, Ordem=:ord, Ativo=:ativo
                            WHERE IDConquista=:id
                        ")->execute([':slug'=>$slug,':nome'=>$nome,':desc'=>$descricao,':icone'=>$icone,
                                     ':img'=>$imagemUrl,':cor'=>$cor,':rar'=>$raridade,':tgat'=>$tipoGatilho,
                                     ':vgat'=>$valorGatilho,':ord'=>$ordem,':ativo'=>$ativo,':id'=>$cid]);
                    } else {
                        $pdo->prepare("
                            UPDATE conquista
                            SET Slug=:slug, Nome=:nome, Descricao=:desc, Icone=:icone,
                                Cor=:cor, Raridade=:rar, TipoGatilho=:tgat, ValorGatilho=:vgat, Ordem=:ord, Ativo=:ativo
                            WHERE IDConquista=:id
                        ")->execute([':slug'=>$slug,':nome'=>$nome,':desc'=>$descricao,':icone'=>$icone,
                                     ':cor'=>$cor,':rar'=>$raridade,':tgat'=>$tipoGatilho,
                                     ':vgat'=>$valorGatilho,':ord'=>$ordem,':ativo'=>$ativo,':id'=>$cid]);
                    }
                    $sucesso = "Conquista atualizada!";
                }
            } catch (Throwable $e) {
                $erro = $e->getMessage() ?: "Erro ao salvar conquista.";
            }
        }

    // ── Excluir ────────────────────────────────────────────────────────────────
    } elseif ($action === 'excluir') {
        $cid = trim($_POST['conquista_id'] ?? '');
        if (empty($cid)) {
            $erro = "ID inválido.";
        } else {
            try {
                // Remove conquistas dos usuários e depois a conquista em si
                $pdo->prepare("DELETE FROM usuario_conquista WHERE FKConquista = :id")->execute([':id' => $cid]);
                $pdo->prepare("DELETE FROM conquista WHERE IDConquista = :id")->execute([':id' => $cid]);
                $sucesso = "Conquista excluída.";
            } catch (PDOException $e) {
                $erro = "Erro ao excluir conquista.";
            }
        }

    // ── Toggle ativo ───────────────────────────────────────────────────────────
    } elseif ($action === 'toggle_ativo') {
        $cid = trim($_POST['conquista_id'] ?? '');
        if (!empty($cid)) {
            try {
                $pdo->prepare("UPDATE conquista SET Ativo = NOT Ativo WHERE IDConquista = :id")->execute([':id' => $cid]);
                $sucesso = "Status atualizado.";
            } catch (PDOException $e) { $erro = "Erro ao atualizar status."; }
        }
    }

    if ($sucesso) { header("Location: conquistas.php?sucesso=1&msg=" . urlencode($sucesso)); exit; }
    if ($erro)    { header("Location: conquistas.php?erro=1&msg="    . urlencode($erro));    exit; }
}

if (isset($_GET['sucesso'])) $sucesso = htmlspecialchars(urldecode($_GET['msg'] ?? 'Operação realizada.'));
if (isset($_GET['erro']))    $erro    = htmlspecialchars(urldecode($_GET['msg'] ?? 'Ocorreu um erro.'));

// ==============================================================================
// DADOS
// ==============================================================================
$conquistas = [];
try {
    $conquistas = $pdo->query("
        SELECT c.*,
               COUNT(uc.IDUsuarioConquista) AS TotalUsuarios
        FROM conquista c
        LEFT JOIN usuario_conquista uc ON uc.FKConquista = c.IDConquista
        GROUP BY c.IDConquista
        ORDER BY c.Ordem ASC, c.Nome ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { $erro = "Erro ao buscar conquistas."; }

$raridadeInfo = [
    'comum'    => ['label' => 'Comum',    'cor' => '#808080'],
    'incomum'  => ['label' => 'Incomum',  'cor' => '#3eb23e'],
    'raro'     => ['label' => 'Raro',     'cor' => '#0070dd'],
    'epico'    => ['label' => 'Épico',    'cor' => '#a335ee'],
    'lendario' => ['label' => 'Lendário', 'cor' => '#ff8000'],
    'mitico'   => ['label' => 'Mítico',   'cor' => '#f3d3fd'],
];

$pageTitle = 'Admin — Conquistas';
require_once '../geral/header.php';
?>

<main class="container-fluid py-4 mt-2 flex-grow-1"
      style="max-width:1400px;padding-inline:var(--space-page-x);min-height:100vh;">

    <!-- Cabeçalho -->
    <div class="d-flex justify-content-between align-items-center mb-4 border-bottom border-secondary-subtle pb-3 gap-3 flex-wrap">
        <a href="/dashboard.php" class="btn btn-outline-secondary btn-sm rounded-pill px-3 flex-shrink-0">
            <i class="bi bi-arrow-left me-1"></i> Voltar
        </a>
        <button type="button" class="btn btn-sm rounded-pill px-3 fw-semibold ms-auto"
                style="background:var(--accent);color:#000;"
                onclick="abrirModalCriar()">
            <i class="bi bi-plus-lg me-1"></i> Nova Conquista
        </button>
    </div>

    <!-- Admin tabs -->
    <ul class="nav nav-pills gap-2 mb-4 flex-wrap">
        <li class="nav-item"><a href="/admin/usuarios.php" class="nav-link rounded-pill" style="background:rgba(255,255,255,.05);color:#9ca3af;font-size:0.85rem;"><i class="bi bi-people me-1"></i> Usuários</a></li>
        <li class="nav-item"><a href="/admin/configuracoes_planos.php" class="nav-link rounded-pill" style="background:rgba(255,255,255,.05);color:#9ca3af;font-size:0.85rem;"><i class="bi bi-sliders me-1"></i> Config. Planos</a></li>
        <li class="nav-item"><a href="/admin/codigos.php" class="nav-link rounded-pill" style="background:rgba(255,255,255,.05);color:#9ca3af;font-size:0.85rem;"><i class="bi bi-gift-fill me-1"></i> Códigos</a></li>
        <li class="nav-item"><a href="/admin/notificacoes.php" class="nav-link rounded-pill" style="background:rgba(255,255,255,.05);color:#9ca3af;font-size:0.85rem;"><i class="bi bi-bell-fill me-1"></i> Notificações</a></li>
        <li class="nav-item"><a href="/admin/revendedores.php" class="nav-link rounded-pill" style="background:rgba(255,255,255,.05);color:#9ca3af;font-size:0.85rem;"><i class="bi bi-people-fill me-1"></i> Revendedores</a></li>
        <li class="nav-item"><a href="/admin/indicacoes.php" class="nav-link rounded-pill" style="background:rgba(255,255,255,.05);color:#9ca3af;font-size:0.85rem;"><i class="bi bi-share-fill me-1"></i> Indicações</a></li>
        <li class="nav-item"><a href="/admin/conquistas.php" class="nav-link rounded-pill active" style="background:#d4af37;color:#000;font-size:0.85rem;"><i class="bi bi-trophy-fill me-1"></i> Conquistas</a></li>
    </ul>

    <!-- Alerts -->
    <?php if ($sucesso): ?><script>window._pendingToast = <?= json_encode($sucesso) ?>;</script><?php endif; ?>
    <?php if ($erro): ?>
    <div class="alert alert-danger d-flex align-items-center gap-2 rounded-3 border-0 bg-danger bg-opacity-10 text-danger fw-semibold mb-4">
        <i class="bi bi-exclamation-triangle-fill"></i> <?= $erro ?>
    </div>
    <?php endif; ?>

    <!-- Stats -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="card border-0 rounded-3 p-3 text-center" style="background:var(--bg-card);">
                <div class="fw-bold" style="font-size:1.6rem;color:var(--accent);"><?= count($conquistas) ?></div>
                <div class="small text-muted">Total</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 rounded-3 p-3 text-center" style="background:var(--bg-card);">
                <div class="fw-bold" style="font-size:1.6rem;color:#22c55e;"><?= count(array_filter($conquistas, fn($c) => $c['Ativo'])) ?></div>
                <div class="small text-muted">Ativas</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 rounded-3 p-3 text-center" style="background:var(--bg-card);">
                <div class="fw-bold" style="font-size:1.6rem;color:#d4af37;"><?= array_sum(array_column($conquistas, 'TotalUsuarios')) ?></div>
                <div class="small text-muted">Conquistas concedidas</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 rounded-3 p-3 text-center" style="background:var(--bg-card);">
                <div class="fw-bold" style="font-size:1.6rem;color:#7c3aed;"><?= count(array_filter($conquistas, fn($c) => !empty($c['ImagemUrl']))) ?></div>
                <div class="small text-muted">Com imagem</div>
            </div>
        </div>
    </div>

    <!-- Tabela de conquistas -->
    <div class="card border-0 rounded-4" style="background:var(--bg-card);">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" style="color:var(--text-main);">
                <thead style="border-bottom:1px solid var(--bs-border-color);font-size:0.78rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em;">
                    <tr>
                        <th class="px-4 py-3">Insígnia</th>
                        <th class="py-3">Nome / Slug</th>
                        <th class="py-3">Raridade</th>
                        <th class="py-3">Gatilho</th>
                        <th class="py-3">Ordem</th>
                        <th class="py-3">Usuários</th>
                        <th class="py-3">Status</th>
                        <th class="py-3 text-end pe-4">Ações</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($conquistas)): ?>
                <tr><td colspan="7" class="text-center py-5 text-muted">Nenhuma conquista cadastrada ainda.</td></tr>
                <?php else: ?>
                <?php foreach ($conquistas as $c):
                    $rar = $raridadeInfo[$c['Raridade']] ?? $raridadeInfo['comum'];
                ?>
                <tr style="border-bottom:1px solid var(--bs-border-color);">
                    <td class="px-4 py-3">
                        <!-- Preview da insígnia -->
                        <div style="width:44px;height:44px;border-radius:50%;background:<?= htmlspecialchars($c['Cor']) ?>22;border:2px solid <?= htmlspecialchars($c['Cor']) ?>55;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                            <?php if (!empty($c['ImagemUrl'])): ?>
                                <img src="<?= htmlspecialchars($c['ImagemUrl']) ?>"
                                     alt="<?= htmlspecialchars($c['Nome']) ?>"
                                     style="width:28px;height:28px;object-fit:contain;border-radius:50%;"
                                     onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
                                <i class="bi <?= htmlspecialchars($c['Icone'] ?? 'bi-trophy') ?>"
                                   style="color:<?= htmlspecialchars($c['Cor']) ?>;display:none;"></i>
                            <?php else: ?>
                                <i class="bi <?= htmlspecialchars($c['Icone'] ?? 'bi-trophy') ?>"
                                   style="color:<?= htmlspecialchars($c['Cor']) ?>;font-size:1.1rem;"></i>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td class="py-3">
                        <div class="fw-semibold" style="font-size:0.9rem;"><?= htmlspecialchars($c['Nome']) ?></div>
                        <div class="text-muted" style="font-size:0.75rem;"><code style="color:var(--text-muted);"><?= htmlspecialchars($c['Slug']) ?></code></div>
                        <?php if (!empty($c['Descricao'])): ?>
                        <div class="text-muted mt-1" style="font-size:0.75rem;max-width:280px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= htmlspecialchars($c['Descricao']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="py-3">
                        <span class="badge rounded-pill"
                              style="background:<?= $rar['cor'] ?>22;color:<?= $rar['cor'] ?>;border:1px solid <?= $rar['cor'] ?>55;font-size:0.72rem;">
                            <?= $rar['label'] ?>
                        </span>
                        <div class="mt-1 d-flex align-items-center gap-1">
                            <span style="display:inline-block;width:12px;height:12px;border-radius:3px;background:<?= htmlspecialchars($c['Cor']) ?>;flex-shrink:0;"></span>
                            <code style="font-size:0.7rem;color:var(--text-muted);"><?= htmlspecialchars($c['Cor']) ?></code>
                        </div>
                    </td>
                    <td class="py-3">
                        <?php if ($c['TipoGatilho'] === 'registros'): ?>
                            <span class="badge rounded-pill" style="background:#0070dd22;color:#0070dd;border:1px solid #0070dd44;font-size:0.7rem;">
                                <i class="bi bi-people-fill me-1"></i><?= (int)$c['ValorGatilho'] ?> registros
                            </span>
                        <?php else: ?>
                            <span class="text-muted" style="font-size:0.75rem;">Manual</span>
                        <?php endif; ?>
                    </td>
                    <td class="py-3 text-center">
                        <span class="badge bg-secondary bg-opacity-25 text-secondary"><?= (int)$c['Ordem'] ?></span>
                    </td>
                    <td class="py-3 text-center">
                        <span class="fw-semibold" style="color:var(--accent);"><?= number_format((int)$c['TotalUsuarios']) ?></span>
                    </td>
                    <td class="py-3">
                        <form method="post" style="display:inline;">
                            <input type="hidden" name="action" value="toggle_ativo">
                            <input type="hidden" name="conquista_id" value="<?= htmlspecialchars($c['IDConquista']) ?>">
                            <button type="submit" class="btn btn-sm rounded-pill px-2 py-0"
                                    style="font-size:0.72rem;background:<?= $c['Ativo'] ? '#22c55e22' : '#ef444422' ?>;color:<?= $c['Ativo'] ? '#22c55e' : '#ef4444' ?>;border:1px solid <?= $c['Ativo'] ? '#22c55e55' : '#ef444455' ?>;">
                                <?= $c['Ativo'] ? 'Ativa' : 'Inativa' ?>
                            </button>
                        </form>
                    </td>
                    <td class="py-3 text-end pe-4">
                        <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill me-1"
                                onclick='abrirModalEditar(<?= json_encode($c) ?>)'>
                            <i class="bi bi-pencil"></i>
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-danger rounded-pill"
                                onclick="confirmarExclusao('<?= htmlspecialchars($c['IDConquista']) ?>','<?= htmlspecialchars(addslashes($c['Nome'])) ?>')">
                            <i class="bi bi-trash3"></i>
                        </button>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</main>

<!-- ══════════════════════════════════════════════════════════ -->
<!-- MODAL CRIAR / EDITAR                                       -->
<!-- ══════════════════════════════════════════════════════════ -->
<div class="modal fade" id="modalConquista" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content border-secondary-subtle rounded-4" style="background:#1a1d21;color:#e5e7eb;">
      <div class="modal-header border-bottom border-secondary-subtle px-4">
        <h5 class="modal-title fw-bold" id="modalConquistaTitulo">Nova Conquista</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="post" enctype="multipart/form-data" id="formConquista">
        <div class="modal-body px-4 py-4">
          <input type="hidden" name="action" id="fAction" value="criar">
          <input type="hidden" name="conquista_id" id="fId">

          <div class="row g-3">
            <!-- Nome -->
            <div class="col-md-8">
              <label class="form-label fw-semibold small">Nome <span class="text-danger">*</span></label>
              <input type="text" name="nome" id="fNome" class="form-control rounded-3" required maxlength="80"
                     style="background:var(--input-bg,#1e2028);border-color:var(--bs-border-color);color:var(--text-main);">
            </div>
            <!-- Slug -->
            <div class="col-md-4">
              <label class="form-label fw-semibold small">Slug <span class="text-danger">*</span></label>
              <input type="text" name="slug" id="fSlug" class="form-control rounded-3 font-monospace" required maxlength="50"
                     placeholder="ex: primeira_transacao"
                     style="background:var(--input-bg,#1e2028);border-color:var(--bs-border-color);color:var(--text-main);">
              <div class="form-text">Minúsculas, sem espaços. Usado internamente.</div>
            </div>

            <!-- Descrição -->
            <div class="col-12">
              <label class="form-label fw-semibold small">Descrição</label>
              <textarea name="descricao" id="fDescricao" class="form-control rounded-3" rows="2" maxlength="255"
                        style="background:var(--input-bg,#1e2028);border-color:var(--bs-border-color);color:var(--text-main);"></textarea>
            </div>

            <!-- Ícone Bootstrap -->
            <div class="col-md-4">
              <label class="form-label fw-semibold small">Ícone Bootstrap Icons</label>
              <div class="input-group">
                <span class="input-group-text" style="background:var(--input-bg,#1e2028);border-color:var(--bs-border-color);">
                  <i id="iconePreview" class="bi bi-trophy" style="color:var(--accent);"></i>
                </span>
                <input type="text" name="icone" id="fIcone" class="form-control rounded-end-3" value="bi-trophy"
                       placeholder="bi-trophy"
                       style="background:var(--input-bg,#1e2028);border-color:var(--bs-border-color);color:var(--text-main);"
                       oninput="document.getElementById('iconePreview').className='bi '+this.value">
              </div>
              <div class="form-text"><a href="https://icons.getbootstrap.com" target="_blank" rel="noopener" style="color:var(--accent);">Ver todos os ícones</a></div>
            </div>

            <!-- Cor -->
            <div class="col-md-4">
              <label class="form-label fw-semibold small">Cor da insígnia</label>
              <div class="d-flex align-items-center gap-2">
                <input type="color" name="cor" id="fCor" value="#d4af37" class="form-control form-control-color rounded-3"
                       style="width:48px;height:40px;padding:3px;background:var(--input-bg,#1e2028);border-color:var(--bs-border-color);">
                <input type="text" id="fCorTexto" class="form-control rounded-3 font-monospace" maxlength="7"
                       value="#d4af37" placeholder="#d4af37"
                       style="background:var(--input-bg,#1e2028);border-color:var(--bs-border-color);color:var(--text-main);"
                       oninput="document.getElementById('fCor').value=this.value">
              </div>
            </div>

            <!-- Raridade -->
            <div class="col-md-4">
              <label class="form-label fw-semibold small">Raridade</label>
              <select name="raridade" id="fRaridade" class="form-select rounded-3"
                      style="background:var(--input-bg,#1e2028);border-color:var(--bs-border-color);color:var(--text-main);">
                <option value="comum">Comum</option>
                <option value="incomum">Incomum</option>
                <option value="raro">Raro</option>
                <option value="epico">Épico</option>
                <option value="lendario">Lendário</option>
                <option value="mitico">Mítico</option>
              </select>
            </div>

            <!-- Gatilho automático -->
            <div class="col-md-4">
              <label class="form-label fw-semibold small">Gatilho</label>
              <select name="tipo_gatilho" id="fTipoGatilho" class="form-select rounded-3"
                      style="background:var(--input-bg,#1e2028);border-color:var(--bs-border-color);color:var(--text-main);"
                      onchange="toggleValorGatilho()">
                <option value="manual">Manual (só via código)</option>
                <option value="registros">Nº de registros via indicação</option>
              </select>
            </div>

            <!-- Valor do gatilho -->
            <div class="col-md-4" id="wrapValorGatilho" style="display:none;">
              <label class="form-label fw-semibold small">Quantidade necessária</label>
              <input type="number" name="valor_gatilho" id="fValorGatilho" min="1" value="1"
                     class="form-control rounded-3"
                     style="background:var(--input-bg,#1e2028);border-color:var(--bs-border-color);color:var(--text-main);">
              <div class="form-text">Usuário precisa atingir esse número para desbloquear.</div>
            </div>

            <!-- Imagem -->
            <div class="col-12">
              <label class="form-label fw-semibold small">Imagem da insígnia <span class="text-muted">(opcional — sobrepõe o ícone Bootstrap)</span></label>

              <!-- Tabs upload vs URL -->
              <ul class="nav nav-pills gap-2 mb-3" id="imgTabs">
                <li class="nav-item">
                  <button type="button" class="nav-link active rounded-pill px-3 py-1" style="font-size:0.82rem;"
                          onclick="toggleImgTab('upload', this)">
                    <i class="bi bi-upload me-1"></i> Upload de arquivo
                  </button>
                </li>
                <li class="nav-item">
                  <button type="button" class="nav-link rounded-pill px-3 py-1" style="font-size:0.82rem;"
                          onclick="toggleImgTab('url', this)">
                    <i class="bi bi-link-45deg me-1"></i> URL externa
                  </button>
                </li>
              </ul>

              <div id="tabUpload">
                <input type="file" name="imagem_arquivo" id="fImagemArquivo" class="form-control rounded-3"
                       accept="image/*" style="background:var(--input-bg,#1e2028);border-color:var(--bs-border-color);color:var(--text-main);"
                       onchange="previewImagem(this)">
                <div class="form-text">JPG, PNG, SVG, WebP ou GIF. Máx. 2 MB. O arquivo é salvo em <code>/geral/img/conquistas/</code>.</div>
              </div>
              <div id="tabUrl" hidden>
                <input type="text" name="imagem_url_manual" id="fImagemUrl" class="form-control rounded-3"
                       placeholder="https://exemplo.com/imagem.png"
                       style="background:var(--input-bg,#1e2028);border-color:var(--bs-border-color);color:var(--text-main);"
                       oninput="previewImagemUrl(this.value)">
              </div>

              <!-- Preview -->
              <div class="mt-3 d-flex align-items-center gap-3" id="previewWrap" hidden>
                <div id="previewCircle" style="width:56px;height:56px;border-radius:50%;background:#d4af3722;border:2px solid #d4af3755;display:flex;align-items:center;justify-content:center;overflow:hidden;">
                  <img id="previewImg" src="" alt="preview" style="width:40px;height:40px;object-fit:contain;border-radius:50%;">
                </div>
                <div>
                  <div class="small fw-semibold" id="previewNomeImagem">—</div>
                  <button type="button" class="btn btn-sm btn-outline-danger rounded-pill mt-1" style="font-size:0.75rem;" onclick="limparImagem()">
                    <i class="bi bi-x me-1"></i>Remover imagem
                  </button>
                </div>
              </div>
              <!-- Preview imagem atual (edição) -->
              <div class="mt-2 small text-muted" id="imgAtualWrap" hidden>
                Imagem atual: <a id="imgAtualLink" href="#" target="_blank" style="color:var(--accent);">ver imagem</a>
                <span class="ms-2 text-secondary">(envie outra para substituir)</span>
              </div>
            </div>

            <!-- Ordem e Ativo -->
            <div class="col-md-4">
              <label class="form-label fw-semibold small">Ordem de exibição</label>
              <input type="number" name="ordem" id="fOrdem" class="form-control rounded-3" value="99" min="1" max="255"
                     style="background:var(--input-bg,#1e2028);border-color:var(--bs-border-color);color:var(--text-main);">
            </div>
            <div class="col-md-4 d-flex align-items-end pb-1">
              <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" name="ativo" id="fAtivo" checked>
                <label class="form-check-label fw-semibold small" for="fAtivo">Ativa</label>
              </div>
            </div>

            <!-- Preview final da insígnia -->
            <div class="col-12">
              <div class="rounded-3 p-3 d-flex align-items-center gap-3" style="background:rgba(255,255,255,.03);border:1px solid var(--bs-border-color);">
                <div id="prevFinalCircle" style="width:52px;height:52px;border-radius:50%;background:#d4af3722;border:2px solid #d4af3755;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:1.3rem;">
                  <i class="bi bi-trophy" id="prevFinalIcon" style="color:#d4af37;"></i>
                </div>
                <div>
                  <div class="fw-semibold" id="prevFinalNome" style="font-size:0.9rem;">Nome da conquista</div>
                  <div class="text-muted small" id="prevFinalDesc">Descrição aparece aqui</div>
                </div>
              </div>
            </div>
          </div>

        </div>
        <div class="modal-footer border-top border-secondary-subtle px-4">
          <button type="button" class="btn btn-outline-secondary rounded-pill px-4" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn fw-semibold rounded-pill px-4" style="background:var(--accent);color:#000;">
            <i class="bi bi-check2 me-1"></i> Salvar
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ══════════════════════════════════════════════════════════ -->
<!-- MODAL EXCLUIR                                              -->
<!-- ══════════════════════════════════════════════════════════ -->
<div class="modal fade" id="modalExcluir" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-secondary-subtle rounded-4" style="background:#1a1d21;color:#e5e7eb;">
      <div class="modal-header border-bottom border-secondary-subtle px-4">
        <h5 class="modal-title fw-bold text-danger"><i class="bi bi-exclamation-triangle-fill me-2"></i>Excluir conquista</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body px-4 py-3">
        <p class="mb-1">Tem certeza que deseja excluir a conquista <strong id="excNome"></strong>?</p>
        <p class="text-danger small mb-0"><i class="bi bi-info-circle me-1"></i>Isso removerá a conquista de <strong>todos os usuários</strong> que a possuem e não pode ser desfeito.</p>
      </div>
      <div class="modal-footer border-top border-secondary-subtle px-4">
        <form method="post" id="formExcluir">
          <input type="hidden" name="action" value="excluir">
          <input type="hidden" name="conquista_id" id="excId">
          <button type="button" class="btn btn-outline-secondary rounded-pill px-4 me-2" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn btn-danger rounded-pill px-4 fw-semibold">
            <i class="bi bi-trash3 me-1"></i> Excluir definitivamente
          </button>
        </form>
      </div>
    </div>
  </div>
</div>

<script>
function _modalConquista() { return bootstrap.Modal.getOrCreateInstance(document.getElementById('modalConquista')); }
function _modalExcluir()   { return bootstrap.Modal.getOrCreateInstance(document.getElementById('modalExcluir')); }

// ── Abrir criar ─────────────────────────────────────────────
function abrirModalCriar() {
    document.getElementById('modalConquistaTitulo').textContent = 'Nova Conquista';
    document.getElementById('fAction').value   = 'criar';
    document.getElementById('fId').value       = '';
    document.getElementById('formConquista').reset();
    document.getElementById('iconePreview').className = 'bi bi-trophy';
    limparImagem();
    sincronizarCorComRaridade();
    document.getElementById('imgAtualWrap').hidden = true;
    _modalConquista().show();
}

// ── Abrir editar ─────────────────────────────────────────────
function abrirModalEditar(c) {
    document.getElementById('modalConquistaTitulo').textContent = 'Editar Conquista';
    document.getElementById('fAction').value    = 'editar';
    document.getElementById('fId').value        = c.IDConquista;
    document.getElementById('fNome').value      = c.Nome       || '';
    document.getElementById('fSlug').value      = c.Slug       || '';
    document.getElementById('fDescricao').value = c.Descricao  || '';
    document.getElementById('fIcone').value     = c.Icone      || 'bi-trophy';
    document.getElementById('fCor').value       = c.Cor        || '#d4af37';
    document.getElementById('fCorTexto').value  = c.Cor        || '#d4af37';
    document.getElementById('fOrdem').value     = c.Ordem      || 99;
    document.getElementById('fAtivo').checked   = c.Ativo == 1;
    document.getElementById('iconePreview').className = 'bi ' + (c.Icone || 'bi-trophy');

    var sel = document.getElementById('fRaridade');
    for (var i = 0; i < sel.options.length; i++) {
        sel.options[i].selected = sel.options[i].value === c.Raridade;
    }

    var selGat = document.getElementById('fTipoGatilho');
    for (var i = 0; i < selGat.options.length; i++) {
        selGat.options[i].selected = selGat.options[i].value === (c.TipoGatilho || 'manual');
    }
    document.getElementById('fValorGatilho').value = c.ValorGatilho || 1;
    toggleValorGatilho();

    limparImagem();
    var imgAtualWrap = document.getElementById('imgAtualWrap');
    if (c.ImagemUrl) {
        imgAtualWrap.hidden = false;
        document.getElementById('imgAtualLink').href        = c.ImagemUrl;
        document.getElementById('imgAtualLink').textContent = c.ImagemUrl;
    } else {
        imgAtualWrap.hidden = true;
    }

    atualizarPreviewFinal();
    _modalConquista().show();
}

// ── Confirmar exclusão ──────────────────────────────────────
function confirmarExclusao(id, nome) {
    document.getElementById('excId').value   = id;
    document.getElementById('excNome').textContent = nome;
    _modalExcluir().show();
}

// ── Toggle tab upload/url ───────────────────────────────────
function toggleImgTab(tab, btn) {
    document.getElementById('tabUpload').hidden = tab !== 'upload';
    document.getElementById('tabUrl').hidden    = tab !== 'url';
    document.querySelectorAll('#imgTabs .nav-link').forEach(function(b) { b.classList.remove('active'); });
    btn.classList.add('active');
}

// ── Preview upload ──────────────────────────────────────────
function previewImagem(input) {
    if (!input.files || !input.files[0]) return;
    var file = input.files[0];
    var url  = URL.createObjectURL(file);
    mostrarPreview(url, file.name);
}
function previewImagemUrl(url) {
    if (!url) { document.getElementById('previewWrap').hidden = true; return; }
    mostrarPreview(url, url.split('/').pop());
}
function mostrarPreview(src, nome) {
    document.getElementById('previewImg').src = src;
    document.getElementById('previewNomeImagem').textContent = nome;
    document.getElementById('previewWrap').hidden = false;
    // Atualiza também o preview final
    var ic = document.getElementById('prevFinalIcon');
    ic.style.display = 'none';
    var existing = document.getElementById('prevFinalImg');
    if (!existing) {
        var img = document.createElement('img');
        img.id  = 'prevFinalImg';
        img.style.cssText = 'width:36px;height:36px;object-fit:contain;border-radius:50%;';
        document.getElementById('prevFinalCircle').appendChild(img);
    }
    document.getElementById('prevFinalImg').src = src;
}
function limparImagem() {
    document.getElementById('previewWrap').hidden = true;
    document.getElementById('previewImg').src = '';
    var existing = document.getElementById('prevFinalImg');
    if (existing) existing.remove();
    document.getElementById('prevFinalIcon').style.display = '';
    if (document.getElementById('fImagemArquivo')) document.getElementById('fImagemArquivo').value = '';
    if (document.getElementById('fImagemUrl'))     document.getElementById('fImagemUrl').value = '';
}

// ── Mostra/oculta campo de valor conforme gatilho ───────────
function toggleValorGatilho() {
    var tipo = document.getElementById('fTipoGatilho').value;
    document.getElementById('wrapValorGatilho').style.display = tipo === 'manual' ? 'none' : '';
}

// ── Cores canônicas por raridade ────────────────────────────
var RARIDADE_CORES = {
    'comum':    '#808080',
    'incomum':  '#3eb23e',
    'raro':     '#0070dd',
    'epico':    '#a335ee',
    'lendario': '#ff8000',
    'mitico':   '#f3d3fd'
};

function sincronizarCorComRaridade() {
    var rar = document.getElementById('fRaridade').value;
    var cor = RARIDADE_CORES[rar] || '#808080';
    document.getElementById('fCor').value      = cor;
    document.getElementById('fCorTexto').value = cor;
    atualizarPreviewFinal();
}

document.getElementById('fRaridade').addEventListener('change', sincronizarCorComRaridade);

// ── Sincroniza cor entre color picker e texto ───────────────
document.getElementById('fCor').addEventListener('input', function() {
    document.getElementById('fCorTexto').value = this.value;
    atualizarPreviewFinal();
});

// ── Preview final em tempo real ─────────────────────────────
function atualizarPreviewFinal() {
    var cor    = document.getElementById('fCor').value || '#808080';
    var icone  = document.getElementById('fIcone').value || 'bi-trophy';
    var nome   = document.getElementById('fNome').value || 'Nome da conquista';
    var desc   = document.getElementById('fDescricao').value || 'Descrição aparece aqui';

    var circle = document.getElementById('prevFinalCircle');
    circle.style.background  = cor + '22';
    circle.style.borderColor = cor + '55';

    var icon = document.getElementById('prevFinalIcon');
    icon.className = 'bi ' + icone;
    icon.style.color = cor;

    document.getElementById('prevFinalNome').textContent = nome;
    document.getElementById('prevFinalDesc').textContent = desc;
}

['fNome','fDescricao','fIcone'].forEach(function(id) {
    var el = document.getElementById(id);
    if (el) el.addEventListener('input', atualizarPreviewFinal);
});
</script>

<?php require_once '../geral/footer.php'; ?>
