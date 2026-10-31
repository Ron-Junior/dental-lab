# Domínio: Cobrança & Assinaturas (Billing)

Billing vive no **banco central** (`database/migrations/`) e descreve a relação financeira entre o SaaS e cada laboratório (`Tenant`). O gateway oficial de pagamentos é o **Stripe**.

---

## 1. Entidades Principais

- **`Plan`:** Plano comercial disponível para contratação. Contém `stripe_price_id` opcional, que aponta sempre para o Price **vigente** (o Price usado em novas assinaturas). Gerenciado pelos sócios em `/planos` (ver §9).
- **`Subscription`:** Assinatura do tenant a um plano. Status (`SubscriptionStatus`): `trial`, `active`, `overdue`, `cancelled`. Guarda `current_period_start/end` e `stripe_subscription_id`, além do **preço contratado** (`price_in_cents`, `stripe_price_id`) e de um eventual reajuste agendado (`scheduled_price_in_cents`, `scheduled_stripe_price_id`, `price_change_effective_at`).
- **`PlanPriceChange`:** Histórico de reajustes de preço de um plano (sócio, preços e Prices antigos e novos, assinaturas afetadas).
- **`OperationalCost`:** Custo operacional do SaaS usado na calculadora de rentabilidade. Tipos: `fixed`, `per_tenant`, `per_storage_gb`, `revenue_percentage` (ver §10).
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
5. **Repasse de Desconto ao Stripe via Coupon:** Ao criar a assinatura no Stripe para um laboratório com desconto pendente/aplicado, o sistema cria um cupom (`coupon`) no Stripe com `percent_off` ou `amount_off` (em centavos de BRL) e duração única (`duration: once`). O cupom é informado no parâmetro `discounts: [['coupon' => $couponId]]` em `subscriptions.create`, garantindo que a primeira fatura da Stripe aplique o abatimento concedido pelos sócios.

---

## 4. Isenção e Checkout

- **Restrição Estrita a Novos Clientes (Não-Assinantes):** A isenção é exclusivamente um benefício de captação/aquisição comercial concedido pelos sócios. **É proibido conceder isenção a quem já é cliente/assinante** (laboratórios com pagamentos já realizados ou assinatura ativa pré-existente). O `ExemptionService::grant` rejeita a concessão caso o tenant já possua histórico de faturamento ativo.
- **Bypass de Checkout:** Tenant isento (`Tenant::isExempt()`) não passa pelo fluxo de checkout de pagamento (sem coleta de cartão) e vai direto para a etapa de provisionamento.
- **Cadastro pelo Painel Admin:** Aceita período de isenção no ato da criação do laboratório; a assinatura nasce `active` cobrindo o período, sem `Payment` inicial.
- **Privilégio Exclusivo de Sócios:** Apenas requisições autenticadas por um `CentralUser` podem conceder isenções e aplicar descontos. Cadastros públicos (self-service do laboratório) nunca criam isenções e seguem o fluxo padrão de checkout com Stripe.
- Ao fim do período de isenção, o tenant volta a ser cobrado normalmente, sem retroativo.
- Concessão e revogação são auditadas (`tenant.exemption.granted|revoked`). Fluxos de checkout/cobrança devem sempre consultar `Tenant::isExempt()`.

---

## 5. Integração com Stripe e Assinaturas Recorrentes

1. **Tokenização Segura (Stripe Elements):**
   - Dados de cartão nunca tocam o banco de dados em texto plano.
   - O checkout utiliza `SetupIntent` para validar e tokenizar o cartão no navegador via Stripe Elements de forma segura.
2. **Método de Pagamento Padrão:**
   - O `PaymentMethod` retornado pelo `SetupIntent` é anexado ao cliente no Stripe (`stripe_customer_id`) e registrado como método padrão de faturamento (`invoice_settings.default_payment_method`).
   - A bandeira (`card_brand`) e os últimos 4 dígitos (`card_last_four`) são persistidos no tenant central para exibição na UI.
