# Auralis - Gestão Financeira Inteligente 🪙

O **Auralis** é um ecossistema de gestão financeira pessoal desenvolvido para oferecer controle total sobre fluxos de caixa, contas recorrentes e análise de patrimônio. Projetado com a visão de se tornar um produto comercial (SaaS), o sistema foca em usabilidade fluida, segurança de dados e automação de processos repetitivos para famílias e pequenos empreendedores.

---

## 🚀 Funcionalidades Principais

* **Modelo Freemium e Assinaturas:** Sistema de planos de acesso (`Free`, `PRO`, `VIP`) e período Trial (50h), com integração à **API do Mercado Pago** para gestão de pagamentos e renovações.
* **Autenticação Avançada e SSO:** Login e cadastro nativos com verificação de e-mail (tokens de uso único) e integração de Single Sign-On (SSO) com **Google OAuth 2.0**, incluindo login por biometria (Face ID / Windows Hello / digital via WebAuthn).
* **Motor de Recorrência Flexível:** Transações recorrentes a cada X dias, semanas ou meses (não só mensal fixo), com motor de reabastecimento automático e parcelamentos com ou sem juros.
* **Cartão de Crédito:** Cadastro de cartões com dia de fechamento/vencimento próprios, geração automática de faturas por ciclo, fechamento automático e lançamentos parcelados vinculados à fatura certa.
* **Cofrinhos (metas de economia):** Reservas de dinheiro dentro de uma carteira, com meta de valor e prazo opcionais.
* **Gestão Multi-Carteiras e Carteiras Compartilhadas:** Múltiplos espaços financeiros independentes, incluindo carteiras compartilhadas entre várias pessoas (dono + convidados) com log de atividade.
* **Assistente de IA no WhatsApp:** Registro de transações, consultas de saldo e edição de lançamentos por conversa natural no WhatsApp (Gemini), incluindo leitura de comprovantes enviados como imagem.
* **Programa de Indicação, Revendedores e Pioneiros:** Sistema de indicação com recompensa em dias grátis ou comissão em dinheiro (configurável), categoria especial de Revendedor para divulgadores, e numeração permanente de "Pioneiro" para os primeiros assinantes VIP.
* **Conquistas e Ranking:** Badges por marcos de uso (quantidade de lançamentos, comprovantes, categorias, tempo de conta, indicações etc.) e ranking dos top 10 usuários em várias categorias.
* **Notificações In-App e Web Push:** Central de notificações dentro do app e notificações reais no celular/computador (mesmo com o app fechado), via Service Worker.
* **Painel de Controle e Análises:** Visão em tempo real de saldos e gráficos interativos (Chart.js) de distribuição de gastos, com identificação de "Fuga de Capital" e "Motores de Renda".
* **Exportação de Dados:** Exportação de transações, faturas e análises em CSV e PDF.
* **Segurança e Conformidade LGPD:** Criptografia de senhas (`password_hash`), recuperação segura por e-mail e exclusão definitiva de conta (apagando dados em cascata de forma segura).

---

## 🛠️ Tecnologias Utilizadas

* **Linguagem Back-end:** PHP 8.x (Orientado a objetos e PDO)
* **Banco de Dados:** MySQL / MariaDB (Arquitetura relacional)
* **Integrações (APIs):** cURL PHP (Google API, Mercado Pago API, Google Gemini API para IA do WhatsApp, Evolution API para envio de mensagens WhatsApp) e SMTP
* **Notificações:** Web Push (VAPID) via `minishlink/web-push`
* **Front-end:** HTML5, CSS3, JavaScript (ES6+), PWA (Service Worker + manifest)
* **Framework UI:** Bootstrap 5.3 (Modo Escuro / Dark Theme nativo)
* **Bibliotecas Externas:**
  * Chart.js (Visualização de dados avançada)
  * Cleave.js (Máscaras de inputs monetários e datas)
  * Bootstrap Icons & Uicons/Flaticon (Tipografia e iconografia visual)

---

