<?php
// ==============================================================================
// AGENDA.PHP — Calendário financeiro (somente visualização de transações)
// ==============================================================================
ob_start(); // Captura qualquer output acidental (BOM, warnings, includes) antes do JSON
session_start();
require_once 'config/conexao.php';
exigirAcessoMinimo(1);

$usuario_id = $_SESSION['usuario_id'];
$hoje       = date('Y-m-d');

// ==============================================================================
// AJAX — só leitura (Usado apenas para navegação futura entre meses)
// ==============================================================================
if (isset($_GET['ajax']) && $_GET['acao'] === 'listar') {
    ob_clean(); // Descarta qualquer output anterior
    header('Content-Type: application/json; charset=utf-8');

    $mes      = preg_replace('/[^0-9\-]/', '', $_GET['mes'] ?? date('Y-m'));
    $carteira = $_GET['carteira'] ?? '';
    $inicio   = $mes . '-01';
    $fim      = date('Y-m-t', strtotime($inicio));

    $where  = "FKUsuario = :u AND DATE(COALESCE(r.DataVencimento, r.MomentoRegistro)) BETWEEN :ini AND :fim";
    $params = [':u' => $usuario_id, ':ini' => $inicio, ':fim' => $fim];

    if ($carteira) {
        $where           .= " AND r.FKCarteira = :cart";
        $params[':cart']  = $carteira;
    }

    try {
        $stmt = $pdo->prepare("
            SELECT r.IDRegistro AS id,
                   r.Descricao  AS titulo,
                   DATE(COALESCE(r.DataVencimento, r.MomentoRegistro)) AS data,
                   r.TipoRegistro   AS tipo_reg,
                   r.StatusRegistro AS status_reg,
                   r.Valor          AS valor,
                   r.ParcelaAtual,
                   r.TotalParcelas,
                   r.Recorrente,
                   c.NomeCategoria  AS categoria
            FROM Registro r
            LEFT JOIN Categoria c ON r.FKCategoria = c.IDCategoria
            WHERE $where
            ORDER BY data ASC
        ");
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$r) {
            $dataStr = substr((string)($r['data'] ?? ''), 0, 10);
            if ($r['status_reg'] === 'pendente') {
                if ($dataStr < $hoje)         $r['urgencia'] = 'atrasada';
                elseif ($dataStr === $hoje)   $r['urgencia'] = 'hoje';
                else                          $r['urgencia'] = 'pendente';
            } else {
                $r['urgencia'] = 'efetivado';
            }
            // Remoção do mb_convert_encoding() que causava quebra fatal se a extensão não estivesse habilitada.
        }
        unset($r);

        $json = json_encode(['ok' => true, 'itens' => $rows, 'hoje' => $hoje], JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            echo json_encode(['ok' => false, 'msg' => 'Erro ao serializar dados.', 'itens' => []]);
        } else {
            echo $json;
        }
        exit;
    } catch (PDOException $e) {
        echo json_encode(['ok' => false, 'msg' => 'Erro na consulta.', 'itens' => []]);
        exit;
    }
}

// ==============================================================================
// PRÉ-CARREGAMENTO — painel lateral
// ==============================================================================
$carteira_sel = $_GET['carteira'] ?? '';

// Inicialização segura para garantir que as variáveis existam mesmo se o banco falhar
$carteiras   = [];
$atrasadas   = [];
$vencem_hoje = [];
$proximos    = [];
$saldo_mes   = 0.0;
$pendentes   = ['pend_desp' => 0, 'pend_rec' => 0];

$iniMes = date('Y-m-01');
$fimMes = date('Y-m-t');

