<?php
session_start();

if (!isset($_SESSION['usuario_id'])) {
    header("Location: usuario/login.php");
    exit;
}

require_once 'config/conexao.php';
require_once 'config/funcoes.php';

$usuario_id = $_SESSION['usuario_id'];
$mensagem = '';
$tipo_mensagem = '';

// ==============================================================================
// 1. LÓGICA DE ATUALIZAÇÃO (POST)
// ==============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // AÇÃO 1: ATUALIZAR DADOS PESSOAIS
    if (isset($_POST['action']) && $_POST['action'] === 'update_profile') {
        $nome     = trim($_POST['nome']);
        $telefone = function_exists('sanitizarTelefone') ? sanitizarTelefone(trim($_POST['telefone'] ?? '')) : null;

        if (!empty($nome)) {
            try {
                $sqlUpd = "UPDATE Usuario SET Nome = :nome, Telefone = :tel WHERE IDUsuario = :uid";
                $stmtUpd = $pdo->prepare($sqlUpd);
                $stmtUpd->execute([
                    ':nome' => $nome,
                    ':tel'  => $telefone,
                    ':uid'  => $usuario_id,
                ]);

                $_SESSION['usuario_nome'] = $nome;
                $mensagem = "Seus dados foram atualizados com sucesso!";
                $tipo_mensagem = "success";
            } catch (PDOException $e) {
                $mensagem = "Erro ao atualizar dados.";
                $tipo_mensagem = "danger";
            }
        }
    }

    // AÇÃO 2: ATUALIZAR SENHA
    if (isset($_POST['action']) && $_POST['action'] === 'update_password') {
        $senha_atual = $_POST['senha_atual'] ?? '';
        $nova_senha = $_POST['nova_senha'] ?? '';
        $confirma_senha = $_POST['confirma_senha'] ?? '';

        if ($nova_senha !== $confirma_senha) {
            $mensagem = "As novas senhas não conferem!";
            $tipo_mensagem = "warning";
        } else {
            try {
                $sqlSenha = "SELECT Senha FROM Usuario WHERE IDUsuario = :uid";
                $stmtSenha = $pdo->prepare($sqlSenha);
                $stmtSenha->execute([':uid' => $usuario_id]);
                $hashBanco = $stmtSenha->fetchColumn();

                $autorizado_senha = false;

                // Se for Google e mandou o código oculto, autoriza a criação da senha
                if ($hashBanco === 'GOOGLE_SSO' && $senha_atual === 'GOOGLE_SSO') {
                    $autorizado_senha = true;
                }
                // Se for usuário comum, verifica a senha digitada
                elseif (password_verify($senha_atual, $hashBanco)) {
                    $autorizado_senha = true;
                }

                if ($autorizado_senha) {
                    $novoHash = password_hash($nova_senha, PASSWORD_DEFAULT);
                    $sqlUpdSenha = "UPDATE Usuario SET Senha = :senha WHERE IDUsuario = :uid";
                    $stmtUpdSenha = $pdo->prepare($sqlUpdSenha);
                    $stmtUpdSenha->execute([':senha' => $novoHash, ':uid' => $usuario_id]);

                    $mensagem = "Senha definida/alterada com segurança!";
                    $tipo_mensagem = "success";
                } else {
                    $mensagem = "A senha atual está incorreta.";
                    $tipo_mensagem = "danger";
                }
            } catch (PDOException $e) {
                $mensagem = "Erro ao alterar a senha.";
                $tipo_mensagem = "danger";
            }
        }
    }

    // AÇÃO 3: TROCAR TEMA
    if (isset($_POST['action']) && $_POST['action'] === 'trocar_tema') {
        $novoTema = strtolower(trim($_POST['tema'] ?? ''));
        $temas    = function_exists('temasDisponiveis') ? temasDisponiveis() : ['dark' => [], 'white' => []];
        if (isset($temas[$novoTema])) {
            $conquista = $temas[$novoTema]['conquista'] ?? null;
            $temAcesso = !$conquista || (function_exists('usuarioPossuiConquista') && usuarioPossuiConquista($conquista));
            if ($temAcesso) {
                try {
                    $pdo->prepare("UPDATE Usuario SET Tema = :tema WHERE IDUsuario = :uid")
                        ->execute([':tema' => $novoTema, ':uid' => $usuario_id]);
                    $_SESSION['tema'] = $novoTema;
                    $mensagem = 'Tema alterado para ' . ucfirst($novoTema) . '!';
                    $tipo_mensagem = 'success';
                } catch (PDOException $e) {
                    $mensagem = 'Erro ao alterar o tema.';
                    $tipo_mensagem = 'danger';
                }
            } else {
                $mensagem = 'Você ainda não desbloqueou este tema.';
                $tipo_mensagem = 'warning';
            }
        }
    }

    // AÇÃO 4: TROCAR NAVEGAÇÃO
    if (isset($_POST['action']) && $_POST['action'] === 'trocar_nav') {
        $novoNav = in_array($_POST['nav_tipo'] ?? '', ['sidebar', 'top']) ? $_POST['nav_tipo'] : 'sidebar';
        try {
            $pdo->prepare("UPDATE Usuario SET NavTipo = :nav WHERE IDUsuario = :uid")
                ->execute([':nav' => $novoNav, ':uid' => $usuario_id]);
            $_SESSION['nav_tipo'] = $novoNav;
            header('Location: configuracoes.php');
            exit;
        } catch (PDOException $e) {
            $mensagem = 'Erro ao salvar preferência de navegação.';
            $tipo_mensagem = 'danger';
        }
    }

    // AÇÃO 6: PREFERÊNCIAS DO DASHBOARD
    if (isset($_POST['action']) && $_POST['action'] === 'salvar_pref_dashboard') {
        $campos = ['cofrinhos', 'cartoes', 'receita_pendente', 'despesa_pendente', 'saldo_projetado'];
        try {
            foreach ($campos as $campo) {
                $valor = isset($_POST["dash_$campo"]) ? '1' : '0';
                $chave = "dash_$campo";
                $stmtChk = $pdo->prepare("SELECT COUNT(*) FROM ConfiguracaoSistema WHERE Chave = :chave AND FKUsuario = :uid");
                $stmtChk->execute([':chave' => $chave, ':uid' => $usuario_id]);
                if ($stmtChk->fetchColumn() > 0) {
                    $pdo->prepare("UPDATE ConfiguracaoSistema SET Valor = :v WHERE Chave = :chave AND FKUsuario = :uid")
                        ->execute([':v' => $valor, ':chave' => $chave, ':uid' => $usuario_id]);
                } else {
                    $pdo->prepare("INSERT INTO ConfiguracaoSistema (Chave, Valor, FKUsuario) VALUES (:chave, :v, :uid)")
                        ->execute([':chave' => $chave, ':v' => $valor, ':uid' => $usuario_id]);
                }
            }
            $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
            if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['ok' => true]); exit; }
            $mensagem = 'Preferências do dashboard salvas!';
            $tipo_mensagem = 'success';
        } catch (PDOException $e) {
            $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
            if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['ok' => false]); exit; }
            $mensagem = 'Erro ao salvar preferências.';
            $tipo_mensagem = 'danger';
        }
    }

    // AÇÃO 5: PERSONALIDADE DO ASSISTENTE WHATSAPP
    if (isset($_POST['action']) && $_POST['action'] === 'salvar_pref_wa') {
        $pers = in_array($_POST['wa_personalidade'] ?? '', ['parceiro', 'profissional']) ? $_POST['wa_personalidade'] : 'parceiro';
        try {
            $stmtChkWa = $pdo->prepare("SELECT COUNT(*) FROM ConfiguracaoSistema WHERE Chave = 'wa_personalidade' AND FKUsuario = :uid");
            $stmtChkWa->execute([':uid' => $usuario_id]);
            if ($stmtChkWa->fetchColumn() > 0) {
                $pdo->prepare("UPDATE ConfiguracaoSistema SET Valor = :v WHERE Chave = 'wa_personalidade' AND FKUsuario = :uid")
                    ->execute([':v' => $pers, ':uid' => $usuario_id]);
            } else {
                $pdo->prepare("INSERT INTO ConfiguracaoSistema (Chave, Valor, FKUsuario) VALUES ('wa_personalidade', :v, :uid)")
                    ->execute([':v' => $pers, ':uid' => $usuario_id]);
            }
            $mensagem = 'Preferência do assistente salva!';
            $tipo_mensagem = 'success';
        } catch (PDOException $e) {
            $mensagem = 'Erro ao salvar preferência.';
            $tipo_mensagem = 'danger';
        }
    }

    // AÇÃO 6: EXCLUIR CONTA (A ZONA DE PERIGO)
    if (isset($_POST['action']) && $_POST['action'] === 'delete_account') {
        $senha_confirmacao = $_POST['senha_confirmacao'] ?? '';

        try {
            $sqlSenha = "SELECT Senha FROM Usuario WHERE IDUsuario = :uid";
            $stmtSenha = $pdo->prepare($sqlSenha);
            $stmtSenha->execute([':uid' => $usuario_id]);
            $hashBanco = $stmtSenha->fetchColumn();

            $autorizado_exclusao = false;

            // Verificação Inteligente
            if ($hashBanco === 'GOOGLE_SSO') {
                if ($senha_confirmacao === 'EXCLUIR') {
                    $autorizado_exclusao = true;
                } else {
                    $mensagem = "Palavra de segurança incorreta. Exclusão cancelada.";
                    $tipo_mensagem = "danger";
                }
            } else {
                if (password_verify($senha_confirmacao, $hashBanco)) {
                    $autorizado_exclusao = true;
                } else {
                    $mensagem = "Senha incorreta. A exclusão foi cancelada para sua segurança.";
                    $tipo_mensagem = "danger";
                }
            }

            if ($autorizado_exclusao) {

                $pdo->beginTransaction();

                $pdo->prepare("DELETE FROM RateioRegistro WHERE FKRegistro IN (SELECT IDRegistro FROM Registro WHERE FKUsuario = :uid)")->execute([':uid' => $usuario_id]);
                $pdo->prepare("DELETE FROM SubCategoria WHERE FKCategoriaPai IN (SELECT IDCategoria FROM Categoria WHERE FKUsuario = :uid)")->execute([':uid' => $usuario_id]);

                $pdo->prepare("DELETE FROM Registro WHERE FKUsuario = :uid")->execute([':uid' => $usuario_id]);
                $pdo->prepare("DELETE FROM MembroCarteira WHERE FKUsuario = :uid")->execute([':uid' => $usuario_id]);
                $pdo->prepare("DELETE FROM Categoria WHERE FKUsuario = :uid")->execute([':uid' => $usuario_id]);
                $pdo->prepare("DELETE FROM ConfiguracaoSistema WHERE FKUsuario = :uid")->execute([':uid' => $usuario_id]);
                $pdo->prepare("DELETE FROM Carteira WHERE FKUsuarioDono = :uid")->execute([':uid' => $usuario_id]);

                $pdo->prepare("DELETE FROM Usuario WHERE IDUsuario = :uid")->execute([':uid' => $usuario_id]);

                $pdo->commit();

                session_destroy();
                setcookie('auralis_remember', '', time() - 3600, '/');
                header("Location: usuario/login.php?conta=excluida");
                exit;
            }
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $mensagem = "Erro ao limpar dados do usuário: " . $e->getMessage();
            $tipo_mensagem = "danger";
        }
    }
}

