<?php
// usuario/salvar_telefone.php
// Salva o telefone a partir do modal de onboarding do dashboard (ex: quem entrou
// pelo Google, que nunca passa pelo campo de telefone do cadastro normal).

session_start();

if (!isset($_SESSION['usuario_id'])) {
    header("Location: login.php");
    exit;
}

require_once '../config/conexao.php';
require_once '../config/funcoes.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $telefone = sanitizarTelefone(trim($_POST['telefone'] ?? ''));

    if ($telefone) {
        try {
            $pdo->prepare("UPDATE Usuario SET Telefone = :tel WHERE IDUsuario = :uid")
                ->execute([':tel' => $telefone, ':uid' => $_SESSION['usuario_id']]);
        } catch (PDOException $e) {
        }
    }
}

header("Location: ../dashboard.php");
exit;
