<?php
// ==============================================================================
// AGENDA.PHP — Calendário visual de eventos e transações (Padrão Auralis)
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
} catch (PDOException $e) {
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

        if (($_GET['transacoes'] ?? '0') === '1') {
            $stmtTr = $pdo->prepare("
                SELECT IDRegistro AS id, Descricao AS titulo,
                       COALESCE(DataVencimento, MomentoRegistro) AS data,
                       TipoRegistro AS cor,
                       IF(StatusRegistro = 'efetivado', 1, 0) AS concluido,
                       Valor AS descricao, 'transacao' AS tipo
                FROM Registro
                WHERE FKUsuario = :u AND COALESCE(DataVencimento, MomentoRegistro) BETWEEN :ini AND :fim
                ORDER BY data ASC
            ");
            $stmtTr->execute([':u' => $usuario_id, ':ini' => $inicio, ':fim' => $fim]);
            $itens = array_merge($itens, $stmtTr->fetchAll(PDO::FETCH_ASSOC));
        }

        echo json_encode(['ok' => true, 'itens' => $itens]);
        exit;
    }

    // ── SALVAR ──────────────────────────────────────────────────────────────
    if ($acao === 'salvar' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id        = trim($_POST['id']        ?? '');
        $titulo    = trim($_POST['titulo']    ?? '');
        $descricao = trim($_POST['descricao'] ?? '');
        $data      = trim($_POST['data']      ?? '');
        $cor       = trim($_POST['cor']       ?? 'roxo');
        $concluido = isset($_POST['concluido']) ? 1 : 0;

        $coresValidas = ['roxo', 'azul', 'verde', 'amarelo', 'vermelho', 'cinza'];
        if (!in_array($cor, $coresValidas)) $cor = 'roxo';

        if (empty($titulo) || empty($data)) {
            echo json_encode(['ok' => false, 'msg' => 'Preencha o título e a data.']);
            exit;
        }

        try {
            if ($id) {
                $stmt = $pdo->prepare("UPDATE AgendaEvento SET Titulo=:t, Descricao=:d, DataEvento=:dt, Cor=:c, Concluido=:co WHERE IDEvento=:id AND FKUsuario=:u");
                $stmt->execute([':t' => $titulo, ':d' => $descricao, ':dt' => $data, ':c' => $cor, ':co' => $concluido, ':id' => $id, ':u' => $usuario_id]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO AgendaEvento (IDEvento, FKUsuario, Titulo, Descricao, DataEvento, Cor, Concluido) VALUES (:id, :u, :t, :d, :dt, :c, :co)");
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
    exit;
}

require_once 'geral/header.php';
?>

<main class="container-fluid py-4 mt-2 flex-grow-1" style="max-width: 1500px; padding-inline: var(--space-page-x); min-height: 100vh;">

    <div class="d-flex justify-content-between align-items-center mb-4 border-bottom border-secondary-subtle pb-3 flex-wrap gap-3">

        <div class="d-flex align-items-center gap-3">
            <div class="icon-circle bg-primary bg-opacity-10 d-flex justify-content-center align-items-center rounded-3 shadow-sm flex-shrink-0" style="width: 48px; height: 48px;">
                <i class="bi bi-calendar3 text-primary fs-4" style="color: var(--primary-gold-analysis) !important;"></i>
            </div>
            <div>
                <h2 class="fw-bold text-light mb-0">Agenda</h2>
                <span id="badge-mes-ano" class="text-secondary small text-uppercase tracking-wide fw-semibold"></span>
            </div>
        </div>

        <div class="d-flex align-items-center gap-2 flex-wrap">

            <div class="bg-body-tertiary border border-secondary-subtle rounded-pill px-3 py-1 me-2 d-flex align-items-center transition-hover">
                <div class="form-check form-switch mb-0 d-flex align-items-center gap-2 toggle-agenda">
                    <input class="form-check-input shadow-none m-0" type="checkbox" id="toggle-transacoes" role="switch">
                    <label class="form-check-label text-secondary small fw-semibold cursor-pointer" for="toggle-transacoes" style="padding-top: 2px;">
                        Incluir Transações
                    </label>
                </div>
            </div>

            <div class="btn-group bg-body-tertiary border border-secondary-subtle rounded-pill overflow-hidden shadow-sm">
                <button class="btn btn-sm btn-link text-secondary text-decoration-none transition-hover px-3" onclick="mudarMes(-1)">
                    <i class="bi bi-chevron-left"></i>
                </button>
                <button class="btn btn-sm btn-link text-light text-decoration-none fw-semibold transition-hover px-3 border-start border-end border-secondary-subtle" onclick="irHoje()">
                    Hoje
                </button>
                <button class="btn btn-sm btn-link text-secondary text-decoration-none transition-hover px-3" onclick="mudarMes(1)">
                    <i class="bi bi-chevron-right"></i>
                </button>
            </div>

            <button class="btn btn-gold btn-sm rounded-pill px-4 fw-bold text-dark transition-hover shadow-sm ms-2" onclick="abrirNovo(null)">
                <i class="bi bi-plus-lg me-1"></i> Novo
            </button>
        </div>
    </div>

    <div class="agenda-cal shadow-sm">
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
                <span class="text-secondary fw-semibold">Sincronizando agenda...</span>
            </div>
        </div>
    </div>

    <div class="d-flex justify-content-center align-items-center gap-4 mt-4 flex-wrap text-secondary small fw-semibold opacity-75">
        <span><i class="bi bi-circle-fill me-1" style="color:#7c3aed;font-size:0.5rem;"></i> Pessoal</span>
        <span><i class="bi bi-circle-fill me-1" style="color:#AA8C2C;font-size:0.5rem;"></i> Importante</span>
        <span><i class="bi bi-dash-lg me-1" style="color:#059669;"></i> Receita (Automática)</span>
        <span><i class="bi bi-dash-lg me-1" style="color:#dc2626;"></i> Despesa (Automática)</span>
    </div>

</main>

<div class="modal fade" id="modalEvento" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content bg-dark border-secondary-subtle shadow-lg rounded-4">

            <div class="modal-header border-bottom border-secondary-subtle p-3">
                <h5 class="modal-title text-light fw-bold d-flex align-items-center gap-2">
                    <i class="bi bi-calendar-event text-primary" style="color: var(--primary-gold-analysis) !important;"></i>
                    <span id="modal-titulo-texto">Novo Evento</span>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body p-4">
                <div id="modal-erro" class="alert alert-danger d-none d-flex align-items-center gap-2 rounded-3 shadow-sm border-0 bg-danger bg-opacity-10 text-danger fw-semibold py-2 px-3 mb-4">
                    <i class="bi bi-exclamation-triangle-fill"></i> <span id="modal-erro-msg"></span>
                </div>

                <input type="hidden" id="ev-id">

                <div class="mb-3">
                    <label class="form-label text-secondary small fw-semibold">Título <span class="text-danger">*</span></label>
                    <input type="text" id="ev-titulo" class="form-control bg-body-tertiary border-secondary-subtle text-light shadow-none fw-semibold" placeholder="Ex: Pagamento do IPTU">
                </div>

                <div class="mb-3">
                    <label class="form-label text-secondary small fw-semibold">Data <span class="text-danger">*</span></label>
                    <input type="date" id="ev-data" class="form-control bg-body-tertiary border-secondary-subtle text-light shadow-none fw-semibold">
                </div>

                <div class="mb-3">
                    <label class="form-label text-secondary small fw-semibold">Observação (Opcional)</label>
                    <textarea id="ev-descricao" rows="2" class="form-control bg-body-tertiary border-secondary-subtle text-light shadow-none" placeholder="Detalhes adicionais..."></textarea>
                </div>

                <div class="mb-4">
                    <label class="form-label text-secondary small fw-semibold mb-2">Classificação por Cor</label>
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
                            <label class="cor-opcao transition-hover">
                                <input type="radio" name="ev-cor" value="<?= $val ?>" <?= $val === 'roxo' ? 'checked' : '' ?> hidden>
                                <span class="cor-bolinha shadow-sm" style="background:<?= $hex ?>;"></span>
                                <span class="cor-texto fw-semibold small"><?= $label ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="form-check form-switch toggle-agenda bg-body-tertiary p-3 rounded-3 border border-secondary-subtle d-flex align-items-center justify-content-between m-0">
                    <label class="form-check-label text-light fw-semibold m-0" for="ev-concluido">Marcar como concluído</label>
                    <input class="form-check-input m-0 shadow-none cursor-pointer" type="checkbox" id="ev-concluido" role="switch">
                </div>
            </div>

            <div class="modal-footer border-top border-secondary-subtle p-3 d-flex justify-content-between">

                <div id="confirmar-exclusao" class="d-none w-100 d-flex align-items-center justify-content-between">
                    <span class="text-danger fw-semibold small"><i class="bi bi-exclamation-triangle-fill me-1"></i> Confirmar exclusão?</span>
                    <div class="d-flex gap-2">
                        <button class="btn btn-sm btn-outline-secondary rounded-pill fw-semibold" onclick="cancelarExclusao()">Cancelar</button>
                        <button class="btn btn-sm btn-danger rounded-pill fw-bold px-3" onclick="confirmarExclusao()" id="btn-confirmar-excluir">Sim, excluir</button>
                    </div>
                </div>

                <div id="rodape-normal" class="w-100 d-flex justify-content-between align-items-center">
                    <button id="btn-excluir" class="btn btn-sm btn-outline-danger rounded-pill fw-semibold d-none" onclick="pedirExclusao()">
                        <i class="bi bi-trash3 me-1"></i> Excluir
                    </button>
                    <div class="d-flex gap-2 ms-auto">
                        <button class="btn btn-link text-secondary text-decoration-none fw-semibold" data-bs-dismiss="modal">Cancelar</button>
                        <button class="btn btn-gold rounded-pill px-4 fw-bold text-dark d-flex align-items-center" onclick="salvarEvento()" id="btn-salvar-evento">
                            <i class="bi bi-check-lg me-2"></i> Salvar
                        </button>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>

<style>
    :root {
        --primary-gold-analysis: #AA8C2C;
        --bg-card-analysis: #2A2A2A;
        --bg-charcoal-analysis: #222222;
        --border-color-analysis: #333333;
    }

    /* ── Calendário ─────────────────────────────────────── */
    .agenda-cal {
        background-color: var(--border-color-analysis);
        border: 1px solid var(--border-color-analysis);
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
        text-align: right;
        padding: 8px 12px;
        font-size: 0.75rem;
        font-weight: 600;
        text-transform: lowercase;
        color: #888;
        background-color: var(--bg-charcoal-analysis);
    }

    .cal-grade {
        display: grid;
        grid-template-columns: repeat(7, 1fr);
        gap: 1px;
        background-color: var(--border-color-analysis);
    }

    .cal-carregando {
        grid-column: 1 / -1;
        background: var(--bg-charcoal-analysis);
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 4rem 0;
    }

    .cal-celula {
        background: var(--bg-card-analysis);
        min-height: 120px;
        padding: 8px;
        display: flex;
        flex-direction: column;
        transition: background-color 0.2s ease;
    }

    .cal-celula:hover {
        background: #2c2f35;
    }

    .cal-celula.outro-mes {
        background: var(--bg-charcoal-analysis);
    }

    .cal-celula.outro-mes .num-dia-num {
        opacity: 0.3;
    }

    .cal-celula.eh-hoje {
        background: rgba(170, 140, 44, 0.05);
    }

    /* Números */
    .num-dia {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 8px;
        flex-direction: row-reverse;
        /* Número na direita, botão na esquerda */
    }

    .num-dia-num {
        font-size: 0.85rem;
        color: #aaaaaa;
        font-weight: 500;
    }

    .num-dia-num.hoje-circulo {
        background: var(--primary-gold-analysis);
        color: #000;
        font-weight: bold;
        width: 24px;
        height: 24px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 50%;
    }

    /* Botão Adicionar do Dia */
    .btn-add-ev {
        background: transparent;
        border: none;
        color: var(--primary-gold-analysis);
        opacity: 0;
        transition: opacity 0.2s;
        padding: 0;
        margin-top: -2px;
    }

    .cal-celula:hover .btn-add-ev {
        opacity: 1;
    }

    .btn-add-ev:hover {
        filter: brightness(1.2);
    }

    /* Pílulas */
    .ev-pill {
        font-size: 0.72rem;
        padding: 4px 6px;
        border-radius: 4px;
        margin-bottom: 4px;
        cursor: pointer;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        display: flex;
        align-items: center;
        gap: 6px;
        font-weight: 500;
        transition: filter 0.2s;
    }

    .ev-pill:hover {
        filter: brightness(1.2);
    }

    .ev-nome {
        overflow: hidden;
        text-overflow: ellipsis;
        flex: 1;
    }

    .ev-icon {
        flex-shrink: 0;
        font-size: 0.65rem;
    }

    /* Cores das Pílulas */
    .ev-roxo {
        background: rgba(124, 58, 237, 0.15);
        color: #c4b5fd;
    }

    .ev-azul {
        background: rgba(37, 99, 235, 0.15);
        color: #93c5fd;
    }

    .ev-verde {
        background: rgba(5, 150, 105, 0.15);
        color: #6ee7b7;
    }

    .ev-amarelo {
        background: rgba(170, 140, 44, 0.15);
        color: #D4AF37;
    }

    .ev-vermelho {
        background: rgba(220, 38, 38, 0.15);
        color: #fca5a5;
    }

    .ev-cinza {
        background: rgba(107, 114, 128, 0.15);
        color: #d1d5db;
    }

    .ev-receita {
        background: rgba(5, 150, 105, 0.1);
        border-left: 2px dashed #059669;
        color: #6ee7b7;
    }

    .ev-despesa {
        background: rgba(220, 38, 38, 0.1);
        border-left: 2px dashed #dc2626;
        color: #fca5a5;
    }

    /* ── Seletor de Cores Moderno ───────────────────────── */
    .cor-opcao {
        display: flex;
        align-items: center;
        gap: 6px;
        padding: 6px 12px;
        border-radius: 20px;
        border: 1px solid var(--border-color-analysis);
        cursor: pointer;
        background: transparent;
    }

    .cor-opcao:has(input:checked) {
        border-color: var(--primary-gold-analysis);
        background: rgba(170, 140, 44, 0.1);
    }

    .cor-opcao:has(input:checked) .cor-texto {
        color: var(--primary-gold-analysis) !important;
    }

    .cor-bolinha {
        width: 12px;
        height: 12px;
        border-radius: 50%;
    }

    .cor-texto {
        color: #888;
    }

    /* Switch Customizado */
    .toggle-agenda .form-check-input:checked {
        background-color: var(--primary-gold-analysis);
        border-color: var(--primary-gold-analysis);
    }

    .cursor-pointer {
        cursor: pointer;
    }

    /* ── Responsividade Auralis (Modo Lista no Celular) ─── */
    @media (max-width: 768px) {
        .cal-semana {
            display: none;
        }

        .cal-grade {
            grid-template-columns: 1fr;
            gap: 0;
        }

        .cal-celula {
            min-height: auto;
            border-bottom: 1px solid var(--border-color-analysis);
            padding: 15px;
            flex-direction: column;
        }

        .cal-celula.outro-mes {
            display: none;
        }

        /* Esconde dias vazios no celular */
        .num-dia {
            flex-direction: row;
            justify-content: flex-start;
            gap: 15px;
            margin-bottom: 10px;
        }

        .btn-add-ev {
            opacity: 1;
            font-size: 1.2rem;
            margin-top: 0;
        }
    }
</style>


<script>
    const HOJE = new Date();
    let anoAtual = HOJE.getFullYear();
    let mesAtual = HOJE.getMonth();
    let itensMes = [];

    const MESES_PT = ['Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];

    // Instância do Modal
    const bsModal = new bootstrap.Modal(document.getElementById('modalEvento'));

    async function carregarMes(ano, mes) {
        const grid = document.getElementById('cal-grid');
        grid.innerHTML = '<div class="cal-carregando"><div class="spinner-border spinner-border-sm text-secondary me-2"></div><span class="text-secondary fw-semibold">Sincronizando agenda...</span></div>';

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

    function renderCalendario(ano, mes) {
        document.getElementById('badge-mes-ano').textContent = `${MESES_PT[mes]} ${ano}`;

        const grid = document.getElementById('cal-grid');
        const primeiroDia = new Date(ano, mes, 1).getDay();
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

            const cell = document.createElement('div');
            cell.className = 'cal-celula' + (outroMes ? ' outro-mes' : '') + (ehHoje ? ' eh-hoje' : '');

            const numDiv = document.createElement('div');
            numDiv.className = 'num-dia';

            const numSpan = document.createElement('span');
            numSpan.className = 'num-dia-num' + (ehHoje ? ' hoje-circulo' : '');
            numSpan.textContent = dia;
            numDiv.appendChild(numSpan);

            const btnAdd = document.createElement('button');
            btnAdd.className = 'btn-add-ev';
            btnAdd.title = 'Adicionar evento';
            btnAdd.innerHTML = '<i class="bi bi-plus-circle-fill"></i>';
            btnAdd.onclick = (e) => {
                e.stopPropagation();
                abrirNovo(dateStr);
            };
            numDiv.appendChild(btnAdd);

            cell.appendChild(numDiv);

            const itensHoje = itensMes.filter(it => it.data && it.data.startsWith(dateStr));

            itensHoje.forEach(item => {
                const pill = document.createElement('div');

                if (item.tipo === 'transacao') {
                    const isReceita = item.cor === 'receita';
                    const valorFmt = parseFloat(item.descricao || 0).toLocaleString('pt-BR', {
                        style: 'currency',
                        currency: 'BRL'
                    });

                    pill.className = `ev-pill ev-${escHtml(item.cor)}`;
                    pill.title = `${item.titulo} — ${valorFmt}`;
                    pill.innerHTML = `<i class="bi bi-${isReceita ? 'arrow-down-short' : 'arrow-up-short'} ev-icon fs-6"></i> <span class="ev-nome">${escHtml(item.titulo)}</span>`;
                    pill.onclick = () => window.location.href = `nova_transacao.php?editar=${encodeURIComponent(item.id)}`;
                } else {
                    pill.className = `ev-pill ev-${escHtml(item.cor)}`;
                    pill.title = item.descricao ? `${item.titulo}: ${item.descricao}` : item.titulo;
                    pill.innerHTML = `<i class="bi bi-${item.concluido == 1 ? 'check-circle-fill' : 'circle'} ev-icon"></i> <span class="ev-nome">${escHtml(item.titulo)}</span>`;
                    pill.onclick = () => abrirEditar(item);
                }
                cell.appendChild(pill);
            });

            grid.appendChild(cell);
        }
    }

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

    function limparModal() {
        document.getElementById('ev-id').value = '';
        document.getElementById('ev-titulo').value = '';
        document.getElementById('ev-data').value = '';
        document.getElementById('ev-descricao').value = '';
        document.getElementById('ev-concluido').checked = false;
        document.querySelector('input[name="ev-cor"][value="roxo"]').checked = true;
        document.getElementById('btn-excluir').classList.add('d-none');
        document.getElementById('modal-erro').classList.add('d-none');
        cancelarExclusao();
    }

    function abrirNovo(data) {
        limparModal();
        document.getElementById('modal-titulo-texto').textContent = 'Novo Evento';
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
        document.getElementById('modal-titulo-texto').textContent = 'Editar Evento';
        bsModal.show();
    }

    async function salvarEvento() {
        const id = document.getElementById('ev-id').value;
        const titulo = document.getElementById('ev-titulo').value.trim();
        const data = document.getElementById('ev-data').value;
        const btnSalvar = document.getElementById('btn-salvar-evento');

        if (!titulo || !data) {
            document.getElementById('modal-erro-msg').textContent = 'Preencha o título e a data.';
            document.getElementById('modal-erro').classList.remove('d-none');
            return;
        }

        // Anti-spam UX
        const textoOriginal = btnSalvar.innerHTML;
        btnSalvar.disabled = true;
        btnSalvar.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span> Salvando...';
        document.getElementById('modal-erro').classList.add('d-none');

        const body = new FormData();
        body.append('id', id);
        body.append('titulo', titulo);
        body.append('data', data);
        body.append('descricao', document.getElementById('ev-descricao').value.trim());
        body.append('cor', document.querySelector('input[name="ev-cor"]:checked')?.value || 'roxo');
        if (document.getElementById('ev-concluido').checked) body.append('concluido', '1');

        try {
            const res = await fetch('agenda.php?ajax=1&acao=salvar', {
                method: 'POST',
                body
            });
            const json = await res.json();
            if (json.ok) {
                bsModal.hide();
                carregarMes(anoAtual, mesAtual);
            } else {
                document.getElementById('modal-erro-msg').textContent = json.msg || 'Erro ao salvar.';
                document.getElementById('modal-erro').classList.remove('d-none');
            }
        } catch (e) {
            document.getElementById('modal-erro-msg').textContent = 'Erro de conexão.';
            document.getElementById('modal-erro').classList.remove('d-none');
        } finally {
            btnSalvar.disabled = false;
            btnSalvar.innerHTML = textoOriginal;
        }
    }

    function pedirExclusao() {
        document.getElementById('confirmar-exclusao').classList.remove('d-none');
        document.getElementById('rodape-normal').classList.add('d-none');
    }

    function cancelarExclusao() {
        document.getElementById('confirmar-exclusao').classList.add('d-none');
        document.getElementById('rodape-normal').classList.remove('d-none');
    }

    async function confirmarExclusao() {
        const btnExcluir = document.getElementById('btn-confirmar-excluir');
        btnExcluir.disabled = true;
        btnExcluir.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';

        const body = new FormData();
        body.append('id', document.getElementById('ev-id').value);
        try {
            const res = await fetch('agenda.php?ajax=1&acao=excluir', {
                method: 'POST',
                body
            });
            if ((await res.json()).ok) {
                bsModal.hide();
                carregarMes(anoAtual, mesAtual);
            }
        } catch (e) {} finally {
            btnExcluir.disabled = false;
            btnExcluir.innerHTML = 'Sim, excluir';
        }
    }

    function escHtml(str) {
        return String(str ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    document.getElementById('toggle-transacoes').addEventListener('change', () => carregarMes(anoAtual, mesAtual));
    document.getElementById('modalEvento').addEventListener('hidden.bs.modal', cancelarExclusao);

    carregarMes(anoAtual, mesAtual);
</script>

<?php require_once 'geral/footer.php'; ?>