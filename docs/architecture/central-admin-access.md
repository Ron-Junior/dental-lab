# Acesso Central (Sócios), Impersonation & Auditoria

Define como os sócios acessam o painel central e inspecionam laboratórios com segurança.

---

## 1. Autorização do Painel Central

- Guard `central` (`CentralUser`). Todo usuário central é sócio/super admin.
- Rotas do painel em `routes/web.php`, dentro do grupo `['auth:central', 'central.admin']`.
- `EnsureCentralAdmin` (alias `central.admin`) define `Auth::shouldUse('central')` e retorna 403 se não houver `CentralUser` autenticado, permitindo que `Gate`/`can` resolvam o usuário correto.
- Regras finas ficam em `TenantPolicy` (`viewOverdue`, `manageDiscounts`, `impersonate`).
- Interface exclusivamente Volt em `resources/views/livewire/pages/central/` (`laboratories/index.blade.php` unificando filtros de status/inadimplência e ações).
- Gestão de planos (`/planos`, `central.plans.index`) e rentabilidade/custos operacionais (`/planos/rentabilidade`, `central.plans.profitability`) ficam no mesmo grupo protegido. Além da proteção de rota por middleware, **todos os métodos públicos de ação e abertura de modal** nos componentes centrais (`openCreatePlanModal`, `openEditPlanModal`, `savePlan`, `confirmSavePlan`, `togglePlanStatus`, `confirmDeactivatePlan`, etc.) validam explicitamente `Auth::guard('central')->user() instanceof CentralUser` no servidor ([spec](../specs/archived/2026-11-livewire-server-side-authorization-enforcement.md)). Reajustes de preço são registrados em `plan_price_changes` com o sócio responsável. Ver [spec](../specs/archived/2026-10-plan-management-and-profitability.md).

### 1.1. Convite e Conclusão de Cadastro de Sócios (`register-invite`)

- Novos sócios (`CentralUser`) são criados inicialmente sem senha (`password = null`, `email_verified_at = null`) e recebem um link assinado (`/convite/registrar`, rota `invite.registration` sob middleware `signed`) via `CentralUserInviteNotification`.
- **Proteção contra Account Takeover ([spec](../specs/archived/2026-10-central-register-invite-account-takeover-protection.md)):**
  - As propriedades públicas de contexto e controle em `resources/views/livewire/pages/auth/register-invite.blade.php` (`$email`, `$token` e `$isExpired`) utilizam obrigatoriamente o atributo `#[Locked]` (`Livewire\Attributes\Locked`), impedindo mutação de `$email` ou `$token` em requisições `POST /livewire/update`.
  - Todo convite gera um token de uso único com expiração de 24 horas persistido na tabela central `central_invitation_tokens` (`App\Models\CentralInvitationToken`), vinculado a `central_user_id`.
  - Tanto no `mount()` quanto no `register()`, o componente valida se o `CentralInvitationToken` pertence ao `CentralUser` alvo (`where('central_user_id', $user->id)->whereNull('used_at')->where('expires_at', '>', now())`) e **impede a sobrescrita** caso o `CentralUser` já tenha finalizado o cadastro (`password !== null` ou `email_verified_at !== null`). Após a conclusão, o token é imediatamente invalidado/removido no banco central.

---

## 2. Impersonation

Fluxo (feature `UserImpersonation` do `stancl/tenancy`, tabela central `tenant_user_impersonation_tokens`):

1. Ação Livewire valida `Gate::authorize('impersonate', $tenant)` (tenant precisa estar `Active`) e rate limit por sócio.
2. `ImpersonateTenantService` localiza, via `$tenant->run()`, o primeiro usuário `Rules::Lab`.
3. Cria token de uso único (TTL 60s) com guard `web` e grava o `AuditLog`.
4. Redireciona para `{scheme}://{domínio-do-tenant}/impersonate/{token}`, rota em `routes/tenant.php` (sob `InitializeTenancyByDomain`) que valida explicitamente `abort_unless((string) $tokenModel->tenant_id === (string) tenant('id'), 403)` **antes** de modificar a sessão e só grava `session()->put('impersonated_by_central', true)` após a execução bem-sucedida de `UserImpersonation::makeResponse($tokenModel)` ([spec](../specs/archived/2026-11-impersonation-token-tenant-validation-before-session.md)), impedindo contaminação de sessão ou bypass de `EnsureTenantIsOperational` via tokens de outros tenants ou expirados.

O domínio do tenant vem de `domains`, nunca hardcoded.

> **Proteção do Usuário Alvo de Impersonation ([spec](../specs/archived/2026-10-deletion-integrity-and-authorization.md)):** O formulário de exclusão da própria conta (`resources/views/livewire/profile/delete-user-form.blade.php`) realiza `SoftDelete` no `User` (e no `Dentist` ou `Partner` vinculado, quando aplicável), mas bloqueia a autoexclusão quando o usuário autenticado é o único administrador ativo do laboratório (`Rules::Lab` / `Rules::Owner`). Isso garante que o tenant jamais fique órfão e preserva a disponibilidade de um usuário `Rules::Lab` ativo para `ImpersonateTenantService`.

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
