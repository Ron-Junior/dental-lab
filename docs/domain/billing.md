# Domínio: Cobrança & Assinaturas (Billing)

Billing vive no **banco central** (`database/migrations/`) e descreve a relação financeira entre o SaaS e cada laboratório (`Tenant`). O gateway oficial de pagamentos é o **Stripe**.

---

## 1. Entidades Principais

- **`Plan`:** Plano comercial disponível para contratação. Contém `stripe_price_id` opcional.
- **`Subscription`:** Assinatura do tenant a um plano. Status (`SubscriptionStatus`): `trial`, `active`, `overdue`, `cancelled`. Guarda `current_period_start/end` e `stripe_subscription_id`.
- **`Payment`:** Cobrança/fatura do tenant. Status (`PaymentStatus`): `pending`, `paid`, `failed`, `expired`. Valor em `amount_in_cents`, vencimento em `due_date`, `metadata` JSON e `gateway_transaction_id`.
- **`Discount`:** Desconto comercial concedido pelos sócios a um tenant.
  - `type` (`DiscountType`): `percentage` ou `fixed_amount`.
  - `value_in_cents`: centavos (fixo) ou percentual inteiro 1–100 (percentual).
  - `expires_at` opcional; `applied_payment_id`/`applied_at` marcam o uso (um desconto só é aplicado uma vez).
- **`Exemption`:** Isenção de pagamento por período concedida pelos sócios (`starts_at`, `ends_at`, `reason`, `revoked_at`). Ativa quando não revogada e `starts_at <= agora < ends_at`.
- **`Tenant` (Campos Financeiros e de Identificação):**
  - `owner_email`: E-mail oficial do responsável cadastrado no onboarding.
  - `verified_at`: Timestamp indicando quando o primeiro acesso e verificação foram concluídos.
  - `stripe_customer_id`: Identificador do cliente no Stripe.
  - `card_brand` e `card_last_four`: Dados seguros do cartão padrão para exibição no perfil.

---

## 2. Inadimplência

Um tenant é considerado inadimplente quando:
- possui assinatura com status `overdue`, **ou**
- possui `Payment` `pending` com `due_date` anterior a agora.

Tenants com isenção ativa (`Tenant::isExempt()`) são sempre excluídos da inadimplência.

Valor devido = soma de `amount_in_cents` dos pagamentos pendentes vencidos. Dias em atraso = dias desde o vencimento mais antigo (ou `current_period_end` se não houver pagamento vencido).

---

## 3. Aplicação de Desconto

`ApplyDiscountService` atua sobre o próximo pagamento (`pending` com menor `due_date`):

1. Rejeita desconto expirado ou já aplicado.
2. Calcula o abatimento (percentual: `floor(valor × pct / 100)`), nunca deixando o valor abaixo de 0.
3. Salva `metadata.discount = {discount_id, original_amount_in_cents, discount_in_cents}`.
4. Marca o desconto como aplicado. Um pagamento aceita no máximo um desconto.

---

## 4. Isenção e Checkout

- **Bypass de Checkout:** Tenant isento (`Tenant::isExempt()`) não passa pelo fluxo de checkout de pagamento (sem coleta de cartão) e vai direto para a etapa de provisionamento.
- **Cadastro pelo Painel Admin:** Aceita período de isenção; a assinatura nasce `active` cobrindo o período, sem `Payment` inicial.
- **Privilégio Exclusivo de Sócios:** Apenas requisições autenticadas por um `CentralUser` podem conceder isenções e aplicar descontos. Cadastros públicos (self-service do laboratório) nunca criam isenções e seguem o fluxo padrão de checkout com Stripe.
- Ao fim do período de isenção, o tenant volta a ser cobrado normalmente, sem retroativo.
- Concessão e revogação são auditadas (`tenant.exemption.granted|revoked`). Fluxos de checkout/cobrança devem sempre consultar `Tenant::isExempt()`.

---

## 5. Integração com Stripe e Trial

1. **Tokenização Segura (Stripe Elements):**
   - Dados de cartão nunca tocam o banco de dados em texto plano.
   - O checkout utiliza `SetupIntent` para registrar o cartão para o período de testes (**Trial**) sem cobrança imediata.
2. **Contador Regressivo no Perfil:**
   - No perfil do laboratório (`/perfil`), o administrador acompanha quantos dias restam para o término do trial.
   - Exibe o cartão atual cadastrado no Stripe (`Mastercard •••• 4242`) e permite atualizar a forma de pagamento via modal com Stripe Elements.
3. **Webhooks Idempotentes:**
   - O endpoint central `POST /webhook/payment` recebe eventos do Stripe (`invoice.payment_succeeded`, `invoice.payment_failed`, `customer.subscription.deleted`).
   - Pagamentos confirmados renovam o período da assinatura e marcam pagamentos como `paid`.

---

## 6. Primeiro Acesso Protegido

- O cadastro (self-service ou sócio) **não coleta senhas** no banco central.
- A senha do dono do laboratório só é definida no **Primeiro Acesso** (`/completar-cadastro` no subdomínio do laboratório) após o tenant já estar provisionado.
- **Trava de Segurança:** O acesso a essa rota é estritamente restrito a tenants com `verified_at === null` e cujo e-mail informado corresponda a `owner_email`.
- Ao definir a senha com sucesso, o sistema marca `tenant.verified_at = now()` e invalida o token, impedindo qualquer reutilização futura do link.

---

## 7. Limites do Plano

`Plan.limits` (JSON) define `users_limit`, `storage_gb_limit` e `monthly_exams_limit` (itens `RequestService` no mês). Chave ausente = ilimitado. Níveis: `normal` (<80%), `warning` (≥80%), `critical` (≥95%), `exceeded` (≥100%). Em `exceeded` apenas a criação é bloqueada (HTTP 403 / toast). Detalhes em [spec](../specs/active/2026-10-plan-limits.md).

---

## 8. Regras Gerais

- Valores sempre em centavos inteiros (ver [Convenções de Dados](../architecture/data-conventions.md)).
- Mudanças de status via webhook são idempotentes.