// ==============================================================================
// 2. BUSCA OS DADOS ATUAIS (Incluindo Senha para a lógica do Front-end)
// ==============================================================================
try {
    $sqlBusca = "SELECT Nome, Email, Telefone, Senha, Tema, CodigoIndicacao FROM Usuario WHERE IDUsuario = :uid LIMIT 1";
    $stmtBusca = $pdo->prepare($sqlBusca);
    $stmtBusca->execute([':uid' => $usuario_id]);
    $dadosUsuario = $stmtBusca->fetch(PDO::FETCH_ASSOC);

    // Descobre se é usuário Google para o HTML
    $isGoogleUser = ($dadosUsuario['Senha'] === 'GOOGLE_SSO');
} catch (PDOException $e) {
    die("Erro ao carregar dados do usuário.");
}

// ── Preferências do Dashboard ────────────────────────────────────────────────
$dashPrefsCfg = ['cofrinhos' => '1', 'cartoes' => '1', 'receita_pendente' => '1', 'despesa_pendente' => '1', 'saldo_projetado' => '1'];
try {
    $stmtDP = $pdo->prepare("SELECT Chave, Valor FROM ConfiguracaoSistema WHERE FKUsuario = :uid AND Chave LIKE 'dash_%'");
    $stmtDP->execute([':uid' => $usuario_id]);
    foreach ($stmtDP->fetchAll() as $row) {
        $key = substr($row['Chave'], 5);
        if (isset($dashPrefsCfg[$key])) $dashPrefsCfg[$key] = $row['Valor'];
    }
} catch (PDOException $e) {}

// ── Personalidade do assistente WhatsApp ────────────────────────────────────
$waPersonalidade = 'parceiro';
try {
    $stmtWaP = $pdo->prepare("SELECT Valor FROM ConfiguracaoSistema WHERE Chave = 'wa_personalidade' AND FKUsuario = :uid LIMIT 1");
    $stmtWaP->execute([':uid' => $usuario_id]);
    $waPersonalidade = $stmtWaP->fetchColumn() ?: 'parceiro';
} catch (PDOException $e) {}

// ── Biometria (WebAuthn) ─────────────────────────────────────────────────────
garantirTabelaCredencialWebAuthn($pdo);
$credenciaisWebAuthn = listarCredenciaisWebAuthn($pdo, $usuario_id);
if (($_GET['sucesso'] ?? '') === 'webauthn_removido') {
    $mensagem = 'Login rápido removido deste dispositivo.';
    $tipo_mensagem = 'success';
} elseif (($_GET['sucesso'] ?? '') === 'webauthn_renomeado') {
    $mensagem = 'Nome do dispositivo atualizado.';
    $tipo_mensagem = 'success';
} elseif (!empty($_GET['sucesso_wa'])) {
    $mensagem = 'Login rápido ativado com sucesso!';
    $tipo_mensagem = 'success';
}

require_once 'geral/header.php';
?>

