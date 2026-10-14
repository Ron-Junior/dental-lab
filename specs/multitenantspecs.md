# Contexto de Desenvolvimento: SaaS Multitenant para Laboratórios de Prótese Dentária

## 1. Visão Geral da Arquitetura
- **Framework:** Laravel (Versão atual)
- **Modelo de Negócio:** SaaS B2B Multitenant
- **Estratégia de Banco de Dados:** PostgreSQL com **Multi-schema Tenancy** (um schema dedicado por laboratório).
- **Pacote de Multitenancy:** `stancl/tenancy` (Tenancy for Laravel).
- **Gerenciamento de Schemas:** O pacote gerencia automaticamente a criação (`CREATE SCHEMA`) e a alternância do `search_path` via PostgreSQLSchemaManager.

---

## 2. Estrutura de Ambientes e Domínios
O código deve ser agnóstico quanto ao ambiente, lidando com domínios e sub-subdomínios de forma transparente através da configuração do pacote.

- **Produção (Oracle Cloud):** `{tenant}.meuapp.com.br`
- **Sandbox (Render):** `{tenant}.sandbox.meuapp.com.br`
- **Domínios Centrais (Landing Page / Auth Central):** `meuapp.com.br` (produção) ou `sandbox.meuapp.com.br` (sandbox).
- **Variável Central:** O `.env` define `CENTRAL_DOMAIN`.

---

## 3. Estrutura de Pastas e Migrações
Sempre respeite a separação de escopo imposta pelo multitenancy:

- **Banco Central (`public`):**
  - Caminho: `database/migrations/`
  - Uso: Tabelas globais do SaaS (ex: `tenants`, `domains`, assinaturas, planos globais).
- **Schemas dos Tenants (`tenant_*`):**
  - Caminho: `database/migrations/tenant/`
  - Uso: Tabelas operacionais do laboratório (ex: `users`, `clients` [dentistas], `orders` [ordens de serviço], `products` [tabela de preços]).

---

## 4. Diretrizes para Criação de Código (Regras para a IA)

### 4.1. Models, Controllers e Rotas
- **Rotas do Tenant:** Devem ser registradas em `routes/tenant.php` e rodam no escopo do tenant pelo `stancl/tenancy`.
- **Rotas Centrais / Landing Page:** Devem ser registradas em `routes/web.php` usando os domínios centrais configurados.
- **Evite queries globais em dados de tenant:** Nunca cruze dados de tabelas centrais com tabelas de tenants na mesma query sem justificativa arquitetural.

### 4.2. Fluxo de Provisionamento (Onboarding Assíncrono com Feedback Visual)
O processo de criação do laboratório não deve travar a requisição HTTP do usuário. Siga estritamente este fluxo:
1. **Cadastro Central:** O usuário preenche o formulário na Landing Page (`meuapp.com.br/cadastro`). O sistema cria o registro na tabela central `tenants` com um status inicial (ex: `status: 'provisioning'`).
2. **Disparo de Job:** O sistema despacha uma **Job em fila (Queue)** responsável por:
   - Criar o schema no PostgreSQL (`CREATE SCHEMA tenant_{slug}`).
   - Executar as migrations do tenant (`php artisan tenants:migrate`).
   - Rodar os seeders iniciais (produtos padrão, status de OS).
   - Criar o usuário Admin dentro do schema do tenant.
   - Atualizar o status do tenant para `active`.
3. **Tela de Carregamento (Polling / Loading State):**
   - Após submeter o cadastro, o usuário é redirecionado para uma tela de espera no domínio central (ex: `meuapp.com.br/bem-vindo/{slug}` ou tela de loading da própria aplicação).
   - A tela deve realizar verificações periódicas (via polling com Livewire, Alpine.js ou Vue) consultando o status do tenant no banco central.
4. **Redirecionamento Pós-Configuração:**
   - **Caso o usuário continue na tela:** Assim que a Job finalizar e o status mudar para `active`, o front-end detecta a mudança e redireciona automaticamente o navegador para o subdomínio do laboratório (`https://artprotese.meuapp.com.br/login` ou direto para o painel se houver sessão ativa).
   - **Caso ele saia da tela:** Ele receberá um e-mail de boas-vindas contendo o link direto de acesso ao seu subdomínio recém-criado.

### 4.3. Assets, URLs e Helpers
- Sempre utilize os helpers do Laravel (`route()`, `asset()`, `url()`) para garantir que os links respeitem o subdomínio/sub-subdomínio atual.
- Não hardcode URLs de laboratórios.

---

## 5. Convenções e Padrões de Código
- **Linguagem de Código:** Inglês para nomes de tabelas, colunas, models, controllers, migrations e jobs. Português (PT-BR) apenas para textos de interface (views/mensagens).
- **Validações:** Sempre valide dados de entrada (FormRequests), especialmente slugs de subdomínios (permitir apenas letras minúsculas, números e hífens, sem espaços ou caracteres especiais).
- **Performance:** Garanta que as chaves estrangeiras e índices dentro dos schemas do PostgreSQL estejam otimizados para o volume operacional de um laboratório de prótese.