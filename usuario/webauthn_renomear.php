<?php
// ==============================================================================
// USUARIO/WEBAUTHN_RENOMEAR.PHP — renomeia o apelido de uma credencial já
// cadastrada (não mexe na credencial em si, só no nome de exibição).
// ==============================================================================
session_start();
if (!isset($_SESSION['usuario_id'])) { header("Location: login.php"); exit; }
require_once '../config/conexao.php';
require_once '../config/funcoes.php';

$uid = $_SESSION['usuario_id'];
$idCredencial = trim($_POST['id_credencial'] ?? '');
$apelido = trim($_POST['apelido'] ?? '');
$apelido = $apelido !== '' ? mb_substr($apelido, 0, 60) : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $idCredencial) {
    $pdo->prepare("UPDATE CredencialWebAuthn SET Apelido = :apelido WHERE IDCredencial = :id AND FKUsuario = :uid")
        ->execute([':apelido' => $apelido, ':id' => $idCredencial, ':uid' => $uid]);
    header("Location: ../configuracoes.php?sucesso=webauthn_renomeado#seguranca");
    exit;
}

header("Location: ../configuracoes.php#seguranca");
exit;