<main class="container py-4 mt-2 flex-grow-1" style="min-height: 100vh; padding-inline: var(--space-page-x);">

    <?php if ($mensagem && $tipo_mensagem === 'success'): ?>
        <script>window._pendingToast = <?= json_encode($mensagem) ?>;</script>
    <?php elseif ($mensagem): ?>
        <div class="alert alert-<?= $tipo_mensagem ?> alert-dismissible fade show d-flex align-items-center rounded-3 shadow-sm border-0" role="alert">
            <i class="bi bi-exclamation-triangle-fill me-2"></i>
            <strong><?= $mensagem ?></strong>
            <button type="button" class="btn-close opacity-50" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php
    $codigoRef  = $dadosUsuario['CodigoIndicacao'] ?? null;
    if ($codigoRef) {
        $protocolo = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
        $linkRef   = $protocolo . '://' . $_SERVER['HTTP_HOST'] . '/usuario/cadastro.php?ref=' . $codigoRef;
    }
    ?>
    <?php if (!empty($codigoRef)): ?>
    <!-- Widget de indicação -->
    <div class="rounded-4 p-4 mb-4" style="background:rgba(212,175,55,.05);border:1px solid rgba(212,175,55,.18);">
        <div class="d-flex align-items-center gap-3 mb-3 flex-wrap">
            <div class="rounded-3 d-flex align-items-center justify-content-center flex-shrink-0"
                style="width:40px;height:40px;background:rgba(212,175,55,.12);border:1px solid rgba(212,175,55,.25);">
                <i class="bi bi-share-fill" style="color:#d4af37;font-size:1.1rem;"></i>
            </div>
            <div>
                <div class="fw-semibold text-light">Seu link de indicação</div>
                <div class="text-secondary" style="font-size:.78rem;">Quando alguém se cadastrar pelo seu link e assinar um plano, você acumula recompensas.</div>
            </div>
        </div>
        <div class="d-flex align-items-center gap-2 flex-wrap">
            <code id="cfgLinkRef" class="px-3 py-2 rounded-3 flex-grow-1"
                style="background:rgba(0,0,0,.3);color:#d4af37;font-size:.82rem;word-break:break-all;display:block;">
                <?= htmlspecialchars($linkRef) ?>
            </code>
            <button onclick="cfgCopiarLink()" id="cfgBtnCopiar"
                class="btn btn-sm rounded-pill px-3 flex-shrink-0"
                style="background:rgba(212,175,55,.15);color:#d4af37;border:1px solid rgba(212,175,55,.3);">
                <i class="bi bi-clipboard me-1"></i> Copiar link
            </button>
            <button onclick="cfgCompartilharLink()" id="cfgBtnCompartilharLink"
                class="btn btn-sm rounded-pill px-3 flex-shrink-0 d-none"
                style="background:rgba(212,175,55,.15);color:#d4af37;border:1px solid rgba(212,175,55,.3);">
                <i class="bi bi-share-fill me-1"></i> Compartilhar
            </button>
        </div>
        <div class="mt-2 d-flex align-items-center gap-2 flex-wrap">
            <span class="text-secondary" style="font-size:.75rem;">Código:</span>
            <code style="color:#d4af37;font-size:.78rem;"><?= htmlspecialchars($codigoRef) ?></code>
            <span class="text-secondary" style="font-size:.72rem;">— esse mesmo código também serve pra convidar alguém pra uma carteira compartilhada.</span>
        </div>
    </div>
    <?php endif; ?>

    <div class="row g-4 mb-5">

        <div class="col-lg-6">
            <div class="card border-secondary-subtle shadow-sm rounded-4 h-100" style="background:var(--bg-card);">
                <div class="card-header border-bottom border-secondary-subtle bg-transparent p-4">
                    <h5 class="text-light fw-bold mb-0">Perfil Público</h5>
                </div>
                <div class="card-body p-4">
                    <form method="POST" action="">
                        <input type="hidden" name="action" value="update_profile">

                        <div class="mb-3">
                            <label class="form-label text-secondary small mb-1">E-mail de Acesso</label>
                            <input type="email" class="form-control bg-body-tertiary border-secondary-subtle text-secondary shadow-none" value="<?= htmlspecialchars($dadosUsuario['Email']) ?>" disabled>
                        </div>

                        <div class="mb-4">
                            <label for="nome" class="form-label text-light fw-semibold mb-1">Nome Completo</label>
                            <input type="text" name="nome" id="nome" class="form-control form-control-lg bg-transparent border-secondary-subtle text-light shadow-none" value="<?= htmlspecialchars($dadosUsuario['Nome']) ?>" required>
                        </div>

                        <?php
                        $telCfg = $dadosUsuario['Telefone'] ?? '';
                        if ($telCfg && strlen($telCfg) >= 12 && substr($telCfg, 0, 2) === '55') {
                            $d = substr($telCfg, 2);
                            if (strlen($d) === 11)      $telCfg = '(' . substr($d,0,2) . ') ' . substr($d,2,5) . '-' . substr($d,7);
                            elseif (strlen($d) === 10)  $telCfg = '(' . substr($d,0,2) . ') ' . substr($d,2,4) . '-' . substr($d,6);
                        }
                        ?>
                        <div class="mb-4">
                            <label for="cfg_telefone" class="form-label text-light fw-semibold mb-1 d-flex align-items-center gap-2">
                                WhatsApp <span class="text-secondary fw-normal" style="font-size:.78rem;">(opcional)</span>
                                <span tabindex="0" data-bs-toggle="tooltip" data-bs-placement="right"
                                      title="Usado apenas para enviar alertas de vencimento de faturas. Deixe em branco para não receber."
                                      style="cursor:help;line-height:1;">
                                    <i class="bi bi-info-circle text-secondary" style="font-size:.85rem;"></i>
                                </span>
                            </label>
                            <div class="input-group">
                                <span class="input-group-text bg-transparent border-secondary-subtle text-secondary"><i class="bi bi-whatsapp"></i></span>
                                <input type="tel" name="telefone" id="cfg_telefone"
                                       class="form-control form-control-lg bg-transparent border-secondary-subtle text-light shadow-none"
                                       value="<?= htmlspecialchars($telCfg) ?>"
                                       maxlength="15" placeholder="(11) 99999-9999"
                                       oninput="this.value=_maskTel(this.value)">
                            </div>
                        </div>

                        <div class="text-end">
                            <button type="submit" class="btn btn-outline-light rounded-pill px-4 fw-semibold transition-hover">
                                Salvar Alterações
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card border-secondary-subtle shadow-sm rounded-4 h-100" id="seguranca" style="background:var(--bg-card);">
                <div class="card-header border-bottom border-secondary-subtle bg-transparent p-4">
                    <h5 class="text-light fw-bold mb-0">Segurança</h5>
                </div>
                <div class="card-body p-4">
                    <form method="POST" action="" id="formSenha">
                        <input type="hidden" name="action" value="update_password">

                        <?php if ($isGoogleUser): ?>
                            <div class="alert alert-info border-0 small bg-info bg-opacity-10 text-info mb-4">
                                <i class="bi bi-google me-2"></i> Sua conta é vinculada ao Google. Crie uma senha abaixo caso queira acessar com seu e-mail manualmente.
                            </div>
                            <input type="hidden" name="senha_atual" value="GOOGLE_SSO">
                        <?php else: ?>
                            <div class="mb-4">
                                <label for="senha_atual" class="form-label text-light fw-semibold mb-1">Senha Atual</label>
                                <input type="password" name="senha_atual" id="senha_atual" class="form-control form-control-lg bg-transparent border-secondary-subtle text-light shadow-none" required placeholder="Digite sua senha atual">
                            </div>
                            <hr class="border-secondary-subtle opacity-50 mb-4">
                        <?php endif; ?>

                        <div class="mb-3">
                            <label for="nova_senha" class="form-label text-light fw-semibold mb-1">Nova Senha</label>
                            <input type="password" name="nova_senha" id="nova_senha" class="form-control form-control-lg bg-transparent border-secondary-subtle text-light shadow-none" required minlength="4" placeholder="No mínimo 4 caracteres">
                        </div>

                        <div class="mb-4">
                            <label for="confirma_senha" class="form-label text-light fw-semibold mb-1">Confirmar Nova Senha</label>
                            <input type="password" name="confirma_senha" id="confirma_senha" class="form-control form-control-lg bg-transparent border-secondary-subtle text-light shadow-none" required minlength="4" placeholder="Repita a nova senha">
                            <div class="invalid-feedback fw-bold">
                                As novas senhas não conferem!
                            </div>
                        </div>

                        <div class="text-end">
                            <button type="submit" class="btn btn-outline-warning rounded-pill px-4 fw-semibold transition-hover">
                                <?= $isGoogleUser ? 'Criar Senha' : 'Atualizar Senha' ?>
                            </button>
                        </div>
                    </form>

                    <hr class="border-secondary-subtle opacity-50 my-4">

                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h6 class="text-light fw-bold mb-0"><i class="bi bi-fingerprint me-2" style="color:var(--accent);"></i>Login rápido do dispositivo</h6>
                        <button type="button" class="btn btn-sm btn-outline-warning rounded-pill px-3 fw-semibold" id="btnCadastrarBiometria" onclick="waCadastrarBiometria()">
                            <i class="bi bi-plus-lg me-1"></i> Ativar neste dispositivo
                        </button>
                    </div>
                    <p class="text-secondary small mb-3" id="waSemSuporte" style="display:none;">
                        Este navegador/dispositivo não tem suporte a esse tipo de login.
                    </p>

                    <?php if (empty($credenciaisWebAuthn)): ?>
                        <p class="text-secondary small mb-0" id="waListaVazia">Nenhum dispositivo cadastrado ainda. Use digital, reconhecimento facial, PIN ou outro método do aparelho pra entrar sem digitar senha.</p>
                    <?php else: ?>
                        <div class="d-flex flex-column gap-2">
                            <?php foreach ($credenciaisWebAuthn as $cred): ?>
                                <div class="d-flex align-items-center justify-content-between p-2 px-3 rounded-3" style="background:var(--bg-hover);">
                                    <div>
                                        <span class="text-light fw-semibold small"><?= htmlspecialchars($cred['Apelido'] ?: 'Dispositivo sem nome') ?></span>
                                        <div class="text-secondary" style="font-size:0.75rem;">
                                            Cadastrado em <?= date('d/m/Y', strtotime($cred['CriadoEm'])) ?>
                                            <?php if ($cred['UltimoUso']): ?> · último uso em <?= date('d/m/Y', strtotime($cred['UltimoUso'])) ?><?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="d-flex align-items-center gap-1">
                                        <button type="button" class="btn btn-sm btn-link text-secondary text-decoration-none p-1"
                                            onclick="waAbrirEdicaoNome('<?= htmlspecialchars($cred['IDCredencial'], ENT_QUOTES) ?>', '<?= htmlspecialchars(addslashes($cred['Apelido'] ?? ''), ENT_QUOTES) ?>')">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        <button type="button" class="btn btn-sm btn-link text-danger text-decoration-none p-1"
                                            onclick="waAbrirRemocao('<?= htmlspecialchars($cred['IDCredencial'], ENT_QUOTES) ?>')">
                                            <i class="bi bi-trash3"></i>
                                        </button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- APARÊNCIA / TEMA -->
        <div class="col-12 mt-2">
            <?php
            $temasCfg     = function_exists('temasDisponiveis') ? temasDisponiveis() : ['dark' => ['nome' => 'Dark', 'conquista' => null, 'secao' => 'padrao']];
            $temaAtivo    = $dadosUsuario['Tema'] ?? ($_SESSION['tema'] ?? 'dark');
            $planoUsuario = strtolower($_SESSION['plano'] ?? 'free');
            $planoPeso    = ['free' => 0, 'pro' => 1, 'vip' => 2];

            $temasPadrao    = array_filter($temasCfg, fn($t) => ($t['secao'] ?? 'padrao') === 'padrao');
            $temasAdicionais = array_filter($temasCfg, fn($t) => ($t['secao'] ?? 'padrao') === 'adicional');

            // Renderiza um card de tema
            $renderCard = function (string $slug, array $info) use ($temaAtivo, $planoUsuario, $planoPeso): void {
                $ativo              = $temaAtivo === $slug;
                $conquista          = $info['conquista'] ?? null;
                $planoMinimo        = $info['plano_minimo'] ?? null;
                $bloqConquista      = $conquista && !(function_exists('usuarioPossuiConquista') && usuarioPossuiConquista($conquista));
                $bloqPlano          = $planoMinimo && (($planoPeso[$planoUsuario] ?? 0) < ($planoPeso[$planoMinimo] ?? 0));
                $bloqueado          = $bloqConquista || $bloqPlano;
                $labelPlano         = $planoMinimo ? strtoupper($planoMinimo) : '';
            ?>
                <div class="col-6 col-md-3">
                    <form method="POST" action="">
                        <input type="hidden" name="action" value="trocar_tema">
                        <input type="hidden" name="tema" value="<?= htmlspecialchars($slug) ?>">
                        <button type="submit" <?= $bloqueado ? 'disabled' : '' ?>
                            class="btn w-100 p-0 border-0 rounded-4 overflow-hidden position-relative"
                            style="outline:2.5px solid <?= $ativo ? 'var(--accent)' : 'transparent' ?>;transition:outline-color .2s,box-shadow .2s;<?= $bloqueado ? 'opacity:.6;cursor:not-allowed;' : '' ?>">

                            <?php if ($slug === 'dark'): ?>
                                <div class="rounded-4 overflow-hidden" style="background:#121418;padding:14px 10px;">
                                    <div style="background:#1e2126;border-radius:8px;padding:8px;margin-bottom:6px;">
                                        <div style="height:6px;width:55%;background:#d4af37;border-radius:4px;margin-bottom:5px;"></div>
                                        <div style="height:5px;width:75%;background:#2d3139;border-radius:4px;"></div>
                                    </div>
                                    <div class="d-flex gap-1">
                                        <div style="flex:1;background:#252a31;border-radius:6px;height:24px;"></div>
                                        <div style="flex:1;background:#252a31;border-radius:6px;height:24px;"></div>
                                    </div>
                                </div>
                            <?php elseif ($slug === 'white'): ?>
                                <div class="rounded-4 overflow-hidden" style="background:#f2f5f9;padding:14px 10px;">
                                    <div style="background:#fff;border-radius:8px;padding:8px;margin-bottom:6px;border:1px solid #d0d8e8;">
                                        <div style="height:6px;width:55%;background:#ffc300;border-radius:4px;margin-bottom:5px;"></div>
                                        <div style="height:5px;width:75%;background:#c9d3e0;border-radius:4px;"></div>
                                    </div>
                                    <div class="d-flex gap-1">
                                        <div style="flex:1;background:#eaeff6;border-radius:6px;height:24px;border:1px solid #d0d8e8;"></div>
                                        <div style="flex:1;background:#eaeff6;border-radius:6px;height:24px;border:1px solid #d0d8e8;"></div>
                                    </div>
                                </div>
                            <?php elseif ($slug === 'sistema'): ?>
                                <div class="rounded-4 overflow-hidden" style="background:linear-gradient(to right,#121418 50%,#f2f5f9 50%);padding:14px 10px;">
                                    <div style="border-radius:8px;padding:8px;margin-bottom:6px;background:linear-gradient(to right,#1e2126 50%,#ffffff 50%);border:1px solid rgba(128,128,128,0.15);">
                                        <div style="height:6px;width:55%;background:linear-gradient(to right,#d4af37,#ffc300);border-radius:4px;margin-bottom:5px;"></div>
                                        <div style="height:5px;width:75%;background:linear-gradient(to right,#2d3139,#c9d3e0);border-radius:4px;"></div>
                                    </div>
                                    <div class="d-flex gap-1 align-items-center justify-content-center py-1">
                                        <i class="bi bi-moon-stars-fill" style="color:#d4af37;font-size:0.8rem;"></i>
                                        <span style="color:#888;font-size:0.65rem;margin:0 6px;">·</span>
                                        <i class="bi bi-sun-fill" style="color:#b8962e;font-size:0.8rem;"></i>
                                    </div>
                                </div>
                            <?php elseif ($slug === 'oceano'): ?>
                                <div class="rounded-4 overflow-hidden" style="background:#0d1230;padding:14px 10px;">
                                    <div style="background:#16204a;border-radius:8px;padding:8px;margin-bottom:6px;border:1px solid #253560;">
                                        <div style="height:6px;width:55%;background:linear-gradient(90deg,#e06c43,#f09a78);border-radius:4px;margin-bottom:5px;"></div>
                                        <div style="height:5px;width:75%;background:#253560;border-radius:4px;"></div>
                                    </div>
                                    <div class="d-flex gap-1">
                                        <div style="flex:1;background:#1f2d60;border-radius:6px;height:24px;border:1px solid #253560;"></div>
                                        <div style="flex:1;background:#1f2d60;border-radius:6px;height:24px;border:1px solid #253560;"></div>
                                    </div>
                                </div>
                            <?php elseif ($slug === 'ambar'): ?>
                                <div class="rounded-4 overflow-hidden" style="background:#0e0c1a;padding:14px 10px;">
                                    <div style="background:#181530;border-radius:8px;padding:8px;margin-bottom:6px;border:1px solid #302c50;">
                                        <div style="height:6px;width:55%;background:linear-gradient(90deg,#f0b030,#f5d060);border-radius:4px;margin-bottom:5px;"></div>
                                        <div style="height:5px;width:75%;background:#302c50;border-radius:4px;"></div>
                                    </div>
                                    <div class="d-flex gap-1">
                                        <div style="flex:1;background:#221e42;border-radius:6px;height:24px;border:1px solid #302c50;"></div>
                                        <div style="flex:1;background:#221e42;border-radius:6px;height:24px;border:1px solid #302c50;"></div>
                                    </div>
                                </div>
                            <?php elseif ($slug === 'aurora'): ?>
                                <div class="rounded-4 overflow-hidden" style="background:#0a0518;padding:14px 10px;">
                                    <div style="background:#150d2e;border-radius:8px;padding:8px;margin-bottom:6px;border:1px solid #2a1e50;">
                                        <div style="height:6px;width:55%;background:linear-gradient(90deg,#38bcd8,#6dd5ec);border-radius:4px;margin-bottom:5px;"></div>
                                        <div style="height:5px;width:75%;background:#2a1e50;border-radius:4px;"></div>
                                    </div>
                                    <div class="d-flex gap-1">
                                        <div style="flex:1;background:#1e1440;border-radius:6px;height:24px;border:1px solid #2a1e50;"></div>
                                        <div style="flex:1;background:#1e1440;border-radius:6px;height:24px;border:1px solid #2a1e50;"></div>
                                    </div>
                                </div>
                            <?php elseif ($slug === 'cosmos'): ?>
                                <div class="rounded-4 overflow-hidden" style="background:#0d0a1a;padding:14px 10px;">
                                    <div style="background:#1a1330;border-radius:8px;padding:8px;margin-bottom:6px;border:1px solid #2d2550;">
                                        <div style="height:6px;width:55%;background:linear-gradient(90deg,#7c3aed,#a78bfa);border-radius:4px;margin-bottom:5px;"></div>
                                        <div style="height:5px;width:75%;background:#2d2550;border-radius:4px;"></div>
                                    </div>
                                    <div class="d-flex gap-1">
                                        <div style="flex:1;background:#221b3a;border-radius:6px;height:24px;border:1px solid #2d2550;"></div>
                                        <div style="flex:1;background:#221b3a;border-radius:6px;height:24px;border:1px solid #2d2550;"></div>
                                    </div>
                                </div>
                            <?php elseif ($slug === 'fortune'): ?>
                                <div class="rounded-4 overflow-hidden" style="background:#0c0900;padding:14px 10px;">
                                    <div style="background:#161100;border-radius:8px;padding:8px;margin-bottom:6px;border:1px solid #2e2200;">
                                        <div style="height:6px;width:55%;background:linear-gradient(90deg,#d4af37,#f5e642);border-radius:4px;margin-bottom:5px;"></div>
                                        <div style="height:5px;width:75%;background:#2e2200;border-radius:4px;"></div>
                                    </div>
                                    <div class="d-flex gap-1">
                                        <div style="flex:1;background:#221a00;border-radius:6px;height:24px;border:1px solid #2e2200;"></div>
                                        <div style="flex:1;background:#221a00;border-radius:6px;height:24px;border:1px solid #2e2200;"></div>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <?php if ($ativo): ?>
                                <span class="position-absolute top-0 end-0 m-1 badge rounded-pill"
                                    style="background:var(--accent);color:#000;font-size:0.6rem;padding:3px 6px;">
                                    <i class="bi bi-check-lg"></i>
                                </span>
                            <?php endif; ?>
                            <?php if ($bloqueado): ?>
                                <span class="position-absolute top-0 start-0 m-1 badge rounded-pill"
                                    style="background:rgba(0,0,0,0.55);color:#fff;font-size:0.6rem;padding:3px 6px;backdrop-filter:blur(4px);">
                                    <i class="bi bi-lock-fill me-1"></i><?= $labelPlano ?>
                                </span>
                            <?php endif; ?>
                        </button>
                        <p class="text-center mt-1 mb-0 small fw-semibold <?= $ativo ? '' : 'text-secondary' ?>">
                            <?= htmlspecialchars($info['nome']) ?>
                        </p>
                    </form>
                </div>
            <?php
            };
            ?>
            <!-- Navegação -->
            <?php $navAtual = $_SESSION['nav_tipo'] ?? 'sidebar'; ?>
            <div class="card border-secondary-subtle shadow-sm rounded-4 mb-4" style="background:var(--bg-card);">
                <div class="card-header border-bottom border-secondary-subtle bg-transparent p-4">
                    <h5 class="fw-bold mb-0"><i class="bi bi-layout-sidebar me-2" style="color:var(--accent);"></i> Navegação</h5>
                </div>
                <div class="card-body p-4">
                    <p class="text-secondary small mb-4">Escolha como prefere navegar pelo sistema.</p>
                    <form method="POST" class="d-flex gap-3 flex-wrap">
                        <input type="hidden" name="action" value="trocar_nav">

                        <!-- Sidebar -->
                        <label class="nav-pref-card <?= $navAtual === 'sidebar' ? 'active' : '' ?>">
                            <input type="radio" name="nav_tipo" value="sidebar" <?= $navAtual === 'sidebar' ? 'checked' : '' ?> onchange="this.form.submit()" style="display:none;">
                            <!-- mini preview: coluna lateral + conteúdo -->
                            <div class="nav-pref-preview" style="display:flex;gap:5px;padding:6px;">
                                <div style="width:13px;background:var(--bg-hover);border-radius:4px;flex-shrink:0;display:flex;flex-direction:column;align-items:center;padding:5px 0;gap:4px;">
                                    <div style="width:6px;height:6px;background:var(--accent);border-radius:50%;"></div>
                                    <div style="width:5px;height:2px;background:var(--card-border-color);border-radius:2px;"></div>
                                    <div style="width:5px;height:2px;background:var(--card-border-color);border-radius:2px;"></div>
                                    <div style="width:5px;height:2px;background:var(--card-border-color);border-radius:2px;"></div>
                                </div>
                                <div style="flex:1;display:flex;flex-direction:column;gap:4px;padding-top:3px;">
                                    <div style="height:3px;width:80%;background:var(--accent);border-radius:2px;opacity:.5;"></div>
                                    <div style="height:2px;width:60%;background:var(--card-border-color);border-radius:2px;"></div>
                                    <div style="height:2px;width:70%;background:var(--card-border-color);border-radius:2px;"></div>
                                    <div style="height:2px;width:50%;background:var(--card-border-color);border-radius:2px;"></div>
                                </div>
                            </div>
                            <span>Barra lateral</span>
                        </label>

                        <!-- Top navbar -->
                        <label class="nav-pref-card <?= $navAtual === 'top' ? 'active' : '' ?>">
                            <input type="radio" name="nav_tipo" value="top" <?= $navAtual === 'top' ? 'checked' : '' ?> onchange="this.form.submit()" style="display:none;">
                            <!-- mini preview: navbar no topo + conteúdo -->
                            <div class="nav-pref-preview" style="display:flex;flex-direction:column;gap:5px;padding:6px;">
                                <div style="height:12px;background:var(--bg-hover);border-radius:4px;flex-shrink:0;display:flex;align-items:center;padding:0 5px;gap:3px;">
                                    <div style="width:6px;height:6px;background:var(--accent);border-radius:50%;flex-shrink:0;"></div>
                                    <div style="width:5px;height:2px;background:var(--card-border-color);border-radius:2px;"></div>
                                    <div style="width:5px;height:2px;background:var(--card-border-color);border-radius:2px;"></div>
                                    <div style="width:5px;height:2px;background:var(--card-border-color);border-radius:2px;"></div>
                                </div>
                                <div style="flex:1;display:flex;flex-direction:column;gap:4px;padding:0 2px;">
                                    <div style="height:3px;width:80%;background:var(--accent);border-radius:2px;opacity:.5;"></div>
                                    <div style="height:2px;width:60%;background:var(--card-border-color);border-radius:2px;"></div>
                                    <div style="height:2px;width:70%;background:var(--card-border-color);border-radius:2px;"></div>
                                </div>
                            </div>
                            <span>Barra superior</span>
                        </label>

                    </form>
                </div>
            </div>

            <div class="card border-secondary-subtle shadow-sm rounded-4" style="background:var(--bg-card);">
                <div class="card-header border-bottom border-secondary-subtle bg-transparent p-4">
                    <h5 class="fw-bold mb-0"><i class="bi bi-palette2 me-2" style="color:var(--accent);"></i> Aparência</h5>
                </div>
                <div class="card-body p-4">
                    <p class="text-secondary small mb-4">Escolha como o Auralis aparece para você. Temas adicionais podem ser desbloqueados por plano ou conquistas.</p>

                    <!-- Seção: Padrão -->
                    <p class="small fw-semibold text-uppercase mb-2" style="color:var(--text-muted);letter-spacing:.07em;">Padrão</p>
                    <div class="row g-3 mb-4">
                        <?php foreach ($temasPadrao as $slug => $info) {
                            $renderCard($slug, $info);
                        } ?>
                    </div>

                    <!-- Seção: Adicionais -->
                    <p class="small fw-semibold text-uppercase mb-2" style="color:var(--text-muted);letter-spacing:.07em;">Adicionais</p>
                    <div class="row g-3">
                        <?php foreach ($temasAdicionais as $slug => $info) {
                            $renderCard($slug, $info);
                        } ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- INSTALAR COMO APP -->
        <div class="col-12 mt-2">
            <div class="card border-secondary-subtle shadow-sm rounded-4" style="background:var(--bg-card);">
                <div class="card-header border-bottom border-secondary-subtle bg-transparent p-4">
                    <h5 class="fw-bold mb-0">
                        <i class="bi bi-phone-fill me-2" style="color:var(--accent);"></i> Instalar como Aplicativo
                    </h5>
                </div>
                <div class="card-body p-4">

                    <!-- Já instalado -->
                    <div id="pwaInstalled" style="display:none;">
                        <div class="d-flex align-items-center gap-3">
                            <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0"
                                 style="width:48px;height:48px;background:rgba(16,185,129,0.12);border:1px solid rgba(16,185,129,0.3);">
                                <i class="bi bi-check-circle-fill" style="color:#10b981;font-size:1.3rem;"></i>
                            </div>
                            <div>
                                <div class="fw-semibold text-light">Auralis já está instalado</div>
                                <div class="text-secondary small">Você está acessando via aplicativo instalado.</div>
                            </div>
                        </div>
                    </div>

                    <!-- Prompt disponível → instalar direto -->
                    <div id="pwaCanInstall" style="display:none;">
                        <div class="fw-semibold text-light mb-1">Acesse o Auralis como um app nativo</div>
                        <div class="text-secondary small mb-3">Abra direto da área de trabalho ou tela inicial, sem precisar abrir o navegador.</div>
                        <button class="btn fw-bold text-dark rounded-pill px-4 py-2"
                                style="background:linear-gradient(135deg,#FFB800,#D4AF37);font-size:0.9rem;"
                                onclick="auralisInstalar(); setTimeout(atualizarCardInstalar, 800);">
                            <i class="bi bi-download me-2"></i> Instalar Agora
                        </button>
                    </div>

                    <!-- Sem prompt → instruções manuais por browser -->
                    <div id="pwaManual" style="display:none;">
                        <div class="fw-semibold text-light mb-3">Como instalar manualmente</div>
                        <div id="pwaManualIOS" style="display:none;" class="text-secondary small lh-lg">
                            <div class="d-flex align-items-center gap-2 mb-1">
                                <span class="d-inline-flex align-items-center justify-content-center rounded-circle flex-shrink-0 fw-bold text-dark"
                                      style="width:20px;height:20px;background:var(--accent);font-size:0.7rem;">1</span>
                                Toque em <i class="bi bi-box-arrow-up mx-1"></i><strong>Compartilhar</strong> na barra do Safari
                            </div>
                            <div class="d-flex align-items-center gap-2">
                                <span class="d-inline-flex align-items-center justify-content-center rounded-circle flex-shrink-0 fw-bold text-dark"
                                      style="width:20px;height:20px;background:var(--accent);font-size:0.7rem;">2</span>
                                Selecione <strong>"Adicionar à Tela de Início"</strong>
                            </div>
                        </div>
                        <div id="pwaManualChrome" style="display:none;" class="text-secondary small lh-lg">
                            <div class="d-flex align-items-center gap-2 mb-1">
                                <span class="d-inline-flex align-items-center justify-content-center rounded-circle flex-shrink-0 fw-bold text-dark"
                                      style="width:20px;height:20px;background:var(--accent);font-size:0.7rem;">1</span>
                                Clique no menu <i class="bi bi-three-dots-vertical mx-1"></i> no canto superior do navegador
                            </div>
                            <div class="d-flex align-items-center gap-2">
                                <span class="d-inline-flex align-items-center justify-content-center rounded-circle flex-shrink-0 fw-bold text-dark"
                                      style="width:20px;height:20px;background:var(--accent);font-size:0.7rem;">2</span>
                                Selecione <strong>"Instalar página como aplicativo"</strong> ou <strong>"Adicionar à tela inicial"</strong>
                            </div>
                        </div>
                        <p class="text-secondary small mt-3 mb-0" style="opacity:.65;">
                            <i class="bi bi-info-circle me-1"></i>
                            Se a opção não aparecer, abra o site em uma nova aba e aguarde alguns instantes.
                        </p>
                    </div>

                </div>
            </div>
        </div>

        <!-- PREFERÊNCIAS DO DASHBOARD -->
        <div class="col-12 mt-2">
            <div class="card border-secondary-subtle shadow-sm rounded-4" style="background:var(--bg-card);">
                <div class="card-header border-bottom border-secondary-subtle bg-transparent p-4">
                    <h5 class="fw-bold text-light mb-0">
                        <i class="bi bi-layout-three-columns me-2" style="color:var(--accent);"></i> Preferências do Dashboard
                    </h5>
                </div>
                <div class="card-body p-4">
                    <p class="text-secondary small mb-4">Escolha quais informações aparecem no painel principal.</p>
                    <form method="POST" id="formPrefDash">
                        <input type="hidden" name="action" value="salvar_pref_dashboard">
                        <div class="d-flex flex-column gap-1">
                            <?php
                            $itensDash = [
                                'cofrinhos'        => ['Cofrinhos',           'Exibe o resumo dos cofrinhos ativos'],
                                'cartoes'          => ['Cartões de Crédito',  'Exibe faturas e cartões em aberto'],
                                'receita_pendente' => ['Receita pendente',    'Mostra "A receber" no card de receitas'],
                                'despesa_pendente' => ['Despesa pendente',    'Mostra "A pagar" no card de despesas'],
                                'saldo_projetado'  => ['Saldo projetado',     'Mostra estimativa de saldo incluindo pendentes'],
                            ];
                            foreach ($itensDash as $key => [$label, $desc]):
                                $checked = ($dashPrefsCfg[$key] ?? '1') === '1' ? 'checked' : '';
                            ?>
                            <div class="d-flex justify-content-between align-items-center py-3 border-bottom border-secondary-subtle">
                                <div>
                                    <div class="fw-semibold text-light" style="font-size:0.9rem;"><?= $label ?></div>
                                    <div class="text-secondary" style="font-size:0.78rem;"><?= $desc ?></div>
                                </div>
                                <div class="form-check form-switch mb-0 ms-3">
                                    <input class="form-check-input" type="checkbox"
                                           name="dash_<?= $key ?>" id="dash_<?= $key ?>"
                                           <?= $checked ?> onchange="salvarPrefDash(this)">
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- ASSISTENTE WHATSAPP -->
        <?php if (!empty($dadosUsuario['Telefone'])): ?>
        <div class="col-12 mt-2">
            <div class="card border-secondary-subtle shadow-sm rounded-4" style="background:var(--bg-card);">
                <div class="card-header border-bottom border-secondary-subtle bg-transparent p-4">
                    <h5 class="fw-bold text-light mb-0">
                        <i class="bi bi-whatsapp me-2" style="color:#25d366;"></i> Assistente WhatsApp
                    </h5>
                </div>
                <div class="card-body p-4">
                    <p class="text-secondary small mb-4">Escolha como o assistente se comunica com você pelo WhatsApp.</p>
                    <form method="POST" id="formPrefWa">
                        <input type="hidden" name="action" value="salvar_pref_wa">
                        <div class="row g-3 mb-4">
                            <div class="col-sm-6">
                                <label class="d-block cursor-pointer" style="cursor:pointer;">
                                    <input type="radio" name="wa_personalidade" value="parceiro"
                                           class="d-none wa-pers-radio"
                                           <?= $waPersonalidade === 'parceiro' ? 'checked' : '' ?>>
                                    <div class="rounded-4 p-4 h-100 wa-pers-option <?= $waPersonalidade === 'parceiro' ? 'wa-pers-active' : '' ?>"
                                         style="border:2px solid <?= $waPersonalidade === 'parceiro' ? 'var(--accent)' : 'rgba(255,255,255,.12)' ?>;background:<?= $waPersonalidade === 'parceiro' ? 'rgba(212,175,55,.06)' : 'rgba(255,255,255,.03)' ?>;transition:.2s;">
                                        <div class="d-flex align-items-center gap-3 mb-2">
                                            <span style="font-size:1.5rem;">🤝</span>
                                            <div class="fw-semibold text-light">Parceiro</div>
                                        </div>
                                        <div class="text-secondary small">Casual e descontraído. Responde como um amigo que entende de finanças — usa expressões naturais e emojis com moderação.</div>
                                        <div class="mt-3 p-3 rounded-3" style="background:rgba(0,0,0,.25);font-size:.78rem;color:#adb5bd;font-style:italic;">
                                            "Opa! Anotado 👍<br>📉 <b>Uber</b>: R$ 23,50 · hoje"
                                        </div>
                                    </div>
                                </label>
                            </div>
                            <div class="col-sm-6">
                                <label class="d-block" style="cursor:pointer;">
                                    <input type="radio" name="wa_personalidade" value="profissional"
                                           class="d-none wa-pers-radio"
                                           <?= $waPersonalidade === 'profissional' ? 'checked' : '' ?>>
                                    <div class="rounded-4 p-4 h-100 wa-pers-option <?= $waPersonalidade === 'profissional' ? 'wa-pers-active' : '' ?>"
                                         style="border:2px solid <?= $waPersonalidade === 'profissional' ? 'var(--accent)' : 'rgba(255,255,255,.12)' ?>;background:<?= $waPersonalidade === 'profissional' ? 'rgba(212,175,55,.06)' : 'rgba(255,255,255,.03)' ?>;transition:.2s;">
                                        <div class="d-flex align-items-center gap-3 mb-2">
                                            <span style="font-size:1.5rem;">💼</span>
                                            <div class="fw-semibold text-light">Profissional</div>
                                        </div>
                                        <div class="text-secondary small">Direto e objetivo. Respostas concisas sem expressões informais, ideal para quem prefere comunicação formal.</div>
                                        <div class="mt-3 p-3 rounded-3" style="background:rgba(0,0,0,.25);font-size:.78rem;color:#adb5bd;font-style:italic;">
                                            "✅ Registrado.<br>📉 <b>Uber</b>: R$ 23,50 · 07/07/2026"
                                        </div>
                                    </div>
                                </label>
                            </div>
                        </div>
                        <div class="text-end">
                            <button type="submit" class="btn btn-outline-light rounded-pill px-4 fw-semibold transition-hover">
                                Salvar Preferência
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- ZONA DE RISCO -->
        <div class="col-12 mt-2">
            <div class="card border-danger border-opacity-25 bg-transparent shadow-sm rounded-4">
                <div class="card-body p-4 d-flex justify-content-between align-items-center flex-wrap gap-3">
                    <div>
                        <h5 class="text-danger fw-bold mb-1"><i class="bi bi-exclamation-triangle-fill me-2"></i> Zona de Risco: Excluir Conta</h5>
                        <p class="text-secondary small mb-0">Esta ação é irreversível. Todos os seus dados, transações e carteiras serão apagados permanentemente do servidor.</p>
                    </div>
                    <button type="button" class="btn btn-outline-danger rounded-pill fw-semibold px-4 transition-hover" data-bs-toggle="modal" data-bs-target="#modalExcluirConta">
                        Apagar Minha Conta
                    </button>
                </div>
            </div>
        </div>

    </div>

