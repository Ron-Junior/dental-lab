# Especificação: Sanitização de PII e Registro Restrito a Identificadores nos Logs do Webhook de Pagamento

- **Identificador:** `2026-11-webhook-payment-log-pii-sanitization`
- **Status:** Concluído / Implementado
- **Data de Conclusão:** 2026-11-02
- **Data:** Novembro de 2026
- **Módulos Afetados:**
  - Controlador de Webhooks de Pagamento (`app/Http/Controllers/PaymentWebhookController.php`)
  - Testes Automatizados de Webhook de Pagamento (`tests/Feature/PaymentWebhookTest.php`)

---

## 1. Contexto & Motivação

A demanda **6.1 ([BAIXA] Registro Integral de Payloads de Pagamento nos Logs)** identifica um problema de privacidade, segurança da informação e conformidade com a LGPD no processamento de notificações do gateway de pagamento:

1. **Gravação Integral do `$payload` em Texto Plano nos Logs (`PaymentWebhookController.php`, linhas 110–114):**
   - No método `PaymentWebhookController::handle(Request $request)`, logo após a verificação da assinatura `Stripe-Signature`, o controlador executa:
     ```php
     $payload = $request->all();

     Log::info('Webhook de pagamento recebido', [
         'payload' => $payload,
     ]);
     ```
   - Eventos enviados pelo Stripe (como `invoice.payment_succeeded`, `invoice.payment_failed`, `charge.paid`, `customer.subscription.deleted`) e payloads internos de onboarding carregam estruturas extensas contendo **Dados Pessoais Identificáveis (PII)** e metadados sensíveis de faturamento, tais como:
     - Nome completo, e-mail (`customer_email`, `receipt_email`, `data.user.email`), telefone (`customer_phone`) e endereço de cobrança (`customer_address`, `billing_details`);
     - Documentos fiscais (CPF/CNPJ em `customer_tax_ids`), detalhes do método de pagamento (`payment_method_details`, bandeira, últimos dígitos, fingerprint) e links privados de faturas/recibos (`hosted_invoice_url`, `invoice_pdf`);
     - Dados de provisionamento do usuário responsável (`data.user.name`, `data.user.email`, e eventuais atributos de onboarding em `data.user`).

2. **Riscos Operacionais e de Conformidade (LGPD / PCI-DSS):**
   - Arquivos de log de aplicação (`storage/logs/laravel.log`) e serviços externos de agregação/monitoramento de logs possuem políticas de retenção e escopo de leitura mais amplos que o banco de dados relacional.
   - Registrar o payload completo sem mascaramento viola o princípio da **minimização de dados** (Art. 6º, III da LGPD) e expõe desnecessariamente PII de clientes e metadados financeiros em texto plano.

---

## 2. Objetivos

1. **Eliminar o despejo integral de `$payload` nos logs de aplicação em `PaymentWebhookController.php`:**
   - Remover a chave `'payload' => $payload` de `Log::info('Webhook de pagamento recebido', ...)` e garantir que nenhuma chamada `Log::*` no controlador exponha o corpo bruto da requisição, objetos do cliente (`user`, `customer_details`, `billing_details`) ou metadados sensíveis.
2. **Registrar estritamente os identificadores de correlação e rastreabilidade (`event_id`, `type`, `tenant_id`, `payment_id`):**
   - Padronizar o contexto do log `'Webhook de pagamento recebido'` para conter exclusivamente as quatro chaves recomendadas:
     - `event_id`: Identificador único do evento no gateway (`$payload['event_id'] ?? $payload['id'] ?? $data['event_id'] ?? null`);
     - `type`: Tipo do evento recebido (`$payload['event'] ?? $payload['type'] ?? null`);
     - `tenant_id`: Identificador do laboratório associado (`$data['tenant_id'] ?? $payload['tenant_id'] ?? $stripeObject['metadata']['tenant_id'] ?? null`, com resolução por `stripe_customer_id` ou `stripe_subscription_id` quando aplicável);
     - `payment_id`: Identificador do pagamento/transação (`$data['payment_id'] ?? $payload['payment_id'] ?? $stripeObject['payment_id'] ?? $stripeObject['metadata']['payment_id'] ?? $transactionId ?? null`).