try {
    // Carteiras do usuário para o filtro
    $stmtCart = $pdo->prepare("SELECT IDCarteira, TipoCarteira FROM Carteira WHERE FKUsuarioDono = :u ORDER BY TipoCarteira ASC");
    $stmtCart->execute([':u' => $usuario_id]);
    $carteiras = $stmtCart->fetchAll();

    // Filtro de carteira nas queries do painel
    $wherePanel  = "FKUsuario = :u AND StatusRegistro = 'pendente'";
    $paramsPanel = [':u' => $usuario_id];
    if ($carteira_sel) {
        $wherePanel            .= " AND FKCarteira = :cart";
        $paramsPanel[':cart']   = $carteira_sel;
    }

    // Atrasadas
    $stmtAt = $pdo->prepare("SELECT IDRegistro AS id, Descricao AS titulo, COALESCE(DataVencimento,MomentoRegistro) AS data, TipoRegistro AS tipo_reg, Valor AS valor FROM Registro WHERE $wherePanel AND COALESCE(DataVencimento,MomentoRegistro) < :hoje ORDER BY data ASC LIMIT 30");
    $stmtAt->execute(array_merge($paramsPanel, [':hoje' => $hoje]));
    $atrasadas = $stmtAt->fetchAll();

    // Hoje
    $stmtHj = $pdo->prepare("SELECT IDRegistro AS id, Descricao AS titulo, COALESCE(DataVencimento,MomentoRegistro) AS data, TipoRegistro AS tipo_reg, Valor AS valor FROM Registro WHERE $wherePanel AND COALESCE(DataVencimento,MomentoRegistro) = :hoje ORDER BY data ASC LIMIT 30");
    $stmtHj->execute(array_merge($paramsPanel, [':hoje' => $hoje]));
    $vencem_hoje = $stmtHj->fetchAll();

    // Próximos 7 dias
    $d1 = date('Y-m-d', strtotime('+1 day'));
    $d7 = date('Y-m-d', strtotime('+7 days'));
    $stmtPx = $pdo->prepare("SELECT IDRegistro AS id, Descricao AS titulo, COALESCE(DataVencimento,MomentoRegistro) AS data, TipoRegistro AS tipo_reg, Valor AS valor FROM Registro WHERE $wherePanel AND COALESCE(DataVencimento,MomentoRegistro) BETWEEN :d1 AND :d7 ORDER BY data ASC LIMIT 30");
    $stmtPx->execute(array_merge($paramsPanel, [':d1' => $d1, ':d7' => $d7]));
    $proximos = $stmtPx->fetchAll();

    // Saldo do mês corrente (efetivados)
    $whereS = "FKUsuario=:u AND StatusRegistro='efetivado' AND MomentoRegistro BETWEEN :ini AND :fim";
    $psS    = [':u' => $usuario_id, ':ini' => $iniMes, ':fim' => $fimMes];
    if ($carteira_sel) {
        $whereS .= " AND FKCarteira=:cart";
        $psS[':cart'] = $carteira_sel;
    }
    $stmtS = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN TipoRegistro='receita' THEN Valor ELSE -Valor END),0) AS saldo FROM Registro WHERE $whereS");
    $stmtS->execute($psS);
    $saldo_mes = (float)$stmtS->fetchColumn();

    // Pendentes do mês
    $whereP = "FKUsuario=:u AND StatusRegistro='pendente' AND COALESCE(DataVencimento,MomentoRegistro) BETWEEN :ini AND :fim";
    $psP    = [':u' => $usuario_id, ':ini' => $iniMes, ':fim' => $fimMes];
    if ($carteira_sel) {
        $whereP .= " AND FKCarteira=:cart";
        $psP[':cart'] = $carteira_sel;
    }
    $stmtP = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN TipoRegistro='despesa' THEN Valor ELSE 0 END),0) AS pend_desp, COALESCE(SUM(CASE WHEN TipoRegistro='receita' THEN Valor ELSE 0 END),0) AS pend_rec FROM Registro WHERE $whereP");
    $stmtP->execute($psP);
    $fetchP = $stmtP->fetch();
    if ($fetchP) {
        $pendentes = $fetchP;
    }
} catch (PDOException $e) {
    // Falha silenciosa: A página carrega vazia ao invés de exibir Erro 500
}