</main>

<script>
function _maskTel(v) {
    v = v.replace(/\D/g, '').slice(0, 11);
    if (v.length > 6) {
        v = '(' + v.slice(0,2) + ') ' + v.slice(2, v.length > 10 ? 7 : 6) + '-' + v.slice(v.length > 10 ? 7 : 6);
    } else if (v.length > 2) {
        v = '(' + v.slice(0,2) + ') ' + v.slice(2);
    } else if (v.length > 0) {
        v = '(' + v;
    }
    return v;
}
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function(el) { new bootstrap.Tooltip(el); });

    // Personalidade WA: highlight ao selecionar
    document.querySelectorAll('.wa-pers-radio').forEach(function(radio) {
        radio.addEventListener('change', function() {
            document.querySelectorAll('.wa-pers-option').forEach(function(opt) {
                opt.style.borderColor = 'rgba(255,255,255,.12)';
                opt.style.background  = 'rgba(255,255,255,.03)';
            });
            var opt = this.closest('label').querySelector('.wa-pers-option');
            opt.style.borderColor = 'var(--accent)';
            opt.style.background  = 'rgba(212,175,55,.06)';
        });
    });
});
</script>

<script>
function cfgCopiarLink() {
    var texto = document.getElementById('cfgLinkRef').textContent.trim();
    navigator.clipboard.writeText(texto).then(function() {
        var btn = document.getElementById('cfgBtnCopiar');
        var orig = btn.innerHTML;
        btn.innerHTML = '<i class="bi bi-check2 me-1"></i> Copiado!';
        setTimeout(function() { btn.innerHTML = orig; }, 2000);
    });
}