3. **Criação da Assinatura Recorrente no Stripe:**
   - No checkout público (laboratórios pagantes), o sistema cria a assinatura recorrente via Stripe Subscriptions API (`$stripe->subscriptions->create`).
   - O plano escolhido é vinculado à assinatura através de `stripe_price_id`.
   - **Provisionamento Automático de Preços:** Se o plano ainda não tiver `stripe_price_id`, o sistema cria o Produto e o Preço correspondente na Stripe sob demanda e atualiza o `Plan`.
   - O identificador da assinatura (`sub_...`) é salvo na coluna `stripe_subscription_id` da `Subscription` local.
   - O ID da fatura inicial gerada (`in_...`) é registrado em `payments.gateway_transaction_id` com status `paid`.
4. **Período de Testes (Trial Concedido por Sócios):**
   - Laboratórios parceiros com período de teste concedido por administradores centrais possuem status `trial` e não são cobrados no checkout.
   - No perfil do laboratório (`/perfil`), o administrador acompanha quantos dias restam para o término do trial (`daysRemainingInTrial()`).
   - Permite cadastrar ou atualizar o cartão a qualquer momento antes do término do período de testes via modal com Stripe Elements.
5. **Webhooks Idempotentes:**
   - O endpoint central `POST /webhook/payment` processa notificações assíncronas do Stripe:
     - `invoice.payment_succeeded`: renova `current_period_end` da assinatura local, garante status `active` e marca cobranças como `paid`.
     - `invoice.payment_failed`: registra a falha de pagamento e aciona o estado de inadimplência (`overdue`).
     - `customer.subscription.deleted`: marca a assinatura local como `cancelled`.
   - O processamento de webhooks é estritamente idempotente, validando assinaturas e evitando duplicações por retransmissão de eventos.

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

---

## 9. Gestão de Planos e Reajuste de Preço

> Especificação: [2026-10-plan-management-and-profitability](../specs/archived/2026-10-plan-management-and-profitability.md).

- Os sócios podem criar, editar e ativar/desativar planos. **Não há exclusão.** Plano inativo some do checkout e do cadastro pelo sócio. Quando um plano com assinaturas vigentes é desativado, o sócio escolhe um **plano ativo de destino**: os assinantes recebem aviso por e-mail e no painel de perfil (`/perfil`) informando que migrarão para o novo plano após **2 meses** (`current_period_end + 2 meses`). Se o plano for reativado antes da data, a migração agendada é cancelada.
- Nome e limites mudam na hora. `billing_cycle` não pode ser alterado se o plano tiver assinaturas `active`, `trial` ou `overdue`.
- **Preço efetivo da assinatura** = `subscriptions.price_in_cents ?? plans.price_in_cents`.
- **Reajuste:** cria um novo Price no Stripe, porque Prices são imutáveis, e o aplica de imediato a novas assinaturas. Assinantes atuais têm **carência de 2 ciclos**: `price_change_effective_at = current_period_end + 2 ciclos`. O período em curso não conta, e em trial a contagem parte do fim do trial. Um novo reajuste antes da virada substitui o valor agendado e mantém a data.
- Um job diário (`plans:apply-scheduled-prices`) troca o price no Stripe (`proration_behavior: none`) e promove o preço agendado para o contratado. É idempotente e isola falhas por assinatura.
- O responsável do laboratório é avisado por e-mail no momento do reajuste.
- Reduzir um limite é permitido, com aviso prévio de quantos laboratórios ficarão acima dele.

---

## 10. Custos Operacionais e Rentabilidade

> Especificação: [2026-10-plan-management-and-profitability](../specs/archived/2026-10-plan-management-and-profitability.md).

- `OperationalCost.amount` depende do tipo: centavos/mês (`fixed`), centavos/mês por laboratório (`per_tenant`), centavos/GB/mês (`per_storage_gb`) ou pontos-base sobre a receita (`revenue_percentage`, 399 = 3,99%). Taxas do gateway são cadastradas como custos comuns.
- Valores são normalizados para mensal (plano anual ÷ 12). Só entram custos ativos.
- **Margem por plano:** preço − custos percentuais − custos por laboratório − storage (uso médio real e pior caso pelo limite) − rateio do custo fixo entre os laboratórios pagantes. Status: Prejuízo (< 0), Apertado (< 20%) ou Saudável.
- **Visão real:**
  - Receita = preço efetivo das assinaturas `active` de laboratórios **não isentos**.
  - Custos = fixo + custo por laboratório × laboratórios que consomem (active, trial e isentos) + storage real total × custo/GB + receita × custos percentuais.
  - O storage real é medido em cada tenant e fica em cache por 1h.
