<?php
// Caminho correto voltando uma pasta
require_once '../geral/header.php';
?>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<script src="https://cdn.jsdelivr.net/npm/cleave.js@1.6.0/dist/cleave.min.js"></script>

<main class="container py-5 mt-4">
    <div class="row justify-content-center">
        <div class="col-lg-7 col-md-10">

            <div class="card bg-body-tertiary border-secondary-subtle shadow-lg p-4 p-md-5 rounded-4">

                <div class="text-center mb-5">
                    <h2 class="fw-bold text-primary display-6">Crie sua Conta</h2>
                    <p class="text-light opacity-75 fs-5">Seja bem-vindo ao futuro do seu controle financeiro.</p>
                </div>

                <form action="processa_cadastro.php" method="POST" id="formCadastro">

                    <div class="mb-4">
                        <label for="nome" class="form-label text-light opacity-75 fw-semibold">Nome Completo</label>
                        <div class="input-group">
                            <span class="input-group-text bg-dark border-secondary text-secondary"><i
                                    class="bi bi-person-fill"></i></span>
                            <input type="text" class="form-control form-control-lg bg-dark border-secondary text-light"
                                id="nome" name="nome" required placeholder="Ex: Gustavo Veronezi">
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-7 mb-4">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <label for="documento" class="form-label text-light opacity-75 fw-semibold mb-0"
                                    id="label_documento">CPF</label>

                                <div class="form-check form-switch p-0 m-0">
                                    <input class="form-check-input ms-0 custom-switch" type="checkbox" role="switch"
                                        id="doc_switch">
                                    <label class="form-check-label text-secondary small" for="doc_switch">Mudar para
                                        CNPJ</label>
                                </div>
                            </div>
                            <div class="input-group">
                                <span class="input-group-text bg-dark border-secondary text-secondary"><i
                                        class="bi bi-card-text"></i></span>
                                <input type="text"
                                    class="form-control form-control-lg bg-dark border-secondary text-light"
                                    id="documento" name="documento" required placeholder="000.000.000-00">
                            </div>
                        </div>

                        <div class="col-md-5 mb-4">
                            <label for="nascimento" class="form-label text-light opacity-75 fw-semibold mb-2"
                                id="label_nascimento">Data de Nascimento</label>
                            <input type="date" class="form-control form-control-lg bg-dark border-secondary text-light"
                                id="nascimento" name="nascimento" required 
                                min="<?= date('Y-m-d', strtotime('-120 years')) ?>" 
                                max="<?= date('Y-m-d', strtotime('-12 years')) ?>">
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-4">
                            <label for="telefone" class="form-label text-light opacity-75 fw-semibold">Telefone</label>
                            <div class="input-group">
                                <span class="input-group-text bg-dark border-secondary text-secondary"><i
                                        class="bi bi-telephone-fill"></i></span>
                                <input type="tel"
                                    class="form-control form-control-lg bg-dark border-secondary text-light"
                                    id="telefone" name="telefone" placeholder="(17) 99999-9999" required>
                            </div>
                        </div>

                        <div class="col-md-6 mb-4">
                            <label for=email class="form-label text-light opacity-75 fw-semibold">E-mail</label>
                            <div class="input-group">
                                <span class="input-group-text bg-dark border-secondary text-secondary"><i
                                        class="bi bi-envelope-fill"></i></span>
                                <input type=email
                                    class="form-control form-control-lg bg-dark border-secondary text-light" id=email
                                    name=email required placeholder="seu@email.com">
                            </div>
                        </div>
                    </div>

                    <div class="row mb-5">
                        <div class="col-md-6 mb-4 mb-md-0">
                            <label for="senha" class="form-label text-light opacity-75 fw-semibold">Senha</label>
                            <input type="password"
                                class="form-control form-control-lg bg-dark border-secondary text-light" id="senha"
                                name="senha" required minlength="8" placeholder="Mínimo 8 caracteres">
                        </div>
                            <div class="col-md-6">
                            <label for="confirma_senha" class="form-label text-light opacity-75 fw-semibold">Confirmar Senha</label>
                            <input type="password" class="form-control form-control-lg bg-dark border-secondary text-light"
                                id="confirma_senha" name="confirma_senha" required minlength="8" placeholder="Repita a senha">
                            <div class="invalid-feedback fw-bold">
                                As senhas não conferem!
                            </div>
                        </div>
                    </div>

                    <div class="d-grid mt-2">
                        <button type="submit"
                            class="btn btn-primary btn-lg fw-bold text-dark fs-5 cardCentral py-3">Finalizar
                            Cadastro</button>
                    </div>

                    <div class="text-center mt-5">
                        <p class="text-light opacity-75 mb-0">Já faz parte do Auralis? <a href="login.php"
                                class="text-primary text-decoration-none fw-semibold custom-link">Acesse sua conta</a>
                        </p>
                    </div>

                </form>
            </div>

        </div>
    </div>
