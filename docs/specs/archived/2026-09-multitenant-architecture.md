# Especificação: Arquitetura Multitenant & Provisionamento de Laboratórios

- **Identificador:** `2026-09-multitenant-architecture`
- **Status:** Implementado / Base Arquitetural
- **Data:** Setembro de 2026
- **Módulos Afetados:** Infraestrutura, Banco de Dados (`stancl/tenancy`), Onboarding (`ProvisionTenant`), Rotas (`routes/web.php`, `routes/tenant.php`).

---

## 1. Contexto & Motivação

O Dental Lab é uma plataforma SaaS B2B criada para atender laboratórios de prótese dentária. Pela sensibilidade dos dados de pacientes e dentistas atendidos, é obrigatório garantir o isolamento estrito entre os laboratórios concorrentes, impedindo vazamento de dados ou lentidão compartilhada em tabelas monolíticas.

A solução adotada é o **PostgreSQL Multi-Schema Tenancy**, gerenciado via pacote `stancl/tenancy`, onde cada laboratório possui um schema isolado (`tenant_{slug}`), compartilhando a mesma instância de banco de dados.

---

## 2. Estrutura de Ambientes e Domínios

O sistema deve ser agnóstico quanto ao ambiente, resolvendo subdomínios e sub-subdomínios de forma transparente através da configuração do `stancl/tenancy`:

- **Produção (Oracle Cloud):** `{tenant}.meuapp.com.br`
- **Sandbox (Render):** `{tenant}.sandbox.meuapp.com.br`
- **Domínios Centrais (Landing Page / Onboarding / Auth Central):** `meuapp.com.br` (produção) ou `sandbox.meuapp.com.br` (sandbox).
- **Variável Central:** Definida via `.env` em `CENTRAL_DOMAIN`.

---

## 3. Estrutura de Schemas e Migrações

1. **Banco Central (`public`):**
   - **Local:** `database/migrations/`
   - **Uso:** Tabelas globais do SaaS (`tenants`, `domains`, `plans`, `subscriptions`, `payments`, `central_users`).
2. **Schemas dos Tenants (`tenant_*`):**
   - **Local:** `database/migrations/tenant/`
   - **Uso:** Tabelas operacionais do laboratório (`users`, `dentists`, `dentist_requests`, `services`, `products`, `stock_batches`, `stock_movements`, `files`).

---

## 4. Regras de Negócio & Diretrizes

### 4.1. Isolamento e Consultas
- **Proibição de Joins Cross-Schema:** Nunca cruze dados de tabelas centrais com tabelas de tenants na mesma query.
- **Roteamento:**
  - Rotas centrais e landing page em `routes/web.php`.
  - Rotas do laboratório em `routes/tenant.php` (inicializadas via middleware `InitializeTenancyByDomain` ou `InitializeTenancyBySubdomain`).

### 4.2. Onboarding & Provisionamento Assíncrono
O processo de criação do laboratório é 100% assíncrono para garantir responsividade:
1. **Cadastro Central:** Usuário envia o formulário central. Cria-se o registro em `tenants` com status `provisioning` (ou `pending_payment`).
2. **Disparo de Job (`ProvisionTenant`):** Despachada para a fila (Queue):
   - Cria o schema PostgreSQL (`CREATE SCHEMA tenant_{slug}`).
   - Executa migrations do tenant (`php artisan tenants:migrate`).
   - Executa seeders iniciais (produtos padrão, status de OS).
   - Cria o usuário administrador inicial dentro do schema do tenant.
   - Atualiza o status do tenant para `active`.
3. **Feedback Visual:** A tela central realiza polling consultando o status do tenant e redireciona o usuário para seu subdomínio assim que o provisionamento for concluído.