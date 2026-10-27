# Domínio: Cobrança & Assinaturas (Billing)

Billing vive no **banco central** (`database/migrations/`) e descreve a relação financeira entre o SaaS e cada laboratório (`Tenant`).

---

## 1. Entidades Principais

- **`Plan`:** Plano comercial disponível para contratação.
- **`Subscription`:** Assinatura do tenant a um plano. Status (`SubscriptionStatus`): `trial`, `active`, `overdue`, `cancelled`. Guarda `current_period_start/end`.
- **`Payment`:** Cobrança/fatura do tenant. Status (`PaymentStatus`): `pending`, `paid`, `failed`, `expired`. Valor em `amount_in_cents`, vencimento em `due_date`, `metadata` JSON.
- **`Discount`:** Desconto comercial concedido pelos sócios a um tenant.
  - `type` (`DiscountType`): `percentage` ou `fixed_amount`.
  - `value_in_cents`: centavos (fixo) ou percentual inteiro 1–100 (percentual).
  - `expires_at` opcional; `applied_payment_id`/`applied_at` marcam o uso (um desconto só é aplicado uma vez).

- **`Exemption`:** Isenção de pagamento por período concedida pelos sócios (`starts_at`, `ends_at`, `reason`, `revoked_at`). Ativa quando não revogada e `starts_at <= agora < ends_at`.

---

## 2. Inadimplência

Um tenant é considerado inadimplente quando:
- possui assinatura com status `overdue`, **ou**
- possui `Payment` `pending` com `due_date` anterior a agora.

Tenants com isenção ativa (`Tenant::isExempt()`) são sempre excluídos.

Valor devido = soma de `amount_in_cents` dos pagamentos pendentes vencidos. Dias em atraso = dias desde o vencimento mais antigo (ou `current_period_end` se não houver pagamento vencido).

---

## 3. Aplicação de Desconto

`ApplyDiscountService` atua sobre o próximo pagamento (`pending` com menor `due_date`):

1. Rejeita desconto expirado ou já aplicado.
2. Calcula o abatimento (percentual: `floor(valor × pct / 100)`), nunca deixando o valor abaixo de 0.
3. Salva `metadata.discount = {discount_id, original_amount_in_cents, discount_in_cents}`.
4. Marca o desconto como aplicado. Um pagamento aceita no máximo um desconto.

---

## 4. Isenção
 
 - Tenant isento não passa pelo fluxo de pagamento (sem cartão/checkout) e não recebe cobranças nem descontos durante o período.
 - Cadastro pelo painel admin aceita período de isenção; a assinatura nasce `active` cobrindo o período, sem `Payment`.
 - A criação de laboratórios com ou sem isenção é centralizada na Action `CreateLaboratory` (`app/Actions/CreateLaboratory.php`), reutilizável no cadastro do admin e no onboarding geral.
 - **Privilégio Exclusivo de Sócios:** Apenas requisições autenticadas por um `CentralUser` podem conceder isenções e aplicar descontos. Cadastros públicos (self-service do laboratório) nunca criam isenções e seguem o fluxo padrão com fatura/pagamento.
 - Ao fim do período de isenção, o tenant volta a ser cobrado normalmente, sem retroativo.
 - Concessão e revogação são auditadas (`tenant.exemption.granted|revoked`). Fluxos de checkout/cobrança devem sempre consultar `Tenant::isExempt()`.

---

## 5. Limites do Plano

`Plan.limits` (JSON) define `users_limit`, `storage_gb_limit` e `monthly_exams_limit` (itens `RequestService` no mês). Chave ausente = ilimitado. Níveis: `normal` (<80%), `warning` (≥80%), `critical` (≥95%), `exceeded` (≥100%). Em `exceeded` apenas a criação é bloqueada (HTTP 403 / toast). Detalhes em [spec](../specs/active/2026-10-plan-limits.md).

---

## 6. Regras

- Valores sempre em centavos inteiros (ver [Convenções de Dados](../architecture/data-conventions.md)).
- Mudanças de status via webhook são idempotentes (ver spec arquivada `2026-10-lab-manager-step2`).
