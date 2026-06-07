# Auralis - Gestão Financeira Inteligente 🪙

O **Auralis** é um ecossistema de gestão financeira pessoal desenvolvido para oferecer controle total sobre fluxos de caixa, contas recorrentes e análise de patrimônio. Projetado com a visão de se tornar um produto comercial (SaaS), o sistema foca em usabilidade fluida, segurança de dados e automação de processos repetitivos para famílias e pequenos empreendedores.

---

## 🚀 Funcionalidades Principais

* **Modelo Freemium e Assinaturas:** Sistema de planos de acesso (`Free`, `PRO`, `VIP`) e período Trial (50h), com integração à **API do Mercado Pago** para gestão de pagamentos e renovações.
* **Autenticação Avançada e SSO:** Login e cadastro nativos com verificação de e-mail (tokens de uso único) e integração de Single Sign-On (SSO) com **Google OAuth 2.0**.
* **Motor de Recorrência e Parcelamentos:** Sistema automático que detecta a virada do mês e clona transações recorrentes. Possui cálculo de **parcelamentos com ou sem juros**, projetando as faturas automaticamente no calendário.
* **Gestão Multi-Carteiras:** Criação de múltiplos espaços financeiros independentes, com capacidade de transferência e mesclagem de dados entre contas.
* **Painel de Controle e Análises:** Visão em tempo real de saldos e gráficos interativos (Chart.js) de distribuição de gastos, com identificação de "Fuga de Capital" e "Motores de Renda".
* **Segurança e Conformidade LGPD:** Criptografia de senhas (`password_hash`), recuperação segura por e-mail e exclusão definitiva de conta (apagando dados em cascata de forma segura).

---

## 🛠️ Tecnologias Utilizadas

* **Linguagem Back-end:** PHP 8.x (Orientado a objetos e PDO)
* **Banco de Dados:** MySQL / MariaDB (Arquitetura relacional)
* **Integrações (APIs):** cURL PHP (Google API, Mercado Pago API) e SMTP
* **Front-end:** HTML5, CSS3, JavaScript (ES6+)
* **Framework UI:** Bootstrap 5.3 (Modo Escuro / Dark Theme nativo)
* **Bibliotecas Externas:**
  * Chart.js (Visualização de dados avançada)
  * Cleave.js (Máscaras de inputs monetários e datas)
  * Bootstrap Icons & Uicons/Flaticon (Tipografia e iconografia visual)

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
├── planos.php          # Checkout e upgrade de assinaturas (Mercado Pago)
└── configuracoes.php   # Perfil do usuário, troca de senha e Zona de Exclusão
