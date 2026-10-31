# Acesso Central (Sócios), Impersonation & Auditoria

Define como os sócios acessam o painel central e inspecionam laboratórios com segurança.

---

## 1. Autorização do Painel Central

- Guard `central` (`CentralUser`). Todo usuário central é sócio/super admin.
- Rotas do painel em `routes/web.php`, dentro do grupo `['auth:central', 'central.admin']`.
- `EnsureCentralAdmin` (alias `central.admin`) define `Auth::shouldUse('central')` e retorna 403 se não houver `CentralUser` autenticado, permitindo que `Gate`/`can` resolvam o usuário correto.
- Regras finas ficam em `TenantPolicy` (`viewOverdue`, `manageDiscounts`, `impersonate`).
- Interface exclusivamente Volt em `resources/views/livewire/pages/central/` (`laboratories/index.blade.php` unificando filtros de status/inadimplência e ações).
- Gestão de planos (`/planos`, `central.plans.index`) e rentabilidade/custos operacionais (`/planos/rentabilidade`, `central.plans.profitability`) ficam no mesmo grupo protegido. Reajustes de preço são registrados em `plan_price_changes` com o sócio responsável. Ver [spec](../specs/archived/2026-10-plan-management-and-profitability.md).

---

## 2. Impersonation

Fluxo (feature `UserImpersonation` do `stancl/tenancy`, tabela central `tenant_user_impersonation_tokens`):

1. Ação Livewire valida `Gate::authorize('impersonate', $tenant)` (tenant precisa estar `Active`) e rate limit por sócio.
2. `ImpersonateTenantService` localiza, via `$tenant->run()`, o primeiro usuário `Rules::Lab`.
3. Cria token de uso único (TTL 60s) com guard `web` e grava o `AuditLog`.
4. Redireciona para `{scheme}://{domínio-do-tenant}/impersonate/{token}`, rota em `routes/tenant.php` (sob `InitializeTenancyByDomain`) que consome o token via `UserImpersonation::makeResponse`.

O domínio do tenant vem de `domains`, nunca hardcoded.

---

## 3. Audit Log (LGPD)

Tabela central `audit_logs`, somente inserção:

| Coluna | Descrição |
| :--- | :--- |
| `central_user_id` | Sócio que executou (FK, `nullOnDelete`) |
| `tenant_id` | Tenant inspecionado (**sem FK**, para sobreviver à exclusão do tenant) |
| `action` | Ex.: `tenant.impersonation.started` |
| `ip_address`, `user_agent` | Origem da requisição |
| `metadata` | JSON (ex.: id do usuário-alvo) |
| `created_at` | Momento da ação |

O registro é gravado **antes** do redirect. Não há fluxo de edição ou exclusão.
