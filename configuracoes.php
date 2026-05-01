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
        $telefone = trim($_POST['telefone']);

        if (!empty($nome)) {
            try {
                $sqlUpd = 'UPDATE usuario SET "Nome" = :nome, "Telefone" = :telefone WHERE "IDUsuario" = :uid';
                $stmtUpd = $pdo->prepare($sqlUpd);
                $stmtUpd->execute([
                    ':nome' => $nome,
                    ':telefone' => $telefone,
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
        $senha_atual = $_POST['senha_atual'];
        $nova_senha = $_POST['nova_senha'];
        $confirma_senha = $_POST['confirma_senha'];

        if ($nova_senha !== $confirma_senha) {
            $mensagem = "As novas senhas não conferem!";
            $tipo_mensagem = "warning";
        } else {
            try {
                $sqlSenha = 'SELECT "Senha" FROM usuario WHERE "IDUsuario" = :uid';
                $stmtSenha = $pdo->prepare($sqlSenha);
                $stmtSenha->execute([':uid' => $usuario_id]);
                $hashBanco = $stmtSenha->fetchColumn();

                if (password_verify($senha_atual, $hashBanco)) {
                    $novoHash = password_hash($nova_senha, PASSWORD_DEFAULT);
                    $sqlUpdSenha = 'UPDATE usuario SET "Senha" = :senha WHERE "IDUsuario" = :uid';
                    $stmtUpdSenha = $pdo->prepare($sqlUpdSenha);
                    $stmtUpdSenha->execute([':senha' => $novoHash, ':uid' => $usuario_id]);

                    $mensagem = "Senha alterada com segurança!";
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

    // AÇÃO 3: EXCLUIR CONTA (A ZONA DE PERIGO)
    if (isset($_POST['action']) && $_POST['action'] === 'delete_account') {
        $senha_confirmacao = $_POST['senha_confirmacao'];

        try {
            // Verifica a senha antes de qualquer loucura
            $sqlSenha = 'SELECT "Senha" FROM usuario WHERE "IDUsuario" = :uid';
            $stmtSenha = $pdo->prepare($sqlSenha);
            $stmtSenha->execute([':uid' => $usuario_id]);
            $hashBanco = $stmtSenha->fetchColumn();

            if (password_verify($senha_confirmacao, $hashBanco)) {
                // Senha bateu. Exterminar o usuário!
                // O banco PostgreSQL (com "ON DELETE CASCADE") apagará junto as carteiras, transações e configurações dele.
                $sqlDel = 'DELETE FROM usuario WHERE "IDUsuario" = :uid';
                $stmtDel = $pdo->prepare($sqlDel);
                $stmtDel->execute([':uid' => $usuario_id]);

                // Destrói a sessão atual
                session_destroy();
                
                // Exclui o Cookie do Lembrar-me
                setcookie('auralis_remember', '', time() - 3600, '/');

                // Manda para o login com uma mensagem secreta pela URL
                header("Location: usuario/login.php?conta=excluida");
                exit;
            } else {
                $mensagem = "Senha incorreta. A exclusão foi cancelada para sua segurança.";
                $tipo_mensagem = "danger";
            }
        } catch (PDOException $e) {
            $mensagem = "Erro ao excluir conta. Verifique se há dados pendentes.";
            $tipo_mensagem = "danger";
        }
    }
}

// ==============================================================================
// 2. BUSCA OS DADOS ATUAIS PARA PREENCHER O FORMULÁRIO
// ==============================================================================
try {
    $sqlBusca = 'SELECT "Nome", email, "Documento", "Telefone" FROM usuario WHERE "IDUsuario" = :uid LIMIT 1';
    $stmtBusca = $pdo->prepare($sqlBusca);
    $stmtBusca->execute([':uid' => $usuario_id]);
    $dadosUsuario = $stmtBusca->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Erro ao carregar dados do usuário.");
}

require_once 'geral/header.php';
?>

<main class="container py-4 mt-3 flex-grow-1" style="min-height: 100vh;">
    
    <div class="d-flex justify-content-between align-items-center mb-4 border-bottom border-secondary-subtle pb-3">
        <h2 class="fw-bold text-light mb-0"><i class="bi bi-gear text-secondary me-2"></i> Configurações da Conta</h2>
    </div>

    <?php if ($mensagem): ?>
        <div class="alert alert-<?= $tipo_mensagem ?> alert-dismissible fade show d-flex align-items-center rounded-3 shadow-sm border-0" role="alert">
            <i class="bi <?= $tipo_mensagem === 'success' ? 'bi-check-circle-fill' : 'bi-exclamation-triangle-fill' ?> me-2"></i>
            <strong><?= $mensagem ?></strong>
            <button type="button" class="btn-close <?php if($tipo_mensagem !== 'warning') echo 'btn-close-white'; ?> opacity-50" data-bs-dismiss="alert" aria-label="Close"></button>
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
                        
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label class="form-label text-secondary small mb-1">E-mail de Acesso</label>
                                <input type=email class="form-control bg-body-tertiary border-secondary-subtle text-secondary shadow-none" value="<?= htmlspecialchars($dadosUsuario['Email']) ?>" disabled>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label text-secondary small mb-1">Documento (CPF/CNPJ)</label>
                                <input type="text" class="form-control bg-body-tertiary border-secondary-subtle text-secondary shadow-none" value="<?= htmlspecialchars($dadosUsuario['Documento']) ?>" disabled>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="nome" class="form-label text-light fw-semibold mb-1">Nome Completo</label>
                            <input type="text" name="nome" id="nome" class="form-control form-control-lg bg-transparent border-secondary-subtle text-light shadow-none" value="<?= htmlspecialchars($dadosUsuario['Nome']) ?>" required>
                        </div>

                        <div class="mb-4">
                            <label for="telefone" class="form-label text-light fw-semibold mb-1">Telefone / WhatsApp</label>
                            <input type="text" name="telefone" id="telefone" class="form-control form-control-lg bg-transparent border-secondary-subtle text-light shadow-none" value="<?= htmlspecialchars($dadosUsuario['Telefone']) ?>">
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

                        <div class="mb-4">
                            <label for="senha_atual" class="form-label text-light fw-semibold mb-1">Senha Atual</label>
                            <input type="password" name="senha_atual" id="senha_atual" class="form-control form-control-lg bg-transparent border-secondary-subtle text-light shadow-none" required placeholder="Digite sua senha atual">
                        </div>

                        <hr class="border-secondary-subtle opacity-50 mb-4">

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
                                Atualizar Senha
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

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
                    <p class="text-light mb-4">Para confirmar que é você mesmo e excluir definitivamente o seu Auralis, digite sua senha abaixo:</p>
                    
                    <div class="mb-3">
                        <label for="senha_confirmacao" class="form-label text-secondary small">Sua Senha</label>
                        <input type="password" name="senha_confirmacao" id="senha_confirmacao" class="form-control form-control-lg bg-transparent border-secondary-subtle text-light shadow-none" required placeholder="Digite sua senha">
                    </div>
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
    // 1. Máscara Inteligente de Telefone
    const inputTelefone = document.getElementById('telefone');
    if(inputTelefone) {
        inputTelefone.addEventListener('input', function (e) {
            let v = e.target.value.replace(/\D/g, ""); 
            v = v.substring(0, 11); 
            
            if (v.length > 2) {
                v = v.replace(/^(\d{2})(\d)/g, "($1) $2"); 
            }
            if (v.length > 9) {
                v = v.replace(/(\d{5})(\d)/, "$1-$2"); 
            } else if (v.length > 6) {
                v = v.replace(/(\d{4})(\d)/, "$1-$2"); 
            }
            e.target.value = v;
        });
    }

    // 2. Validação Front-End da Nova Senha
    const formSenha = document.getElementById('formSenha');
    const inputNovaSenha = document.getElementById('nova_senha');
    const inputConfirmaSenha = document.getElementById('confirma_senha');

    if (formSenha) {
        formSenha.addEventListener('submit', function (e) {
            if (inputNovaSenha.value !== inputConfirmaSenha.value) {
                e.preventDefault(); 
                inputConfirmaSenha.classList.add('is-invalid'); 
                inputConfirmaSenha.focus(); 
            } else {
                inputConfirmaSenha.classList.remove('is-invalid'); 
            }
        });

        inputConfirmaSenha.addEventListener('input', function () {
            inputConfirmaSenha.classList.remove('is-invalid');
        });
    }
</script>

<?php require_once 'geral/footer.php'; ?>