<?php
// ==============================================================================
// USUARIO/PROCESSA_FOTO_PERFIL.PHP — Upload/remoção de foto real de perfil
// (separada do personagem — nunca apaga o FotoPerfil/DiceBear, só some na frente dele)
// ==============================================================================
session_start();
if (!isset($_SESSION['usuario_id'])) { http_response_code(403); exit; }
require_once '../config/conexao.php';
require_once '../config/funcoes.php';
garantirColunaFotoPerfilReal($pdo);

$uid = $_SESSION['usuario_id'];

// Busca a foto atual antes de trocar, pra poder apagar o arquivo velho depois (sem
// acumular lixo em uploads/perfil/).
$stmtAtual = $pdo->prepare("SELECT FotoPerfilReal FROM Usuario WHERE IDUsuario = :uid");
$stmtAtual->execute([':uid' => $uid]);
$fotoAntiga = $stmtAtual->fetchColumn();

if (($_POST['action'] ?? '') === 'remover_foto') {
    $pdo->prepare("UPDATE Usuario SET FotoPerfilReal = NULL WHERE IDUsuario = :uid")->execute([':uid' => $uid]);
    if ($fotoAntiga) {
        $caminho = dirname(__DIR__) . $fotoAntiga;
        if (is_file($caminho)) @unlink($caminho);
    }
    header("Location: /perfil.php?sucesso=foto_removida#personagem");
    exit;
}

if (empty($_FILES['foto']['tmp_name']) || $_FILES['foto']['error'] !== UPLOAD_ERR_OK) {
    header("Location: /perfil.php?erro=foto_invalida#personagem");
    exit;
}

$maxSize = 5 * 1024 * 1024;
if ($_FILES['foto']['size'] > $maxSize) {
    header("Location: /perfil.php?erro=foto_grande#personagem");
    exit;
}

$allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
$mime    = mime_content_type($_FILES['foto']['tmp_name']);
if (!isset($allowed[$mime])) {
    header("Location: /perfil.php?erro=foto_tipo#personagem");
    exit;
}

$filename = gerarUuid() . '.' . $allowed[$mime];
$dir      = dirname(__DIR__) . '/uploads/perfil/' . $uid . '/';
if (!is_dir($dir)) mkdir($dir, 0755, true);

if (move_uploaded_file($_FILES['foto']['tmp_name'], $dir . $filename)) {
    $urlPublica = '/uploads/perfil/' . $uid . '/' . $filename;
    $pdo->prepare("UPDATE Usuario SET FotoPerfilReal = :url WHERE IDUsuario = :uid")
        ->execute([':url' => $urlPublica, ':uid' => $uid]);

    if ($fotoAntiga) {
        $caminhoAntigo = dirname(__DIR__) . $fotoAntiga;
        if (is_file($caminhoAntigo)) @unlink($caminhoAntigo);
    }
    header("Location: /perfil.php?sucesso=foto#personagem");
} else {
    header("Location: /perfil.php?erro=foto_upload#personagem");
}
exit;