function cfgCompartilharLink() {
    var link = document.getElementById('cfgLinkRef').textContent.trim();
    if (navigator.share) {
        navigator.share({ title: 'Auralis', text: 'Ei, usa meu link pra entrar no Auralis:', url: link }).catch(function() {});
    }
}

document.addEventListener('DOMContentLoaded', function() {
    if (navigator.share) {
        var btn = document.getElementById('cfgBtnCompartilharLink');
        if (btn) btn.classList.remove('d-none');
    }
});
</script>

<div class="modal fade" id="modalCadastrarBiometria" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-secondary-subtle shadow-lg rounded-4" style="background:var(--bg-card);">
            <div class="modal-header border-bottom border-secondary-subtle">
                <h5 class="modal-title text-light fw-bold"><i class="bi bi-fingerprint me-2" style="color:var(--accent);"></i> Ativar login rápido</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <p class="text-secondary small mb-3">Dê um nome pra esse dispositivo, pra reconhecer depois na sua lista.</p>
                <div class="mb-2">
                    <label for="waApelidoInput" class="form-label text-light fw-semibold mb-1">Nome do dispositivo</label>
                    <input type="text" id="waApelidoInput" class="form-control form-control-lg bg-transparent border-secondary-subtle text-light shadow-none" maxlength="60" placeholder="Ex.: Meu celular">
                </div>
                <div class="alert border-0 small mt-3 mb-0 d-none" id="waErro"></div>
            </div>
            <div class="modal-footer border-top border-secondary-subtle">
                <button type="button" class="btn btn-secondary rounded-pill px-4 fw-semibold" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-outline-warning rounded-pill px-4 fw-bold" id="btnConfirmarBiometria" onclick="waConfirmarCadastro()">
                    <i class="bi bi-fingerprint me-1"></i> Ativar
                </button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="modalEditarNomeBiometria" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-secondary-subtle shadow-lg rounded-4" style="background:var(--bg-card);">
            <form method="POST" action="usuario/webauthn_renomear.php">
                <div class="modal-header border-bottom border-secondary-subtle">
                    <h5 class="modal-title text-light fw-bold"><i class="bi bi-pencil me-2" style="color:var(--accent);"></i> Renomear dispositivo</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <input type="hidden" name="id_credencial" id="waEditarId">
                    <label for="waEditarApelidoInput" class="form-label text-light fw-semibold mb-1">Nome do dispositivo</label>
                    <input type="text" name="apelido" id="waEditarApelidoInput" class="form-control form-control-lg bg-transparent border-secondary-subtle text-light shadow-none" maxlength="60" placeholder="Ex.: Meu celular">
                </div>
                <div class="modal-footer border-top border-secondary-subtle">
                    <button type="button" class="btn btn-secondary rounded-pill px-4 fw-semibold" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-outline-warning rounded-pill px-4 fw-bold">Salvar</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="modalRemoverBiometria" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-danger border-opacity-50 shadow-lg rounded-4" style="background:var(--bg-card);">
            <form method="POST" action="usuario/webauthn_remover.php">
                <div class="modal-header border-bottom border-secondary-subtle">
                    <h5 class="modal-title text-danger fw-bold"><i class="bi bi-trash3 me-2"></i> Remover dispositivo</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <input type="hidden" name="id_credencial" id="waRemoverId">
                    <p class="text-light mb-0">Remover esse dispositivo? Você não vai mais poder entrar com ele sem senha.</p>
                </div>
                <div class="modal-footer border-top border-secondary-subtle">
                    <button type="button" class="btn btn-secondary rounded-pill px-4 fw-semibold" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-danger rounded-pill px-4 fw-bold">Remover</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="modalExcluirConta" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-danger border-opacity-50 shadow-lg rounded-4" style="background:var(--bg-card);">
            <div class="modal-header border-bottom border-secondary-subtle">
                <h5 class="modal-title text-danger fw-bold"><i class="bi bi-shield-x me-2"></i> Verificação de Segurança</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="">
                <div class="modal-body p-4">
                    <input type="hidden" name="action" value="delete_account">

                    <?php if ($isGoogleUser): ?>
                        <p class="text-light mb-4">Como você acessa o sistema via Google, digite a palavra <strong class="text-danger">EXCLUIR</strong> abaixo para confirmar a exclusão definitiva da conta:</p>
                        <div class="mb-3">
                            <label for="senha_confirmacao" class="form-label text-secondary small">Palavra de Confirmação</label>
                            <input type="text" name="senha_confirmacao" id="senha_confirmacao" class="form-control form-control-lg bg-transparent border-secondary-subtle text-light shadow-none" required placeholder="Digite EXCLUIR" pattern="EXCLUIR">
                        </div>
                    <?php else: ?>
                        <p class="text-light mb-4">Para confirmar que é você mesmo e excluir definitivamente o seu Auralis, digite sua senha abaixo:</p>
                        <div class="mb-3">
                            <label for="senha_confirmacao" class="form-label text-secondary small">Sua Senha</label>
                            <input type="password" name="senha_confirmacao" id="senha_confirmacao" class="form-control form-control-lg bg-transparent border-secondary-subtle text-light shadow-none" required placeholder="Digite sua senha">
                        </div>
                    <?php endif; ?>

                </div>
                <div class="modal-footer border-top border-secondary-subtle">
                    <button type="button" class="btn btn-secondary rounded-pill px-4 fw-semibold" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-danger rounded-pill px-4 fw-bold">Sim, Excluir Tudo</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function salvarPrefDash(el) {
    var fd = new FormData(el.form);
    fetch('configuracoes.php', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: fd
    })
    .then(function(r) { return r.json(); })
    .then(function(d) { if (typeof auralisToast === 'function') auralisToast(d.ok ? 'Preferências salvas!' : 'Erro ao salvar.'); })
    .catch(function() { el.checked = !el.checked; });
}
</script>