// ==============================================================================
// CARGA INICIAL DA GRADE DO CALENDÁRIO (SSR - Injeção Direta)
// ==============================================================================
$rowsIni = [];

try {
    $whereIni  = "FKUsuario = :u AND DATE(COALESCE(r.DataVencimento, r.MomentoRegistro)) BETWEEN :ini AND :fim";
    $paramsIni = [':u' => $usuario_id, ':ini' => $iniMes, ':fim' => $fimMes];

    if ($carteira_sel) {
        $whereIni .= " AND r.FKCarteira = :cart";
        $paramsIni[':cart'] = $carteira_sel;
    }

    $stmtIni = $pdo->prepare("
        SELECT r.IDRegistro AS id, r.Descricao  AS titulo, DATE(COALESCE(r.DataVencimento, r.MomentoRegistro)) AS data, r.TipoRegistro AS tipo_reg, r.StatusRegistro AS status_reg, r.Valor AS valor, r.ParcelaAtual, r.TotalParcelas, r.Recorrente, c.NomeCategoria  AS categoria
        FROM Registro r LEFT JOIN Categoria c ON r.FKCategoria = c.IDCategoria
        WHERE $whereIni ORDER BY data ASC
    ");
    $stmtIni->execute($paramsIni);
    $rowsIni = $stmtIni->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rowsIni as &$r) {
        $dataStr = substr((string)($r['data'] ?? ''), 0, 10);
        if ($r['status_reg'] === 'pendente') {
            if ($dataStr < $hoje)         $r['urgencia'] = 'atrasada';
            elseif ($dataStr === $hoje)   $r['urgencia'] = 'hoje';
            else                          $r['urgencia'] = 'pendente';
        } else {
            $r['urgencia'] = 'efetivado';
        }
        // Remoção do mb_convert_encoding() 
    }
    unset($r);
} catch (PDOException $e) {
    die("<div style='background:#dc2626; color:white; padding:20px; border-radius:8px; margin-bottom:20px;'>
            <h3>🚨 Erro SQL Detectado!</h3>
            <p>O MySQL recusou a consulta do calendário pelo seguinte motivo:</p>
            <code style='color: #ffcccc; font-size: 1.1rem;'>" . $e->getMessage() . "</code>
         </div>");
}

// JSON gerado com segurança extra (HEX tags) para evitar quebra de script
$json_iniciais = json_encode($rowsIni, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);

// Se o JSON falhar, a página vai gritar o motivo exato com uma tarja vermelha
if ($json_iniciais === false) {
    die("<div style='background:#dc2626; color:white; padding:20px; margin-bottom:20px; border-radius:8px; font-family:sans-serif;'>
            <h3>🚨 Raio-X do Erro Ativado</h3>
            <p><strong>Motivo da falha do JSON:</strong> " . json_last_error_msg() . "</p>
            <p>Isso geralmente significa que há um caractere corrompido no banco de dados que não é um UTF-8 válido.</p>
         </div>");
}
// Utilitários protegidos contra re-declaração (conflito de cache)
if (!function_exists('fv')) {
    function fv(float $v): string
    {
        return 'R$ ' . number_format($v, 2, ',', '.');
    }
}
if (!function_exists('fd')) {
    function fd(string $d): string
    {
        $m = ['', 'Jan', 'Fev', 'Mar', 'Abr', 'Mai', 'Jun', 'Jul', 'Ago', 'Set', 'Out', 'Nov', 'Dez'];
        [$y, $mo, $day] = explode('-', $d);
        return intval($day) . ' ' . $m[intval($mo)];
    }
}
if (!function_exists('itemLinha')) {
    function itemLinha(array $r, string $urgencia = ''): string
    {
        $isRec = $r['tipo_reg'] === 'receita';
        $bg    = $isRec ? 'rgba(34,197,94,.12)'  : 'rgba(220,38,38,.12)';
        $icon  = $isRec ? 'bi-arrow-up-short text-success' : 'bi-arrow-down-short text-danger';
        $cor   = $isRec ? '#22c55e' : '#f87171';
        $sinal = $isRec ? '+' : '-';
        $data  = fd($r['data']);
        $label = htmlspecialchars($r['titulo']);
        $valor = fv((float)$r['valor']);
        return "
        <a href='nova_transacao.php?editar=" . urlencode($r['id']) . "'
           class='d-flex align-items-center justify-content-between gap-2 py-2 text-decoration-none border-bottom border-secondary-subtle panel-row' style='color:inherit;'>
            <div class='d-flex align-items-center gap-2 min-w-0'>
                <span class='d-inline-flex align-items-center justify-content-center rounded-circle flex-shrink-0'
                      style='width:26px;height:26px;min-width:26px;background:{$bg};'>
                    <i class='bi {$icon}' style='font-size:.85rem;'></i>
                </span>
                <div class='min-w-0'>
                    <div class='text-light fw-semibold text-truncate' style='font-size:.78rem;max-width:145px;' title='" . htmlspecialchars($r['titulo']) . "'>{$label}</div>
                    <div style='font-size:.68rem;color:#6b7280;'>{$data}</div>
                </div>
            </div>
            <span class='fw-bold flex-shrink-0' style='font-size:.78rem;color:{$cor};'>{$sinal}{$valor}</span>
        </a>";
    }
}

$pageTitle = "Agenda — Auralis";
require_once 'geral/header.php';
?>

<main class="container-fluid py-4 mt-2 flex-grow-1"
    style="max-width:1500px;padding-inline:var(--space-page-x);min-height:100vh;">

    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 border-bottom border-secondary-subtle pb-3 gap-3">

        <div class="d-flex align-items-center gap-3">
            <div class="d-flex align-items-center justify-content-center rounded-3 flex-shrink-0"
                style="width:44px;height:44px;background:rgba(212,175,55,.12);border:1px solid rgba(212,175,55,.25);">
                <i class="bi bi-calendar3" style="color:var(--accent);font-size:1.25rem;"></i>
            </div>
            <div>
                <h2 class="fw-bold text-light mb-0">Agenda Financeira</h2>
                <span id="badge-mes-ano" class="text-secondary fw-semibold text-uppercase"
                    style="font-size:.7rem;letter-spacing:.06em;"></span>
            </div>
        </div>

        <div class="d-flex align-items-center gap-2 flex-wrap">

            <?php if (count($carteiras) > 1): ?>
                <form method="get" class="d-flex" id="formCarteira">
                    <select name="carteira" class="form-select form-select-sm border-secondary-subtle text-light rounded-pill"
                        style="background:#252a31;font-size:.8rem;max-width:160px;cursor:pointer;"
                        onchange="this.form.submit()">
                        <option value="">Todas as carteiras</option>
                        <?php foreach ($carteiras as $c): ?>
                            <option value="<?= htmlspecialchars($c['IDCarteira']) ?>"
                                <?= $carteira_sel === $c['IDCarteira'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($c['TipoCarteira']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>
            <?php endif; ?>

            <div class="d-flex align-items-center border border-secondary-subtle rounded-pill"
                style="background:#1e2126;padding:3px;">
                <button class="btn btn-sm btn-link text-secondary px-3 py-1 text-decoration-none" onclick="mudarMes(-1)">
                    <i class="bi bi-chevron-left" style="font-size:.75rem;"></i>
                </button>
                <button class="btn btn-sm btn-link text-light px-3 py-1 text-decoration-none fw-semibold"
                    onclick="irHoje()"
                    style="font-size:.825rem;border-inline:1px solid rgba(255,255,255,.08);">
                    Este mês
                </button>
                <button class="btn btn-sm btn-link text-secondary px-3 py-1 text-decoration-none" onclick="mudarMes(1)">
                    <i class="bi bi-chevron-right" style="font-size:.75rem;"></i>
                </button>
            </div>

            <a href="nova_transacao.php<?= $carteira_sel ? '?carteira_id=' . urlencode($carteira_sel) : '' ?>"
                class="btn btn-sm rounded-pill px-4 fw-bold"
                style="background:linear-gradient(135deg,#d4af37,#AA8C2C);color:#121418;border:none;">
                <i class="bi bi-plus-lg me-1"></i> Nova transação
            </a>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <?php
        $resumo = [
            ['Efetivado no mês',  $saldo_mes,              $saldo_mes >= 0 ? '#22c55e' : '#f87171', 'bi-check-circle',    ($saldo_mes >= 0 ? '+' : '') . fv($saldo_mes)],
            ['A pagar no mês',    (float)$pendentes['pend_desp'], '#f87171',  'bi-hourglass-split', '- ' . fv((float)$pendentes['pend_desp'])],
            ['A receber no mês',  (float)$pendentes['pend_rec'],  '#22c55e',  'bi-clock',           '+ ' . fv((float)$pendentes['pend_rec'])],
        ];
        foreach ($resumo as [$label, $val, $cor, $icon, $fmt]):
        ?>
            <div class="col-4 col-lg-4">
                <div class="card rounded-4 border-secondary-subtle h-100" style="background:var(--bg-card);">
                    <div class="card-body py-3 px-3">
                        <div class="d-flex align-items-center gap-2 mb-1">
                            <i class="bi <?= $icon ?>" style="color:<?= $cor ?>;font-size:.9rem;"></i>
                            <span class="text-secondary" style="font-size:.72rem;font-weight:600;"><?= $label ?></span>
                        </div>
                        <div class="fw-bold" style="font-size:clamp(.85rem,2vw,1.1rem);color:<?= $cor ?>;"><?= $fmt ?></div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="row g-4 align-items-start">

        <div class="col-lg-8">
            <div class="agenda-cal">
                <div class="cal-semana">
                    <?php foreach (['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'] as $d): ?>
                        <div><?= $d ?></div>
                    <?php endforeach; ?>
                </div>
                <div id="cal-grid" class="cal-grade">
                </div>
            </div>

            <div class="d-flex flex-wrap gap-3 mt-3 px-1" style="font-size:.72rem;color:#6b7280;">
                <span><span class="leg" style="background:#dc2626;"></span> Atrasada</span>
                <span><span class="leg" style="background:#d4af37;"></span> Vence hoje</span>
                <span><span class="leg" style="background:#3b82f6;"></span> Pendente</span>
                <span><span class="leg" style="background:#22c55e;"></span> Efetivada</span>
            </div>
        </div>

        <div class="col-lg-4 d-flex flex-column gap-3">

            <?php if ($atrasadas): ?>
                <div class="card rounded-4 border-0"
                    style="background:rgba(220,38,38,.06);border:1px solid rgba(220,38,38,.18) !important;">
                    <div class="card-body p-3">
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <i class="bi bi-exclamation-triangle-fill text-danger" style="font-size:.9rem;"></i>
                            <span class="fw-bold text-danger" style="font-size:.825rem;">
                                Atrasadas <span class="opacity-75">(<?= count($atrasadas) ?>)</span>
                            </span>
                        </div>
                        <?php foreach ($atrasadas as $r) echo itemLinha($r); ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($vencem_hoje): ?>
                <div class="card rounded-4 border-0"
                    style="background:rgba(212,175,55,.06);border:1px solid rgba(212,175,55,.22) !important;">
                    <div class="card-body p-3">
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <i class="bi bi-alarm-fill" style="color:#d4af37;font-size:.9rem;"></i>
                            <span class="fw-bold" style="font-size:.825rem;color:#d4af37;">
                                Vencem hoje <span class="opacity-75">(<?= count($vencem_hoje) ?>)</span>
                            </span>
                        </div>
                        <?php foreach ($vencem_hoje as $r) echo itemLinha($r); ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($proximos): ?>
                <div class="card rounded-4 border-secondary-subtle" style="background:var(--bg-card);">
                    <div class="card-body p-3">
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <i class="bi bi-calendar-week text-secondary" style="font-size:.9rem;"></i>
                            <span class="fw-bold text-secondary" style="font-size:.825rem;">
                                Próximos 7 dias <span class="opacity-75">(<?= count($proximos) ?>)</span>
                            </span>
                        </div>
                        <?php foreach ($proximos as $r) echo itemLinha($r); ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (!$atrasadas && !$vencem_hoje && !$proximos): ?>
                <div class="card rounded-4 border-secondary-subtle text-center p-4" style="background:var(--bg-card);">
                    <i class="bi bi-check-circle-fill text-success mb-2" style="font-size:2rem;"></i>
                    <div class="fw-semibold text-light mb-1" style="font-size:.875rem;">Tudo em dia!</div>
                    <div class="text-secondary" style="font-size:.8rem;">Nenhum vencimento pendente nos próximos dias.</div>
                </div>
            <?php endif; ?>

        </div>
    </div>
</main>

<style>
    /* ── Calendário ─────────────────────────────────────────────── */
    .agenda-cal {
        background: rgba(255, 255, 255, .04);
        border: 1px solid rgba(255, 255, 255, .07);
        border-radius: 12px;
        overflow: hidden;
    }

    .cal-semana {
        display: grid;
        grid-template-columns: repeat(7, 1fr);
        background: #1a1d21;
        gap: 1px;
        padding-bottom: 1px;
    }

    .cal-semana>div {
        padding: 8px 10px;
        font-size: .7rem;
        font-weight: 600;
        text-transform: lowercase;
        letter-spacing: .04em;
        color: #555;
        background: #1e2126;
        text-align: right;
    }

    .cal-grade {
        display: grid;
        grid-template-columns: repeat(7, 1fr);
        gap: 1px;
        background: rgba(255, 255, 255, .04);
    }

    .cal-loading {
        grid-column: 1/-1;
        background: #1e2126;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 4rem 0;
    }

    /* Células */
    .cal-celula {
        background: #1e2126;
        min-height: 100px;
        padding: 5px;
        display: flex;
        flex-direction: column;
        position: relative;
        transition: background .15s;
    }

    .cal-celula:hover {
        background: #252a31;
    }

    .cal-celula.outro {
        background: #181a1f;
    }

    .cal-celula.outro .num {
        opacity: .2;
    }

    /* Hoje */
    .cal-celula.eh-hoje::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 2px;
        background: linear-gradient(90deg, #d4af37, #AA8C2C);
    }

    /* Status de urgência na célula */
    .cal-celula.cel-atrasada {
        background: rgba(220, 38, 38, .05);
    }

    .cal-celula.cel-hoje {
        background: rgba(212, 175, 55, .04);
    }

    /* Número do dia */
    .num-wrap {
        display: flex;
        align-items: center;
        justify-content: flex-end;
        margin-bottom: 4px;
    }

    .num {
        font-size: .75rem;
        color: #666;
        font-weight: 500;
        line-height: 1;
    }

    .num.circulo {
        background: var(--accent);
        color: #121418;
        font-weight: 700;
        width: 22px;
        height: 22px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 50%;
        font-size: .7rem;
    }

    /* Pills */
    .ev-pill {
        font-size: .66rem;
        padding: 2px 5px;
        border-radius: 3px;
        margin-bottom: 2px;
        cursor: pointer;
        display: flex;
        align-items: center;
        gap: 3px;
        font-weight: 600;
        border-left: 2px solid transparent;
        white-space: nowrap;
        overflow: hidden;
        transition: filter .15s, opacity .15s;
    }

    .ev-pill:hover {
        filter: brightness(1.25);
    }

    .ev-nome {
        overflow: hidden;
        text-overflow: ellipsis;
        flex: 1;
        min-width: 0;
    }

    .ev-atrasada {
        background: rgba(220, 38, 38, .14);
        color: #fca5a5;
        border-left-color: #dc2626;
    }

    .ev-hoje {
        background: rgba(212, 175, 55, .14);
        color: #d4af37;
        border-left-color: #d4af37;
    }

    .ev-pendente {
        background: rgba(59, 130, 246, .12);
        color: #93c5fd;
        border-left-color: #3b82f6;
    }

    .ev-efetivado {
        background: rgba(34, 197, 94, .08);
        color: #86efac;
        border-left-color: #22c55e;
        opacity: .7;
    }

    /* Legenda */
    .leg {
        display: inline-block;
        width: 8px;
        height: 8px;
        border-radius: 50%;
        margin-right: 3px;
    }

    /* Painel lateral */
    .panel-row:hover {
        background: rgba(255, 255, 255, .03);
        border-radius: 6px;
    }

    /* Mobile */
    @media (max-width:767.98px) {
        .cal-semana {
            display: none;
        }

        .cal-grade {
            grid-template-columns: 1fr;
            gap: 0;
        }

        .cal-celula {
            min-height: auto;
            padding: 10px;
            border-bottom: 1px solid rgba(255, 255, 255, .05);
        }

        .cal-celula.outro {
            display: none;
        }

        .cal-celula.vazio {
            display: none;
        }

        .num-wrap {
            justify-content: flex-start;
            margin-bottom: 6px;
        }
    }
</style>

<script>
    const HOJE_JS = new Date();
    const MESES = ['Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];

    // Injeção PHP direta (SSR) para carga inicial imediata e sem erros de atraso
    let anoAtual = <?= (int)date('Y') ?>;
    let mesAtual = <?= (int)date('n') - 1 ?>;
    let itens = <?= $json_iniciais ?>;

    function padZ(n) {
        return String(n).padStart(2, '0');
    }

    function ds(d) {
        return `${d.getFullYear()}-${padZ(d.getMonth()+1)}-${padZ(d.getDate())}`;
    }

    function esc(s) {
        return String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function brl(v) {
        return parseFloat(v || 0).toLocaleString('pt-BR', {
            style: 'currency',
            currency: 'BRL'
        });
    }

    function carteiraSel() {
        const s = document.querySelector('select[name="carteira"]');
        return s ? encodeURIComponent(s.value) : '';
    }

    async function carregarMes(ano, mes) {
        const grid = document.getElementById('cal-grid');
        grid.innerHTML = '<div class="cal-loading"><div class="spinner-border spinner-border-sm text-secondary me-2"></div><span class="text-secondary" style="font-size:.875rem;">Carregando...</span></div>';

        document.getElementById('badge-mes-ano').textContent = `${MESES[mes]} ${ano}`;

        const mesStr = padZ(mes + 1);
        const cart = carteiraSel();
        try {
            const r = await fetch(`agenda.php?ajax=1&acao=listar&mes=${ano}-${mesStr}${cart?'&carteira='+cart:''}`);
            const j = await r.json();
            if (!j.ok) console.warn('[Agenda] AJAX retornou erro:', j.msg);
            itens = Array.isArray(j.itens) ? j.itens : [];
        } catch (e) {
            console.error('[Agenda] Falha ao carregar eventos:', e);
            itens = [];
        }

        renderCal(ano, mes);
    }

    function renderCal(ano, mes) {
        const grid = document.getElementById('cal-grid');
        const hojeStr = ds(HOJE_JS);
        const primeiro = new Date(ano, mes, 1).getDay();
        const diasMes = new Date(ano, mes + 1, 0).getDate();
        const diasAnt = new Date(ano, mes, 0).getDate();
        const total = Math.ceil((primeiro + diasMes) / 7) * 7;

        grid.innerHTML = '';

        for (let i = 0; i < total; i++) {
            let dia, outro = false,
                dt;

            if (i < primeiro) {
                dia = diasAnt - primeiro + i + 1;
                outro = true;
                dt = new Date(ano, mes - 1, dia);
            } else if (i >= primeiro + diasMes) {
                dia = i - primeiro - diasMes + 1;
                outro = true;
                dt = new Date(ano, mes + 1, dia);
            } else {
                dia = i - primeiro + 1;
                dt = new Date(ano, mes, dia);
            }

            const dStr = ds(dt);
            const ehHoje = dStr === hojeStr;
            const diaItens = itens.filter(it => (it.data ?? '').slice(0, 10) === dStr);
            const temAtras = diaItens.some(it => it.urgencia === 'atrasada');
            const temHoje = diaItens.some(it => it.urgencia === 'hoje');
            const vazio = diaItens.length === 0;

            const cel = document.createElement('div');
            cel.className = [
                'cal-celula',
                outro ? 'outro' : '',
                ehHoje ? 'eh-hoje' : '',
                temAtras ? 'cel-atrasada' : '',
                (temHoje && !temAtras) ? 'cel-hoje' : '',
                (vazio && outro) ? 'vazio' : '',
            ].filter(Boolean).join(' ');

            // Número
            const numW = document.createElement('div');
            numW.className = 'num-wrap';
            const numS = document.createElement('span');
            numS.className = 'num' + (ehHoje ? ' circulo' : '');
            numS.textContent = dia;
            numW.appendChild(numS);
            cel.appendChild(numW);

            // Pills de transações
            diaItens.forEach(item => {
                const isRec = item.tipo_reg === 'receita';
                const icon = isRec ? 'bi-arrow-up-short text-success' : 'bi-arrow-down-short text-danger';
                const pill = document.createElement('div');

                pill.className = `ev-pill ev-${esc(item.urgencia||'pendente')}`;

                // Badge de parcela
                let extra = '';
                if (item.TotalParcelas > 1) {
                    extra = ` <span style="opacity:.6;font-size:.6rem;">${item.ParcelaAtual}/${item.TotalParcelas}</span>`;
                } else if (item.Recorrente == 1) {
                    extra = ` <i class="bi bi-arrow-repeat" style="opacity:.6;font-size:.6rem;"></i>`;
                }

                pill.innerHTML = `<i class="bi ${icon} flex-shrink-0" style="font-size:.8rem;"></i><span class="ev-nome">${esc(item.titulo)}${extra}</span>`;
                pill.title = `${item.titulo} — ${brl(item.valor)}${item.categoria ? ' · '+item.categoria : ''}`;
                pill.onclick = () => window.location.href = `nova_transacao.php?editar=${encodeURIComponent(item.id)}`;
                cel.appendChild(pill);
            });

            grid.appendChild(cel);
        }
    }

    function mudarMes(d) {
        mesAtual += d;
        if (mesAtual > 11) {
            mesAtual = 0;
            anoAtual++;
        }
        if (mesAtual < 0) {
            mesAtual = 11;
            anoAtual--;
        }
        carregarMes(anoAtual, mesAtual);
    }

    function irHoje() {
        anoAtual = HOJE_JS.getFullYear();
        mesAtual = HOJE_JS.getMonth();
        carregarMes(anoAtual, mesAtual);
    }

    // A mágica acontece aqui: ao invés de chamar carregarMes() (que causava o delay do fetch), 
    // nós apenas imprimimos o título e renderizamos os dados que o PHP já entregou instantaneamente!
    document.getElementById('badge-mes-ano').textContent = `${MESES[mesAtual]} ${anoAtual}`;
    renderCal(anoAtual, mesAtual);
</script>

<?php require_once 'geral/footer.php'; ?>