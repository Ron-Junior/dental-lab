# Especificação: Segurança de Assinatura e Trava de Ciclo de Vida no Webhook de Pagamento

- **Identificador:** `2026-10-webhook-security-and-lifecycle`
- **Status:** Concluído / Implementado
- **Data:** Outubro de 2026
- **Data de Conclusão:** 2026-10-31
- **Módulos Afetados:** Cobrança e Webhooks (`PaymentWebhookController`, `config/services.php`), Modelos Centrais (`Subscription`, `Payment`, `Plan`, `Tenant`), Provisionamento (`ProvisionTenant`).

---

## 1. Contexto & Motivação

A implementação atual de `PaymentWebhookController::handle` e do fluxo pós-pagamento possui quatro lacunas críticas de segurança e regra de negócio:

1. **Ausência de validação criptográfica (`Stripe-Signature`):** O endpoint `POST /webhook/payment` possui exceção de CSRF em `bootstrap/app.php` e aceita qualquer payload JSON externo sem verificar a assinatura criptográfica do Stripe via `Webhook::constructEvent`. Qualquer requisição forjada com `{"event": "payment.succeeded", "tenant_id": "..."}` aprova faturas, ativa assinaturas e dispara provisionamento gratuitamente.
2. **Período de renovação fixo em 30 dias:** A renovação via webhook calcula `$periodEnd = $now->copy()->addDays(30)`, ignorando os timestamps oficiais enviados pelo Stripe e o ciclo de cobrança contratado no plano (`billing_cycle = 'annual'` ou `'monthly'`).
3. **Re-execução indevida do provisionamento em renovações:** `ProvisionTenant::dispatch($tenantId, $userData)` é disparado em todo evento de pagamento aprovado — inclusive nas renovações mensais/anuais seguintes — resetando o `InvitationToken` do proprietário e reenviando o e-mail de boas-vindas com link de criação de senha a cada ciclo.
4. **Falta de tratamento para inadimplência e cancelamento:** Eventos como `invoice.payment_failed` e `customer.subscription.deleted` são ignorados, impedindo que assinaturas inadimplentes ou encerradas no Stripe transicionem automaticamente para `Overdue` ou `Cancelled`.

---

## 2. Objetivos

- Exigir e validar a assinatura criptográfica do Stripe (`Stripe-Signature`) antes de processar qualquer evento no webhook.
- Respeitar o ciclo de cobrança do plano (`monthly` / `annual`) e os timestamps oficiais de período da fatura/assinatura do Stripe, além de efetivar trocas/reajustes agendados na virada do ciclo.
- Garantir que o provisionamento (`ProvisionTenant`) e o envio do convite de primeiro acesso ocorram **exclusivamente** na ativação inicial do laboratório (onboarding).
- Processar eventos de falha de pagamento (`invoice.payment_failed`, `charge.failed`, `payment.failed`) e cancelamento de assinatura (`customer.subscription.deleted`, `subscription.cancelled`), mantendo o estado local sincronizado com o gateway.

---

## 3. Regras de Negócio

### 3.1. RN-01: Autenticidade e Validação Criptográfica do Webhook

1. **Segredo de Webhook:** O segredo do endpoint é lido de `config('services.stripe.webhook_secret')` (`STRIPE_WEBHOOK_SECRET`).
2. **Validação de Assinatura:**
   - Quando `services.stripe.webhook_secret` estiver configurado (obrigatório em produção/staging), toda requisição a `POST /webhook/payment` deve conter o cabeçalho `Stripe-Signature`.
   - O payload bruto (`$request->getContent()`) deve ser validado via `\Stripe\Webhook::constructEvent($rawPayload, $signatureHeader, $webhookSecret)`.
   - Caso o cabeçalho esteja ausente, o JSON seja inválido (`\UnexpectedValueException`) ou a assinatura seja inválida (`\Stripe\Exception\SignatureVerificationException`), o sistema deve registrar `Log::warning` e retornar **HTTP 400 (Bad Request)** imediatamente, sem executar nenhuma alteração no banco de dados.
   - Em ambientes fora de `local`/`testing`, se `services.stripe.webhook_secret` estiver vazio, o endpoint deve recusar o processamento com `Log::critical` e **HTTP 500**, evitando operação insegura silenciosa em produção.

### 3.2. RN-02: Cálculo do Período de Vigência e Ciclo do Plano

1. **Prioridade aos timestamps do Stripe:**
   - Se o objeto do evento trouxer as datas de vigência do ciclo (`lines.data[0].period.start` / `lines.data[0].period.end` na `invoice`, ou `current_period_start` / `current_period_end` na `subscription`), converter os timestamps Unix para `Carbon` e utilizá-los em `current_period_start` e `current_period_end`.
2. **Cálculo via `Plan::addBillingCycles`:**
   - Na ausência de timestamps explícitos no payload, determinar o plano ativo da assinatura (`$subscription?->plan ?? Plan::find($planId)`) e calcular o término do ciclo via `$plan->addBillingCycles($periodStart, 1)`:
     - Plano mensal (`billing_cycle = 'monthly'`): soma 1 mês (`addMonthsNoOverflow(1)`).
     - Plano anual (`billing_cycle = 'annual'`): soma 1 ano (`addYearsNoOverflow(1)`).
   - O fallback de `addDays(30)` só é utilizado caso nenhum plano seja localizado.
