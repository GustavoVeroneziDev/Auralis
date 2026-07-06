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
 * Calcula DataFechamento e DataVencimento para um MesReferencia dado.
 *
 * Dois casos possíveis:
 *  a) diaVenc >= diaFech → fechamento e vencimento ficam no MESMO mês (ex: fecha dia 5, vence dia 10)
 *     DataFechamento = MesRef, diaFech
 *     DataVencimento = MesRef, diaVenc
 *
 *  b) diaVenc < diaFech → fechamento no mês ANTERIOR ao vencimento (ex: fecha dia 25, vence dia 3)
 *     DataFechamento = MesRef - 1 mês, diaFech
 *     DataVencimento = MesRef, diaVenc
 */
function _cc_datasParaMesRef(int $diaFech, int $diaVenc, string $mesRef): array
{
    [$ano, $mes] = array_map('intval', explode('-', $mesRef));

    $diaVencCapped = min($diaVenc, _cc_diasNoMes($mes, $ano));
    $vencimento    = sprintf('%04d-%02d-%02d', $ano, $mes, $diaVencCapped);

    if ($diaVenc >= $diaFech) {
        // Fechamento no MESMO mês do vencimento
        $diaFechCapped = min($diaFech, _cc_diasNoMes($mes, $ano));
        $fechamento    = sprintf('%04d-%02d-%02d', $ano, $mes, $diaFechCapped);
    } else {
        // Fechamento no mês ANTERIOR ao vencimento
        $dtPrev    = new DateTime("$ano-" . str_pad($mes, 2, '0', STR_PAD_LEFT) . "-01");
        $dtPrev->modify('-1 month');
        $anoPrev   = (int)$dtPrev->format('Y');
        $mesPrev   = (int)$dtPrev->format('n');
        $diaFechCapped = min($diaFech, _cc_diasNoMes($mesPrev, $anoPrev));
        $fechamento    = sprintf('%04d-%02d-%02d', $anoPrev, $mesPrev, $diaFechCapped);
    }

    return ['fechamento' => $fechamento, 'vencimento' => $vencimento];
}

/**
 * Retorna o MesReferencia (YYYY-MM) da fatura aberta atual.
 *
 * Regra: encontra o PRÓXIMO DataFechamento a partir de hoje, depois determina
 * o MesRef conforme a relação entre DiaFech e DiaVenc:
 *
 *  diaVenc >= diaFech → vencimento no MESMO mês do fechamento → MesRef = mês do fechamento
 *  diaVenc <  diaFech → vencimento no MÊS SEGUINTE ao fechamento → MesRef = mês do fechamento + 1
 *
 * Exemplos (diaFech=5, diaVenc=10, hoje=14/06):
 *   → próximo fechamento = 05/07 → diaVenc(10) >= diaFech(5) → MesRef = 2026-07
 *
 * Exemplos (diaFech=25, diaVenc=3, hoje=14/06):
 *   → próximo fechamento = 25/06 → diaVenc(3) < diaFech(25) → MesRef = 2026-07
 */
