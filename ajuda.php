<?php
session_start();
if (!isset($_SESSION['usuario_id'])) {
    header("Location: usuario/login.php");
    exit;
}
require_once 'config/conexao.php';
require_once 'config/funcoes.php';

$topico = preg_replace('/[^a-z0-9_-]/', '', $_GET['topico'] ?? 'inicio');

$topicos = [
    'inicio'        => ['titulo' => 'Primeiros Passos',              'icone' => 'bi-rocket-takeoff',      'tag' => 'Fundamentos'],
    'recorrentes'   => ['titulo' => 'Contas Recorrentes',            'icone' => 'bi-arrow-repeat',         'tag' => 'Transações'],
    'parcelamentos' => ['titulo' => 'Compras Parceladas',            'icone' => 'bi-list-ol',              'tag' => 'Transações'],
    'carteiras'     => ['titulo' => 'Conta Pessoal e Empresarial',   'icone' => 'bi-wallet2',              'tag' => 'Carteiras'],
    'transferencia' => ['titulo' => 'Transferência entre Carteiras', 'icone' => 'bi-arrow-left-right',    'tag' => 'Carteiras'],
    'compartilhadas'=> ['titulo' => 'Carteiras Compartilhadas',      'icone' => 'bi-people-fill',          'tag' => 'Carteiras'],
    'cartao'        => ['titulo' => 'Cartão de Crédito',             'icone' => 'bi-credit-card-2-front', 'tag' => 'Transações'],
    'categorias'    => ['titulo' => 'Categorias',                    'icone' => 'bi-tags',                'tag' => 'Organização'],
    'analises'      => ['titulo' => 'Análises e Gráficos',           'icone' => 'bi-graph-up-arrow',      'tag' => 'Organização'],
    'agenda'        => ['titulo' => 'Agenda e Planejamento',         'icone' => 'bi-calendar3',           'tag' => 'Planejamento'],
    'cofrinhos'     => ['titulo' => 'Cofrinhos',                     'icone' => 'bi-piggy-bank',          'tag' => 'Planejamento'],
    'metas'         => ['titulo' => 'Metas e Orçamento por Categoria','icone' => 'bi-bullseye',            'tag' => 'Planejamento'],
    'empreendedores'=> ['titulo' => 'Empreendedores',                'icone' => 'bi-briefcase',           'tag' => 'Recomendações'],
    'freelancers'   => ['titulo' => 'Freelancers e Autônomos',       'icone' => 'bi-laptop',               'tag' => 'Recomendações'],
    'familia'       => ['titulo' => 'Casais e Família',              'icone' => 'bi-people',              'tag' => 'Recomendações'],
    'estudantes'    => ['titulo' => 'Estudantes',                    'icone' => 'bi-mortarboard',         'tag' => 'Recomendações'],
];

if (!array_key_exists($topico, $topicos)) $topico = 'inicio';
$topicoAtual = $topicos[$topico];

require_once 'geral/header.php';
?>

