<?php
// Caminho correto voltando uma pasta
require_once '../geral/header.php';
?>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

<main class="container py-5 mt-4">
    <div class="row justify-content-center">
        <div class="col-lg-6 col-md-8 col-sm-10">

            <div class="card bg-body-tertiary border-secondary-subtle shadow-lg p-4 p-md-5 rounded-4">

                <div class="text-center mb-5">
                    <h2 class="fw-bold text-primary display-6">Crie sua Conta</h2>
                    <p class="text-light opacity-75 fs-5">Seja bem-vindo ao futuro do seu controle financeiro.</p>
                </div>

                <?php if (isset($_GET['erro'])): ?>
                    <?php
                    $mensagemErro = "Ocorreu um erro ao processar seu cadastro."; // Mensagem padrão

                    if ($_GET['erro'] === 'email_existe') {
                        $mensagemErro = "Este e-mail já está cadastrado. Tente fazer login ou recupere sua senha.";
                    } elseif ($_GET['erro'] === 'senhas_diferentes') {
                        $mensagemErro = "As senhas digitadas não conferem. Digite novamente com atenção.";
                    } elseif ($_GET['erro'] === 'banco') {
                        $mensagemErro = "Ops! Nossos servidores estão um pouco lentos agora. Tente novamente em instantes.";
                    }
                    ?>
                    <div class="alert alert-danger d-flex align-items-center gap-2 rounded-3 shadow-sm border-0 mb-4">
                        <i class="bi bi-exclamation-triangle-fill"></i>
                        <span><?php echo $mensagemErro; ?></span>
                    </div>
                <?php endif; ?>

                <form action="processa_cadastro.php" method="POST" id="formCadastro">
                    <?php if (!empty($_GET['ref'])): ?>
                        <input type="hidden" name="ref_code" value="<?= htmlspecialchars(strtoupper(trim($_GET['ref']))) ?>">
                    <?php endif; ?>

                    <div class="mb-4">
                        <label for="nome" class="form-label text-light opacity-75 fw-semibold">Nome Completo</label>
                        <div class="input-group">
                            <span class="input-group-text bg-dark border-secondary text-secondary"><i
                                    class="bi bi-person-fill"></i></span>
                            <input type="text" class="form-control form-control-lg bg-dark border-secondary text-light"
                                id="nome" name="nome" required>
                        </div>
                    </div>

                    <div class="mb-4">
                        <label for="email" class="form-label text-light opacity-75 fw-semibold">E-mail</label>
                        <div class="input-group">
                            <span class="input-group-text bg-dark border-secondary text-secondary"><i
                                    class="bi bi-envelope-fill"></i></span>
                            <input type="email" class="form-control form-control-lg bg-dark border-secondary text-light"
                                id="email" name="email" required>
                        </div>
                    </div>

                    <div class="row mb-4">
                        <div class="col-md-6 mb-4 mb-md-0">
                            <label for="senha" class="form-label text-light opacity-75 fw-semibold">Senha</label>
                            <input type="password"
                                class="form-control form-control-lg bg-dark border-secondary text-light" id="senha"
                                name="senha" required minlength="4" placeholder="Mínimo 4 caracteres">
                        </div>
                        <div class="col-md-6">
                            <label for="confirma_senha" class="form-label text-light opacity-75 fw-semibold">Confirmar
                                Senha</label>
                            <input type="password"
                                class="form-control form-control-lg bg-dark border-secondary text-light"
                                id="confirma_senha" name="confirma_senha" required minlength="4"
                                placeholder="Repita a senha">
                            <div class="invalid-feedback fw-bold">
                                As senhas não conferem!
                            </div>
                        </div>
                    </div>

                    <?php if (empty($_GET['ref'])): ?>
                    <!-- Código pessoal de quem indicou — mesma "chave" usada pra convite de carteira
                         compartilhada, então serve tanto pra código de revendedor quanto de um
                         usuário comum. Só aparece se não veio de um link de indicação (?ref=), que
                         já anexa isso sozinho. -->
                    <div class="form-check mb-3 text-start toggle-analysis">
                        <input class="form-check-input bg-dark border-secondary shadow-none" type="checkbox" id="tem_codigo_parceiro"
                            onchange="document.getElementById('blocoCodigoParceiro').classList.toggle('d-none', !this.checked)">
                        <label class="form-check-label text-secondary small selection-none" for="tem_codigo_parceiro" style="font-size:0.85rem;">
                            Possuo código de parceiro
                        </label>
                    </div>
                    <div id="blocoCodigoParceiro" class="mb-4 d-none">
                        <label for="codigo_parceiro" class="form-label text-light opacity-75 fw-semibold small">Código de quem te indicou</label>
                        <input type="text" class="form-control bg-dark border-secondary text-light" id="codigo_parceiro"
                            name="codigo_parceiro" placeholder="Ex: AUR-AB12CD" maxlength="12" style="text-transform:uppercase;">
                        <div class="form-text text-secondary" style="font-size:0.72rem;">Código de um revendedor ou de outro usuário do Auralis.</div>
                    </div>
                    <?php endif; ?>

                    <div class="form-check mb-4 text-start toggle-analysis">
                        <input class="form-check-input bg-dark border-secondary shadow-none" type="checkbox" id="aceita_termos" name="aceita_termos" required>
                        <label class="form-check-label text-secondary small selection-none" for="aceita_termos" style="font-size: 0.8rem; line-height: 1.4;">
                            Li e aceito os <a href="../termos.php" target="_blank" class="text-decoration-none" style="color: var(--primary-gold-analysis);">Termos de Uso</a>
                            e a <a href="../privacidade.php" target="_blank" class="text-decoration-none" style="color: var(--primary-gold-analysis);">Política de Privacidade</a> do Auralis.
                        </label>
                        <div class="invalid-feedback" style="font-size: 0.75rem;">
                            É necessário aceitar os termos para prosseguir.
                        </div>
                    </div>

                    <div class="d-grid mt-4">
                        <button type="submit"
                            class="btn btn-primary btn-lg fw-bold text-dark fs-5 cardCentral py-3 shadow-lg transition-hover">
                            Criar Minha Conta
                        </button>
                    </div>

                    <div class="mt-4 mb-2">
                        <div class="d-flex align-items-center mb-4">
                            <hr class="flex-grow-1 border-secondary opacity-25">
                            <span class="mx-3 text-secondary small text-uppercase" style="letter-spacing: 1px;">Ou entre
                                com</span>
                            <hr class="flex-grow-1 border-secondary opacity-25">
                        </div>

                        <div id="googleBtnContainer" class="d-flex justify-content-center" style="color-scheme: light;"></div>
                    </div>

                    <div class="text-center mt-5">
                        <p class="text-light opacity-75 mb-0">Já faz parte do Auralis?
                            <a href="login.php" class="text-primary text-decoration-none fw-semibold custom-link">Acesse
                                sua conta</a>
                        </p>
                    </div>

                </form>
            </div>

        </div>
    </div>