function _cc_mesRefAtual(int $diaFech, int $diaVenc): string
{
    $hoje      = new DateTime('today');
    $diaHoje   = (int)$hoje->format('j');
    $mesAtual  = (int)$hoje->format('n');
    $anoAtual  = (int)$hoje->format('Y');

    // Dia de fechamento efetivo no mês atual (29/30/31 vira último dia do mês)
    $diaFechEfetivo = min($diaFech, _cc_diasNoMes($mesAtual, $anoAtual));

    // Mês onde o PRÓXIMO fechamento vai ocorrer
    $mesFech = clone $hoje;
    if ($diaHoje > $diaFechEfetivo) {
        $mesFech->modify('+1 month');
    }

    // MesRef = mês do vencimento
    if ($diaVenc < $diaFech) {
        // Vencimento no mês seguinte ao fechamento
        $mesFech->modify('+1 month');
    }

    return $mesFech->format('Y-m');
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

    $mesRef = _cc_mesRefAtual((int)$cartao['DiaFechamento'], (int)$cartao['DiaVencimento']);
    return cartao_criarFatura($pdo, $cartaoId, $uid, $cartao, $mesRef);
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
 * Mantém um Registro pendente de "preview" para cada fatura aberta.
 * - Cria o Registro na primeira vez que há lançamentos
 * - Atualiza o valor total a cada lançamento adicionado ou removido
 * - Remove o Registro se todos os lançamentos forem excluídos
 * - Requer coluna FKRegistroPreview em FaturaCartao (alter_cartoes_v2.sql)
 */
function cartao_sincronizarPreview(PDO $pdo, string $faturaId, string $uid, array $cartao): void
{
    if (empty($cartao['FKCarteiraDebito'])) return;

    // Tenta ler FKRegistroPreview; retorna cedo se a coluna não existir (ALTER TABLE pendente)
    try {
        $stmtF = $pdo->prepare(
            "SELECT IDFatura, FKRegistroPreview, DataFechamento, DataVencimento
             FROM FaturaCartao WHERE IDFatura = :id AND FKUsuario = :uid AND Status = 'aberta'"
        );
        $stmtF->execute([':id' => $faturaId, ':uid' => $uid]);
        $fatura = $stmtF->fetch(PDO::FETCH_ASSOC);
        if (!$fatura) return;
    } catch (PDOException $e) {
        return; // Coluna FKRegistroPreview não existe ainda
    }

    // Calcula total atual
    $stmtT = $pdo->prepare("SELECT COALESCE(SUM(Valor), 0) FROM LancamentoCartao WHERE FKFatura = :fid");
    $stmtT->execute([':fid' => $faturaId]);
    $total = (float)$stmtT->fetchColumn();

    $desc     = 'Fatura ' . $cartao['Nome'];
    $dataRef  = $fatura['DataFechamento'];
    $dataVenc = $fatura['DataVencimento'];

    if ($total <= 0) {
        if (!empty($fatura['FKRegistroPreview'])) {
            $pdo->prepare("DELETE FROM Registro WHERE IDRegistro = :rid AND FKUsuario = :uid")
                ->execute([':rid' => $fatura['FKRegistroPreview'], ':uid' => $uid]);
            $pdo->prepare("UPDATE FaturaCartao SET FKRegistroPreview = NULL WHERE IDFatura = :fid")
                ->execute([':fid' => $faturaId]);
        }
        return;
    }

    if (!empty($fatura['FKRegistroPreview'])) {
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
        // INSERT e UPDATE devem ser atômicos para evitar Registros órfãos
        $rid = gerarUuid();
        $pdo->beginTransaction();
        try {
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
            $pdo->commit();
        } catch (PDOException $e) {
            $pdo->rollBack();
        }
    }
}

/**
 * Fecha uma fatura: remove preview, congela valor, cria lembrete de pagamento e abre próxima.
 */
function cartao_fecharFatura(PDO $pdo, array $fatura, string $uid, ?float $valorManual = null): void
{
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(Valor), 0) FROM LancamentoCartao WHERE FKFatura = :id");
    $stmt->execute([':id' => $fatura['IDFatura']]);
    $total = $valorManual !== null ? $valorManual : (float)$stmt->fetchColumn();

    // Remove o Registro de preview (será substituído pelo definitivo de pagamento)
    if (!empty($fatura['FKRegistroPreview'])) {
        try {
            $pdo->prepare("DELETE FROM Registro WHERE IDRegistro = :rid AND FKUsuario = :uid")
                ->execute([':rid' => $fatura['FKRegistroPreview'], ':uid' => $uid]);
            $pdo->prepare("UPDATE FaturaCartao SET FKRegistroPreview = NULL WHERE IDFatura = :fid")
                ->execute([':fid' => $fatura['IDFatura']]);
        } catch (PDOException $e) {}
    }

    $pdo->prepare("UPDATE FaturaCartao SET Status = 'fechada', ValorTotal = :t WHERE IDFatura = :id")
        ->execute([':t' => $total, ':id' => $fatura['IDFatura']]);

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
    $stmtC = $pdo->prepare("SELECT * FROM CartaoCredito WHERE IDCartao = :id");
    $stmtC->execute([':id' => $fatura['FKCartao']]);
    $cartao = $stmtC->fetch(PDO::FETCH_ASSOC);
    if ($cartao) {
        $proximoMes = _cc_mesRefAdiante($fatura['MesReferencia'], 1);
        cartao_criarFatura($pdo, $cartao['IDCartao'], $uid, $cartao, $proximoMes);
    }
}