3. **Assegurar cobertura por testes automatizados (PHPUnit):**
   - Verificar em `tests/Feature/PaymentWebhookTest.php` que o log `'Webhook de pagamento recebido'` registra exatamente as chaves `['event_id', 'type', 'tenant_id', 'payment_id']` e **não** contém a chave `'payload'` nem vaza dados pessoais (`email`, `name`, `password`, `billing_details`) enviados no corpo do webhook.

---

## 3. Regras de Negócio & Segurança

### 3.1. RN-01: Minimização de Dados e Proibição de PII nos Logs de Webhook

1. **Proibição de Log Integral de Payloads:**
   - O controlador `PaymentWebhookController` **nunca** deve passar `$payload`, `$rawPayload`, `$data`, `$stripeObject` ou `$userData` diretamente no array de contexto de chamadas `Log::info`, `Log::warning`, `Log::error` ou `Log::critical`.
2. **Estrutura Estrita de Contexto no Recebimento do Webhook:**
   - O registro de entrada `Log::info('Webhook de pagamento recebido', ...)` deve conter **exclusivamente** o array associativo com as chaves:
     ```php
     [
         'event_id' => $eventId,
         'type' => $event,
         'tenant_id' => $tenantId,
         'payment_id' => $paymentId,
     ]
     ```
   - Nenhum campo de e-mail, nome de usuário, senha, endereço, telefone, cartão ou URL de fatura pode ser incluído nos logs.

### 3.2. RN-02: Extração Padronizada dos Identificadores para Log e Processamento

1. **Extração Resiliente de Identificadores:**
   - A partir de `$payload = $request->all()`, `$data = is_array($payload['data'] ?? null) ? $payload['data'] : []` e `$stripeObject = is_array($data['object'] ?? null) ? $data['object'] : []`:
     - **`event_id`:** `$payload['event_id'] ?? $payload['id'] ?? $data['event_id'] ?? null` (suporta tanto o campo padrão `id` de um objeto `Event` do Stripe, ex.: `evt_...`, quanto `event_id` em payloads internos).
     - **`type`:** `$payload['event'] ?? $payload['type'] ?? null` (suporta tanto `type` padrão do Stripe quanto `event`).
     - **`payment_id`:** `$data['payment_id'] ?? $payload['payment_id'] ?? $stripeObject['payment_id'] ?? $stripeObject['metadata']['payment_id'] ?? null`.
     - **`tenant_id`:** `$data['tenant_id'] ?? $payload['tenant_id'] ?? $stripeObject['metadata']['tenant_id'] ?? null` (com fallback de resolução via `stripe_customer_id` e `stripe_subscription_id` quando o evento é processável e `tenant_id` não veio explícito no payload).
2. **Ordem de Execução:**
   - Os identificadores devem ser extraídos antes da chamada `Log::info('Webhook de pagamento recebido', ...)`, garantindo que mesmo eventos posteriormente ignorados (não suportados) tenham seu `event_id` e `type` registrados de forma limpa e segura, sem gravar o payload bruto.

---

## 4. Detalhamento Técnico

### 4.1. `app/Http/Controllers/PaymentWebhookController.php`

No método `handle(Request $request): JsonResponse` (linhas 110–153), substituir o log integral do `$payload` pela extração prévia dos identificadores e registro restrito:

```php
        $payload = $request->all();
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $stripeObject = is_array($data['object'] ?? null) ? $data['object'] : [];

        $eventId = $payload['event_id'] ?? $payload['id'] ?? $data['event_id'] ?? null;
        $event = $payload['event'] ?? $payload['type'] ?? null;
        $paymentId = $data['payment_id'] ?? $payload['payment_id'] ?? $stripeObject['payment_id'] ?? $stripeObject['metadata']['payment_id'] ?? null;
        $transactionId = $data['transaction_id'] ?? $data['id'] ?? $payload['transaction_id'] ?? $stripeObject['id'] ?? null;
        $tenantId = $data['tenant_id'] ?? $payload['tenant_id'] ?? $stripeObject['metadata']['tenant_id'] ?? null;
        $stripeSubscriptionId = $stripeObject['subscription'] ?? $payload['subscription'] ?? (($stripeObject['object'] ?? null) === 'subscription' ? ($stripeObject['id'] ?? null) : null);

        // Se tenantId não foi passado diretamente, tentar localizar por stripe_customer_id
        $stripeCustomerId = $stripeObject['customer'] ?? $payload['customer'] ?? null;
        if ($tenantId === null && $stripeCustomerId !== null) {
            $tenantId = Tenant::query()->where('stripe_customer_id', $stripeCustomerId)->value('id');
        }

        // Se ainda não localizou tenantId mas temos subscription id
        if ($tenantId === null && $stripeSubscriptionId !== null) {
            $tenantId = Subscription::query()->where('stripe_subscription_id', $stripeSubscriptionId)->value('tenant_id');
        }

        Log::info('Webhook de pagamento recebido', [
            'event_id' => $eventId,
            'type' => $event,
            'tenant_id' => $tenantId,
            'payment_id' => $paymentId,
        ]);

        $isSuccess = in_array($event, self::SUCCESS_EVENTS, true);
        $isFailure = in_array($event, self::FAILURE_EVENTS, true);
        $isCancellation = in_array($event, self::CANCELLATION_EVENTS, true);

        if (! $isSuccess && ! $isFailure && ! $isCancellation) {
            Log::info('Evento de webhook ignorado (não aprovado ou não suportado)', [
                'event' => $event,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Evento ignorado.',
            ], 200);
        }

        $planId = $data['plan_id'] ?? $payload['plan_id'] ?? $stripeObject['metadata']['plan_id'] ?? null;
        $amountInCents = $data['amount_in_cents'] ?? $data['amount'] ?? $payload['amount'] ?? $stripeObject['amount_paid'] ?? $stripeObject['amount'] ?? null;
        $userData = $data['user'] ?? $payload['user'] ?? [];
```

---

## 5. Critérios de Aceite (Testes Automatizados)

1. **Registro Restrito a Identificadores (`event_id`, `type`, `tenant_id`, `payment_id`):**
   - Ao receber uma requisição válida em `POST /webhook/payment` com `id`/`event_id`, `type`/`event`, `tenant_id`, `payment_id` e dados sensíveis de cliente (`user.name`, `user.email`, `user.password`, `customer_email`, `billing_details`), `Log::info('Webhook de pagamento recebido', ...)` deve ser invocado contendo estritamente as chaves `['event_id', 'type', 'tenant_id', 'payment_id']`.
2. **Ausência de `payload` e de PII no Contexto de Log:**
   - O array de contexto registrado em `Log::info('Webhook de pagamento recebido', ...)` **não** deve possuir a chave `'payload'`, nem conter e-mails, nomes, senhas ou detalhes de cobrança do cliente.
3. **Preservação Integral do Fluxo Funcional do Webhook:**
   - Todos os cenários existentes de confirmação de pagamento, renovação de ciclo, falha (`Overdue`), cancelamento (`Cancelled`), idempotência e validação de assinatura `Stripe-Signature` em `tests/Feature/PaymentWebhookTest.php` devem continuar passando sem regressões.

---

## 6. Documentação Relacionada

- [Domínio: Cobrança & Assinaturas (Billing) — §5.5 Webhooks Idempotentes e Seguros](../../domain/billing.md)
- [Arquitetura: Tech Stack & Convenções Técnicas — §1 Monitoramento & Logs](../../architecture/tech-stack.md)
- [Especificação Arquivada: Segurança de Assinatura e Trava de Ciclo de Vida no Webhook de Pagamento](../archived/2026-10-webhook-security-and-lifecycle.md)

