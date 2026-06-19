<?php
// usuario/esqueci_senha.php
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Recuperar Senha - Auralis</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
            <link rel="shortcut icon" href="/geral/img/icone.ico" type="image/x-icon">
    <style>
        body {
            background-color: #0f0f16;
            color: #e0e0e0;
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .card-recovery {
            background-color: #161622;
            border: 1px solid #252538;
            border-radius: 16px;
            padding: 40px;
            width: 100%;
            max-width: 420px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.25);
        }
        .form-control {
            background-color: #1f1f30;
            border: 1px solid #2d2d44;
            color: #ffffff;
            border-radius: 8px;
            padding: 12px;
        }
        .form-control:focus {
            background-color: #24243a;
            border-color: #0d6efd;
            box-shadow: 0 0 0 0.25rem rgba(13, 110, 253, 0.15);
            color: #ffffff;
        }
        .btn-primary {
            background-color: #0d6efd;
            border: none;
            padding: 12px;
            border-radius: 8px;
            font-weight: 600;
            transition: all 0.3s;
        }
        .btn-primary:hover {
            background-color: #0b5ed7;
            transform: translateY(-2px);
        }
    </style>
</head>
<body>

<div class="card-recovery">
    <div class="text-center mb-4">
        <img src="/geral/img/LogoAuralisSemEscudo.png" alt="Auralis" style="max-height: 45px;" class="mb-3">
        <h4 class="fw-bold text-white">Recuperar Acesso</h4>
        <p class=" small">Insira seu e-mail para receber um link de redefinição de senha.</p>
    </div>

    <?php if (isset($_GET['status']) && $_GET['status'] === 'enviado'): ?>
        <div class="alert alert-success border-0 text-center small" style="background-color: rgba(25, 135, 84, 0.1); color: #2ecc71;">
            <i class="bi bi-check-circle-fill me-2"></i> Link enviado! Verifique sua caixa de entrada e de spam.
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['erro']) && $_GET['erro'] === 'nao_encontrado'): ?>
        <div class="alert alert-danger border-0 text-center small" style="background-color: rgba(220, 53, 69, 0.1); color: #e74c3c;">
            <i class="bi bi-exclamation-triangle-fill me-2"></i> E-mail não encontrado no sistema.
        </div>
    <?php endif; ?>

    <form action="processa_esqueci_senha.php" method="POST">
        <div class="mb-4">
            <label for="email" class="form-label small text-secondary">E-mail Cadastrado</label>
            <div class="input-group">
                <span class="input-group-text border-0" style="background-color: #1f1f30; color: #6c757d;"><i class="bi bi-envelope"></i></span>
                <input type="email" name="email" id="email" class="form-control" placeholder="seuemail@exemplo.com" required>
            </div>
        </div>

        <button type="submit" class="btn btn-primary w-100 mb-3">Enviar Link de Recuperação</button>

        <div class="text-center">
            <a href="login.php" class="text-decoration-none small text-secondary"><i class="bi bi-arrow-left me-1"></i> Voltar para o Login</a>
        </div>
    </form>
</div>

</body>
</html>