## 📦 Estrutura do Projeto

```text
/Auralis
├── admin/               # Painel administrativo (usuários, revendedores, indicações, conquistas, notificações)
├── cartao_credito/      # Cartões de crédito e faturas
├── carteira/            # Gestão de carteiras (própria e compartilhadas), mesclagem e edição
├── cofrinho/            # Metas de economia (cofrinhos)
├── comprovante/         # Upload e visualização de comprovantes anexados aos lançamentos
├── config/              # Conexão com banco (PDO), funções globais, chaves/segredos (fora do git)
├── cron/                # Jobs agendados (recorrência, notificações WhatsApp/push, verificação de vencimentos)
├── geral/                # Componentes estruturais (Header, Footer, CSS, Imagens, landing page)
├── notificacoes/        # Central de notificações in-app e assinatura de Web Push
├── revendedor/          # Autoatendimento do revendedor (link, saldo, extrato)
├── usuario/              # Autenticação (Login, SSO, biometria, Cadastro, Recuperação)
├── vendor/               # Dependências PHP (Composer) — commitado, pois o servidor não roda `composer install`
├── dashboard.php         # Painel central, Onboarding, motor de recorrência e agenda
├── nova_transacao.php    # Criação/edição de lançamentos (simples, parcelado, recorrente, cartão)
├── analises.php          # Processamento de estatísticas e renderização de gráficos
├── agenda.php            # Contas a pagar/receber e faturas de cartão pendentes
├── ranking.php           # Ranking de usuários (top 10 em várias categorias)
├── perfil.php            # Perfil, conquistas e insígnias em destaque
├── planos.php            # Checkout e upgrade de assinaturas (Mercado Pago)
├── configuracoes.php     # Perfil do usuário, troca de senha, indicação e Zona de Exclusão
├── webhook_mercadopago.php  # Confirmação passiva de pagamentos (Mercado Pago)
├── webhook_whatsapp_ia.php  # Assistente de IA via WhatsApp (Gemini)
└── sw.js                 # Service Worker (Web Push + PWA)
```

---

## 📌 Lembretes — Como os Principais Sistemas Funcionam

Esta seção é um resumo rápido de "onde mexer" e "como cada engrenagem funciona" — útil pra relembrar decisões de design conforme o sistema cresce, sem precisar reler todo o código.

### 1. Recorrência Flexível

**O que faz:** Cria transações que se repetem a cada X dias, semanas ou meses (não só "todo mês", como era antes).

**Como funciona:** `Registro` tem `TipoRecorrencia` (`dias`/`semanas`/`meses`), `IntervaloRecorrencia` e `RecorrenciaAtiva`. Uma série é criada de uma vez em lote (não uma linha por vez) por `criarSerieRecorrente()`, sempre com um `GrupoParcela` real ligando as ocorrências. O motor `reabastecerRecorrencias()` roda 1x por usuário/dia (throttle em `ConfiguracaoSistema.ultima_recorrencia`) e completa novas ocorrências à medida que o tempo passa, ancorado pelo `GrupoParcela` (não mais "olhar o mês anterior").

**Arquivos:** `config/funcoes.php` (`calcularProximaOcorrenciaRecorrente`, `criarSerieRecorrente`, `reabastecerRecorrencias`), `dashboard.php` (dispara o reabastecimento), `nova_transacao.php` (UI de criação), `cron/reabastecer_recorrencias.php` (cron diário de backup).

**Ponto de atenção:** `RecorrenciaAtiva = 0` é o que marca uma série como "encerrada" (excluir "este e os futuros") — sem isso, o motor recriaria a série pra sempre.

### 2. Cartão de Crédito e Faturas

**O que faz:** Cada cartão tem dia de fechamento e vencimento próprios; o sistema gera e fecha as faturas (`FaturaCartao`) sozinho conforme o tempo passa.

