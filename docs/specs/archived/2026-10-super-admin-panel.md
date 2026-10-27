# Especificação: Painel do Sócio (Super Admin)

- **Identificador:** `2026-10-super-admin-panel`
- **Status:** Ativo / Aprovado para implementação
- **Data:** Outubro de 2026
- **Módulos Afetados:** Billing central (`Payment`, `Subscription`, `Discount`), Tenancy (`Tenant`), Auditoria (`AuditLog`), UI central (Volt).

---

## 1. Contexto & Motivação

Os sócios precisam acompanhar a inadimplência dos laboratórios, conceder descontos comerciais e inspecionar um laboratório para suporte, tudo a partir do domínio central e com rastreabilidade (LGPD).

---

## 2. Decisões

- Todo `CentralUser` é sócio/super admin; não existem usuários centrais de laboratório. O acesso é protegido por `auth:central` + middleware `central.admin` + `TenantPolicy`.
- Interface 100% Livewire/Volt (sem API JSON e sem controllers). Lógica em Services.
- A gestão de inadimplentes é unificada diretamente na listagem de laboratórios (`pages.central.laboratories.index`) através de filtros rápidos (ex: "Todos", "Ativos", "Inadimplentes") e modais de ações (desconto, isenção e impersonation), sem necessidade de uma página isolada.
- Impersonation usa a feature nativa `UserImpersonation` do `stancl/tenancy` (token de uso único, TTL de 60s).
- O usuário impersonado é o primeiro usuário com `Rules::Lab` do tenant (administrador criado em `ProvisionTenant`).
- Desconto reduz `payments.amount_in_cents` do próximo pagamento pendente; o valor original fica em `metadata.discount`.
- Em descontos `percentage`, `value_in_cents` guarda o percentual inteiro (1–100); em `fixed_amount`, centavos.
- Isenção de pagamento por período (`exemptions`): tenant isento não precisa do fluxo de pagamento (cartão etc.), não aparece em inadimplentes e não recebe desconto. Regra única em `Tenant::isExempt()`; fluxos de checkout/cobrança devem consultá-la. Ao fim do período volta a ser cobrado normalmente, sem retroativo.
- A criação de laboratórios é centralizada na Action `CreateLaboratory` (`app/Actions/CreateLaboratory.php`), reutilizável no cadastro administrativo e no onboarding público do laboratório.
- Concessão de isenção de pagamento (`exemptions`) e aplicação de descontos comerciais são prerrogativas exclusivas de `CentralUser` autenticado; fluxos fora desse contexto nunca criam isenções nem aplicam descontos.
- Laboratórios podem ser cadastrados pelo painel admin com plano e período de isenção opcional; a listagem de laboratórios exibe selo "Isento até dd/mm/aaaa".

---

## 3. Funcionalidades

### 3.1. Inadimplentes (Filtro e Gestão na Listagem)
Filtro rápido na tela principal de laboratórios: Tenants cuja assinatura está `overdue` **ou** com `Payment` `pending` e `due_date` vencida (excluindo tenants isentos). Para laboratórios inadimplentes, exibe alerta visual de inadimplência, valor devido e ações para concessão de desconto, isenção e inspeção de suporte.

### 3.2. Descontos
Tabela central `discounts` (`tenant_id`, `type`, `value_in_cents`, `expires_at`, `applied_payment_id`, `applied_at`). `ApplyDiscountService` aplica em transação com lock; rejeita desconto expirado/já aplicado, pagamento já descontado ou ausência de pagamento pendente.

### 3.3. Isenção de pagamento
Tabela central `exemptions` (`tenant_id`, `starts_at`, `ends_at`, `reason`, `created_by_central_user_id`, `revoked_at`). `ExemptionService` concede (sem sobreposição, fim no futuro) e revoga, com `AuditLog`. Pode ser definida no cadastro de laboratório pelo painel admin (que também exige plano e cria assinatura ativa sem pagamento).

### 3.4. Impersonation
Ação nos cards de laboratório: valida policy, rate limit por sócio, cria token via `UserImpersonation`, grava `AuditLog` (IP, user agent, sócio, tenant, usuário-alvo, timestamp) e redireciona ao domínio do tenant (`/impersonate/{token}`).

---

## 4. Critérios de Aceite (Testes)

1. Convidado é redirecionado ao login central; guard `web` não acessa o painel.
2. Listagem inclui assinatura `overdue` e pagamento pendente vencido; exclui em dia/pagos.
3. Descontos: percentual, fixo, piso em zero, expirado, já aplicado e dupla aplicação bloqueados.
4. Isenção: `isExempt()` correto (ativa, futura, expirada, revogada); isento fora de inadimplentes e sem desconto; cadastro admin com isenção não cria `Payment`; selo na listagem.
5. Impersonation: tenant inativo gera 403; audit log criado com IP/sócio/tenant; token é de uso único e expira.

---

## 5. Documentação Relacionada

- [Billing](../../domain/billing.md)
- [Acesso central e impersonation](../../architecture/central-admin-access.md)
