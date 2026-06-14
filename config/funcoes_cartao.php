<?php
// funcoes_cartao.php — Lógica do sistema de cartões de crédito
// Requer que funcoes.php (gerarUuid) e conexao.php ($pdo) já estejam carregados.

if (!function_exists('cartao_criarFatura')) :

// ─────────────────────────────────────────────────────────────────────────────
// HELPERS DE DATA
// ─────────────────────────────────────────────────────────────────────────────

function _cc_diasNoMes(int $mes, int $ano): int
{
    return (int)(new DateTime("$ano-" . str_pad($mes, 2, '0', STR_PAD_LEFT) . "-01"))->format('t');
}

/**
 * Dado um MesReferencia (YYYY-MM = mês do vencimento), calcula as datas da fatura.
 * DataFechamento = mês anterior ao MesReferencia, dia DiaFechamento
 * DataVencimento = MesReferencia, dia DiaVencimento
 */
function _cc_datasParaMesRef(int $diaFech, int $diaVenc, string $mesRef): array
{
    [$ano, $mes] = array_map('intval', explode('-', $mesRef));

    // Vencimento: dentro do MesReferencia
    $diaVencCapped = min($diaVenc, _cc_diasNoMes($mes, $ano));
    $vencimento    = sprintf('%04d-%02d-%02d', $ano, $mes, $diaVencCapped);

    // Fechamento: mês anterior
    $dtPrev = new DateTime("$ano-" . str_pad($mes, 2, '0', STR_PAD_LEFT) . "-01");
    $dtPrev->modify('-1 month');
    $anoPrev = (int)$dtPrev->format('Y');
    $mesPrev = (int)$dtPrev->format('n');
    $diaFechCapped = min($diaFech, _cc_diasNoMes($mesPrev, $anoPrev));
    $fechamento    = sprintf('%04d-%02d-%02d', $anoPrev, $mesPrev, $diaFechCapped);

    return ['fechamento' => $fechamento, 'vencimento' => $vencimento];
}

/**
 * Retorna o MesReferencia (YYYY-MM) da fatura aberta atual para um dado DiaFechamento.
 * Se hoje <= DiaFechamento → fatura fecha este mês → vence mês que vem → MesRef = próx. mês
 * Se hoje >  DiaFechamento → fatura fecha mês que vem → vence daqui 2 meses → MesRef = +2 meses
 */
function _cc_mesRefAtual(int $diaFech): string
{
    $hoje    = new DateTime('today');
    $diaHoje = (int)$hoje->format('j');
    $offset  = ($diaHoje <= $diaFech) ? '+1 month' : '+2 months';
    $hoje->modify($offset);
    return $hoje->format('Y-m');
}

function _cc_mesRefAdiante(string $mesRefBase, int $n): string
{
    $dt = new DateTime($mesRefBase . '-01');
    $dt->modify("+$n month");
    return $dt->format('Y-m');
}

// ─────────────────────────────────────────────────────────────────────────────
// OPERAÇÕES DE FATURA
// ─────────────────────────────────────────────────────────────────────────────