**Como funciona:** `cartao_obterFaturaAberta()` retorna (ou cria) a fatura do ciclo atual. `cartao_verificarFechamentos()` roda a cada carregamento de página e fecha automaticamente faturas cujo `DataFechamento` já passou, criando a próxima e gerando um lançamento "pendente" (a cobrança) na carteira de débito do cartão. Uma fatura fechada pode ser reaberta pra edição (`cartao_reabrirFatura()`) — nesse caso, ela e a fatura do ciclo atual ficam as duas com `Status='aberta'` ao mesmo tempo por um instante, por isso a busca da fatura atual sempre pega a de fechamento **mais recente**.

**Arquivos:** `config/funcoes_cartao.php` (toda a lógica), `cartao_credito/index.php` (lista de cartões), `cartao_credito/fatura.php` (detalhe de uma fatura).

**Tabelas:** `CartaoCredito`, `FaturaCartao` (`Status`: aberta/fechada/paga), `LancamentoCartao`.

**Ponto de atenção:** o total gasto (`Usado X% do limite`) é sempre da fatura **atual**, não soma faturas antigas em aberto por reabertura manual.

### 3. Cofrinhos

**O que faz:** Reserva de dinheiro dentro de uma carteira, com meta de valor e prazo opcionais (ex: "Viagem — R$ 5.000 até dezembro").

**Como funciona:** Não existe um "saldo" guardado — cada depósito/retirada é uma linha normal na tabela `Registro` (com `FKCofrinho` preenchido e `TipoRegistro` = `cofrinho` ou `cofrinho_retirada`), e o saldo do cofrinho é sempre a soma dessas linhas, calculada na hora. Bater a meta concede a conquista `metabatida`.

**Arquivos:** `cofrinho/processa_cofrinho.php`; também dá pra depositar direto pelo WhatsApp (`_waCofrinhoDepositar` em `webhook_whatsapp_ia.php`).

**Tabela:** `Cofrinho` (Nome, Icone, Cor, ValorMeta, DataLimite).

### 4. Carteiras Compartilhadas

**O que faz:** Permite que várias pessoas usem a mesma carteira — um "dono" e vários "convidados".

**Como funciona:** Convite é feito digitando o código pessoal (`CodigoIndicacao`) de quem você quer convidar — o mesmo código usado pra indicação de amigos e revendedor. O convidado aceita ou recusa em `carteira/listar_carteiras.php`. O papel de cada pessoa (`dono`/`convidado`) e os limites de quantos convidados cabem (de acordo com o **plano do dono**, não do convidado) ficam em `config/funcoes.php`. Toda ação relevante (lançamento criado, membro entrou/saiu) fica registrada num log que só o dono vê.

**Arquivos:** `config/funcoes.php` (`garantirEstruturaCarteirasCompartilhadas`, `carteiraPapelDoUsuario`, `planoEfetivoDaCarteira`, `logAtividadeCarteira`), `carteira/administrar_carteira.php`.

**Tabelas:** `Carteira.Compartilhada`, `MembroCarteira` (`StatusConvite`: pendente/ativo), `LogAtividadeCarteira`.

### 5. Planos e Assinaturas (Mercado Pago)

**O que faz:** Vende os planos PRO e VIP (mensal/anual) via Mercado Pago.

**Como funciona:** Existem dois caminhos que confirmam um pagamento: o usuário volta pro site depois de pagar (`sucesso_pagamento.php`, busca ativamente) e o Mercado Pago avisa por conta própria (`webhook_mercadopago.php`, passivo — o mais confiável dos dois, pois não depende do usuário completar o redirecionamento). Os dois chamam a mesma função central, `mpAtivarPlano()`, que decide sozinha se é uma assinatura nova, uma renovação (mesmo `IDAssinaturaGW`) ou um upgrade no meio do ciclo (credita os dias que sobraram do plano antigo). `Usuario.Plano` é o campo rápido que o resto do sistema lê pra liberar/bloquear feature; `Assinatura` é o histórico completo.

**Arquivos:** `planos.php`, `sucesso_pagamento.php`, `webhook_mercadopago.php`, `config/funcoes.php` (`mpAtivarPlano`, `obterPlanoEfetivo`, `obterHorasRestantesTeste`).

