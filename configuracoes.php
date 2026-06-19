<?php
session_start();

if (!isset($_SESSION['usuario_id'])) {
    header("Location: usuario/login.php");
    exit;
}

require_once 'config/conexao.php';

$usuario_id = $_SESSION['usuario_id'];
$mensagem = '';
$tipo_mensagem = '';

// ==============================================================================
// 1. LÓGICA DE ATUALIZAÇÃO (POST)
// ==============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // AÇÃO 1: ATUALIZAR DADOS PESSOAIS
    if (isset($_POST['action']) && $_POST['action'] === 'update_profile') {
        $nome = trim($_POST['nome']);

        if (!empty($nome)) {
            try {
                $sqlUpd = "UPDATE Usuario SET Nome = :nome WHERE IDUsuario = :uid";
                $stmtUpd = $pdo->prepare($sqlUpd);
                $stmtUpd->execute([
                    ':nome' => $nome,
                    ':uid' => $usuario_id
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

    // AÇÃO 4: EXCLUIR CONTA (A ZONA DE PERIGO)
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
    $sqlBusca = "SELECT Nome, Email, Senha, Tema FROM Usuario WHERE IDUsuario = :uid LIMIT 1";
    $stmtBusca = $pdo->prepare($sqlBusca);
    $stmtBusca->execute([':uid' => $usuario_id]);
    $dadosUsuario = $stmtBusca->fetch(PDO::FETCH_ASSOC);

    // Descobre se é usuário Google para o HTML
    $isGoogleUser = ($dadosUsuario['Senha'] === 'GOOGLE_SSO');
} catch (PDOException $e) {
    die("Erro ao carregar dados do usuário.");
}

require_once 'geral/header.php';
?>

<main class="container py-4 mt-2 flex-grow-1" style="min-height: 100vh; padding-inline: var(--space-page-x);">

    <div class="d-flex justify-content-between align-items-center mb-4 border-bottom border-secondary-subtle pb-3">
        <h2 class="fw-bold text-light mb-0"><i class="bi bi-gear text-secondary me-2"></i> Configurações da Conta</h2>
    </div>

    <?php if ($mensagem): ?>
        <div class="alert alert-<?= $tipo_mensagem ?> alert-dismissible fade show d-flex align-items-center rounded-3 shadow-sm border-0" role="alert">
            <i class="bi <?= $tipo_mensagem === 'success' ? 'bi-check-circle-fill' : 'bi-exclamation-triangle-fill' ?> me-2"></i>
            <strong><?= $mensagem ?></strong>
            <button type="button" class="btn-close <?php if ($tipo_mensagem !== 'warning') echo 'btn-close-white'; ?> opacity-50" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <div class="row g-4 mb-5">

        <div class="col-lg-6">
            <div class="card bg-dark border-secondary-subtle shadow-sm rounded-4 h-100">
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
            <div class="card bg-dark border-secondary-subtle shadow-sm rounded-4 h-100">
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
                            <input type="password" name="nova_senha" id="nova_senha" class="form-control form-control-lg bg-transparent border-secondary-subtle text-light shadow-none" required minlength="8" placeholder="No mínimo 8 caracteres">
                        </div>

                        <div class="mb-4">
                            <label for="confirma_senha" class="form-label text-light fw-semibold mb-1">Confirmar Nova Senha</label>
                            <input type="password" name="confirma_senha" id="confirma_senha" class="form-control form-control-lg bg-transparent border-secondary-subtle text-light shadow-none" required minlength="8" placeholder="Repita a nova senha">
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
            $renderCard = function(string $slug, array $info) use ($temaAtivo, $planoUsuario, $planoPeso): void {
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
            <div class="card border-secondary-subtle shadow-sm rounded-4" style="background:var(--bg-card);">
                <div class="card-header border-bottom border-secondary-subtle bg-transparent p-4">
                    <h5 class="fw-bold mb-0"><i class="bi bi-palette2 me-2" style="color:var(--accent);"></i> Aparência</h5>
                </div>
                <div class="card-body p-4">
                    <p class="text-secondary small mb-4">Escolha como o Auralis aparece para você. Temas adicionais podem ser desbloqueados por plano ou conquistas.</p>

                    <!-- Seção: Padrão -->
                    <p class="small fw-semibold text-uppercase mb-2" style="color:var(--text-muted);letter-spacing:.07em;">Padrão</p>
                    <div class="row g-3 mb-4">
                        <?php foreach ($temasPadrao as $slug => $info) { $renderCard($slug, $info); } ?>
                    </div>

                    <!-- Seção: Adicionais -->
                    <p class="small fw-semibold text-uppercase mb-2" style="color:var(--text-muted);letter-spacing:.07em;">Adicionais</p>
                    <div class="row g-3">
                        <?php foreach ($temasAdicionais as $slug => $info) { $renderCard($slug, $info); } ?>
                    </div>
                </div>
            </div>
        </div>

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

<div class="modal fade" id="modalExcluirConta" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content bg-dark border-danger border-opacity-50 shadow-lg rounded-4">
            <div class="modal-header border-bottom border-secondary-subtle">
                <h5 class="modal-title text-danger fw-bold"><i class="bi bi-shield-x me-2"></i> Verificação de Segurança</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
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

<?php require_once 'geral/footer.php'; ?>