function cartao_criarFatura(PDO $pdo, string $cartaoId, string $uid, array $cartao, string $mesRef): array
{
    $datas = _cc_datasParaMesRef((int)$cartao['DiaFechamento'], (int)$cartao['DiaVencimento'], $mesRef);
    $id    = gerarUuid();
    $pdo->prepare(
        "INSERT IGNORE INTO FaturaCartao
             (IDFatura, FKCartao, FKUsuario, MesReferencia, DataFechamento, DataVencimento, Status, ValorTotal)
         VALUES (:id, :cid, :uid, :mr, :fech, :venc, 'aberta', 0.00)"
    )->execute([
        ':id'   => $id,
        ':cid'  => $cartaoId,
        ':uid'  => $uid,
        ':mr'   => $mesRef,
        ':fech' => $datas['fechamento'],
        ':venc' => $datas['vencimento'],
    ]);

    // Se INSERT IGNORE silenciou um duplicado, busca a existente
    $stmt = $pdo->prepare("SELECT * FROM FaturaCartao WHERE FKCartao = :cid AND FKUsuario = :uid AND MesReferencia = :mr LIMIT 1");
    $stmt->execute([':cid' => $cartaoId, ':uid' => $uid, ':mr' => $mesRef]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * Retorna a fatura ABERTA do cartão, criando-a se não existir.
 */
function cartao_obterFaturaAberta(PDO $pdo, string $cartaoId, string $uid, array $cartao): array
{
    $stmt = $pdo->prepare(
        "SELECT * FROM FaturaCartao WHERE FKCartao = :cid AND FKUsuario = :uid AND Status = 'aberta'
         ORDER BY DataFechamento ASC LIMIT 1"
    );
    $stmt->execute([':cid' => $cartaoId, ':uid' => $uid]);
    $f = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($f) return $f;

    return cartao_criarFatura($pdo, $cartaoId, $uid, $cartao, _cc_mesRefAtual((int)$cartao['DiaFechamento']));
}

/**
 * Retorna (ou cria) a fatura para um MesRef específico — usado para parcelas futuras.
 */
function cartao_obterFaturaParaMesRef(PDO $pdo, string $cartaoId, string $uid, array $cartao, string $mesRef): array
{
    $stmt = $pdo->prepare(
        "SELECT * FROM FaturaCartao WHERE FKCartao = :cid AND FKUsuario = :uid AND MesReferencia = :mr LIMIT 1"
    );
    $stmt->execute([':cid' => $cartaoId, ':uid' => $uid, ':mr' => $mesRef]);
    $f = $stmt->fetch(PDO::FETCH_ASSOC);
    return $f ?: cartao_criarFatura($pdo, $cartaoId, $uid, $cartao, $mesRef);
}

/**
 * Mantém um Registro pendente de "preview" para a fatura aberta.
 * Criado/atualizado após cada lançamento; deletado quando a fatura fecha.
 * Requer coluna FKRegistroPreview em FaturaCartao (alter_cartoes_v2.sql).
 */
function cartao_sincronizarPreview(PDO $pdo, string $faturaId, string $uid, array $cartao): void
{
    if (empty($cartao['FKCarteiraDebito'])) return;

    try {
        $stmtF = $pdo->prepare("SELECT * FROM FaturaCartao WHERE IDFatura = :id AND FKUsuario = :uid AND Status = 'aberta'");
        $stmtF->execute([':id' => $faturaId, ':uid' => $uid]);
        $fatura = $stmtF->fetch(PDO::FETCH_ASSOC);
        if (!$fatura) return;

        $stmtT = $pdo->prepare("SELECT COALESCE(SUM(Valor), 0) FROM LancamentoCartao WHERE FKFatura = :fid");
        $stmtT->execute([':fid' => $faturaId]);
        $total = (float)$stmtT->fetchColumn();

        $desc = 'Fatura ' . $cartao['Nome'];
        $dataRef = $fatura['DataFechamento'];
        $dataVenc = $fatura['DataVencimento'];

        if ($total <= 0) {
            // Sem lançamentos: remove o preview se existia
            if (!empty($fatura['FKRegistroPreview'])) {
                $pdo->prepare("DELETE FROM Registro WHERE IDRegistro = :rid AND FKUsuario = :uid")
                    ->execute([':rid' => $fatura['FKRegistroPreview'], ':uid' => $uid]);
                $pdo->prepare("UPDATE FaturaCartao SET FKRegistroPreview = NULL WHERE IDFatura = :fid")
                    ->execute([':fid' => $faturaId]);
            }
            return;
        }

        if (!empty($fatura['FKRegistroPreview'])) {
            // Atualiza preview existente
            $pdo->prepare(
                "UPDATE Registro SET Valor = :v, Descricao = :d, MomentoRegistro = :m, DataVencimento = :dv
                 WHERE IDRegistro = :id AND FKUsuario = :uid"
            )->execute([
                ':v'   => $total,
                ':d'   => $desc,
                ':m'   => $dataRef,
                ':dv'  => $dataVenc,
                ':id'  => $fatura['FKRegistroPreview'],
                ':uid' => $uid,
            ]);
        } else {
            // Cria preview novo
            $rid = gerarUuid();
            $pdo->prepare(
                "INSERT INTO Registro
                     (IDRegistro, TipoRegistro, Valor, Descricao, MomentoRegistro, DataVencimento,
                      StatusRegistro, Recorrente, FKCarteira, FKUsuario)
                 VALUES (:id, 'despesa', :v, :d, :m, :dv, 'pendente', 0, :cart, :uid)"
            )->execute([
                ':id'   => $rid,
                ':v'    => $total,
                ':d'    => $desc,
                ':m'    => $dataRef,
                ':dv'   => $dataVenc,
                ':cart' => $cartao['FKCarteiraDebito'],
                ':uid'  => $uid,
            ]);
            $pdo->prepare("UPDATE FaturaCartao SET FKRegistroPreview = :rid WHERE IDFatura = :fid")
                ->execute([':rid' => $rid, ':fid' => $faturaId]);
        }
    } catch (PDOException $e) {
        // Silencia caso a coluna ainda não exista (antes do alter_cartoes_v2.sql)
    }
}

/**
 * Fecha uma fatura: congela valor, remove preview, cria lembrete de pagamento e abre próxima.
 */
function cartao_fecharFatura(PDO $pdo, array $fatura, string $uid): void
{
    // Total dos lançamentos
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(Valor), 0) FROM LancamentoCartao WHERE FKFatura = :id");
    $stmt->execute([':id' => $fatura['IDFatura']]);
    $total = (float)$stmt->fetchColumn();

    // Remove o Registro de preview (será substituído pelo de pagamento real)
    if (!empty($fatura['FKRegistroPreview'])) {
        $pdo->prepare("DELETE FROM Registro WHERE IDRegistro = :rid AND FKUsuario = :uid")
            ->execute([':rid' => $fatura['FKRegistroPreview'], ':uid' => $uid]);
        $pdo->prepare("UPDATE FaturaCartao SET FKRegistroPreview = NULL WHERE IDFatura = :fid")
            ->execute([':fid' => $fatura['IDFatura']]);
    }

    // Fecha a fatura
    $pdo->prepare("UPDATE FaturaCartao SET Status = 'fechada', ValorTotal = :t WHERE IDFatura = :id")
        ->execute([':t' => $total, ':id' => $fatura['IDFatura']]);

    // Cria lembrete de pagamento no Registro se há carteira de débito configurada e total > 0
    if ($total > 0 && !empty($fatura['FKCarteiraDebito'])) {
        $rid = gerarUuid();
        $pdo->prepare(
            "INSERT INTO Registro
                 (IDRegistro, TipoRegistro, Valor, Descricao, MomentoRegistro, DataVencimento,
                  StatusRegistro, Recorrente, FKCarteira, FKUsuario)
             VALUES (:id, 'despesa', :val, :desc, :momento, :venc, 'pendente', 0, :cart, :uid)"
        )->execute([
            ':id'      => $rid,
            ':val'     => $total,
            ':desc'    => 'Fatura ' . ($fatura['NomeCartao'] ?? 'Cartão'),
            ':momento' => $fatura['DataVencimento'],
            ':venc'    => $fatura['DataVencimento'],
            ':cart'    => $fatura['FKCarteiraDebito'],
            ':uid'     => $uid,
        ]);
        $pdo->prepare("UPDATE FaturaCartao SET FKRegistroPagamento = :rid WHERE IDFatura = :id")
            ->execute([':rid' => $rid, ':id' => $fatura['IDFatura']]);
    }

    // Abre próxima fatura automaticamente
    $cartao = $pdo->prepare("SELECT * FROM CartaoCredito WHERE IDCartao = :id");
    $cartao->execute([':id' => $fatura['FKCartao']]);
    $cartao = $cartao->fetch(PDO::FETCH_ASSOC);
    if ($cartao) {
        $proximoMes = _cc_mesRefAdiante($fatura['MesReferencia'], 1);
        cartao_criarFatura($pdo, $cartao['IDCartao'], $uid, $cartao, $proximoMes);
    }
}

/**
 * Verificação automática: fecha faturas cujo DataFechamento já passou.
 * Chamada uma vez por request (static guard).
 */
function cartao_verificarFechamentos(PDO $pdo, string $uid): void
{
    static $rodou = false;
    if ($rodou) return;
    $rodou = true;

    try {
        $hoje = date('Y-m-d');
        $stmt = $pdo->prepare(
            "SELECT f.*, c.DiaVencimento, c.FKCarteiraDebito, c.Nome AS NomeCartao
             FROM FaturaCartao f
             JOIN CartaoCredito c ON f.FKCartao = c.IDCartao
             WHERE f.FKUsuario = :uid AND f.Status = 'aberta' AND f.DataFechamento <= :hoje"
        );
        $stmt->execute([':uid' => $uid, ':hoje' => $hoje]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $fatura) {
            cartao_fecharFatura($pdo, $fatura, $uid);
        }
    } catch (PDOException $e) {
        // Silencia caso as tabelas ainda não existam
    }
}

/**
 * Total acumulado da fatura aberta de um cartão.
 */
function cartao_totalFaturaAberta(PDO $pdo, string $cartaoId, string $uid): float
{
    try {
        $stmt = $pdo->prepare(
            "SELECT COALESCE(SUM(l.Valor), 0)
             FROM LancamentoCartao l
             JOIN FaturaCartao f ON l.FKFatura = f.IDFatura
             WHERE f.FKCartao = :cid AND f.FKUsuario = :uid AND f.Status = 'aberta'"
        );
        $stmt->execute([':cid' => $cartaoId, ':uid' => $uid]);
        return (float)$stmt->fetchColumn();
    } catch (PDOException $e) {
        return 0.0;
    }
}

/**
 * Lista os cartões ativos do usuário. Retorna [] se a tabela não existir.
 */
function cartao_listarAtivos(PDO $pdo, string $uid): array
{
    try {
        $stmt = $pdo->prepare(
            "SELECT * FROM CartaoCredito WHERE FKUsuario = :uid AND Ativo = 1 ORDER BY CriadoEm ASC"
        );
        $stmt->execute([':uid' => $uid]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
    }
}

endif;