<script>
    // Validação Front-End da Nova Senha
    const formSenha = document.getElementById('formSenha');
    const inputNovaSenha = document.getElementById('nova_senha');
    const inputConfirmaSenha = document.getElementById('confirma_senha');

    if (formSenha) {
        formSenha.addEventListener('submit', function(e) {
            if (inputNovaSenha.value !== inputConfirmaSenha.value) {
                e.preventDefault();
                inputConfirmaSenha.classList.add('is-invalid');
                inputConfirmaSenha.focus();
            } else {
                inputConfirmaSenha.classList.remove('is-invalid');
            }
        });

        inputConfirmaSenha.addEventListener('input', function() {
            inputConfirmaSenha.classList.remove('is-invalid');
        });
    }
</script>

<script>
function atualizarCardInstalar() {
    var standalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
    var isIOS      = /iPad|iPhone|iPod/.test(navigator.userAgent) && !window.MSStream;

    var elInstalled   = document.getElementById('pwaInstalled');
    var elCanInstall  = document.getElementById('pwaCanInstall');
    var elManual      = document.getElementById('pwaManual');
    if (!elInstalled) return;

    elInstalled.style.display  = 'none';
    elCanInstall.style.display = 'none';
    elManual.style.display     = 'none';

    if (standalone) {
        elInstalled.style.display = '';
    } else if (window.auralisInstallPrompt || isIOS) {
        elCanInstall.style.display = '';
    } else {
        elManual.style.display = '';
        var elIOS    = document.getElementById('pwaManualIOS');
        var elChrome = document.getElementById('pwaManualChrome');
        if (isIOS) { if (elIOS) elIOS.style.display = ''; }
        else        { if (elChrome) elChrome.style.display = ''; }
    }
}

