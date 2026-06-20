<?php
// ============================================================
// exportar.php — Geração de CSV para PRO e VIP
// Tipos suportados: transacoes | fatura | analises
// ============================================================
session_start();
if (!isset($_SESSION['usuario_id'])) {
    header('Location: /usuario/login.php');
    exit;
}

require_once 'config/conexao.php';
require_once 'config/funcoes.php';

$uid   = $_SESSION['usuario_id'];
$plano = obterPlanoAtual();
$tipo  = trim($_GET['tipo'] ?? '');

if (!in_array($plano, ['pro', 'vip'])) {
    header('Location: /planos.php?upgrade=pro');
    exit;
}

// ── Helpers ────────────────────────────────────────────────────────────────
function csvHeaders(string $filename): void
{
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . addslashes($filename) . '"');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    echo "\xEF\xBB\xBF"; // UTF-8 BOM — garante acentos no Excel
}

$meses = ['Janeiro','Fevereiro','Março','Abril','Maio','Junho',
          'Julho','Agosto','Setembro','Outubro','Novembro','Dezembro'];

// ── TRANSAÇÕES ─────────────────────────────────────────────────────────────
if ($tipo === 'transacoes') {
    $mes        = max(1, min(12, (int)($_GET['mes'] ?? date('n'))));
    $ano        = max(2000, (int)($_GET['ano'] ?? date('Y')));
    $carteiraId = trim($_GET['carteira'] ?? '');

    $stmtCart = $pdo->prepare(
        "SELECT IDCarteira, TipoCarteira FROM Carteira
         WHERE IDCarteira = :cid AND FKUsuarioDono = :uid LIMIT 1"
    );
    $stmtCart->execute([':cid' => $carteiraId, ':uid' => $uid]);
    $carteira = $stmtCart->fetch();
    if (!$carteira) { http_response_code(403); exit('Acesso negado.'); }

    $stmt = $pdo->prepare("
        SELECT r.MomentoRegistro, r.Descricao, r.TipoRegistro, r.Valor,
               r.StatusRegistro, r.ParcelaAtual, r.TotalParcelas,
               COALESCE(c.NomeCategoria, 'Sem Categoria') AS Categoria
        FROM Registro r
        LEFT JOIN Categoria c ON r.FKCategoria = c.IDCategoria
        WHERE r.FKCarteira = :cid AND r.FKUsuario = :uid
          AND MONTH(r.MomentoRegistro) = :mes AND YEAR(r.MomentoRegistro) = :ano
        ORDER BY r.MomentoRegistro DESC
    ");
    $stmt->execute([':cid' => $carteiraId, ':uid' => $uid, ':mes' => $mes, ':ano' => $ano]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $nomeMes  = $meses[$mes - 1];
    $nomeCart = preg_replace('/[^a-zA-Z0-9]/', '-', $carteira['TipoCarteira']);
    csvHeaders("auralis-{$nomeCart}-{$nomeMes}-{$ano}.csv");

    $out = fopen('php://output', 'w');
    fputcsv($out, ['Data', 'Descrição', 'Tipo', 'Categoria', 'Valor (R$)', 'Status', 'Parcela'], ';');
    foreach ($rows as $r) {
        $sinal   = $r['TipoRegistro'] === 'despesa' ? '-' : '+';
        $parcela = ($r['ParcelaAtual'] && $r['TotalParcelas'])
            ? "{$r['ParcelaAtual']}/{$r['TotalParcelas']}" : '-';
        fputcsv($out, [
            date('d/m/Y', strtotime($r['MomentoRegistro'])),
            $r['Descricao'],
            $r['TipoRegistro'] === 'receita' ? 'Receita' : 'Despesa',
            $r['Categoria'],
            $sinal . number_format(abs($r['Valor']), 2, ',', '.'),
            ucfirst($r['StatusRegistro']),
            $parcela,
        ], ';');
    }
    fclose($out);
    exit;
}

// ── FATURA ─────────────────────────────────────────────────────────────────
if ($tipo === 'fatura') {
    $faturaId = trim($_GET['fatura'] ?? '');

    $stmtFat = $pdo->prepare("
        SELECT f.*, c.Nome AS NomeCartao
        FROM FaturaCartao f
        JOIN CartaoCredito c ON f.FKCartao = c.IDCartao
        WHERE f.IDFatura = :fid AND f.FKUsuario = :uid LIMIT 1
    ");
    $stmtFat->execute([':fid' => $faturaId, ':uid' => $uid]);
    $fatura = $stmtFat->fetch();
    if (!$fatura) { http_response_code(403); exit('Acesso negado.'); }

    $stmt = $pdo->prepare("
        SELECT l.DataCompra, l.Descricao, l.Valor, l.ParcelaAtual, l.TotalParcelas,
               COALESCE(cat.NomeCategoria, 'Sem Categoria') AS Categoria
        FROM LancamentoCartao l
        LEFT JOIN Categoria cat ON l.FKCategoria = cat.IDCategoria
        WHERE l.FKFatura = :fid AND l.FKUsuario = :uid
        ORDER BY l.DataCompra DESC
    ");
    $stmt->execute([':fid' => $faturaId, ':uid' => $uid]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $mesVenc  = date('m-Y', strtotime($fatura['DataVencimento']));
    $nomeCart = preg_replace('/[^a-zA-Z0-9]/', '-', $fatura['NomeCartao']);
    csvHeaders("fatura-{$nomeCart}-{$mesVenc}.csv");

    $out   = fopen('php://output', 'w');
    $venc  = date('d/m/Y', strtotime($fatura['DataVencimento']));
    fputcsv($out, ["Fatura: {$fatura['NomeCartao']} — Vencimento {$venc}"], ';');
    fputcsv($out, [], ';');
    fputcsv($out, ['Data da Compra', 'Descrição', 'Categoria', 'Valor (R$)', 'Parcela'], ';');
    $total = 0.0;
    foreach ($rows as $r) {
        $parcela = ($r['ParcelaAtual'] && $r['TotalParcelas'])
            ? "{$r['ParcelaAtual']}/{$r['TotalParcelas']}" : '-';
        fputcsv($out, [
            date('d/m/Y', strtotime($r['DataCompra'])),
            $r['Descricao'],
            $r['Categoria'],
            'R$ ' . number_format($r['Valor'], 2, ',', '.'),
            $parcela,
        ], ';');
        $total += (float)$r['Valor'];
    }
    fputcsv($out, [], ';');
    fputcsv($out, ['', '', 'TOTAL', 'R$ ' . number_format($total, 2, ',', '.'), ''], ';');
    fclose($out);
    exit;
}

// ── ANÁLISES ───────────────────────────────────────────────────────────────
if ($tipo === 'analises') {
    $mes        = max(1, min(12, (int)($_GET['mes'] ?? date('n'))));
    $ano        = max(2000, (int)($_GET['ano'] ?? date('Y')));
    $carteiraId = trim($_GET['carteira'] ?? '');

    $stmtCart = $pdo->prepare(
        "SELECT IDCarteira FROM Carteira WHERE IDCarteira = :cid AND FKUsuarioDono = :uid LIMIT 1"
    );
    $stmtCart->execute([':cid' => $carteiraId, ':uid' => $uid]);
    if (!$stmtCart->fetch()) { http_response_code(403); exit('Acesso negado.'); }

    $stmt = $pdo->prepare("
        SELECT COALESCE(c.NomeCategoria, 'Sem Categoria') AS Categoria,
               r.TipoRegistro,
               SUM(ABS(r.Valor)) AS Total,
               COUNT(*) AS Quantidade
        FROM Registro r
        LEFT JOIN Categoria c ON r.FKCategoria = c.IDCategoria
        WHERE r.FKUsuario = :uid AND r.FKCarteira = :cid
          AND r.StatusRegistro = 'efetivado'
          AND MONTH(r.MomentoRegistro) = :mes AND YEAR(r.MomentoRegistro) = :ano
        GROUP BY c.IDCategoria, c.NomeCategoria, r.TipoRegistro
        ORDER BY Total DESC
    ");
    $stmt->execute([':uid' => $uid, ':cid' => $carteiraId, ':mes' => $mes, ':ano' => $ano]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $totalGeral = array_sum(array_column($rows, 'Total')) ?: 1;
    $nomeMes    = $meses[$mes - 1];
    csvHeaders("auralis-analises-{$nomeMes}-{$ano}.csv");

    $out = fopen('php://output', 'w');
    fputcsv($out, ['Categoria', 'Tipo', 'Total (R$)', 'Transações', '% do Total'], ';');
    foreach ($rows as $r) {
        $pct = round($r['Total'] / $totalGeral * 100, 1);
        fputcsv($out, [
            $r['Categoria'],
            $r['TipoRegistro'] === 'receita' ? 'Receita' : 'Despesa',
            number_format($r['Total'], 2, ',', '.'),
            $r['Quantidade'],
            "{$pct}%",
        ], ';');
    }
    fclose($out);
    exit;
}

http_response_code(400);
echo 'Tipo de exportação inválido.';