3. **Efetivação de Reajuste ou Migração Agendada:**
   - Ao renovar uma `Subscription` que possua `price_change_effective_at !== null && $now->greaterThanOrEqualTo($subscription->price_change_effective_at)`:
     - Se `scheduled_plan_id` estiver preenchido, atualizar `plan_id = scheduled_plan_id`.
     - Se `scheduled_price_in_cents` estiver preenchido, atualizar `price_in_cents = scheduled_price_in_cents` e `stripe_price_id = scheduled_stripe_price_id`.
     - Limpar `scheduled_plan_id`, `scheduled_price_in_cents`, `scheduled_stripe_price_id` e `price_change_effective_at`.

### 3.3. RN-03: Trava de Ciclo de Vida no Provisionamento (`ProvisionTenant`)

1. **Disparo Restrito ao Onboarding:**
   - `ProvisionTenant::dispatch($tenantId, $userData)` só deve ser enfileirado quando o tenant necessitar de provisionamento inicial, ou seja:
     - O status do `Tenant` antes do processamento do pagamento era `TenantStatus::PendingPayment` ou `TenantStatus::Failed`, **e**
     - O tenant ainda não foi verificado (`! $tenant->isVerified()`).
2. **Renovações de Tenants Ativos:**
   - Quando o tenant já estiver com `status === TenantStatus::Active` (ou já possuir `verified_at !== null`), o pagamento aprovado apenas quita a fatura (`PaymentStatus::Paid`) e renova a vigência/status da `Subscription` (`SubscriptionStatus::Active`), **sem** disparar `ProvisionTenant`.
3. **Defesa em Profundidade no Job `ProvisionTenant`:**
   - Dentro de `ProvisionTenant::handle()`, antes de recriar `InvitationToken` e enviar `TenantWelcomeNotification`, verificar se `$tenant->isVerified()` é `false` e se o usuário proprietário ainda não concluiu o primeiro acesso (`email_verified_at === null`).
   - Se o laboratório já estiver verificado, o job não deve resetar tokens de convite nem reenviar o e-mail de boas-vindas.

### 3.4. RN-04: Eventos de Inadimplência e Cancelamento

1. **Falha de Pagamento (`invoice.payment_failed`, `charge.failed`, `payment.failed`):**
   - Localizar o `Payment` pendente/correspondente por `payment_id`, `gateway_transaction_id` ou último pendente do tenant; atualizar `status = PaymentStatus::Failed` (ou criar o registro com `PaymentStatus::Failed` caso ainda não exista localmente).
   - Se o `Tenant` já estiver ativo (`status === TenantStatus::Active`) e **não** possuir isenção ativa (`! $tenant->isExempt()`), atualizar o status da `Subscription` vigente para `SubscriptionStatus::Overdue`.
   - Se o `Tenant` ainda estiver em `TenantStatus::PendingPayment` (primeira cobrança negada no onboarding), manter o `Tenant` em `PendingPayment` e não disparar provisionamento.
2. **Cancelamento de Assinatura (`customer.subscription.deleted`, `subscription.cancelled`):**
   - Localizar a `Subscription` por `stripe_subscription_id` (ou pela assinatura vigente do `tenant_id`).
   - Atualizar `status = SubscriptionStatus::Cancelled` e limpar eventuais campos de agendamento (`scheduled_plan_id`, `scheduled_price_in_cents`, `scheduled_stripe_price_id`, `price_change_effective_at`).
3. **Idempotência em Todos os Eventos:**
   - Reprocessar o mesmo evento de falha ou cancelamento já aplicado deve retornar **HTTP 200** sem efeitos colaterais duplicados.

---

## 4. Critérios de Aceite (Testes Automatizados)

1. **Rejeição de Assinatura Inválida ou Ausente:** Requisição a `POST /webhook/payment` sem `Stripe-Signature` ou com assinatura inválida (quando `services.stripe.webhook_secret` está configurado) retorna HTTP 400 e não altera `payments`, `subscriptions` nem `tenants`.
2. **Assinatura Válida Processada:** Requisição assinada com HMAC-SHA256 válido do Stripe (`Stripe-Signature`) é aceita e processada com HTTP 200.
3. **Ciclo Anual vs. Mensal:** Pagamento aprovado para plano com `billing_cycle = 'annual'` avança `current_period_end` em 1 ano (`addYearsNoOverflow(1)`), e plano `'monthly'` avança em 1 mês (`addMonthsNoOverflow(1)`), ou utiliza o `period_end` enviado pelo Stripe.
4. **Trava de Provisionamento na Renovação:** Em um tenant já `Active` e verificado (`verified_at !== null`), um novo webhook `invoice.payment_succeeded` renova a assinatura e registra o pagamento, mas **não** despacha `ProvisionTenant`, **não** altera o token de convite e **não** envia `TenantWelcomeNotification`.
5. **Inadimplência Automática (`invoice.payment_failed`):** Recebimento de `invoice.payment_failed` marca a cobrança como `failed` e altera a assinatura do tenant não-isento para `overdue`.
6. **Cancelamento Automático (`customer.subscription.deleted`):** Recebimento de `customer.subscription.deleted` altera o status da assinatura correspondente para `cancelled`.