**Ponto de atenção:** o Trial de 50h não fica guardado em lugar nenhum — é calculado na hora a partir de `Usuario.MomentoCriacao` (data de cadastro).

### 6. Assistente de IA no WhatsApp

**O que faz:** Deixa registrar gastos/receitas, consultar saldo, editar e excluir lançamentos e ler comprovantes (foto) direto pelo WhatsApp, em linguagem natural.

**Como funciona:** Toda mensagem chega em `webhook_whatsapp_ia.php`, que manda o texto (e a imagem, se tiver) pro Gemini junto com o contexto financeiro do usuário (carteiras, categorias, últimos lançamentos — pra evitar a IA "inventar" dados). O Gemini devolve uma ou mais "ações" num JSON (`registrar`, `editar`, `consultar`, `cofrinho_depositar` etc.), e cada ação tem sua própria função `_wa*()` que executa no banco.

**Arquivos:** `webhook_whatsapp_ia.php` (arquivo único e grande — concentra prompt, parsing e todas as ações).

**Pontos de atenção:**

* Esse arquivo tem credenciais reais de produção compartilhadas entre os ambientes — por isso, mudanças aqui costumam ir direto pra `main` (não passam por `beta` primeiro), pra reduzir o tempo com beta/main desalinhados nesse arquivo específico.
* `MomentoRegistro` (data real da transação, usada pra separar por dia no dashboard) e `DataVencimento` precisam **sempre** ser setados juntos — um bug de esquecer o primeiro fazia comprovantes de dias atrás aparecerem sempre "hoje" (corrigido em 07/08/2026).

### 7. Indicação, Revendedores e Programa Pioneiros

**O que faz:** Todo usuário pode indicar amigos com seu código pessoal. O que a pessoa ganha por isso — dias grátis de plano ou comissão real em dinheiro — é configurável pelo admin. Existe também uma categoria de "Revendedor" (pequenos divulgadores), com comissão mais agressiva, cadastrada manualmente. E um número permanente de "Pioneiro" pros primeiros assinantes VIP.

**Como funciona:**

* `processarIndicacaoConversao()` é chamada toda vez que um pagamento é confirmado (dentro de `mpAtivarPlano()`) e decide o caminho: se o indicador é Revendedor (`Categoria='revendedor'` na tabela `Revendedor`) ou indicação comum (`Categoria='normal'`, autocriada na primeira comissão se o modo "dinheiro" estiver ativo).
* A **primeira** comissão de cada indicador é sempre marcada como prioritária (`EhPrimeiraComissao=1`) e dispara um aviso urgente por WhatsApp pro admin — a ideia é pagar na hora pra provar que o sistema funciona. As comissões seguintes acumulam até bater um valor mínimo configurável (`admin/revendedores.php`, chave `comissao_valor_minimo_saque`).
* O pagamento em si é **manual** — o admin marca como "paga" no painel depois de fazer o PIX. Não existe envio automático de PIX ainda (decisão deliberada, ver seção de negócio abaixo).
* "Pioneiro" é atribuído automaticamente (`atribuirPioneiroSeElegivel()`) na primeira vez que alguém vira VIP, respeitando um limite de vagas (`ConfiguracaoSistema.pioneiros_vagas_totais`), e reaproveita o sistema de conquistas pra exibição.

**Arquivos:** `config/funcoes.php` (`processarIndicacaoConversao`, `processarComissaoUsuarioComum`, `determinarPercentualRevendedor`, `atribuirPioneiroSeElegivel`, `avisarAdminComissaoUrgente`), `admin/revendedores.php`, `admin/indicacoes.php` (liga/desliga o modo dinheiro).

**Tabelas:** `Revendedor` (`Categoria`, `TipoComissao`, `GatilhoParte1`: clientes ou dias), `RevendedorCliente` (trava o % de cada cliente na 1ª compra, pra sempre), `ComissaoRevendedor` (`Status`: pendente/paga, `EhPrimeiraComissao`), `Pioneiro` (numeração automática).

