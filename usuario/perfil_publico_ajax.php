<?php
// usuario/perfil_publico_ajax.php
// Dados do perfil de outra pessoa pro modal aberto a partir do Ranking.

session_start();
if (!isset($_SESSION['usuario_id'])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'erro' => 'Sem permissão']);
    exit;
}

require_once '../config/conexao.php';
require_once '../config/funcoes.php';

header('Content-Type: application/json; charset=utf-8');

$uidViewer = $_SESSION['usuario_id'];
$uidAlvo   = trim($_GET['id'] ?? '');

if (empty($uidAlvo)) {
    echo json_encode(['ok' => false, 'erro' => 'ID inválido']);
    exit;
}

garantirTabelaAmizade($pdo);

$stmtU = $pdo->prepare("
    SELECT IDUsuario, Nome, Plano, MomentoCriacao, FotoPerfil, FotoPerfilReal
    FROM Usuario WHERE IDUsuario = :id AND StatusConta = 'ativo' LIMIT 1
");
$stmtU->execute([':id' => $uidAlvo]);
$usuario = $stmtU->fetch(PDO::FETCH_ASSOC);

if (!$usuario) {
    echo json_encode(['ok' => false, 'erro' => 'Usuário não encontrado']);
    exit;
}

$dataCriacao = new DateTime($usuario['MomentoCriacao']);
$diasAtivo   = (int)(new DateTime())->diff($dataCriacao)->days;
$dataMembro  = $dataCriacao->format('d/m/Y');

// ── Stats públicos — só contagens, nunca o conteúdo dos comprovantes ─────────
$stmtStats = $pdo->prepare("
    SELECT
        (SELECT COUNT(*) FROM Registro    WHERE FKUsuario = :uid  AND TipoRegistro IN ('receita','despesa')) AS transacoes,
        (SELECT COUNT(*) FROM Categoria   WHERE FKUsuario = :uid2) AS categorias,
        (SELECT COUNT(*) FROM Comprovante WHERE FKUsuario = :uid3) AS comprovantes
");
$stmtStats->execute([':uid' => $uidAlvo, ':uid2' => $uidAlvo, ':uid3' => $uidAlvo]);
$stats = $stmtStats->fetch(PDO::FETCH_ASSOC) ?: ['transacoes' => 0, 'categorias' => 0, 'comprovantes' => 0];

// ── Conquistas ────────────────────────────────────────────────────────────
$conquistas = [];
try {
    $stmtC = $pdo->prepare("
        SELECT c.IDConquista, c.Nome, c.Descricao, c.Icone, COALESCE(c.ImagemUrl, '') AS ImagemUrl,
               c.Cor, c.Raridade, uc.DataConquista
        FROM conquista c
        LEFT JOIN usuario_conquista uc
               ON uc.FKConquista = c.IDConquista AND uc.FKUsuario = :uid
        WHERE c.Ativo = 1
        ORDER BY
            CASE WHEN uc.DataConquista IS NOT NULL THEN 0 ELSE 1 END ASC,
            CASE c.Raridade
                WHEN 'mitico' THEN 1 WHEN 'lendario' THEN 2 WHEN 'epico' THEN 3
                WHEN 'raro' THEN 4 WHEN 'incomum' THEN 5 WHEN 'comum' THEN 6 ELSE 7
            END ASC
    ");
    $stmtC->execute([':uid' => $uidAlvo]);
    $conquistas = $stmtC->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
}

$totalConquistas    = count($conquistas);
$totalDesbloqueadas = count(array_filter($conquistas, fn($c) => $c['DataConquista'] !== null));

$amizade = obterStatusAmizade($pdo, $uidViewer, $uidAlvo);

echo json_encode([
    'ok'                 => true,
    'id'                 => $usuario['IDUsuario'],
    'nome'               => $usuario['Nome'],
    'avatarHtml'         => renderAvatarUsuario($usuario, 80),
    'plano'              => strtolower($usuario['Plano'] ?? 'free'),
    'dataMembro'         => $dataMembro,
    'diasAtivo'          => $diasAtivo,
    'stats'              => [
        'transacoes'   => (int)$stats['transacoes'],
        'categorias'   => (int)$stats['categorias'],
        'comprovantes' => (int)$stats['comprovantes'],
    ],
    'totalConquistas'    => $totalConquistas,
    'totalDesbloqueadas' => $totalDesbloqueadas,
    'conquistas'         => array_map(fn($c) => [
        'nome'         => $c['Nome'],
        'descricao'    => $c['Descricao'],
        'icone'        => $c['Icone'],
        'imagem'       => $c['ImagemUrl'],
        'cor'          => $c['Cor'],
        'raridade'     => $c['Raridade'] ?? 'comum',
        'desbloqueada' => $c['DataConquista'] !== null,
    ], $conquistas),
    'amizade'            => $amizade,
], JSON_UNESCAPED_UNICODE);
