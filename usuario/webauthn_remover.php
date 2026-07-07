<?php
// ==============================================================================
// USUARIO/WEBAUTHN_REMOVER.PHP — remove uma credencial de biometria cadastrada.
// ==============================================================================
session_start();
if (!isset($_SESSION['usuario_id'])) { header("Location: login.php"); exit; }
require_once '../config/conexao.php';
require_once '../config/funcoes.php';

$uid = $_SESSION['usuario_id'];
$idCredencial = trim($_POST['id_credencial'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $idCredencial) {
    $pdo->prepare("DELETE FROM CredencialWebAuthn WHERE IDCredencial = :id AND FKUsuario = :uid")
        ->execute([':id' => $idCredencial, ':uid' => $uid]);
    header("Location: ../configuracoes.php?sucesso=webauthn_removido#seguranca");
    exit;
}

header("Location: ../configuracoes.php#seguranca");
exit;