// Atualiza quando o prompt for capturado pelo footer
window.addEventListener('beforeinstallprompt', function() {
    setTimeout(atualizarCardInstalar, 100);
});
// Avalia estado inicial após footer carregar
document.addEventListener('DOMContentLoaded', function() {
    setTimeout(atualizarCardInstalar, 600);
});
</script>

<script>
(function() {
    if (!window.PublicKeyCredential) {
        var btn = document.getElementById('btnCadastrarBiometria');
        if (btn) btn.style.display = 'none';
        var aviso = document.getElementById('waSemSuporte');
        if (aviso) aviso.style.display = '';
    }
})();

function waAbrirEdicaoNome(id, apelidoAtual) {
    document.getElementById('waEditarId').value = id;
    document.getElementById('waEditarApelidoInput').value = apelidoAtual;
    new bootstrap.Modal(document.getElementById('modalEditarNomeBiometria')).show();
}

function waAbrirRemocao(id) {
    document.getElementById('waRemoverId').value = id;
    new bootstrap.Modal(document.getElementById('modalRemoverBiometria')).show();
}

function waCadastrarBiometria() {
    var apelidoPadrao = /iPad|iPhone|iPod/.test(navigator.userAgent) ? 'iPhone/iPad'
        : /Android/.test(navigator.userAgent) ? 'Celular Android'
        : /Mac/.test(navigator.userAgent) ? 'Mac'
        : 'Windows';
    document.getElementById('waApelidoInput').value = apelidoPadrao;
    document.getElementById('waErro').classList.add('d-none');
    new bootstrap.Modal(document.getElementById('modalCadastrarBiometria')).show();
}

