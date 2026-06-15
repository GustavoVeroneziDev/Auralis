<?php
// usuario/redefinir_senha.php
require_once '../config/conexao.php';

$token = $_GET['token'] ?? '';
$valido = false;

if (!empty($token)) {
    try {
        // Busca o usuário com o token e confere se a expiração é maior que "agora"
        $sql = "SELECT IDUsuario FROM Usuario WHERE TokenRecuperacao = :token AND TokenRecuperacaoExpiracao > NOW() LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':token' => $token]);
        $usuario = $stmt->fetch();

        if ($usuario) {
            $valido = true;
        }
    } catch (PDOException $e) {
        $valido = false;
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Redefinir Senha - Auralis</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="shortcut icon" href="/geral/img/icone.ico" type="image/x-icon">
    <style>
        body { background-color: #0f0f16; color: #e0e0e0; font-family: 'Segoe UI', sans-serif; min-height: 100vh; display: flex; align-items: center; justify-content: center; }
        .card-reset { background-color: #161622; border: 1px solid #252538; border-radius: 16px; padding: 40px; width: 100%; max-width: 420px; box-shadow: 0 10px 30px rgba(0, 0, 0, 0.25); }
        .form-control { background-color: #1f1f30; border: 1px solid #2d2d44; color: #ffffff; border-radius: 8px; padding: 12px; }
        .form-control:focus { background-color: #24243a; border-color: #0d6efd; box-shadow: 0 0 0 0.25rem rgba(13, 110, 253, 0.15); color: #ffffff; }
        .btn-primary { background-color: #0d6efd; border: none; padding: 12px; border-radius: 8px; font-weight: 600; }
    </style>
</head>
<body>

<div class="card-reset">
    <div class="text-center mb-4">
        <img src="/geral/img/LogoAuralisSemEscudo.png" alt="Auralis" style="max-height: 45px;" class="mb-3">
        <h4 class="fw-bold text-white">Nova Senha</h4>
        <p class="small">Crie uma senha forte e de fácil memorização.</p>
    </div>

    <?php if ($valido): ?>
        
        <?php if (isset($_GET['erro']) && $_GET['erro'] === 'senhas_diferentes'): ?>
            <div class="alert alert-warning border-0 small mb-4 text-center" style="background-color: rgba(255, 193, 7, 0.1); color: #ffc107;">
                <i class="bi bi-exclamation-circle-fill me-2"></i> As senhas digitadas não conferem.
            </div>
        <?php endif; ?>

        <form action="salvar_nova_senha.php" method="POST" id="formSenha">
            <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">

            <div class="mb-3">
                <label for="senha" class="form-label small text-secondary">Nova Senha</label>
                <input type="password" name="senha" id="senha" class="form-control" required minlength="8">
            </div>

            <div class="mb-4">
                <label for="confirma_senha" class="form-label small text-secondary">Confirmar Nova Senha</label>
                <input type="password" name="confirma_senha" id="confirma_senha" class="form-control" required minlength="8">
            </div>

            <button type="submit" class="btn btn-primary w-100">Atualizar Senha</button>
        </form>

        <script>
            const senha = document.getElementById('senha');
            const confirma_senha = document.getElementById('confirma_senha');

            function validarSenha() {
                if (senha.value !== confirma_senha.value) {
                    confirma_senha.setCustomValidity("As senhas não conferem!");
                } else {
                    confirma_senha.setCustomValidity(''); // Limpa o erro
                }
            }

            senha.addEventListener('change', validarSenha);
            confirma_senha.addEventListener('keyup', validarSenha);
        </script>

    <?php else: ?>
        <div class="text-center py-3">
            <div class="alert alert-danger border-0 small mb-4" style="background-color: rgba(220, 53, 69, 0.1); color: #e74c3c;">
                <i class="bi bi-exclamation-triangle-fill me-2"></i> Este link expirou ou é inválido.
            </div>
            <a href="esqueci_senha.php" class="btn btn-secondary btn-sm w-100" style="background-color: #1f1f30; border: 1px solid #2d2d44;">Solicitar Novo Link</a>
        </div>
    <?php endif; ?>
</div>

</body>
</html>