**Decisão de negócio:** o envio automático de PIX (sem intervenção manual) está propositalmente pausado por enquanto — decisão explícita, não esquecimento. Reavaliar se/quando o programa escalar ou existir CNPJ/conta compartilhada com o sócio.

### 8. Conquistas e Ranking

**O que faz:** Badges por marcos de uso (quantidade de lançamentos, comprovantes, categorias, dias de conta, indicações, plano VIP/PRO etc.) e um ranking dos top 10 usuários em 5 categorias diferentes.

**Como funciona:** `concederConquistaParaUsuario($pdo, $uid, $slug)` é a função central — é **idempotente** (não concede duas vezes) e o próprio retorno dela (`true`/`false`) serve pra saber se "essa é a primeira vez" que algo aconteceu (é assim que o Pioneiro sabe quando alguém virou VIP pela primeira vez). O ranking não guarda nota nenhuma — cada categoria é uma consulta ao vivo (`COUNT`/`SUM` + `LIMIT 10`) toda vez que a página carrega.

**Arquivos:** `config/funcoes.php` (`concederConquistaParaUsuario`, `verificarConquistasAutomaticas`), `config/conquistas_regras.php` (limites de cada marco), `ranking.php`, `perfil.php` (até 3 insígnias em destaque, escolhidas pelo usuário).

**Tabelas:** `conquista` (catálogo — Slug, Nome, Raridade), `usuario_conquista` (quem já ganhou o quê).

### 9. Notificações (in-app + Web Push)

**O que faz:** Dois canais paralelos e independentes: um sino dentro do app (histórico) e notificações de verdade no celular/computador, mesmo com o app fechado.

**Como funciona:** `criarNotificacaoSistema()` cria a notificação in-app (com uma janela de "de-duplicação" pra não repetir o mesmo aviso). Pra push de verdade, o navegador se inscreve (`notificacoes/salvar_subscription.php` guarda o "endereço" da inscrição), e `enviarPushParaUsuario()` manda pra esse endereço usando as chaves VAPID — o `sw.js` (Service Worker) é quem efetivamente mostra a notificação no aparelho e trata o clique nela. **Os dois canais não se disparam automaticamente um ao outro** — quem quiser os dois, chama as duas funções.

**Arquivos:** `config/funcoes.php` (`criarNotificacaoSistema`), `config/web_push.php` (`enviarPushParaUsuario`), `notificacoes/_widget.php` (sino), `sw.js`, crons como `cron/verificar_vencimentos_push.php` (lembretes de conta vencendo hoje).

**Tabelas:** `Notificacao`/`NotificacaoLeitura` (in-app), `PushSubscription` (inscrições de push).

### 10. Deploy e Ambientes

**O que faz:** Existem dois ambientes — `beta.meuauralis.com` (teste) e `meuauralis.com` (produção) — sincronizados automaticamente ao dar `push` no GitHub.

**Como funciona:** GitHub Actions manda os arquivos por FTP pra cada ambiente conforme a branch (`beta` → beta, `main` → produção). **Não existe acesso SSH** ao servidor — nada de rodar comando direto lá. Por causa disso:

* `vendor/` (dependências do Composer) fica commitado no repositório — é a única forma de "instalar" biblioteca PHP em produção.
* Mudanças de banco de dados usam funções `garantirTabelaX()`/`garantirEstruturaX()` (checam e criam/alteram a tabela sozinhas, chamadas no topo das páginas que precisam) em vez de migrations tradicionais.
* Arquivos com credenciais reais (`config/conexao.php`, `config/vapid_keys.php`, `config/mercadopago_keys.php`, `config/gemini.php`, `usuario/chaves_google.php`) **não estão no git** (`.gitignore`) — já existem prontos em cada servidor, com valores diferentes entre beta e produção. Qualquer mudança nesses arquivos específicos precisa ser aplicada manualmente via cPanel em cada ambiente — o deploy automático nunca toca neles.