/**
 * Repara uma fatura "órfã": fechada/paga sem carteira de pagamento definida na hora do
 * fechamento, então cartao_fecharFatura() nunca criou o Registro de cobrança — a fatura
 * fica invisível na agenda e não debita o saldo, mesmo já estando fechada ou marcada paga.
 * Se agora o cartão já tem FKCarteiraDebito definido, cria o Registro retroativamente e
 * vincula. Sem isso, uma fatura que já virou "paga" fica sem nenhuma ação disponível pra
 * corrigir (os botões de marcar paga/reabrir somem nesse status).
 * Retorna true se reparou algo, false se não havia nada a reparar (ou faltava a carteira).
 */
function cartao_repararRegistroPagamento(PDO $pdo, string $faturaId, string $uid): bool
{
    $stmt = $pdo->prepare("
        SELECT f.IDFatura, f.Status, f.ValorTotal, f.DataVencimento, f.FKRegistroPagamento,
               c.FKCarteiraDebito, c.Nome AS NomeCartao
        FROM FaturaCartao f
        JOIN CartaoCredito c ON c.IDCartao = f.FKCartao
        WHERE f.IDFatura = :id AND f.FKUsuario = :uid
    ");
    $stmt->execute([':id' => $faturaId, ':uid' => $uid]);
    $fatura = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$fatura) return false;
    if (!empty($fatura['FKRegistroPagamento'])) return false; // já tem, nada a reparar
    if (empty($fatura['FKCarteiraDebito'])) return false;      // ainda sem carteira — não dá pra reparar
    if (!in_array($fatura['Status'], ['fechada', 'paga'], true)) return false;
    if ((float)$fatura['ValorTotal'] <= 0) return false;

    $rid = gerarUuid();
    $statusReg = $fatura['Status'] === 'paga' ? 'efetivado' : 'pendente';
    $pdo->prepare("
        INSERT INTO Registro
            (IDRegistro, TipoRegistro, Valor, Descricao, MomentoRegistro, DataVencimento,
             StatusRegistro, Recorrente, FKCarteira, FKUsuario)
        VALUES (:id, 'despesa', :val, :desc, :venc, :venc, :status, 0, :cart, :uid)
    ")->execute([
        ':id'     => $rid,
        ':val'    => $fatura['ValorTotal'],
        ':desc'   => 'Fatura ' . $fatura['NomeCartao'],
        ':venc'   => $fatura['DataVencimento'],
        ':status' => $statusReg,
        ':cart'   => $fatura['FKCarteiraDebito'],
        ':uid'    => $uid,
    ]);
    $pdo->prepare("UPDATE FaturaCartao SET FKRegistroPagamento = :rid WHERE IDFatura = :id")
        ->execute([':rid' => $rid, ':id' => $faturaId]);

    return true;
}

/**
 * Reabre uma fatura fechada: remove o lembrete de pagamento pendente e restaura status aberta.
 */