</main>

<style>
    /* Estilização Premium para o Switch do Bootstrap */
    .custom-switch {
        height: 1.5rem !important;
        width: 2.8rem !important;
        cursor: pointer;
        border-color: #334155 !important;
        background-color: #0f172a !important;
    }

    .custom-switch:checked {
        background-color: var(--accent) !important;
        border-color: var(--accent) !important;
    }

    .custom-switch:focus {
        box-shadow: 0 0 0 0.25rem rgba(56, 189, 248, 0.25) !important;
    }
</style>

<script>
    // 1. Máscara do Telefone (JavaScript Puro - Adaptável para Fixo e Celular)
    const inputTelefone = document.getElementById('telefone');
    if(inputTelefone) {
        inputTelefone.addEventListener('input', function (e) {
            let v = e.target.value.replace(/\D/g, ""); // Remove tudo o que não é número
            v = v.substring(0, 11); // Limita a 11 dígitos máximos (DDD + 9 dígitos)
            
            if (v.length > 2) {
                v = v.replace(/^(\d{2})(\d)/g, "($1) $2"); // Coloca parênteses no DDD
            }
            if (v.length > 9) {
                v = v.replace(/(\d{5})(\d)/, "$1-$2"); // Formato celular: (99) 99999-9999
            } else if (v.length > 6) {
                v = v.replace(/(\d{4})(\d)/, "$1-$2"); // Formato fixo: (99) 9999-9999
            }
            e.target.value = v;
        });
    }

    // 2. Lógica Dinâmica do CPF/CNPJ e Data
    const docSwitch = document.getElementById('doc_switch');
    const docLabel = document.getElementById('label_documento');
    const docInput = document.getElementById('documento');
    const dataLabel = document.getElementById('label_nascimento');

    let cleaveInstance;

    function applyDocumentMask() {
        if (cleaveInstance) {
            cleaveInstance.destroy();
        }

        if (docSwitch.checked) {
            // MODO CNPJ
            docLabel.textContent = 'CNPJ';
            dataLabel.textContent = 'Data de Abertura';
            docInput.placeholder = '00.000.000/0000-00';
            cleaveInstance = new Cleave(docInput, {
                blocks: [2, 3, 3, 4, 2],
                delimiters: ['.', '.', '/', '-'],
                numericOnly: true
            });
        } else {
            // MODO CPF
            docLabel.textContent = 'CPF';
            dataLabel.textContent = 'Data de Nascimento';
            docInput.placeholder = '000.000.000-00';
            cleaveInstance = new Cleave(docInput, {
                blocks: [3, 3, 3, 2],
                delimiters: ['.', '.', '-'],
                numericOnly: true
            });
        }
        docInput.value = '';
    }

    docSwitch.addEventListener('change', applyDocumentMask);
    applyDocumentMask();

// 3. Validação de Senhas no Front-End (Antes de enviar)
    const formCadastro = document.getElementById('formCadastro');
    const inputSenha = document.getElementById('senha');
    const inputConfirmaSenha = document.getElementById('confirma_senha');

    if (formCadastro) {
        formCadastro.addEventListener('submit', function (e) {
            // Se as senhas forem diferentes...
            if (inputSenha.value !== inputConfirmaSenha.value) {
                e.preventDefault(); // Impede o formulário de ir para o PHP!
                inputConfirmaSenha.classList.add('is-invalid'); // Deixa a caixa vermelha e mostra o aviso
                inputConfirmaSenha.focus(); // Joga o cursor do mouse lá pra pessoa arrumar
            } else {
                inputConfirmaSenha.classList.remove('is-invalid'); // Tudo certo, deixa passar
            }
        });

        // Limpa o erro (tira o vermelho) assim que a pessoa começa a apagar/digitar de novo
        inputConfirmaSenha.addEventListener('input', function () {
            inputConfirmaSenha.classList.remove('is-invalid');
        });
    }

</script>

<?php
// Volta uma pasta para pegar o footer
require_once '../geral/footer.php';
?>