<main class="container-fluid py-4 mt-2 flex-grow-1" style="max-width:1500px;padding-inline:var(--space-page-x);">

    <!-- Cabeçalho da página -->
    <div class="mb-4">
        <div class="d-flex align-items-center gap-2 mb-1">
            <i class="bi bi-mortarboard" style="color:var(--primary-gold-analysis);font-size:1.1rem;"></i>
            <h4 class="fw-bold text-light mb-0">Tutoriais</h4>
        </div>
        <p class="text-secondary small mb-0">Guias práticos para tirar o máximo do Auralis.</p>
    </div>

    <div class="row g-4">

        <!-- ── Sidebar de tópicos ─────────────────────────────────────── -->
        <div class="col-12 col-md-3 col-xl-2">
            <div class="sticky-top" style="top:76px;">

                <!-- Mobile: toggle -->
                <button class="btn btn-sm w-100 d-flex align-items-center justify-content-between d-md-none mb-2 rounded-3"
                    style="background:var(--bg-card);border:1px solid var(--card-border-color);color:var(--text-main);"
                    type="button" data-bs-toggle="collapse" data-bs-target="#topicNav">
                    <span class="d-flex align-items-center gap-2">
                        <i class="bi <?= htmlspecialchars($topicoAtual['icone']) ?>" style="color:var(--primary-gold-analysis);"></i>
                        <?= htmlspecialchars($topicoAtual['titulo']) ?>
                    </span>
                    <i class="bi bi-chevron-down small"></i>
                </button>

                <div class="collapse d-md-block" id="topicNav">
                    <?php
                    $grupos = [];
                    foreach ($topicos as $key => $t) {
                        $grupos[$t['tag']][] = ['key' => $key, 'titulo' => $t['titulo'], 'icone' => $t['icone']];
                    }
                    $_primeiroGrupo = true;
                    foreach ($grupos as $tag => $items): ?>
                        <div class="<?= $_primeiroGrupo ? 'mb-4' : 'mt-4 mb-4 pt-4' ?>"
                            style="<?= $_primeiroGrupo ? '' : 'border-top:1px solid var(--card-border-color);' ?>">
                            <div class="text-uppercase fw-bold mb-2"
                                style="font-size:0.78rem;letter-spacing:.06em;color:var(--primary-gold-analysis);">
                                <?= htmlspecialchars($tag) ?>
                            </div>
                            <?php foreach ($items as $item):
                                $isAtivo = $item['key'] === $topico;
                            ?>
                            <a href="?topico=<?= $item['key'] ?>"
                                class="d-flex align-items-center gap-2 px-2 py-2 ms-2 rounded-3 text-decoration-none mb-1 transition-hover"
                                style="font-size:0.82rem;
                                       <?= $isAtivo
                                           ? 'background:var(--primary-gold-analysis)18;color:var(--primary-gold-analysis);font-weight:600;'
                                           : 'color:var(--text-secondary);' ?>">
                                <i class="bi <?= htmlspecialchars($item['icone']) ?>" style="font-size:0.85rem;flex-shrink:0;"></i>
                                <?= htmlspecialchars($item['titulo']) ?>
                            </a>
                            <?php endforeach; ?>
                        </div>
                    <?php $_primeiroGrupo = false; endforeach; ?>
                </div>
            </div>
        </div>

        <!-- ── Conteúdo do tópico ─────────────────────────────────────── -->
        <div class="col-12 col-md-9 col-xl-10">
            <div class="rounded-4 p-4 p-md-5" style="background:var(--bg-card);border:1px solid var(--card-border-color);">

                <!-- Cabeçalho do tópico -->
                <div class="d-flex align-items-center gap-3 mb-4 pb-3" style="border-bottom:1px solid var(--card-border-color);">
                    <div class="d-flex align-items-center justify-content-center rounded-3 flex-shrink-0"
                        style="width:46px;height:46px;background:var(--primary-gold-analysis)18;">
                        <i class="bi <?= htmlspecialchars($topicoAtual['icone']) ?>"
                            style="color:var(--primary-gold-analysis);font-size:1.4rem;"></i>
                    </div>
                    <div>
                        <div class="text-secondary small text-uppercase fw-bold" style="font-size:0.65rem;letter-spacing:.08em;">
                            <?= htmlspecialchars($topicoAtual['tag']) ?>
                        </div>
                        <h4 class="fw-bold text-light mb-0"><?= htmlspecialchars($topicoAtual['titulo']) ?></h4>
                    </div>
                </div>

                <?php if ($topico === 'inicio'): ?>
                <!-- ═══ PRIMEIROS PASSOS ═══════════════════════════════════════ -->
                <p class="text-secondary mb-4">Seja bem-vindo ao Auralis. Em poucos minutos você terá tudo configurado para acompanhar suas finanças com clareza. Veja como o sistema funciona:</p>

                <div class="row g-3 mb-4">
                    <div class="col-12 col-sm-4">
                        <div class="rounded-3 p-3 h-100" style="background:rgba(96,165,250,0.07);border:1px solid rgba(96,165,250,0.2);">
                            <div class="d-flex align-items-center gap-2 mb-2">
                                <span class="fw-bold" style="color:#60a5fa;font-size:1.3rem;">1</span>
                                <i class="bi bi-wallet2" style="color:#60a5fa;"></i>
                            </div>
                            <div class="fw-semibold text-light mb-1">Crie sua carteira</div>
                            <div class="text-secondary small">Representa sua conta bancária, carteira física ou caixa da empresa.</div>
                        </div>
                    </div>
                    <div class="col-12 col-sm-4">
                        <div class="rounded-3 p-3 h-100" style="background:rgba(34,197,94,0.07);border:1px solid rgba(34,197,94,0.2);">
                            <div class="d-flex align-items-center gap-2 mb-2">
                                <span class="fw-bold" style="color:#22c55e;font-size:1.3rem;">2</span>
                                <i class="bi bi-plus-circle" style="color:#22c55e;"></i>
                            </div>
                            <div class="fw-semibold text-light mb-1">Lance receitas e despesas</div>
                            <div class="text-secondary small">Cada entrada ou saída fica registrada e o saldo é atualizado na hora.</div>
                        </div>
                    </div>
                    <div class="col-12 col-sm-4">
                        <div class="rounded-3 p-3 h-100" style="background:rgba(245,158,11,0.07);border:1px solid rgba(245,158,11,0.2);">
                            <div class="d-flex align-items-center gap-2 mb-2">
                                <span class="fw-bold" style="color:#f59e0b;font-size:1.3rem;">3</span>
                                <i class="bi bi-graph-up-arrow" style="color:#f59e0b;"></i>
                            </div>
                            <div class="fw-semibold text-light mb-1">Acompanhe e analise</div>
                            <div class="text-secondary small">Gráficos, comparativos e relatórios gerados automaticamente.</div>
                        </div>
                    </div>
                </div>

                <h6 class="fw-bold text-light mb-3"><i class="bi bi-pin-angle me-2" style="color:var(--primary-gold-analysis);"></i>Conceitos importantes</h6>
                <div class="mb-3">
                    <div class="d-flex gap-3 mb-3 p-3 rounded-3" style="background:var(--bg-body);">
                        <i class="bi bi-check-circle-fill flex-shrink-0 mt-1" style="color:#22c55e;"></i>
                        <div><span class="fw-semibold text-light">Efetivado</span> <span class="text-secondary">— a transação já aconteceu e afeta o saldo atual.</span></div>
                    </div>
                    <div class="d-flex gap-3 mb-3 p-3 rounded-3" style="background:var(--bg-body);">
                        <i class="bi bi-clock-history flex-shrink-0 mt-1 text-warning"></i>
                        <div><span class="fw-semibold text-light">Pendente</span> <span class="text-secondary">— agendada para o futuro. Não afeta o saldo, mas aparece na agenda e no saldo projetado.</span></div>
                    </div>
                    <div class="d-flex gap-3 p-3 rounded-3" style="background:var(--bg-body);">
                        <i class="bi bi-wallet2 flex-shrink-0 mt-1" style="color:#60a5fa;"></i>
                        <div><span class="fw-semibold text-light">Carteira</span> <span class="text-secondary">— onde o dinheiro fica guardado. Você pode ter várias (pessoal, empresa, poupança).</span></div>
                    </div>
                </div>

                <div class="rounded-3 p-3" style="background:rgba(245,158,11,0.08);border:1px solid rgba(245,158,11,0.25);">
                    <div class="d-flex gap-2 align-items-start">
                        <i class="bi bi-lightbulb-fill flex-shrink-0 mt-1" style="color:#f59e0b;"></i>
                        <div>
                            <div class="fw-semibold" style="color:#f59e0b;">Dica de início</div>
                            <div class="text-secondary small mt-1">Na primeira vez que acessar o Dashboard, o Auralis vai perguntar qual é o seu saldo atual. Informe o valor real que você tem naquela conta — isso garante que todos os cálculos sejam precisos desde o dia zero.</div>
                        </div>
                    </div>
                </div>

                <?php elseif ($topico === 'recorrentes'): ?>
                <!-- ═══ CONTAS RECORRENTES ═══════════════════════════════════ -->
                <p class="text-secondary mb-4">Contas recorrentes são despesas ou receitas que se repetem todos os meses no mesmo valor — aluguel, internet, academia, salário fixo. O Auralis cria os registros futuros automaticamente para você.</p>

                <h6 class="fw-bold text-light mb-3"><i class="bi bi-list-check me-2" style="color:var(--primary-gold-analysis);"></i>Como criar uma conta recorrente</h6>
                <ol class="ps-3 text-secondary mb-4" style="line-height:2;">
                    <li>No Dashboard, clique em <strong class="text-light">Receita</strong> ou <strong class="text-light">Despesa</strong>.</li>
                    <li>Preencha a descrição, o valor e a data.</li>
                    <li>Marque a opção <strong class="text-light">"Conta Recorrente"</strong>.</li>
                    <li>Informe o <strong class="text-light">dia do mês</strong> que ela costuma cair (ex.: dia 5 para aluguel).</li>
                    <li>Salve. O sistema vai criar automaticamente os próximos 24 meses.</li>
                </ol>

                <h6 class="fw-bold text-light mb-3"><i class="bi bi-gear me-2" style="color:var(--primary-gold-analysis);"></i>Gerenciando recorrentes</h6>
                <div class="mb-3">
                    <div class="d-flex gap-3 mb-2 p-3 rounded-3" style="background:var(--bg-body);">
                        <i class="bi bi-x-circle flex-shrink-0 mt-1 text-danger"></i>
                        <div><span class="fw-semibold text-light">Excluir apenas este mês</span> <span class="text-secondary">— remove só o registro do mês atual, os demais continuam.</span></div>
                    </div>
                    <div class="d-flex gap-3 p-3 rounded-3" style="background:var(--bg-body);">
                        <i class="bi bi-x-octagon flex-shrink-0 mt-1 text-danger"></i>
                        <div><span class="fw-semibold text-light">Excluir este e todos os futuros</span> <span class="text-secondary">— encerra a recorrência a partir deste mês.</span></div>
                    </div>
                </div>

                <div class="rounded-3 p-3 mt-3" style="background:rgba(245,158,11,0.08);border:1px solid rgba(245,158,11,0.25);">
                    <div class="d-flex gap-2">
                        <i class="bi bi-lightbulb-fill flex-shrink-0 mt-1" style="color:#f59e0b;"></i>
                        <div>
                            <div class="fw-semibold" style="color:#f59e0b;">Exemplos de uso</div>
                            <div class="text-secondary small mt-1">Aluguel todo dia 1º · Plano de internet dia 10 · Netflix dia 15 · Salário fixo dia 5 · Mensalidade academia dia 20</div>
                        </div>
                    </div>
                </div>

                <?php elseif ($topico === 'parcelamentos'): ?>
                <!-- ═══ COMPRAS PARCELADAS ═══════════════════════════════════ -->
                <p class="text-secondary mb-4">Ao parcelar uma compra, o Auralis distribui o valor automaticamente pelos meses, criando um registro por parcela. Diferente de recorrente — a compra parcelada tem data de encerramento definida.</p>

                <h6 class="fw-bold text-light mb-3"><i class="bi bi-list-check me-2" style="color:var(--primary-gold-analysis);"></i>Como cadastrar uma compra parcelada</h6>
                <ol class="ps-3 text-secondary mb-4" style="line-height:2;">
                    <li>No Dashboard, clique em <strong class="text-light">Despesa</strong>.</li>
                    <li>Informe o <strong class="text-light">valor total</strong> da compra (ex.: R$ 1.200,00).</li>
                    <li>Marque a opção <strong class="text-light">"Parcelado"</strong>.</li>
                    <li>Informe o <strong class="text-light">número de parcelas</strong> (ex.: 12x).</li>
                    <li>O sistema criará 12 registros de R$ 100,00, um por mês.</li>
                </ol>

                <div class="row g-3 mb-4">
                    <div class="col-12 col-sm-6">
                        <div class="rounded-3 p-3 h-100" style="background:var(--bg-body);border:1px solid var(--card-border-color);">
                            <div class="fw-semibold text-light mb-1 d-flex align-items-center gap-2">
                                <i class="bi bi-arrow-repeat text-warning"></i> Recorrente
                            </div>
                            <div class="text-secondary small">Sem fim definido. Gerado todo mês indefinidamente. Ótimo para contas fixas como aluguel.</div>
                        </div>
                    </div>
                    <div class="col-12 col-sm-6">
                        <div class="rounded-3 p-3 h-100" style="background:var(--bg-body);border:1px solid var(--card-border-color);">
                            <div class="fw-semibold text-light mb-1 d-flex align-items-center gap-2">
                                <i class="bi bi-list-ol" style="color:#60a5fa;"></i> Parcelado
                            </div>
                            <div class="text-secondary small">Encerra após N meses. Ótimo para compras no cartão parcelado ou financiamentos.</div>
                        </div>
                    </div>
                </div>

                <div class="rounded-3 p-3" style="background:rgba(245,158,11,0.08);border:1px solid rgba(245,158,11,0.25);">
                    <div class="d-flex gap-2">
                        <i class="bi bi-lightbulb-fill flex-shrink-0 mt-1" style="color:#f59e0b;"></i>
                        <div>
                            <div class="fw-semibold" style="color:#f59e0b;">Dica</div>
                            <div class="text-secondary small mt-1">As parcelas aparecem na Agenda como despesas futuras pendentes. Assim você sabe exatamente quanto vai gastar nos próximos meses, antes de gastar.</div>
                        </div>
                    </div>
                </div>

                <?php elseif ($topico === 'carteiras'): ?>
                <!-- ═══ CONTA PESSOAL E EMPRESARIAL ══════════════════════════ -->
                <p class="text-secondary mb-4">Misturar dinheiro pessoal com o da empresa é uma das maiores fontes de confusão financeira. No Auralis, você cria uma carteira separada para cada conta — e alterna entre elas no Dashboard com um clique.</p>

                <h6 class="fw-bold text-light mb-3"><i class="bi bi-list-check me-2" style="color:var(--primary-gold-analysis);"></i>Como criar uma nova carteira</h6>
                <ol class="ps-3 text-secondary mb-4" style="line-height:2;">
                    <li>Na sidebar, clique em <strong class="text-light">Carteiras</strong>.</li>
                    <li>Clique no botão <strong class="text-light">"+ Nova Carteira"</strong>.</li>
                    <li>Dê um nome claro (ex.: <em>"Conta Empresa"</em>, <em>"Nubank Pessoal"</em>).</li>
                    <li>Salve. A nova carteira aparecerá no seletor do Dashboard.</li>
                </ol>

                <h6 class="fw-bold text-light mb-3"><i class="bi bi-arrow-left-right me-2" style="color:var(--primary-gold-analysis);"></i>Como alternar entre carteiras</h6>
                <p class="text-secondary mb-4">No Dashboard, no topo da página, há um seletor com o nome da carteira atual. Clique nele para alternar entre as suas carteiras. Cada uma tem saldo, transações e análises independentes.</p>

                <h6 class="fw-bold text-light mb-3"><i class="bi bi-diagram-2 me-2" style="color:var(--primary-gold-analysis);"></i>Exemplo prático</h6>
                <div class="mb-3">
                    <div class="d-flex gap-3 mb-2 p-3 rounded-3" style="background:var(--bg-body);">
                        <i class="bi bi-briefcase flex-shrink-0 mt-1" style="color:#60a5fa;"></i>
                        <div><span class="fw-semibold text-light">Caixa Empresa</span> <span class="text-secondary">— recebe pagamentos de clientes, paga fornecedores e funcionários.</span></div>
                    </div>
                    <div class="d-flex gap-3 p-3 rounded-3" style="background:var(--bg-body);">
                        <i class="bi bi-person flex-shrink-0 mt-1" style="color:#22c55e;"></i>
                        <div><span class="fw-semibold text-light">Conta Pessoal</span> <span class="text-secondary">— recebe o salário retirado da empresa, paga contas da vida pessoal.</span></div>
                    </div>
                </div>

                <div class="rounded-3 p-3" style="background:rgba(245,158,11,0.08);border:1px solid rgba(245,158,11,0.25);">
                    <div class="d-flex gap-2">
                        <i class="bi bi-lightbulb-fill flex-shrink-0 mt-1" style="color:#f59e0b;"></i>
                        <div>
                            <div class="fw-semibold" style="color:#f59e0b;">Para mover dinheiro entre elas</div>
                            <div class="text-secondary small mt-1">Use a função <strong>Transferência entre Carteiras</strong> — disponível no botão "Transferir" no Dashboard. O valor sai de uma e entra na outra sem afetar o total consolidado.</div>
                        </div>
                    </div>
                </div>

                <?php elseif ($topico === 'transferencia'): ?>
                <!-- ═══ TRANSFERÊNCIA ENTRE CARTEIRAS ════════════════════════ -->
                <p class="text-secondary mb-4">A transferência permite mover dinheiro de uma carteira para outra — como retirar seu salário do caixa da empresa e depositar na conta pessoal, ou mover uma reserva para poupança.</p>

                <h6 class="fw-bold text-light mb-3"><i class="bi bi-list-check me-2" style="color:var(--primary-gold-analysis);"></i>Como fazer uma transferência</h6>
                <ol class="ps-3 text-secondary mb-4" style="line-height:2;">
                    <li>No Dashboard, clique no botão <strong class="text-light">"Transferir"</strong> (aparece quando você tem 2 ou mais carteiras).</li>
                    <li>Selecione a carteira de <strong class="text-light">origem</strong> (de onde sai o dinheiro).</li>
                    <li>Selecione a carteira de <strong class="text-light">destino</strong> (para onde vai o dinheiro).</li>
                    <li>Informe o valor, a data e uma descrição opcional.</li>
                    <li>Clique em <strong class="text-light">"Transferir"</strong>. O saldo de ambas as carteiras é atualizado.</li>
                </ol>

                <h6 class="fw-bold text-light mb-3"><i class="bi bi-eye me-2" style="color:var(--primary-gold-analysis);"></i>Como aparece no extrato</h6>
                <div class="mb-4">
                    <div class="d-flex gap-3 mb-2 p-3 rounded-3" style="background:var(--bg-body);">
                        <i class="bi bi-arrow-left-right flex-shrink-0 mt-1" style="color:#60a5fa;"></i>
                        <div><span class="fw-semibold text-light">Na carteira de origem</span> <span class="text-secondary">— aparece como saída (valor negativo) com a indicação "→ Carteira Destino".</span></div>
                    </div>
                    <div class="d-flex gap-3 p-3 rounded-3" style="background:var(--bg-body);">
                        <i class="bi bi-arrow-left-right flex-shrink-0 mt-1" style="color:#60a5fa;"></i>
                        <div><span class="fw-semibold text-light">Na carteira de destino</span> <span class="text-secondary">— aparece como entrada (valor positivo) com a indicação "← Carteira Origem".</span></div>
                    </div>
                </div>

                <div class="rounded-3 p-3" style="background:rgba(245,158,11,0.08);border:1px solid rgba(245,158,11,0.25);">
                    <div class="d-flex gap-2">
                        <i class="bi bi-lightbulb-fill flex-shrink-0 mt-1" style="color:#f59e0b;"></i>
                        <div>
                            <div class="fw-semibold" style="color:#f59e0b;">Dica</div>
                            <div class="text-secondary small mt-1">Ao excluir uma transferência, o par é removido de ambas as carteiras de uma vez. Para desfazer, expanda a transação no extrato e clique em "Excluir".</div>
                        </div>
                    </div>
                </div>

                <?php elseif ($topico === 'compartilhadas'): ?>
                <!-- ═══ CARTEIRAS COMPARTILHADAS ═════════════════════════════ -->
                <p class="text-secondary mb-4">Convide outras pessoas pra ver e lançar transações numa mesma carteira — ideal pra casal, família ou sócios que dividem uma conta. Disponível nos planos <strong class="text-light">Pro</strong> (até 2 pessoas) e <strong class="text-light">VIP</strong> (até 8 pessoas).</p>

                <h6 class="fw-bold text-light mb-3"><i class="bi bi-list-check me-2" style="color:var(--primary-gold-analysis);"></i>Como criar e convidar</h6>
                <ol class="ps-3 text-secondary mb-4" style="line-height:2;">
                    <li>Em <strong class="text-light">Carteiras</strong>, clique em <strong class="text-light">"+ Nova Carteira"</strong> e marque <strong class="text-light">"Carteira compartilhada?"</strong> — só dá pra decidir isso na criação. Ela já nasce com o mesmo kit de categorias prontas de uma conta nova.</li>
                    <li>Peça pra pessoa abrir <strong class="text-light">Perfil</strong> e te passar a chave pessoal dela (tipo "AUR-AB12CD") — é o mesmo código usado pra indicar amigos.</li>
                    <li>Clique na própria carteira (ou no menu de 3 pontos) pra abrir <strong class="text-light">"Administrar Carteira"</strong> e cole o código na aba <strong class="text-light">Membros</strong> pra enviar o convite.</li>
                    <li>A pessoa vê o convite direto na página <strong class="text-light">Carteiras</strong> (com um selinho de aviso na barra lateral) e precisa aceitar pra entrar — nada acontece automaticamente sem a confirmação dela.</li>
                </ol>

                <h6 class="fw-bold text-light mb-3"><i class="bi bi-diagram-2 me-2" style="color:var(--primary-gold-analysis);"></i>Como funciona a hierarquia</h6>
                <div class="mb-4">
                    <div class="d-flex gap-3 mb-2 p-3 rounded-3" style="background:var(--bg-body);">
                        <i class="bi bi-star-fill flex-shrink-0 mt-1" style="color:var(--primary-gold-analysis);"></i>
                        <div><span class="fw-semibold text-light">Dono</span> <span class="text-secondary">— quem criou a carteira. Gerencia membros, categorias da carteira, define permissões (ex: se convidado pode excluir lançamento livremente) e pode editar ou excluir o lançamento de qualquer pessoa.</span></div>
                    </div>
                    <div class="d-flex gap-3 p-3 rounded-3" style="background:var(--bg-body);">
                        <i class="bi bi-person flex-shrink-0 mt-1" style="color:#60a5fa;"></i>
                        <div><span class="fw-semibold text-light">Convidado</span> <span class="text-secondary">— vê tudo (sem privacidade seletiva), lança transações normalmente, edita o que ele mesmo lançou, e exclui também — a não ser que o dono tenha desligado isso em Permissões. Categorias da carteira só o dono cria ou edita.</span></div>
                    </div>
                </div>

                <h6 class="fw-bold text-light mb-3"><i class="bi bi-eye me-2" style="color:var(--primary-gold-analysis);"></i>O que muda no dia a dia</h6>
                <div class="mb-4">
                    <div class="d-flex gap-3 mb-2 p-3 rounded-3" style="background:var(--bg-body);">
                        <i class="bi bi-person-fill flex-shrink-0 mt-1" style="color:#60a5fa;"></i>
                        <div><span class="fw-semibold text-light">Quem lançou</span> <span class="text-secondary">— cada transação mostra um selinho com o nome de quem lançou, no Dashboard e na Agenda.</span></div>
                    </div>
                    <div class="d-flex gap-3 p-3 rounded-3" style="background:var(--bg-body);">
                        <i class="bi bi-clock-history flex-shrink-0 mt-1" style="color:#60a5fa;"></i>
                        <div><span class="fw-semibold text-light">Atividade</span> <span class="text-secondary">— em "Administrar Carteira", a aba Atividade mostra quem criou, editou, excluiu, efetivou ou transferiu cada lançamento, além de convites/entradas/saídas. Filtra por "Tudo", "Movimentações na Carteira" (lançamentos) ou "Movimentações de Membro" (convite, entrou, saiu).</span></div>
                    </div>
                </div>

                <div class="rounded-3 p-3" style="background:rgba(245,158,11,0.08);border:1px solid rgba(245,158,11,0.25);">
                    <div class="d-flex gap-2">
                        <i class="bi bi-lightbulb-fill flex-shrink-0 mt-1" style="color:#f59e0b;"></i>
                        <div>
                            <div class="fw-semibold" style="color:#f59e0b;">Dica</div>
                            <div class="text-secondary small mt-1">Sair da carteira (ou ser removido) não apaga nada — suas transações já lançadas continuam lá, você só perde o acesso a partir daquele momento.</div>
                        </div>
                    </div>
                </div>

                <?php elseif ($topico === 'cofrinhos'): ?>
                <!-- ═══ COFRINHOS ═══════════════════════════════════════════ -->
                <p class="text-secondary mb-4">Cofrinhos são reservas para objetivos específicos — viagem, computador novo, reserva de emergência. O dinheiro sai da carteira, fica guardado no cofrinho e pode ser retirado a qualquer momento.</p>

                <h6 class="fw-bold text-light mb-3"><i class="bi bi-list-check me-2" style="color:var(--primary-gold-analysis);"></i>Como criar um cofrinho</h6>
                <ol class="ps-3 text-secondary mb-4" style="line-height:2;">
                    <li>Na sidebar, clique em <strong class="text-light">Análises</strong>.</li>
                    <li>Role até a seção <strong class="text-light">Cofrinhos</strong> e clique em <strong class="text-light">"+ Novo"</strong>.</li>
                    <li>Defina o nome (ex.: <em>"Viagem Europa"</em>), ícone e cor.</li>
                    <li>Vincule a uma carteira — é de onde sairá o dinheiro dos depósitos.</li>
                    <li>Opcionalmente, defina uma <strong class="text-light">meta</strong> (valor alvo) e <strong class="text-light">data limite</strong>.</li>
                </ol>

                <h6 class="fw-bold text-light mb-3"><i class="bi bi-plus-circle me-2" style="color:var(--primary-gold-analysis);"></i>Como depositar</h6>
                <ol class="ps-3 text-secondary mb-4" style="line-height:2;">
                    <li>Em <strong class="text-light">Análises > Cofrinhos</strong>, clique no card do cofrinho.</li>
                    <li>Clique em <strong class="text-light">"Depositar"</strong>.</li>
                    <li>Informe o valor. Ele sairá da carteira vinculada e entrará no cofrinho.</li>
                    <li>A barra de progresso e o saldo do cofrinho são atualizados na hora.</li>
                </ol>

                <div class="rounded-3 p-3 mb-3" style="background:rgba(245,158,11,0.08);border:1px solid rgba(245,158,11,0.25);">
                    <div class="d-flex gap-2">
                        <i class="bi bi-lightbulb-fill flex-shrink-0 mt-1" style="color:#f59e0b;"></i>
                        <div>
                            <div class="fw-semibold" style="color:#f59e0b;">Como aparece no Dashboard</div>
                            <div class="text-secondary small mt-1">Os cofrinhos com saldo aparecem como cards na tela inicial, mostrando o valor guardado e o percentual de conclusão da meta. Clique em "Ver tudo" para ir direto para Análises.</div>
                        </div>
                    </div>
                </div>

                <?php elseif ($topico === 'metas'): ?>
                <!-- ═══ METAS E ORÇAMENTO POR CATEGORIA ═════════════════════ -->
                <p class="text-secondary mb-4">Defina um limite mensal para cada categoria de despesa (orçamento) ou um alvo para cada categoria de receita (meta), e o Auralis avisa automaticamente se você estourou o limite ou bateu a meta — comparando com o que foi lançado no mês.</p>

                <h6 class="fw-bold text-light mb-3"><i class="bi bi-list-check me-2" style="color:var(--primary-gold-analysis);"></i>Como definir um orçamento ou meta</h6>
                <ol class="ps-3 text-secondary mb-4" style="line-height:2;">
                    <li>Na sidebar, clique em <strong class="text-light">Categorias</strong>.</li>
                    <li>Na linha da categoria desejada, clique em <strong class="text-light">"+ Orçamento"</strong> (despesa) ou <strong class="text-light">"+ Meta"</strong> (receita).</li>
                    <li>Informe o valor mensal (ex.: R$ 200,00 para Assinaturas).</li>
                    <li>Salve. O valor vale todo mês, até você editar ou remover.</li>
                </ol>

                <h6 class="fw-bold text-light mb-3"><i class="bi bi-graph-up me-2" style="color:var(--primary-gold-analysis);"></i>Onde acompanhar o progresso</h6>
                <p class="text-secondary mb-4">Em <strong class="text-light">Análises</strong>, os cards <strong class="text-light">"Orçamento por Categoria"</strong> e <strong class="text-light">"Meta por Categoria"</strong> mostram uma barra de progresso por categoria, comparando o gasto/recebido do mês selecionado com o valor definido. Se uma despesa passar de 100%, aparece <strong class="text-light">"Estourou X%!"</strong>; se uma receita passar de 100%, aparece <strong class="text-light">"Parabéns! +X%"</strong>.</p>

                <h6 class="fw-bold text-light mb-3"><i class="bi bi-arrow-left-right me-2" style="color:var(--primary-gold-analysis);"></i>Relação Entrada/Saída</h6>
                <p class="text-secondary mb-4">Ainda em <strong class="text-light">Categorias</strong>, o card no topo da página soma todas as suas metas de receita (entrada) e aplica um percentual de poupança mensal que você define — o restante é o quanto sobra pra distribuir entre os orçamentos de despesa. Se a soma dos orçamentos passar do que sobra, o painel avisa em destaque.</p>

                <div class="rounded-3 p-3" style="background:rgba(245,158,11,0.08);border:1px solid rgba(245,158,11,0.25);">
                    <div class="d-flex gap-2">
                        <i class="bi bi-lightbulb-fill flex-shrink-0 mt-1" style="color:#f59e0b;"></i>
                        <div>
                            <div class="fw-semibold" style="color:#f59e0b;">Dica</div>
                            <div class="text-secondary small mt-1">Comece definindo o percentual de poupança em "Relação Entrada/Saída" antes de distribuir os orçamentos — assim você já desconta a reserva antes de decidir quanto cada categoria pode gastar.</div>
                        </div>
                    </div>
                </div>

                <?php elseif ($topico === 'cartao'): ?>
                <!-- ═══ CARTÃO DE CRÉDITO ════════════════════════════════════ -->
                <p class="text-secondary mb-4">O Auralis gerencia seus cartões de crédito separando as compras por fatura mensal. Você lança as compras no cartão e, quando a fatura fecha, registra o pagamento saindo da sua carteira.</p>

                <h6 class="fw-bold text-light mb-3"><i class="bi bi-list-check me-2" style="color:var(--primary-gold-analysis);"></i>Configuração inicial</h6>
                <ol class="ps-3 text-secondary mb-4" style="line-height:2;">
                    <li>Na sidebar, clique em <strong class="text-light">Cartões</strong>.</li>
                    <li>Clique em <strong class="text-light">"+ Novo Cartão"</strong> e informe o nome, bandeira e cor.</li>
                    <li>Defina o <strong class="text-light">dia de fechamento</strong> (quando a fatura fecha) e o <strong class="text-light">dia de vencimento</strong>.</li>
                    <li>O Auralis criará automaticamente as faturas mensais.</li>
                </ol>

                <h6 class="fw-bold text-light mb-3"><i class="bi bi-cart me-2" style="color:var(--primary-gold-analysis);"></i>Lançando compras</h6>
                <ol class="ps-3 text-secondary mb-4" style="line-height:2;">
                    <li>Em <strong class="text-light">Cartões</strong>, clique no cartão desejado.</li>
                    <li>Abra a fatura do mês atual e clique em <strong class="text-light">"+ Lançamento"</strong>.</li>
                    <li>Informe a descrição, o valor e a categoria.</li>
                    <li>A compra aparece na fatura e o total acumulado é atualizado.</li>
                </ol>

                <h6 class="fw-bold text-light mb-3"><i class="bi bi-cash me-2" style="color:var(--primary-gold-analysis);"></i>Pagando a fatura</h6>
                <ol class="ps-3 text-secondary mb-4" style="line-height:2;">
                    <li>Quando a fatura fechar, acesse-a e clique em <strong class="text-light">"Registrar Pagamento"</strong>.</li>
                    <li>Selecione a carteira que vai pagar e confirme o valor.</li>
                    <li>O valor sai da carteira selecionada e a fatura é marcada como paga.</li>
                </ol>

                <div class="rounded-3 p-3" style="background:rgba(245,158,11,0.08);border:1px solid rgba(245,158,11,0.25);">
                    <div class="d-flex gap-2">
                        <i class="bi bi-lightbulb-fill flex-shrink-0 mt-1" style="color:#f59e0b;"></i>
                        <div>
                            <div class="fw-semibold" style="color:#f59e0b;">Dica</div>
                            <div class="text-secondary small mt-1">As faturas abertas dos seus cartões aparecem como cards no Dashboard. Assim você sempre sabe quanto está acumulado em cada cartão antes do fechamento.</div>
                        </div>
                    </div>
                </div>

                <?php elseif ($topico === 'agenda'): ?>
                <!-- ═══ AGENDA E PLANEJAMENTO ════════════════════════════════ -->
                <p class="text-secondary mb-4">A Agenda é uma visão calendário de todas as suas transações — passadas, presentes e futuras. É onde você planeja o mês: sabe o que já veio, o que ainda falta pagar e como o saldo vai evoluir.</p>

                <h6 class="fw-bold text-light mb-3"><i class="bi bi-eye me-2" style="color:var(--primary-gold-analysis);"></i>O que você vê na Agenda</h6>
                <div class="mb-4">
                    <div class="d-flex gap-3 mb-2 p-3 rounded-3" style="background:var(--bg-body);">
                        <i class="bi bi-check-circle-fill flex-shrink-0 mt-1" style="color:#22c55e;"></i>
                        <div><span class="fw-semibold text-light">Transações efetivadas</span> <span class="text-secondary">— já aconteceram e afetam o saldo.</span></div>
                    </div>
                    <div class="d-flex gap-3 mb-2 p-3 rounded-3" style="background:var(--bg-body);">
                        <i class="bi bi-clock-history flex-shrink-0 mt-1 text-warning"></i>
                        <div><span class="fw-semibold text-light">Contas pendentes</span> <span class="text-secondary">— agendadas para o futuro. Inclui recorrentes e parceladas dos próximos meses.</span></div>
                    </div>
                    <div class="d-flex gap-3 p-3 rounded-3" style="background:var(--bg-body);">
                        <i class="bi bi-funnel flex-shrink-0 mt-1" style="color:#60a5fa;"></i>
                        <div><span class="fw-semibold text-light">Filtro por carteira</span> <span class="text-secondary">— veja só uma carteira ou todas ao mesmo tempo.</span></div>
                    </div>
                </div>

                <h6 class="fw-bold text-light mb-3"><i class="bi bi-check2-circle me-2" style="color:var(--primary-gold-analysis);"></i>Confirmando contas pagas</h6>
                <p class="text-secondary mb-4">Clique em um dia no calendário para ver as transações. Expanda qualquer item e clique em <strong class="text-light">"Marcar como Pago"</strong> ou <strong class="text-light">"Marcar como Recebido"</strong> — a transação passa de pendente para efetivada e o saldo é atualizado.</p>

                <div class="rounded-3 p-3" style="background:rgba(245,158,11,0.08);border:1px solid rgba(245,158,11,0.25);">
                    <div class="d-flex gap-2">
                        <i class="bi bi-lightbulb-fill flex-shrink-0 mt-1" style="color:#f59e0b;"></i>
                        <div>
                            <div class="fw-semibold" style="color:#f59e0b;">Dica de planejamento</div>
                            <div class="text-secondary small mt-1">Use contas recorrentes para pré-popular a agenda. Assim você abre o mês já sabendo exatamente o que vai entrar e sair — sem surpresas.</div>
                        </div>
                    </div>
                </div>

                <?php elseif ($topico === 'categorias'): ?>
                <!-- ═══ CATEGORIAS ════════════════════════════════════════════ -->
                <p class="text-secondary mb-4">Categorias organizam suas transações por tipo de gasto ou receita — Alimentação, Transporte, Moradia, Freelance, etc. Com categorias bem definidas, os gráficos em Análises ficam muito mais reveladores.</p>

                <h6 class="fw-bold text-light mb-3"><i class="bi bi-list-check me-2" style="color:var(--primary-gold-analysis);"></i>Como criar categorias</h6>
                <ol class="ps-3 text-secondary mb-4" style="line-height:2;">
                    <li>Na sidebar, clique em <strong class="text-light">Categorias</strong>.</li>
                    <li>Clique em <strong class="text-light">"+ Nova Categoria"</strong>.</li>
                    <li>Defina o nome, ícone e se é uma categoria de <strong class="text-light">receita</strong> ou <strong class="text-light">despesa</strong>.</li>
                    <li>Salve. A categoria aparecerá ao criar novas transações.</li>
                </ol>

                <h6 class="fw-bold text-light mb-3"><i class="bi bi-tags me-2" style="color:var(--primary-gold-analysis);"></i>Sugestões de categorias</h6>
                <div class="row g-2 mb-4">
                    <?php
                    $catSugestoes = [
                        ['nome'=>'Moradia','icone'=>'bi-house','cor'=>'#60a5fa'],
                        ['nome'=>'Alimentação','icone'=>'bi-cart3','cor'=>'#f59e0b'],
                        ['nome'=>'Transporte','icone'=>'bi-car-front','cor'=>'#a78bfa'],
                        ['nome'=>'Saúde','icone'=>'bi-heart-pulse','cor'=>'#f87171'],
                        ['nome'=>'Lazer','icone'=>'bi-controller','cor'=>'#34d399'],
                        ['nome'=>'Educação','icone'=>'bi-book','cor'=>'#22c55e'],
                        ['nome'=>'Salário','icone'=>'bi-briefcase','cor'=>'#22c55e'],
                        ['nome'=>'Freelance','icone'=>'bi-laptop','cor'=>'#f59e0b'],
                    ];
                    foreach ($catSugestoes as $cat): ?>
                    <div class="col-6 col-sm-4 col-md-3">
                        <div class="d-flex align-items-center gap-2 p-2 rounded-3" style="background:var(--bg-body);border:1px solid var(--card-border-color);">
                            <i class="bi <?= $cat['icone'] ?>" style="color:<?= $cat['cor'] ?>;font-size:0.9rem;"></i>
                            <span class="text-light small"><?= $cat['nome'] ?></span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <div class="rounded-3 p-3" style="background:rgba(245,158,11,0.08);border:1px solid rgba(245,158,11,0.25);">
                    <div class="d-flex gap-2">
                        <i class="bi bi-lightbulb-fill flex-shrink-0 mt-1" style="color:#f59e0b;"></i>
                        <div>
                            <div class="fw-semibold" style="color:#f59e0b;">Por que categorizar?</div>
                            <div class="text-secondary small mt-1">O gráfico de distribuição de despesas em Análises agrupa por categoria. Você vai descobrir rapidamente onde está gastando mais do que imagina.</div>
                        </div>
                    </div>
                </div>

                <?php elseif ($topico === 'analises'): ?>
                <!-- ═══ ANÁLISES E GRÁFICOS ══════════════════════════════════ -->
                <p class="text-secondary mb-4">A página de Análises reúne todos os dados das suas finanças em gráficos e resumos. Ela é atualizada automaticamente conforme você lança transações no Dashboard.</p>

                <h6 class="fw-bold text-light mb-3"><i class="bi bi-bar-chart me-2" style="color:var(--primary-gold-analysis);"></i>O que você encontra em Análises</h6>
                <div class="mb-4">
                    <div class="d-flex gap-3 mb-2 p-3 rounded-3" style="background:var(--bg-body);">
                        <i class="bi bi-graph-up flex-shrink-0 mt-1" style="color:#22c55e;"></i>
                        <div><span class="fw-semibold text-light">Receitas vs Despesas</span> <span class="text-secondary">— comparativo visual do mês, com seta indicando se melhorou ou piorou em relação ao mês anterior.</span></div>
                    </div>
                    <div class="d-flex gap-3 mb-2 p-3 rounded-3" style="background:var(--bg-body);">
                        <i class="bi bi-pie-chart flex-shrink-0 mt-1" style="color:#a78bfa;"></i>
                        <div><span class="fw-semibold text-light">Distribuição de Despesas</span> <span class="text-secondary">— gráfico de pizza por categoria, mostrando onde vai cada real gasto.</span></div>
                    </div>
                    <div class="d-flex gap-3 mb-2 p-3 rounded-3" style="background:var(--bg-body);">
                        <i class="bi bi-calendar-range flex-shrink-0 mt-1" style="color:#60a5fa;"></i>
                        <div><span class="fw-semibold text-light">Evolução Mensal</span> <span class="text-secondary">— linha do tempo dos últimos meses para identificar tendências.</span></div>
                    </div>
                    <div class="d-flex gap-3 p-3 rounded-3" style="background:var(--bg-body);">
                        <i class="bi bi-piggy-bank flex-shrink-0 mt-1" style="color:#f59e0b;"></i>
                        <div><span class="fw-semibold text-light">Cofrinhos</span> <span class="text-secondary">— progresso de cada cofrinho com barra visual e percentual de conclusão.</span></div>
                    </div>
                </div>

                <div class="rounded-3 p-3" style="background:rgba(245,158,11,0.08);border:1px solid rgba(245,158,11,0.25);">
                    <div class="d-flex gap-2">
                        <i class="bi bi-lightbulb-fill flex-shrink-0 mt-1" style="color:#f59e0b;"></i>
                        <div>
                            <div class="fw-semibold" style="color:#f59e0b;">Filtrando por carteira</div>
                            <div class="text-secondary small mt-1">No topo de Análises, você pode selecionar uma carteira específica. Assim os gráficos mostram apenas os dados daquela conta — útil para separar pessoal de empresarial.</div>
                        </div>
                    </div>
                </div>

                <?php elseif ($topico === 'empreendedores'): ?>
                <!-- ═══ RECOMENDAÇÃO: EMPREENDEDORES ═════════════════════════ -->
                <p class="text-secondary mb-4">Para quem tem um negócio próprio (CNPJ ou informal) e precisa parar de misturar o dinheiro da empresa com o pessoal. Combinação de recursos recomendada:</p>

                <div class="mb-4">
                    <div class="d-flex gap-3 mb-2 p-3 rounded-3" style="background:var(--bg-body);">
                        <i class="bi bi-wallet2 flex-shrink-0 mt-1" style="color:#60a5fa;"></i>
                        <div><span class="fw-semibold text-light">Carteira separada por conta</span> <span class="text-secondary">— crie uma <a href="?topico=carteiras" class="text-decoration-none fw-semibold" style="color:var(--primary-gold-analysis);">"Carteira Empresa"</a> e mantenha a pessoal separada. Nunca lance venda ou despesa do negócio na carteira pessoal.</span></div>
                    </div>
                    <div class="d-flex gap-3 mb-2 p-3 rounded-3" style="background:var(--bg-body);">
                        <i class="bi bi-arrow-left-right flex-shrink-0 mt-1" style="color:#60a5fa;"></i>
                        <div><span class="fw-semibold text-light">Pró-labore por transferência</span> <span class="text-secondary">— retire seu salário fazendo uma <a href="?topico=transferencia" class="text-decoration-none fw-semibold" style="color:var(--primary-gold-analysis);">Transferência</a> da carteira da empresa pra pessoal, num valor fixo mensal (ideal como recorrente).</span></div>
                    </div>
                    <div class="d-flex gap-3 mb-2 p-3 rounded-3" style="background:var(--bg-body);">
                        <i class="bi bi-tags flex-shrink-0 mt-1" style="color:#f59e0b;"></i>
                        <div><span class="fw-semibold text-light">Categorias como centro de custo</span> <span class="text-secondary">— crie <a href="?topico=categorias" class="text-decoration-none fw-semibold" style="color:var(--primary-gold-analysis);">categorias</a> tipo "Fornecedores", "Marketing", "Impostos" pra saber exatamente onde o dinheiro do negócio está indo.</span></div>
                    </div>
                    <div class="d-flex gap-3 mb-2 p-3 rounded-3" style="background:var(--bg-body);">
                        <i class="bi bi-bullseye flex-shrink-0 mt-1" style="color:#22c55e;"></i>
                        <div><span class="fw-semibold text-light">Orçamento nas despesas fixas</span> <span class="text-secondary">— defina <a href="?topico=metas" class="text-decoration-none fw-semibold" style="color:var(--primary-gold-analysis);">orçamentos por categoria</a> pras contas fixas do negócio e veja na hora quando alguma estourar.</span></div>
                    </div>
                    <div class="d-flex gap-3 p-3 rounded-3" style="background:var(--bg-body);">
                        <i class="bi bi-piggy-bank flex-shrink-0 mt-1" style="color:#f59e0b;"></i>
                        <div><span class="fw-semibold text-light">Reserva de impostos</span> <span class="text-secondary">— crie um <a href="?topico=cofrinhos" class="text-decoration-none fw-semibold" style="color:var(--primary-gold-analysis);">cofrinho</a> e guarde uma porcentagem de cada venda ali, pra nunca ser pego de surpresa no vencimento do imposto.</span></div>
                    </div>
                </div>

                <div class="rounded-3 p-3" style="background:rgba(245,158,11,0.08);border:1px solid rgba(245,158,11,0.25);">
                    <div class="d-flex gap-2">
                        <i class="bi bi-lightbulb-fill flex-shrink-0 mt-1" style="color:#f59e0b;"></i>
                        <div>
                            <div class="fw-semibold" style="color:#f59e0b;">Dica</div>
                            <div class="text-secondary small mt-1">Em Análises, filtre pela carteira da empresa pra ver a margem real do negócio — sem o pró-labore e as despesas pessoais misturados no gráfico.</div>
                        </div>
                    </div>
                </div>

                <?php elseif ($topico === 'freelancers'): ?>
                <!-- ═══ RECOMENDAÇÃO: FREELANCERS E AUTÔNOMOS ════════════════ -->
                <p class="text-secondary mb-4">Para quem vive de renda variável — sem salário fixo, com meses bons e meses fracos. O foco aqui é enxergar tendência e criar fôlego pros períodos de pouco trabalho.</p>

                <div class="mb-4">
                    <div class="d-flex gap-3 mb-2 p-3 rounded-3" style="background:var(--bg-body);">
                        <i class="bi bi-bullseye flex-shrink-0 mt-1" style="color:#22c55e;"></i>
                        <div><span class="fw-semibold text-light">Meta por cliente ou categoria de receita</span> <span class="text-secondary">— crie uma categoria por cliente fixo e defina uma <a href="?topico=metas" class="text-decoration-none fw-semibold" style="color:var(--primary-gold-analysis);">meta mensal</a>, pra saber se está batendo o mínimo que precisa faturar.</span></div>
                    </div>
                    <div class="d-flex gap-3 mb-2 p-3 rounded-3" style="background:var(--bg-body);">
                        <i class="bi bi-piggy-bank flex-shrink-0 mt-1" style="color:#f59e0b;"></i>
                        <div><span class="fw-semibold text-light">Reserva de entressafra</span> <span class="text-secondary">— num <a href="?topico=cofrinhos" class="text-decoration-none fw-semibold" style="color:var(--primary-gold-analysis);">cofrinho</a>, guarde uma % de cada recebimento nos meses bons pra cobrir os meses fracos sem sufoco.</span></div>
                    </div>
                    <div class="d-flex gap-3 mb-2 p-3 rounded-3" style="background:var(--bg-body);">
                        <i class="bi bi-arrow-repeat flex-shrink-0 mt-1" style="color:#f59e0b;"></i>
                        <div><span class="fw-semibold text-light">Assinaturas e ferramentas como recorrente</span> <span class="text-secondary">— lance suas <a href="?topico=recorrentes" class="text-decoration-none fw-semibold" style="color:var(--primary-gold-analysis);">contas recorrentes</a> (softwares, plataformas) uma vez só e não esqueça mais delas.</span></div>
                    </div>
                    <div class="d-flex gap-3 mb-2 p-3 rounded-3" style="background:var(--bg-body);">
                        <i class="bi bi-graph-up flex-shrink-0 mt-1" style="color:#60a5fa;"></i>
                        <div><span class="fw-semibold text-light">Evolução mensal</span> <span class="text-secondary">— em <a href="?topico=analises" class="text-decoration-none fw-semibold" style="color:var(--primary-gold-analysis);">Análises</a>, olhe a tendência dos últimos meses, não só o mês isolado — renda variável engana quando vista mês a mês.</span></div>
                    </div>
                    <div class="d-flex gap-3 p-3 rounded-3" style="background:var(--bg-body);">
                        <i class="bi bi-graph-up-arrow flex-shrink-0 mt-1" style="color:#a78bfa;"></i>
                        <div><span class="fw-semibold text-light">Orçamento de despesa mais apertado</span> <span class="text-secondary">— como a entrada varia, controlar o teto de gasto fixo importa ainda mais. Defina orçamento nas categorias de despesa recorrentes.</span></div>
                    </div>
                </div>

                <div class="rounded-3 p-3" style="background:rgba(245,158,11,0.08);border:1px solid rgba(245,158,11,0.25);">
                    <div class="d-flex gap-2">
                        <i class="bi bi-lightbulb-fill flex-shrink-0 mt-1" style="color:#f59e0b;"></i>
                        <div>
                            <div class="fw-semibold" style="color:#f59e0b;">Dica</div>
                            <div class="text-secondary small mt-1">Some pelo menos 3 meses de histórico antes de confiar na "Evolução Mensal" — renda de autônomo costuma ter picos e vales que só fazem sentido olhados em conjunto.</div>
                        </div>
                    </div>
                </div>

                <?php elseif ($topico === 'familia'): ?>
                <!-- ═══ RECOMENDAÇÃO: CASAIS E FAMÍLIA ═══════════════════════ -->
                <p class="text-secondary mb-4">Para dividir contas da casa sem perder a visão do que é gasto individual — cada um mantém a carteira pessoal, e uma carteira compartilhada de verdade cuida do que é comum.</p>

                <div class="mb-4">
                    <div class="d-flex gap-3 mb-2 p-3 rounded-3" style="background:var(--bg-body);">
                        <i class="bi bi-people-fill flex-shrink-0 mt-1" style="color:#60a5fa;"></i>
                        <div><span class="fw-semibold text-light">Carteira da casa compartilhada de verdade</span> <span class="text-secondary">— crie uma <a href="?topico=compartilhadas" class="text-decoration-none fw-semibold" style="color:var(--primary-gold-analysis);">carteira compartilhada</a> (Pro ou VIP) só pras despesas do lar, e convide seu par pelo código dele. Os dois veem e lançam nela, sem precisar ficar transferindo nada.</span></div>
                    </div>
                    <div class="d-flex gap-3 mb-2 p-3 rounded-3" style="background:var(--bg-body);">
                        <i class="bi bi-arrow-left-right flex-shrink-0 mt-1" style="color:#60a5fa;"></i>
                        <div><span class="fw-semibold text-light">Contribuição mensal por transferência</span> <span class="text-secondary">— se preferir cada um "depositar" um valor fixo na carteira da casa em vez de lançar direto, use <a href="?topico=transferencia" class="text-decoration-none fw-semibold" style="color:var(--primary-gold-analysis);">transferência</a> da conta pessoal (ideal como recorrente).</span></div>
                    </div>
                    <div class="d-flex gap-3 mb-2 p-3 rounded-3" style="background:var(--bg-body);">
                        <i class="bi bi-tags flex-shrink-0 mt-1" style="color:#f59e0b;"></i>
                        <div><span class="fw-semibold text-light">Categorias da casa</span> <span class="text-secondary">— "Mercado", "Filhos", "Contas da Casa" já nascem <a href="?topico=categorias" class="text-decoration-none fw-semibold" style="color:var(--primary-gold-analysis);">na própria carteira compartilhada</a> (quem cria é o dono), pra saber onde o orçamento comum está sendo gasto.</span></div>
                    </div>
                    <div class="d-flex gap-3 mb-2 p-3 rounded-3" style="background:var(--bg-body);">
                        <i class="bi bi-bullseye flex-shrink-0 mt-1" style="color:#22c55e;"></i>
                        <div><span class="fw-semibold text-light">Orçamento combinado</span> <span class="text-secondary">— defina <a href="?topico=metas" class="text-decoration-none fw-semibold" style="color:var(--primary-gold-analysis);">orçamentos</a> nas categorias da casa pra não estourar o que foi combinado entre vocês.</span></div>
                    </div>
                    <div class="d-flex gap-3 p-3 rounded-3" style="background:var(--bg-body);">
                        <i class="bi bi-piggy-bank flex-shrink-0 mt-1" style="color:#f59e0b;"></i>
                        <div><span class="fw-semibold text-light">Metas em conjunto</span> <span class="text-secondary">— um <a href="?topico=cofrinhos" class="text-decoration-none fw-semibold" style="color:var(--primary-gold-analysis);">cofrinho</a> pra viagem em família ou reforma, alimentado pelos dois.</span></div>
                    </div>
                </div>

                <div class="rounded-3 p-3" style="background:rgba(245,158,11,0.08);border:1px solid rgba(245,158,11,0.25);">
                    <div class="d-flex gap-2">
                        <i class="bi bi-lightbulb-fill flex-shrink-0 mt-1" style="color:#f59e0b;"></i>
                        <div>
                            <div class="fw-semibold" style="color:#f59e0b;">Dica</div>
                            <div class="text-secondary small mt-1">Cada transação na carteira da casa mostra quem lançou — dá pra ver o extrato completo sem perder de vista quem pagou o quê, sem precisar de planilha à parte.</div>
                        </div>
                    </div>
                </div>

                <?php elseif ($topico === 'estudantes'): ?>
                <!-- ═══ RECOMENDAÇÃO: ESTUDANTES ═════════════════════════════ -->
                <p class="text-secondary mb-4">Para quem vive de mesada, bolsa ou os primeiros freelas — pouco volume de dinheiro, mas o momento certo de criar o hábito de acompanhar cada real.</p>

                <div class="mb-4">
                    <div class="d-flex gap-3 mb-2 p-3 rounded-3" style="background:var(--bg-body);">
                        <i class="bi bi-tags flex-shrink-0 mt-1" style="color:#f59e0b;"></i>
                        <div><span class="fw-semibold text-light">Categorias simples</span> <span class="text-secondary">— comece só com o essencial em <a href="?topico=categorias" class="text-decoration-none fw-semibold" style="color:var(--primary-gold-analysis);">Categorias</a>: Alimentação, Transporte, Lazer, Estudos.</span></div>
                    </div>
                    <div class="d-flex gap-3 mb-2 p-3 rounded-3" style="background:var(--bg-body);">
                        <i class="bi bi-bullseye flex-shrink-0 mt-1" style="color:#22c55e;"></i>
                        <div><span class="fw-semibold text-light">Orçamento pra não estourar a mesada</span> <span class="text-secondary">— defina um <a href="?topico=metas" class="text-decoration-none fw-semibold" style="color:var(--primary-gold-analysis);">limite mensal</a> pra Lazer e Alimentação fora de casa, as categorias que mais pegam no fim do mês.</span></div>
                    </div>
                    <div class="d-flex gap-3 mb-2 p-3 rounded-3" style="background:var(--bg-body);">
                        <i class="bi bi-piggy-bank flex-shrink-0 mt-1" style="color:#f59e0b;"></i>
                        <div><span class="fw-semibold text-light">Metas pequenas em cofrinho</span> <span class="text-secondary">— celular novo, curso, viagem de formatura: um <a href="?topico=cofrinhos" class="text-decoration-none fw-semibold" style="color:var(--primary-gold-analysis);">cofrinho</a> com meta e data ajuda a visualizar o progresso.</span></div>
                    </div>
                    <div class="d-flex gap-3 p-3 rounded-3" style="background:var(--bg-body);">
                        <i class="bi bi-calendar3 flex-shrink-0 mt-1" style="color:#60a5fa;"></i>
                        <div><span class="fw-semibold text-light">Agenda pra fechar o mês</span> <span class="text-secondary">— antes de gastar o que sobrou, confira a <a href="?topico=agenda" class="text-decoration-none fw-semibold" style="color:var(--primary-gold-analysis);">Agenda</a> pra ver o que ainda falta pagar até o fim do mês.</span></div>
                    </div>
                </div>

                <div class="rounded-3 p-3" style="background:rgba(245,158,11,0.08);border:1px solid rgba(245,158,11,0.25);">
                    <div class="d-flex gap-2">
                        <i class="bi bi-lightbulb-fill flex-shrink-0 mt-1" style="color:#f59e0b;"></i>
                        <div>
                            <div class="fw-semibold" style="color:#f59e0b;">Dica</div>
                            <div class="text-secondary small mt-1">Não precisa criar categoria pra tudo. Comece com poucas, bem usadas — dá pra criar novas categorias a qualquer momento conforme sentir necessidade.</div>
                        </div>
                    </div>
                </div>

                <?php endif; ?>

                <!-- Navegação entre tópicos -->
                <?php
                $keys = array_keys($topicos);
                $idx  = array_search($topico, $keys);
                $prev = $idx > 0 ? $keys[$idx - 1] : null;
                $next = $idx < count($keys) - 1 ? $keys[$idx + 1] : null;
                ?>
                <div class="d-flex justify-content-between mt-5 pt-4" style="border-top:1px solid var(--card-border-color);">
                    <?php if ($prev): ?>
                        <a href="?topico=<?= $prev ?>" class="btn btn-sm btn-outline-secondary rounded-pill d-flex align-items-center gap-2" style="font-size:0.8rem;">
                            <i class="bi bi-arrow-left"></i>
                            <?= htmlspecialchars($topicos[$prev]['titulo']) ?>
                        </a>
                    <?php else: ?>
                        <div></div>
                    <?php endif; ?>
                    <?php if ($next): ?>
                        <a href="?topico=<?= $next ?>" class="btn btn-sm rounded-pill d-flex align-items-center gap-2"
                            style="background:var(--primary-gold-analysis)18;color:var(--primary-gold-analysis);border:1px solid var(--primary-gold-analysis)33;font-size:0.8rem;">
                            <?= htmlspecialchars($topicos[$next]['titulo']) ?>
                            <i class="bi bi-arrow-right"></i>
                        </a>
                    <?php endif; ?>
                </div>

            </div>
        </div>

    </div>
</main>

<?php require_once 'geral/footer.php'; ?>
