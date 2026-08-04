<?php
// cron/backfill_grupo_parcela_recorrencia.php
//
// Migration de dado (roda 1x, não é um cron recorrente): atribui um GrupoParcela
// real pra todo Registro recorrente órfão (Recorrente=1 AND GrupoParcela IS NULL —
// hoje isso acontece com todo item recorrente criado pela IA do WhatsApp, e com
// qualquer ocorrência que o motor antigo já tenha gerado). Sem GrupoParcela, a
// opção "excluir este e os futuros" falha silenciosamente e cai em "excluir só
// este" — é o bug raiz que motivou essa migration.
//
// Agrupa por (FKUsuario, FKCarteira, TipoRegistro, Descricao, Valor) — mesmo
// critério de correspondência que o NOT EXISTS do motor antigo já usava e é
// confiável em produção.
//
// Rodar primeiro com ?preview=1 (ou --preview via CLI) pra conferir quantos
// grupos seriam criados ANTES de aplicar de verdade.
//
// Configurar/rodar via cPanel > Trabalhos Cron (ou manualmente 1x):
//   Comando: /usr/local/bin/php /home/gust9360/public_html/cron/backfill_grupo_parcela_recorrencia.php
// Ou via URL: https://meuauralis.com/cron/backfill_grupo_parcela_recorrencia.php?token=SEU_TOKEN&preview=1

require_once __DIR__ . '/../config/vapid_keys.php';
if (PHP_SAPI !== 'cli') {
    header('Content-Type: text/plain; charset=utf-8');
    $tokenRecebido = $_GET['token'] ?? '';
    if (!isset($cronToken) || !hash_equals($cronToken, $tokenRecebido)) {
        http_response_code(403);
        exit('forbidden');
    }
}

require_once __DIR__ . '/../config/conexao.php';
require_once __DIR__ . '/../config/funcoes.php';

$preview = isset($_GET['preview']) || in_array('--preview', $argv ?? [], true);

garantirColunasRecorrenciaGeneralizada($pdo);

// Idempotência: não roda a aplicação de verdade 2x (preview pode rodar quantas vezes quiser)
$stmtFlag = $pdo->prepare(
    "SELECT Valor FROM ConfiguracaoSistema WHERE Chave = 'backfill_grupo_parcela_recorrencia_v1' AND FKUsuario IS NULL"
);
$stmtFlag->execute();
$jaRodou = $stmtFlag->fetchColumn();

if ($jaRodou && !$preview) {
    echo "Backfill já foi aplicado anteriormente — nada a fazer." . PHP_EOL;
    exit;
}

$orfaos = $pdo->query("
    SELECT IDRegistro, FKUsuario, FKCarteira, TipoRegistro, Descricao, Valor, MomentoRegistro
    FROM Registro
    WHERE Recorrente = 1 AND GrupoParcela IS NULL AND TotalParcelas IS NULL
    ORDER BY FKUsuario, FKCarteira, TipoRegistro, Descricao, Valor, MomentoRegistro
")->fetchAll(PDO::FETCH_ASSOC);

if (!$orfaos) {
    echo "Nenhum Registro recorrente órfão encontrado." . PHP_EOL;
    if (!$preview) {
        $pdo->prepare(
            "INSERT INTO ConfiguracaoSistema (Chave, Valor, FKUsuario) VALUES ('backfill_grupo_parcela_recorrencia_v1', '1', NULL)"
        )->execute();
    }
    exit;
}

$chaveAtual  = null;
$grupoAtual  = null;
$totalGrupos = 0;
$totalLinhas = 0;

$stmtUpd = $preview ? null : $pdo->prepare("UPDATE Registro SET GrupoParcela = :g WHERE IDRegistro = :id");

foreach ($orfaos as $row) {
    $chave = implode('|', [$row['FKUsuario'], $row['FKCarteira'], $row['TipoRegistro'], $row['Descricao'], $row['Valor']]);
    if ($chave !== $chaveAtual) {
        $chaveAtual = $chave;
        $grupoAtual = gerarUuid();
        $totalGrupos++;
        echo "Grupo {$totalGrupos}: usuário={$row['FKUsuario']} descricao=\"{$row['Descricao']}\" valor={$row['Valor']}" . PHP_EOL;
    }
    echo "  → {$row['IDRegistro']} ({$row['MomentoRegistro']})" . PHP_EOL;
    if ($stmtUpd) {
        $stmtUpd->execute([':g' => $grupoAtual, ':id' => $row['IDRegistro']]);
    }
    $totalLinhas++;
}

echo PHP_EOL . "{$totalGrupos} grupo(s), {$totalLinhas} linha(s) no total." . PHP_EOL;

if ($preview) {
    echo "[PREVIEW] Nada foi alterado. Rode sem ?preview=1 pra aplicar de verdade." . PHP_EOL;
} else {
    $pdo->prepare(
        "INSERT INTO ConfiguracaoSistema (Chave, Valor, FKUsuario) VALUES ('backfill_grupo_parcela_recorrencia_v1', '1', NULL)"
    )->execute();
    echo "Backfill aplicado com sucesso." . PHP_EOL;
}
