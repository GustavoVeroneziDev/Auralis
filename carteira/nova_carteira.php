<?php
// 1. Verificação de Segurança
session_start();
if (!isset($_SESSION['usuario_id'])) {
    header("Location: ../usuario/login.php");
    exit;
}

// 2. Puxa a conexão com o banco
require_once '../config/conexao.php';

$usuario_id = $_SESSION['usuario_id'];
$id_carteira = isset($_GET['editar']) ? $_GET['editar'] : (isset($_GET['id']) ? $_GET['id'] : null);$nome_carteira = '';
$is_edit = false;

// 3. Verifica se estamos no "MODO EDIÇÃO"
if ($id_carteira) {
    try {
        // Busca a carteira, garantindo que ela pertence a este usuário
        $sql = 'SELECT "TipoCarteira" FROM Carteira WHERE "IDCarteira" = :id AND "FKUsuarioDono" = :uid LIMIT 1';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':id' => $id_carteira, ':uid' => $usuario_id]);
        $resultado = $stmt->fetch();

        if ($resultado) {
            $nome_carteira = $resultado['TipoCarteira'];
            $is_edit = true;
        } else {
            // Se tentar editar uma carteira que não existe ou é de outra pessoa
            header("Location: listar_carteiras.php?erro=nao_encontrada");
            exit;
        }
    } catch (PDOException $e) {
        die("Erro ao buscar dados da carteira: " . $e->getMessage());
    }
}

// 4. Puxa o cabeçalho
require_once '../geral/header.php';
?>

<main class="container py-5 mt-4">
    <div class="row justify-content-center">
        <div class="col-lg-6 col-md-8">
            
            <div class="mb-4">
                <a href="listar_carteiras.php" class="text-secondary text-decoration-none custom-link">
                    <i class="bi bi-arrow-left me-1"></i> Voltar para Carteiras
                </a>
            </div>

            <div class="card bg-body-tertiary border-secondary-subtle shadow-lg p-4 p-md-5 rounded-4">
                
                <div class="text-center mb-4">
                    <div class="d-inline-flex align-items-center justify-content-center bg-primary bg-opacity-10 rounded-circle mb-3" style="width: 80px; height: 80px;">
                        <i class="bi <?= $is_edit ? 'bi-pencil-square' : 'bi-wallet-fill' ?> text-primary" style="font-size: 2.5rem;"></i>
                    </div>
                    <h2 class="fw-bold text-light"><?= $is_edit ? 'Editar Carteira' : 'Nova Carteira' ?></h2>
                    <p class="text-light opacity-75">
                        <?= $is_edit ? 'Altere o nome do seu espaço financeiro.' : 'Crie um novo espaço para gerenciar suas finanças.' ?>
                    </p>
                </div>

                <form action="processa_carteira.php" method="POST">
                    
                    <?php if ($is_edit): ?>
                        <input type="hidden" name="id_carteira" value="<?= htmlspecialchars($id_carteira) ?>">
                    <?php endif; ?>

                    <div class="mb-4">
                        <label for="tipo_carteira" class="form-label text-light opacity-75 fw-semibold">Nome ou Tipo da Carteira</label>
                        <div class="input-group">
                            <span class="input-group-text bg-dark border-secondary text-secondary">
                                <i class="bi bi-tag-fill"></i>
                            </span>
                            <input type="text" class="form-control form-control-lg bg-dark border-secondary text-light" 
                                   id="tipo_carteira" name="tipo_carteira" required 
                                   value="<?= htmlspecialchars($nome_carteira) ?>"
                                   placeholder="Ex: Conta Pessoal, Conta Famíliar">
                        </div>
                        <div class="form-text text-secondary mt-2">
                            Use um nome que facilite a identificação no seu dia a dia.
                        </div>
                    </div>

                    <div class="d-grid mt-5">
                        <button type="submit" class="btn btn-primary btn-lg fw-bold text-dark fs-5 cardCentral py-3">
                            <i class="bi <?= $is_edit ? 'bi-arrow-repeat' : 'bi-check-lg' ?> me-2"></i> 
                            <?= $is_edit ? 'Atualizar Carteira' : 'Salvar Carteira' ?>
                        </button>
                    </div>

                </form>
            </div>

        </div>
    </div>
</main>
<script>
    // Impede o spam de cliques ou a metralhadora de 'Enter'
    const formCarteira = document.getElementById('formCarteira');
    const btnSalvar = document.getElementById('btnSalvar');

    if (formCarteira) {
        formCarteira.addEventListener('submit', function() {
            // Desativa o botão instantaneamente
            btnSalvar.disabled = true;
            
            // Muda o texto para dar um feedback visual pro usuário
            btnSalvar.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span> Salvando...';
        });
    }
</script>
<?php require_once '../geral/footer.php'; ?>