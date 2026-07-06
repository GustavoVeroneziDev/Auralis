<?php
// ==============================================================================
// USUARIO/PROCESSA_INSIGNIAS.PHP — Define/limpa um dos 3 espaços de insígnia em
// destaque no perfil (Usuario.InsigniasDestaque)
// ==============================================================================
session_start();
if (!isset($_SESSION['usuario_id'])) { http_response_code(403); exit; }
require_once '../config/conexao.php';
require_once '../config/funcoes.php';
garantirColunaInsigniasDestaque($pdo);

$uid  = $_SESSION['usuario_id'];
$slot = (int)($_POST['slot'] ?? -1);
$conquistaId = trim($_POST['conquista_id'] ?? '') ?: null;

if ($slot < 0 || $slot > 2) {
    header("Location: /perfil.php?erro=insignia_invalida#listaConquistas");
    exit;
}

// Só deixa destacar conquista que o usuário realmente já desbloqueou.
if ($conquistaId !== null) {
    $stmtChk = $pdo->prepare("SELECT 1 FROM usuario_conquista WHERE FKUsuario = :uid AND FKConquista = :cid LIMIT 1");
    $stmtChk->execute([':uid' => $uid, ':cid' => $conquistaId]);
    if (!$stmtChk->fetchColumn()) {
        header("Location: /perfil.php?erro=insignia_invalida#listaConquistas");
        exit;
    }
}

$insignias = obterInsigniasDestaque($pdo, $uid);
$insignias[$slot] = $conquistaId;

try {
    $pdo->prepare("UPDATE Usuario SET InsigniasDestaque = :v WHERE IDUsuario = :uid")
        ->execute([':v' => json_encode($insignias), ':uid' => $uid]);
    header("Location: /perfil.php?sucesso=insignia_salva#listaConquistas");
} catch (PDOException $e) {
    header("Location: /perfil.php?erro=banco#listaConquistas");
}
exit;