function waMostrarMensagem(box, texto, tipo) {
    tipo = tipo || 'danger';
    box.className = 'alert border-0 bg-' + tipo + ' bg-opacity-10 text-' + tipo + ' small mt-3 mb-0';
    box.textContent = texto;
}

function waConfirmarCadastro() {
    var apelido = document.getElementById('waApelidoInput').value.trim();
    var btn = document.getElementById('btnConfirmarBiometria');
    var erroBox = document.getElementById('waErro');
    erroBox.classList.add('d-none');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';

    fetch('usuario/webauthn_criar_opcoes.php')
        .then(function(r) { return r.json(); })
        .then(function(args) {
            if (args.success === false) throw new Error(args.msg || 'Erro ao iniciar.');
            args.publicKey.challenge = waB64urlToBuf(args.publicKey.challenge);
            args.publicKey.user.id   = waB64urlToBuf(args.publicKey.user.id);
            (args.publicKey.excludeCredentials || []).forEach(function(c) { c.id = waB64urlToBuf(c.id); });
            return navigator.credentials.create(args);
        })
        .then(function(cred) {
            return fetch('usuario/webauthn_criar_verificar.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    clientDataJSON: waBufToB64(cred.response.clientDataJSON),
                    attestationObject: waBufToB64(cred.response.attestationObject),
                    apelido: apelido
                })
            });
        })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            if (res.success) {
                location.href = 'configuracoes.php?sucesso_wa=1#seguranca';
            } else {
                waMostrarMensagem(erroBox, res.msg || 'Não foi possível ativar o login rápido.', 'danger');
                erroBox.classList.remove('d-none');
            }
        })
        .catch(function(e) {
            if (e.name === 'NotAllowedError') return;
            if (e.name === 'InvalidStateError') {
                waMostrarMensagem(erroBox, 'Esse dispositivo já está com login rápido ativado — não precisa cadastrar de novo.', 'info');
            } else {
                waMostrarMensagem(erroBox, 'Não foi possível ativar o login rápido: ' + e.message, 'danger');
            }
            erroBox.classList.remove('d-none');
        })
        .finally(function() {
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-fingerprint me-1"></i> Ativar';
        });
}
</script>

<?php require_once 'geral/footer.php'; ?>