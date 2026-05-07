# Auralis - Gestão Financeira Inteligente 🪙

O **Auralis** é um ecossistema de gestão financeira pessoal desenvolvido para oferecer controle total sobre fluxos de caixa, contas recorrentes e análise de patrimônio. Projetado com a visão de se tornar um produto comercial (SaaS), o sistema foca em usabilidade fluida, segurança de dados e automação de processos repetitivos para famílias e pequenos empreendedores.

---

## 🚀 Funcionalidades Principais

* **Autenticação Avançada e SSO:** Login e cadastro nativos com verificação de e-mail (tokens de uso único) e integração de Single Sign-On (SSO) com **Google OAuth 2.0**.
* **Gestão Multi-Carteiras:** Criação de múltiplos espaços financeiros independentes, com capacidade de transferência e mesclagem de dados entre contas.
* **Onboarding Inteligente:** Fluxo de boas-vindas para novos usuários, garantindo a criação da primeira carteira e definição do saldo inicial sem fricções.
* **Motor de Recorrência (Cron Nativo):** Sistema automático que detecta a virada do mês no carregamento do dashboard e clona transações marcadas como recorrentes, poupando trabalho manual.
* **Painel de Controle e Análises:** Visão em tempo real de saldos e gráficos interativos de distribuição de gastos e ganhos utilizando Chart.js, com filtros por carteira e período.
* **Segurança Reforçada:** Criptografia de senhas com `password_hash`, sistema de recuperação de acesso seguro por e-mail e persistência de sessão protegida contra falsificações.
* **Conformidade LGPD (Zona de Risco):** Gestão completa de perfil com opção de exclusão definitiva de conta, apagando dados em cascata de forma segura e adaptada para usuários de credencial própria ou do Google.

---

## 🛠️ Tecnologias Utilizadas

* **Linguagem Back-end:** PHP 8.x (Orientado a objetos e PDO)
* **Banco de Dados:** MySQL / MariaDB (Arquitetura relacional)
* **Integrações (APIs):** cURL PHP (Google API) e SMTP (E-mails Transacionais)
* **Front-end:** HTML5, CSS3, JavaScript (ES6+)
* **Framework UI:** Bootstrap 5.3 (Modo Escuro / Dark Theme nativo)
* **Bibliotecas Externas:**
  * Chart.js (Visualização de dados)
  * Cleave.js (Máscaras de inputs monetários e datas)
  * Bootstrap Icons (Tipografia visual)

---

## 📦 Estrutura do Projeto

```text
/Auralis
├── config/             # Configurações globais e de conexão com o banco (PDO)
├── geral/              # Componentes estruturais (Header, Footer, CSS, Imagens)
├── usuario/            # Lógica de Autenticação (Login, SSO, Cadastro, Recuperação)
├── carteira/           # Gestão isolada de contas, mesclagem e edição
├── dashboard.php       # Painel central, Onboarding e Motor de Recorrência
├── analises.php        # Processamento de estatísticas e renderização de gráficos
└── configuracoes.php   # Perfil do usuário, troca de senha e Zona de Exclusão
```

## 🧠 Arquitetura e Regras de Negócio (Para Desenvolvedores)

* **Usuários Google (SSO):** Usuários cadastrados via Google recebem a tag `GOOGLE_SSO` no banco de dados. O sistema identifica essa tag para ocultar campos de senha atual e permitir a criação de uma senha manual nativa a qualquer momento.
* **Validação de Saldo Zero:** O backend está preparado para aceitar valores `0` (`isset` e `!== ''`) no saldo inicial, evitando o bloqueio da função `empty()` em inícios de jornada zerados.
* **Exclusão em Cascata:** A exclusão de uma conta aciona uma transação PDO rigorosa, deletando subcategorias, rateios, transações e carteiras antes de remover o usuário, prevenindo dados órfãos no banco.