function cartao_reabrirFatura(PDO $pdo, array $fatura, string $uid, array $cartao): void
{
    if (!empty($fatura['FKRegistroPagamento'])) {
        $pdo->prepare(
            "DELETE FROM Registro WHERE IDRegistro = :rid AND FKUsuario = :uid AND StatusRegistro = 'pendente'"
        )->execute([':rid' => $fatura['FKRegistroPagamento'], ':uid' => $uid]);
    }

    $pdo->prepare(
        "UPDATE FaturaCartao
         SET Status = 'aberta', ValorTotal = 0, FKRegistroPagamento = NULL
         WHERE IDFatura = :id AND FKUsuario = :uid AND Status = 'fechada'"
    )->execute([':id' => $fatura['IDFatura'], ':uid' => $uid]);

    cartao_sincronizarPreview($pdo, $fatura['IDFatura'], $uid, $cartao);
}

/**
 * Verificação automática: fecha faturas cujo DataFechamento já passou.
 */
function cartao_verificarFechamentos(PDO $pdo, string $uid): void
{
    static $rodou = false;
    if ($rodou) return;
    $rodou = true;

    try {
        // Estritamente < hoje (não <=): o dia do fechamento em si ainda pertence à fatura que
        // está fechando — uma compra feita nesse mesmo dia (a qualquer hora) precisa cair nela.
        // Só fecha de fato quando o dia seguinte começar.
        // O "NOT EXISTS" é o que protege uma fatura antiga reaberta manualmente pra edição:
        // sem ele, assim que a página recarrega essa verificação via encontra a fatura
        // reaberta (aberta + DataFechamento no passado) e fecha ela de novo sozinha, antes
        // mesmo de qualquer edição ser feita — desfazendo a reabertura silenciosamente.
        // Só fecha automaticamente quando é mesmo a fatura "da vez" do cartão (não existe
        // outra aberta mais recente pra esse mesmo cartão).
        $hoje = date('Y-m-d');
        $stmt = $pdo->prepare(
            "SELECT f.*, c.DiaVencimento, c.FKCarteiraDebito, c.Nome AS NomeCartao
             FROM FaturaCartao f
             JOIN CartaoCredito c ON f.FKCartao = c.IDCartao
             WHERE f.FKUsuario = :uid AND f.Status = 'aberta' AND f.DataFechamento < :hoje
               AND NOT EXISTS (
                   SELECT 1 FROM FaturaCartao f2
                   WHERE f2.FKCartao = f.FKCartao AND f2.FKUsuario = f.FKUsuario
                     AND f2.Status = 'aberta' AND f2.IDFatura != f.IDFatura
                     AND f2.DataFechamento > f.DataFechamento
               )"
        );
        $stmt->execute([':uid' => $uid, ':hoje' => $hoje]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $fatura) {
            cartao_fecharFatura($pdo, $fatura, $uid);
        }
    } catch (PDOException $e) {}

    // Auto-repara faturas fechadas/pagas que ficaram órfãs (fecharam antes do cartão ter
    // uma carteira de pagamento definida). Antes só reparava quando o usuário abria a
    // fatura e clicava em algo — agora corrige sozinho assim que o cartão passa a ter
    // FKCarteiraDebito, sem precisar de ação manual.
    try {
        $stmtOrf = $pdo->prepare(
            "SELECT f.IDFatura
             FROM FaturaCartao f
             JOIN CartaoCredito c ON f.FKCartao = c.IDCartao
             WHERE f.FKUsuario = :uid AND f.Status IN ('fechada','paga')
               AND f.FKRegistroPagamento IS NULL AND f.ValorTotal > 0
               AND c.FKCarteiraDebito IS NOT NULL"
        );
        $stmtOrf->execute([':uid' => $uid]);
        foreach ($stmtOrf->fetchAll(PDO::FETCH_COLUMN) as $faturaOrfaId) {
            cartao_repararRegistroPagamento($pdo, $faturaOrfaId, $uid);
        }
    } catch (PDOException $e) {}
}

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