</main>

<script src="https://accounts.google.com/gsi/client" async defer onload="iniciarBotaoGoogle()"></script>
<script>
    // Recebe o ID token (JWT) do Google Identity Services e envia pro backend via POST
    function handleGoogleCredentialResponse(response) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = 'login_google.php';

        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'credential';
        input.value = response.credential;

        form.appendChild(input);
        document.body.appendChild(form);
        form.submit();
    }

    // Renderiza o botão via JS (em vez do atributo data-width fixo) pra calcular a
    // largura real do container e recalcular no resize — o widget do Google não
    // é responsivo por padrão, e um valor fixo quebra em telas pequenas.
    function iniciarBotaoGoogle() {
        google.accounts.id.initialize({
            client_id: '808511905880-4l0raul5fuf3rkukms9easdq65375o2t.apps.googleusercontent.com',
            callback: handleGoogleCredentialResponse
        });

        const container = document.getElementById('googleBtnContainer');

        function desenharBotaoGoogle() {
            container.innerHTML = '';
            const largura = Math.max(200, Math.min(400, container.offsetWidth));
            google.accounts.id.renderButton(container, {
                type: 'standard',
                shape: 'pill',
                theme: 'filled_black',
                text: 'signup_with',
                size: 'large',
                logo_alignment: 'left',
                width: largura
            });
        }

        desenharBotaoGoogle();

        let resizeTimeout;
        window.addEventListener('resize', function () {
            clearTimeout(resizeTimeout);
            resizeTimeout = setTimeout(desenharBotaoGoogle, 200);
        });
    }

    // Única Validação de Front-End necessária: Verificar se as senhas são iguais
    const formCadastro = document.getElementById('formCadastro');
    const inputSenha = document.getElementById('senha');
    const inputConfirmaSenha = document.getElementById('confirma_senha');

    if (formCadastro) {
        formCadastro.addEventListener('submit', function(e) {
            // Se as senhas forem diferentes...
            if (inputSenha.value !== inputConfirmaSenha.value) {
                e.preventDefault(); // Impede o formulário de ir para o banco!
                inputConfirmaSenha.classList.add('is-invalid'); // Deixa a caixa vermelha e mostra o aviso
                inputConfirmaSenha.focus(); // Joga o cursor do mouse lá pra pessoa arrumar
            } else {
                inputConfirmaSenha.classList.remove('is-invalid'); // Tudo certo, deixa passar
            }
        });

        // Limpa o erro (tira o vermelho) assim que a pessoa começa a apagar/digitar de novo
        inputConfirmaSenha.addEventListener('input', function() {
            inputConfirmaSenha.classList.remove('is-invalid');
        });
    }
</script>

<?php
// Volta uma pasta para pegar o footer
require_once '../geral/footer.php';
?>