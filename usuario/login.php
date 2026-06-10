<?php
// Caminho correto voltando uma pasta para pegar o header
require_once '../geral/header.php';
?>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

<main class="container">
    <div class="row justify-content-center">
        <div class="col-lg-5 col-md-8 col-sm-10">

            <div class="card bg-body-tertiary border-secondary-subtle shadow-lg p-4 p-md-5 rounded-4">

                <div class="text-center mb-4">
                    <div class="display-4 text-primary mb-3"><i class="bi bi-box-arrow-in-right"></i></div>
                    <h2 class="fw-bold text-light">Bem-vindo de volta</h2>
                    <p class="text-light opacity-75">Acesse sua conta para continuar.</p>
                </div>
                
                <?php if (isset($_GET['erro'])): ?>
                    <?php if ($_GET['erro'] === 'invalido'): ?>
                        <div class="alert alert-danger d-flex align-items-center rounded-3 shadow-sm border-0 mb-4 bg-danger bg-opacity-10 text-danger" role="alert">
                            <i class="bi bi-exclamation-triangle-fill me-3 fs-5"></i>
                            <div><strong>Ops!</strong> E-mail ou senha incorretos. Tente novamente.</div>
                        </div>
                    <?php elseif ($_GET['erro'] === 'vazio'): ?>
                        <div class="alert alert-warning d-flex align-items-center rounded-3 shadow-sm border-0 mb-4 bg-warning bg-opacity-10 text-warning" role="alert">
                            <i class="bi bi-exclamation-circle-fill me-3 fs-5"></i>
                            <div>Por favor, preencha todos os campos para entrar.</div>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>

                <?php if (isset($_GET['cadastro']) && $_GET['cadastro'] === 'sucesso'): ?>
                    <div class="alert alert-success d-flex align-items-center rounded-3 shadow-sm border-0 mb-4 bg-success bg-opacity-10 text-success" role="alert">
                        <i class="bi bi-check-circle-fill me-3 fs-5"></i>
                        <div><strong>Conta criada com sucesso!</strong> Faça seu login para começar.</div>
                    </div>
                <?php endif; ?>

                <?php if (isset($_GET['conta']) && $_GET['conta'] === 'excluida'): ?>
                    <div class="alert alert-info d-flex align-items-center rounded-3 shadow-sm border-0 mb-4 bg-info bg-opacity-10 text-info" role="alert">
                        <i class="bi bi-info-circle-fill me-3 fs-5"></i>
                        <div>Sua conta foi excluída permanentemente. Sentiremos sua falta no Auralis!</div>
                    </div>
                <?php endif; ?>
                
                <?php if (isset($_GET['erro']) && $_GET['erro'] === 'pendente'): ?>
                    <div class="alert alert-warning d-flex align-items-center gap-2 rounded-3 shadow-sm border-0 mb-4">
                        <i class="bi bi-envelope-exclamation-fill text-warning fs-4"></i>
                        <div>
                            <strong>Quase lá!</strong><br>
                            Sua conta está inativa. Verifique a caixa de entrada do seu e-mail e clique no link de ativação para liberar seu acesso.
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (isset($_GET['ativacao']) && $_GET['ativacao'] === 'sucesso'): ?>
                    <div class="alert alert-success d-flex align-items-center gap-2 rounded-3 shadow-sm border-0 mb-4">
                        <i class="bi bi-check-circle-fill text-success fs-4"></i>
                        <span><strong>Conta ativada com sucesso!</strong> Seja bem-vindo ao Auralis. Faça seu login abaixo.</span>
                    </div>
                <?php endif; ?>
                
                <form action="processa_login.php" method="POST">

                    <div class="mb-4">
                        <label for="email" class="form-label text-light opacity-75 fw-semibold">E-mail</label>
                        <div class="input-group">
                            <span class="input-group-text bg-dark border-secondary text-secondary"><i class="bi bi-envelope-fill"></i></span>
                            <input type="email" class="form-control form-control-lg bg-dark border-secondary text-light" id="email" name="email" required placeholder="">
                        </div>
                    </div>
                    
                    <div class="mb-4">
                        <div class="d-flex justify-content-between">
                            <label for="senha" class="form-label text-light opacity-75 fw-semibold">Senha</label>
                            <a href="esqueci_senha.php" class="text-primary text-decoration-none small custom-link">Esqueceu a senha?</a>
                        </div>
                        <div class="input-group">
                            <span class="input-group-text bg-dark border-secondary text-secondary"><i class="bi bi-lock-fill"></i></span>
                            <input type="password" class="form-control form-control-lg bg-dark border-secondary text-light border-end-0" id="senha" name="senha" required placeholder="">
                            <button class="btn btn-dark border-secondary border-start-0 text-secondary" type="button" id="btnMostrarSenha">
                                <i class="bi bi-eye-fill" id="iconeSenha"></i>
                            </button>
                        </div>
                    </div>

                    <div class="form-check text-start mb-4">
                        <input class="form-check-input bg-dark border-secondary" type="checkbox" name="lembrar" value="sim" id="lembrar">
                        <label class="form-check-label text-light opacity-75" for="lembrar">
                            Salvar neste dispositivo
                        </label>
                    </div>

                    <div class="d-grid mt-4">
                        <button type="submit" class="btn btn-primary btn-lg fw-bold text-dark fs-5 cardCentral py-3">Entrar no Auralis</button>
                    </div>

                    <div class="d-flex align-items-center my-4">
                        <hr class="flex-grow-1 border-secondary opacity-25">
                        <span class="mx-3 text-secondary small text-uppercase fw-semibold">ou</span>
                        <hr class="flex-grow-1 border-secondary opacity-25">
                    </div>

                    <div class="d-grid mb-4">
                        <a href="https://accounts.google.com/o/oauth2/auth?client_id=808511905880-4l0raul5fuf3rkukms9easdq65375o2t.apps.googleusercontent.com&redirect_uri=https://meuauralis.com/usuario/login_google.php&response_type=code&scope=email%20profile" 
                           class="btn btn-outline-secondary btn-lg d-flex align-items-center justify-content-center transition-hover border-secondary-subtle text-decoration-none">
                            <i class="bi bi-google text-light me-3 fs-5"></i> 
                            <span class="text-light fs-6">Entrar com o Google</span>
                        </a>
                    </div>
                    <div class="text-center mt-5">
                        <p class="text-light opacity-75 mb-0">Ainda não tem uma conta? <a href="cadastro.php" class="text-primary text-decoration-none fw-semibold custom-link">Cadastre-se grátis</a></p>
                    </div>

                </form>
            </div>

        </div>
    </div>
</main>

<script>
    // Lógica para o botão de Mostrar/Ocultar Senha
    const btnMostrarSenha = document.getElementById('btnMostrarSenha');
    const inputSenha = document.getElementById('senha');
    const iconeSenha = document.getElementById('iconeSenha');

    btnMostrarSenha.addEventListener('click', function () {
        // Verifica se o campo está como password ou text
        if (inputSenha.type === 'password') {
            inputSenha.type = 'text'; // Mostra a senha
            iconeSenha.classList.remove('bi-eye-fill');
            iconeSenha.classList.add('bi-eye-slash-fill'); // Troca o ícone para o olho cortado
        } else {
            inputSenha.type = 'password'; // Oculta a senha
            iconeSenha.classList.remove('bi-eye-slash-fill');
            iconeSenha.classList.add('bi-eye-fill'); // Volta o ícone normal
        }
    });
</script>

<?php
// Caminho correto voltando uma pasta para pegar o footer
require_once '../geral/footer.php';
?>