<?php
// ==============================================================================
// AGENDA.PHP — Calendário visual de eventos e transações
// ==============================================================================
session_start();
if (!isset($_SESSION['usuario_id'])) {
    header("Location: usuario/login.php");
    exit;
}
require_once 'config/conexao.php';

if (!function_exists('gerarUuid')) {
    function gerarUuid()
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff)
        );
    }
}

$usuario_id = $_SESSION['usuario_id'];

// Garante que a tabela de eventos existe
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS AgendaEvento (
            IDEvento   VARCHAR(36)  PRIMARY KEY,
            FKUsuario  VARCHAR(36)  NOT NULL,
            Titulo     VARCHAR(255) NOT NULL,
            Descricao  TEXT,
            DataEvento DATE         NOT NULL,
            Cor        VARCHAR(20)  DEFAULT 'roxo',
            Concluido  TINYINT(1)   DEFAULT 0,
            CriadoEm  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_agenda_usuario_data (FKUsuario, DataEvento)
        )
    ");
} catch (PDOException $e) { /* silencia se a tabela já existe */
}


// ==============================================================================
// ENDPOINT AJAX — responde JSON e encerra
// ==============================================================================
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    $acao = trim($_GET['acao'] ?? '');

    // ── LISTAR ──────────────────────────────────────────────────────────────
    if ($acao === 'listar') {
        $mes    = preg_replace('/[^0-9\-]/', '', $_GET['mes'] ?? date('Y-m'));
        $inicio = $mes . '-01';
        $fim    = date('Y-m-t', strtotime($inicio));

        // Eventos da agenda
        $stmtEv = $pdo->prepare("
            SELECT IDEvento AS id, Titulo AS titulo, DataEvento AS data,
                   Cor AS cor, Concluido AS concluido, Descricao AS descricao,
                   'evento' AS tipo
            FROM AgendaEvento
            WHERE FKUsuario = :u AND DataEvento BETWEEN :ini AND :fim
            ORDER BY DataEvento ASC, CriadoEm ASC
        ");
        $stmtEv->execute([':u' => $usuario_id, ':ini' => $inicio, ':fim' => $fim]);
        $itens = $stmtEv->fetchAll(PDO::FETCH_ASSOC);

        // Transações do período (opcional, via toggle)
        if (($_GET['transacoes'] ?? '0') === '1') {
            $stmtTr = $pdo->prepare("
                SELECT IDRegistro AS id,
                       Descricao AS titulo,
                       COALESCE(DataVencimento, MomentoRegistro) AS data,
                       TipoRegistro AS cor,
                       IF(StatusRegistro = 'efetivado', 1, 0) AS concluido,
                       Valor AS descricao,
                       'transacao' AS tipo
                FROM Registro
                WHERE FKUsuario = :u
                  AND COALESCE(DataVencimento, MomentoRegistro) BETWEEN :ini AND :fim
                ORDER BY data ASC
            ");
            $stmtTr->execute([':u' => $usuario_id, ':ini' => $inicio, ':fim' => $fim]);
            $itens = array_merge($itens, $stmtTr->fetchAll(PDO::FETCH_ASSOC));
        }

        echo json_encode(['ok' => true, 'itens' => $itens]);
        exit;
    }

    // ── SALVAR (criar ou atualizar) ──────────────────────────────────────────
    if ($acao === 'salvar' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id        = trim($_POST['id']        ?? '');
        $titulo    = trim($_POST['titulo']    ?? '');
        $descricao = trim($_POST['descricao'] ?? '');
        $data      = trim($_POST['data']      ?? '');
        $cor       = trim($_POST['cor']       ?? 'roxo');
        $concluido = isset($_POST['concluido']) ? 1 : 0;

        $coresValidas = ['roxo', 'azul', 'verde', 'amarelo', 'vermelho', 'cinza'];
        if (!in_array($cor, $coresValidas)) $cor = 'roxo';

        if (empty($titulo)) {
            echo json_encode(['ok' => false, 'msg' => 'O título não pode ficar em branco.']);
            exit;
        }
        if (empty($data)) {
            echo json_encode(['ok' => false, 'msg' => 'Selecione uma data para o evento.']);
            exit;
        }

        try {
            if ($id) {
                $stmt = $pdo->prepare("
                    UPDATE AgendaEvento
                    SET Titulo=:t, Descricao=:d, DataEvento=:dt, Cor=:c, Concluido=:co
                    WHERE IDEvento=:id AND FKUsuario=:u
                ");
                $stmt->execute([':t' => $titulo, ':d' => $descricao, ':dt' => $data, ':c' => $cor, ':co' => $concluido, ':id' => $id, ':u' => $usuario_id]);
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO AgendaEvento (IDEvento, FKUsuario, Titulo, Descricao, DataEvento, Cor, Concluido)
                    VALUES (:id, :u, :t, :d, :dt, :c, :co)
                ");
                $stmt->execute([':id' => gerarUuid(), ':u' => $usuario_id, ':t' => $titulo, ':d' => $descricao, ':dt' => $data, ':c' => $cor, ':co' => $concluido]);
            }
            echo json_encode(['ok' => true]);
        } catch (PDOException $e) {
            echo json_encode(['ok' => false, 'msg' => 'Erro ao salvar o evento.']);
        }
        exit;
    }

    // ── EXCLUIR ──────────────────────────────────────────────────────────────
    if ($acao === 'excluir' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id = trim($_POST['id'] ?? '');
        try {
            $stmt = $pdo->prepare("DELETE FROM AgendaEvento WHERE IDEvento=:id AND FKUsuario=:u");
            $stmt->execute([':id' => $id, ':u' => $usuario_id]);
            echo json_encode(['ok' => true]);
        } catch (PDOException $e) {
            echo json_encode(['ok' => false, 'msg' => 'Erro ao excluir.']);
        }
        exit;
    }

    echo json_encode(['ok' => false, 'msg' => 'Ação inválida.']);
    exit;
}

require_once 'geral/header.php';
?>

<main class="agenda-main flex-grow-1" style="padding: 1.5rem var(--space-page-x, 1.5rem);">

    <!-- ── Cabeçalho ─────────────────────────────────────────────────── -->
    <div class="d-flex align-items-center justify-content-between mb-4 pb-3 border-bottom border-secondary-subtle flex-wrap gap-3">

        <div class="d-flex align-items-center gap-3">
            <h2 class="fw-bold text-light mb-0" style="font-size:1.3rem;">
                <i class="bi bi-calendar3 me-2" style="color:#D4AF37;"></i>
                Agenda
            </h2>
            <span id="badge-mes-ano" class="badge fw-normal"
                style="background:rgba(170,140,44,0.12);color:#D4AF37;border:1px solid rgba(170,140,44,0.3);font-size:0.8rem;">
            </span>
        </div>

        <div class="d-flex align-items-center gap-2 flex-wrap">
            <!-- Toggle transações financeiras -->
            <div class="form-check form-switch mb-0 toggle-agenda d-flex align-items-center gap-2 me-1">
                <input class="form-check-input bg-dark border-secondary shadow-none"
                    type="checkbox" id="toggle-transacoes" role="switch">
                <label class="form-check-label" style="font-size:0.8rem;color:#888;cursor:pointer;" for="toggle-transacoes">
                    <i class="bi bi-arrow-left-right me-1"></i>Transações
                </label>
            </div>

            <a href="dashboard.php" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1"></i> Voltar
            </a>
            <button class="btn btn-sm btn-outline-secondary" onclick="irHoje()">Hoje</button>
            <button class="btn btn-sm btn-outline-secondary" onclick="mudarMes(-1)" aria-label="Mês anterior">
                <i class="bi bi-chevron-left"></i>
            </button>
            <button class="btn btn-sm btn-outline-secondary" onclick="mudarMes(1)" aria-label="Próximo mês">
                <i class="bi bi-chevron-right"></i>
            </button>
            <button class="btn btn-sm btn-gold fw-semibold text-dark" onclick="abrirNovo(null)">
                <i class="bi bi-plus-lg me-1"></i> Novo evento
            </button>
        </div>
    </div>

    <!-- ── Grade do calendário ──────────────────────────────────────── -->
    <div class="agenda-cal">
        <div class="cal-semana">
            <div>Dom</div>
            <div>Seg</div>
            <div>Ter</div>
            <div>Qua</div>
            <div>Qui</div>
            <div>Sex</div>
            <div>Sáb</div>
        </div>
        <div id="cal-grid" class="cal-grade">
            <div class="cal-carregando">
                <div class="spinner-border spinner-border-sm text-secondary me-2"></div>
                <span class="text-secondary" style="font-size:0.85rem;">Carregando...</span>
            </div>
        </div>
    </div>

    <!-- Legenda das cores de eventos -->
    <div class="d-flex align-items-center gap-3 mt-3 flex-wrap" style="font-size:0.72rem;color:#555;">
        <span><i class="bi bi-circle-fill me-1" style="color:#7c3aed;font-size:0.6rem;"></i>Roxo</span>
        <span><i class="bi bi-circle-fill me-1" style="color:#2563eb;font-size:0.6rem;"></i>Azul</span>
        <span><i class="bi bi-circle-fill me-1" style="color:#059669;font-size:0.6rem;"></i>Verde</span>
        <span><i class="bi bi-circle-fill me-1" style="color:#AA8C2C;font-size:0.6rem;"></i>Âmbar</span>
        <span><i class="bi bi-circle-fill me-1" style="color:#dc2626;font-size:0.6rem;"></i>Vermelho</span>
        <span><i class="bi bi-circle-fill me-1" style="color:#6b7280;font-size:0.6rem;"></i>Cinza</span>
        <span class="ms-1 text-secondary" style="border-left:1px solid #333;padding-left:10px;">
            <i class="bi bi-dash me-1"></i>borda tracejada = transação financeira
        </span>
    </div>

</main>


<!-- ==============================================================
     MODAL — Criar / Editar evento
     ============================================================== -->
<div class="modal fade" id="modalEvento" tabindex="-1" aria-labelledby="modalEventoLabel">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="background:#2A2A2A;border:1px solid #333;">

            <div class="modal-header" style="border-color:#333;">
                <h5 class="modal-title text-light fw-semibold" style="font-size:0.95rem;" id="modalEventoLabel">
                    <i class="bi bi-calendar-plus me-2" style="color:#D4AF37;"></i>
                    <span id="modal-titulo-texto">Novo evento</span>
                </h5>
                <button type="button" class="btn-close btn-close-white opacity-50" data-bs-dismiss="modal"></button>
            </div>

            <div class="modal-body">

                <!-- Erro inline no modal -->
                <div id="modal-erro" class="d-none d-flex align-items-center gap-2 rounded-3 px-3 py-2 mb-3"
                    style="background-color:rgba(120,0,0,0.35);border:1px solid rgba(200,50,50,0.45);color:#f28b8b;">
                    <i class="bi bi-exclamation-triangle-fill flex-shrink-0" style="font-size:0.85rem;"></i>
                    <span id="modal-erro-msg" style="font-size:0.85rem;font-weight:500;"></span>
                </div>

                <input type="hidden" id="ev-id">

                <div class="mb-3">
                    <label class="form-label text-secondary mb-1" style="font-size:0.8rem;">
                        Título <span class="text-danger">*</span>
                    </label>
                    <input type="text" id="ev-titulo"
                        class="form-control bg-dark border-secondary text-light shadow-none"
                        placeholder="Ex: Reunião, Pagamento, Consulta..." maxlength="255"
                        style="font-size:0.9rem;">
                </div>

                <div class="mb-3">
                    <label class="form-label text-secondary mb-1" style="font-size:0.8rem;">
                        Data <span class="text-danger">*</span>
                    </label>
                    <input type="date" id="ev-data"
                        class="form-control bg-dark border-secondary text-light shadow-none"
                        style="font-size:0.9rem;">
                </div>

                <div class="mb-3">
                    <label class="form-label text-secondary mb-1" style="font-size:0.8rem;">Observação</label>
                    <textarea id="ev-descricao" rows="2"
                        class="form-control bg-dark border-secondary text-light shadow-none"
                        placeholder="Detalhes opcionais..."
                        style="font-size:0.88rem;resize:none;"></textarea>
                </div>

                <div class="mb-3">
                    <label class="form-label text-secondary mb-2" style="font-size:0.8rem;">Cor</label>
                    <div class="d-flex gap-2 flex-wrap" id="cor-picker">
                        <?php
                        $cores = [
                            'roxo'     => ['#7c3aed', 'Roxo'],
                            'azul'     => ['#2563eb', 'Azul'],
                            'verde'    => ['#059669', 'Verde'],
                            'amarelo'  => ['#AA8C2C', 'Âmbar'],
                            'vermelho' => ['#dc2626', 'Vermelho'],
                            'cinza'    => ['#6b7280', 'Cinza'],
                        ];
                        foreach ($cores as $val => [$hex, $label]):
                        ?>
                            <label class="cor-opcao" data-cor="<?= $val ?>">
                                <input type="radio" name="ev-cor" value="<?= $val ?>" <?= $val === 'roxo' ? 'checked' : '' ?> hidden>
                                <span class="cor-bolinha" style="background:<?= $hex ?>;"></span>
                                <span><?= $label ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="form-check form-switch toggle-agenda">
                    <input class="form-check-input bg-dark border-secondary shadow-none"
                        type="checkbox" id="ev-concluido" role="switch">
                    <label class="form-check-label" style="font-size:0.85rem;color:#888;" for="ev-concluido">
                        Marcar como concluído
                    </label>
                </div>

            </div>

            <div class="modal-footer" style="border-color:#333;">
                <!-- Confirmação de exclusão inline -->
                <div id="confirmar-exclusao" class="d-none w-100 d-flex align-items-center justify-content-between">
                    <span style="font-size:0.82rem;color:#f28b8b;">
                        <i class="bi bi-exclamation-triangle me-1"></i> Excluir este evento?
                    </span>
                    <div class="d-flex gap-2">
                        <button class="btn btn-sm btn-outline-secondary" onclick="cancelarExclusao()">Não</button>
                        <button class="btn btn-sm btn-danger" onclick="confirmarExclusao()">Sim, excluir</button>
                    </div>
                </div>

                <!-- Rodapé normal -->
                <div id="rodape-normal" class="w-100 d-flex justify-content-between align-items-center">
                    <button id="btn-excluir" class="btn btn-sm btn-outline-danger d-none" onclick="pedirExclusao()">
                        <i class="bi bi-trash me-1"></i> Excluir
                    </button>
                    <div class="d-flex gap-2 ms-auto">
                        <button class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button class="btn btn-sm btn-gold fw-semibold text-dark" onclick="salvarEvento()">
                            <i class="bi bi-check-lg me-1"></i> Salvar
                        </button>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>


<!-- ==============================================================
     ESTILOS
     ============================================================== -->
<style>
    :root {
        --primary-gold-analysis: #AA8C2C;
        --bg-card-analysis: #2A2A2A;
        --bg-charcoal-analysis: #222222;
        --border-color-analysis: #333333;
        --text-light-analysis: #E0E0E0;
        --text-muted-analysis: #888888;
        --text-gold-analysis: #D4AF37;
    }

    /* ── Layout geral ───────────────────────────────────── */
    .agenda-main {
        max-width: 1600px;
        margin: 0 auto;
    }

    /* ── Grade do calendário ────────────────────────────── */
    .agenda-cal {
        border: 1px solid var(--border-color-analysis);
        border-radius: 12px;
        overflow: hidden;
    }

    .cal-semana {
        display: grid;
        grid-template-columns: repeat(7, 1fr);
        background: #1a1a1a;
        border-bottom: 1px solid var(--border-color-analysis);
    }

    .cal-semana>div {
        text-align: center;
        padding: 10px 0;
        font-size: 0.7rem;
        font-weight: 600;
        letter-spacing: 0.07em;
        text-transform: uppercase;
        color: var(--text-muted-analysis);
    }

    .cal-grade {
        display: grid;
        grid-template-columns: repeat(7, 1fr);
        background: var(--border-color-analysis);
        gap: 1px;
    }

    .cal-carregando {
        grid-column: 1 / -1;
        background: #1F1F1F;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 3rem 0;
    }

    /* ── Célula do dia ──────────────────────────────────── */
    .cal-celula {
        background: #1F1F1F;
        min-height: 115px;
        padding: 7px 7px 5px;
        position: relative;
        transition: background 0.12s;
    }

    .cal-celula:hover {
        background: #232323;
    }

    .cal-celula:hover .btn-add-ev {
        opacity: 1;
    }

    .cal-celula.outro-mes {
        background: #191919;
    }

    .cal-celula.outro-mes .num-dia-num {
        color: #404040;
    }

    .cal-celula.eh-hoje {
        background: #1e1c14;
    }

    /* Número do dia */
    .num-dia {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 5px;
    }

    .num-dia-num {
        font-size: 0.75rem;
        font-weight: 500;
        color: var(--text-muted-analysis);
        width: 24px;
        height: 24px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 50%;
    }

    .num-dia-num.hoje-circulo {
        background: #e74c3c;
        color: #fff;
        font-weight: 600;
    }

    /* Botão + hover */
    .btn-add-ev {
        background: transparent;
        border: none;
        color: var(--text-muted-analysis);
        font-size: 0.8rem;
        padding: 0 2px;
        cursor: pointer;
        opacity: 0;
        transition: opacity 0.12s, color 0.12s;
        line-height: 1;
    }

    .btn-add-ev:hover {
        color: #D4AF37;
        opacity: 1 !important;
    }

    /* ── Pílulas de evento ──────────────────────────────── */
    .ev-pill {
        font-size: 0.68rem;
        padding: 2px 6px;
        border-radius: 4px;
        margin-bottom: 2px;
        cursor: pointer;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        display: flex;
        align-items: center;
        gap: 4px;
        border-left: 3px solid transparent;
        transition: filter 0.12s;
        max-width: 100%;
    }

    .ev-pill:hover {
        filter: brightness(1.18);
    }

    .ev-pill .ev-icon {
        flex-shrink: 0;
        font-size: 0.6rem;
    }

    .ev-pill .ev-nome {
        overflow: hidden;
        text-overflow: ellipsis;
        flex: 1;
    }

    .ev-pill .ev-ok {
        font-size: 0.55rem;
        background: rgba(255, 255, 255, 0.18);
        border-radius: 3px;
        padding: 1px 3px;
        flex-shrink: 0;
    }

    /* Cores dos eventos */
    .ev-roxo {
        background: rgba(124, 58, 237, 0.2);
        color: #c4b5fd;
        border-left-color: #7c3aed;
    }

    .ev-azul {
        background: rgba(37, 99, 235, 0.2);
        color: #93c5fd;
        border-left-color: #2563eb;
    }

    .ev-verde {
        background: rgba(5, 150, 105, 0.2);
        color: #6ee7b7;
        border-left-color: #059669;
    }

    .ev-amarelo {
        background: rgba(170, 140, 44, 0.2);
        color: #D4AF37;
        border-left-color: #AA8C2C;
    }

    .ev-vermelho {
        background: rgba(220, 38, 38, 0.2);
        color: #fca5a5;
        border-left-color: #dc2626;
    }

    .ev-cinza {
        background: rgba(107, 114, 128, 0.2);
        color: #d1d5db;
        border-left-color: #6b7280;
    }

    /* Transações financeiras (borda tracejada) */
    .ev-receita {
        background: rgba(5, 150, 105, 0.15);
        color: #6ee7b7;
        border-left: 3px dashed #059669;
    }

    .ev-despesa {
        background: rgba(220, 38, 38, 0.15);
        color: #fca5a5;
        border-left: 3px dashed #dc2626;
    }

    /* ── Seletor de cor ─────────────────────────────────── */
    .cor-opcao {
        display: flex;
        align-items: center;
        gap: 6px;
        padding: 4px 10px;
        border-radius: 20px;
        border: 1px solid #3a3a3a;
        cursor: pointer;
        font-size: 0.78rem;
        color: #888;
        transition: all 0.15s;
    }

    .cor-opcao:has(input:checked) {
        border-color: #666;
        color: #E0E0E0;
        background: rgba(255, 255, 255, 0.05);
    }

    .cor-bolinha {
        width: 10px;
        height: 10px;
        border-radius: 50%;
        flex-shrink: 0;
    }

    /* ── Toggle switch ──────────────────────────────────── */
    .toggle-agenda .form-check-input:checked {
        background-color: var(--primary-gold-analysis);
        border-color: var(--primary-gold-analysis);
    }

    /* ── Botão gold ─────────────────────────────────────── */
    .btn-gold {
        background: linear-gradient(135deg, #FFB800 0%, #D4AF37 100%);
        border: none;
    }

    .btn-gold:hover {
        background: linear-gradient(135deg, #FFD04F 0%, #E7C665 100%);
        color: #000 !important;
    }

    /* ── Focus inputs no modal ──────────────────────────── */
    #modalEvento .form-control:focus,
    #modalEvento .form-select:focus {
        border-color: var(--primary-gold-analysis) !important;
        box-shadow: none;
    }
</style>


<!-- ==============================================================
     JAVASCRIPT
     ============================================================== -->
<script>
    // ──────────────────────────────────────────────────────────
    // ESTADO GLOBAL
    // ──────────────────────────────────────────────────────────
    const HOJE = new Date();
    let anoAtual = HOJE.getFullYear();
    let mesAtual = HOJE.getMonth(); // 0-indexed
    let itensMes = [];

    const MESES_PT = [
        'Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho',
        'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'
    ];

    // ──────────────────────────────────────────────────────────
    // CARREGA EVENTOS VIA AJAX
    // ──────────────────────────────────────────────────────────
    async function carregarMes(ano, mes) {
        const grid = document.getElementById('cal-grid');
        grid.innerHTML = '<div class="cal-carregando"><div class="spinner-border spinner-border-sm text-secondary me-2"></div><span class="text-secondary" style="font-size:0.85rem;">Carregando...</span></div>';

        const mesStr = String(mes + 1).padStart(2, '0');
        const comTrans = document.getElementById('toggle-transacoes').checked ? '1' : '0';

        try {
            const res = await fetch(`agenda.php?ajax=1&acao=listar&mes=${ano}-${mesStr}&transacoes=${comTrans}`);
            const json = await res.json();
            itensMes = json.ok ? json.itens : [];
        } catch (e) {
            itensMes = [];
        }

        renderCalendario(ano, mes);
    }

    // ──────────────────────────────────────────────────────────
    // RENDERIZA A GRADE DO CALENDÁRIO
    // ──────────────────────────────────────────────────────────
    function renderCalendario(ano, mes) {
        document.getElementById('badge-mes-ano').textContent = MESES_PT[mes] + ' · ' + ano;

        const grid = document.getElementById('cal-grid');
        const primeiroDia = new Date(ano, mes, 1).getDay(); // 0=Dom
        const diasNoMes = new Date(ano, mes + 1, 0).getDate();
        const diasAnt = new Date(ano, mes, 0).getDate();
        const totalCelulas = Math.ceil((primeiroDia + diasNoMes) / 7) * 7;

        grid.innerHTML = '';

        for (let i = 0; i < totalCelulas; i++) {
            let dia, outroMes = false,
                dtCelula;

            if (i < primeiroDia) {
                dia = diasAnt - primeiroDia + i + 1;
                outroMes = true;
                dtCelula = new Date(ano, mes - 1, dia);
            } else if (i >= primeiroDia + diasNoMes) {
                dia = i - primeiroDia - diasNoMes + 1;
                outroMes = true;
                dtCelula = new Date(ano, mes + 1, dia);
            } else {
                dia = i - primeiroDia + 1;
                dtCelula = new Date(ano, mes, dia);
            }

            const dateStr = `${dtCelula.getFullYear()}-${String(dtCelula.getMonth()+1).padStart(2,'0')}-${String(dtCelula.getDate()).padStart(2,'0')}`;
            const ehHoje = dtCelula.toDateString() === HOJE.toDateString();

            // ── Célula ──
            const cell = document.createElement('div');
            cell.className = 'cal-celula' +
                (outroMes ? ' outro-mes' : '') +
                (ehHoje ? ' eh-hoje' : '');

            // Número do dia + botão adicionar
            const numDiv = document.createElement('div');
            numDiv.className = 'num-dia';

            const numSpan = document.createElement('span');
            numSpan.className = 'num-dia-num' + (ehHoje ? ' hoje-circulo' : '');
            numSpan.textContent = dia;
            numDiv.appendChild(numSpan);

            const btnAdd = document.createElement('button');
            btnAdd.className = 'btn-add-ev';
            btnAdd.title = 'Adicionar evento';
            btnAdd.innerHTML = '<i class="bi bi-plus-circle"></i>';
            btnAdd.onclick = (e) => {
                e.stopPropagation();
                abrirNovo(dateStr);
            };
            numDiv.appendChild(btnAdd);
            cell.appendChild(numDiv);

            // ── Eventos do dia ──
            const itensHoje = itensMes.filter(it => it.data && it.data.startsWith(dateStr));

            itensHoje.forEach(item => {
                const pill = document.createElement('div');

                if (item.tipo === 'transacao') {
                    // Transação financeira — leva para edição ao clicar
                    const isReceita = item.cor === 'receita';
                    const valorFmt = parseFloat(item.descricao || 0)
                        .toLocaleString('pt-BR', {
                            style: 'currency',
                            currency: 'BRL'
                        });

                    pill.className = `ev-pill ev-${escHtml(item.cor)}`;
                    pill.title = `${item.titulo} — ${valorFmt}${item.concluido == 1 ? ' (efetivado)' : ' (pendente)'}`;
                    pill.innerHTML = `<i class="bi bi-${isReceita ? 'arrow-down-circle' : 'arrow-up-circle'} ev-icon"></i>` +
                        `<span class="ev-nome">${escHtml(item.titulo)}</span>` +
                        (item.concluido == 1 ? '<span class="ev-ok">✓</span>' : '');
                    pill.onclick = () => {
                        window.location.href = `nova_transacao.php?editar=${encodeURIComponent(item.id)}`;
                    };

                } else {
                    // Evento da agenda — abre modal de edição
                    pill.className = `ev-pill ev-${escHtml(item.cor)}`;
                    pill.title = item.descricao ? `${item.titulo}: ${item.descricao}` : item.titulo;
                    pill.innerHTML = `<i class="bi bi-${item.concluido == 1 ? 'check-circle-fill' : 'circle'} ev-icon"></i>` +
                        `<span class="ev-nome">${escHtml(item.titulo)}</span>` +
                        (item.concluido == 1 ? '<span class="ev-ok">✓</span>' : '');
                    pill.onclick = () => abrirEditar(item);
                }

                cell.appendChild(pill);
            });

            grid.appendChild(cell);
        }
    }

    // ──────────────────────────────────────────────────────────
    // NAVEGAÇÃO DO MÊS
    // ──────────────────────────────────────────────────────────
    function mudarMes(delta) {
        mesAtual += delta;
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
        anoAtual = HOJE.getFullYear();
        mesAtual = HOJE.getMonth();
        carregarMes(anoAtual, mesAtual);
    }

    // ──────────────────────────────────────────────────────────
    // MODAL — ABRIR / FECHAR
    // ──────────────────────────────────────────────────────────
    const bsModal = new bootstrap.Modal(document.getElementById('modalEvento'));

    function limparModal() {
        document.getElementById('ev-id').value = '';
        document.getElementById('ev-titulo').value = '';
        document.getElementById('ev-data').value = '';
        document.getElementById('ev-descricao').value = '';
        document.getElementById('ev-concluido').checked = false;
        document.querySelector('input[name="ev-cor"][value="roxo"]').checked = true;
        document.getElementById('btn-excluir').classList.add('d-none');
        document.getElementById('modal-erro').classList.add('d-none');
        document.getElementById('confirmar-exclusao').classList.add('d-none');
        document.getElementById('rodape-normal').classList.remove('d-none');
    }

    function abrirNovo(data) {
        limparModal();
        document.getElementById('modal-titulo-texto').textContent = 'Novo evento';
        document.querySelector('#modalEventoLabel i').className = 'bi bi-calendar-plus me-2';
        if (data) document.getElementById('ev-data').value = data;
        bsModal.show();
        setTimeout(() => document.getElementById('ev-titulo').focus(), 300);
    }

    function abrirEditar(item) {
        limparModal();
        document.getElementById('ev-id').value = item.id;
        document.getElementById('ev-titulo').value = item.titulo;
        document.getElementById('ev-data').value = item.data;
        document.getElementById('ev-descricao').value = item.descricao || '';
        document.getElementById('ev-concluido').checked = item.concluido == '1';

        const radioCorEl = document.querySelector(`input[name="ev-cor"][value="${item.cor}"]`);
        if (radioCorEl) radioCorEl.checked = true;

        document.getElementById('btn-excluir').classList.remove('d-none');
        document.getElementById('modal-titulo-texto').textContent = 'Editar evento';
        bsModal.show();
    }

    // ──────────────────────────────────────────────────────────
    // MODAL — ERRO INLINE
    // ──────────────────────────────────────────────────────────
    function mostrarErroModal(msg) {
        const box = document.getElementById('modal-erro');
        document.getElementById('modal-erro-msg').textContent = msg;
        box.classList.remove('d-none');
    }

    // ──────────────────────────────────────────────────────────
    // SALVAR EVENTO
    // ──────────────────────────────────────────────────────────
    async function salvarEvento() {
        const id = document.getElementById('ev-id').value;
        const titulo = document.getElementById('ev-titulo').value.trim();
        const data = document.getElementById('ev-data').value;
        const descricao = document.getElementById('ev-descricao').value.trim();
        const cor = document.querySelector('input[name="ev-cor"]:checked')?.value || 'roxo';
        const concluido = document.getElementById('ev-concluido').checked;

        if (!titulo) return mostrarErroModal('O título não pode ficar em branco.');
        if (!data) return mostrarErroModal('Selecione uma data para o evento.');

        document.getElementById('modal-erro').classList.add('d-none');

        const body = new FormData();
        body.append('id', id);
        body.append('titulo', titulo);
        body.append('data', data);
        body.append('descricao', descricao);
        body.append('cor', cor);
        if (concluido) body.append('concluido', '1');

        try {
            const res = await fetch('agenda.php?ajax=1&acao=salvar', {
                method: 'POST',
                body
            });
            const json = await res.json();
            if (json.ok) {
                bsModal.hide();
                carregarMes(anoAtual, mesAtual);
            } else mostrarErroModal(json.msg || 'Erro ao salvar o evento.');
        } catch (e) {
            mostrarErroModal('Erro de conexão. Tente novamente.');
        }
    }

    // ──────────────────────────────────────────────────────────
    // EXCLUIR EVENTO (confirmação inline, sem alert() nativo)
    // ──────────────────────────────────────────────────────────
    function pedirExclusao() {
        document.getElementById('confirmar-exclusao').classList.remove('d-none');
        document.getElementById('rodape-normal').classList.add('d-none');
    }

    function cancelarExclusao() {
        document.getElementById('confirmar-exclusao').classList.add('d-none');
        document.getElementById('rodape-normal').classList.remove('d-none');
    }

    async function confirmarExclusao() {
        const id = document.getElementById('ev-id').value;
        const body = new FormData();
        body.append('id', id);
        try {
            const res = await fetch('agenda.php?ajax=1&acao=excluir', {
                method: 'POST',
                body
            });
            const json = await res.json();
            if (json.ok) {
                bsModal.hide();
                carregarMes(anoAtual, mesAtual);
            }
        } catch (e) {}
    }

    // ──────────────────────────────────────────────────────────
    // UTILITÁRIO
    // ──────────────────────────────────────────────────────────
    function escHtml(str) {
        return String(str ?? '')
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    // ──────────────────────────────────────────────────────────
    // INIT
    // ──────────────────────────────────────────────────────────
    document.getElementById('toggle-transacoes').addEventListener('change', () => {
        carregarMes(anoAtual, mesAtual);
    });

    // Reseta a confirmação de exclusão ao fechar o modal
    document.getElementById('modalEvento').addEventListener('hidden.bs.modal', cancelarExclusao);

    carregarMes(anoAtual, mesAtual);
</script>

<?php require_once 'geral/footer.php'; ?>