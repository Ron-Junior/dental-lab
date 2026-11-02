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

### 2.1. Bloqueio de Acesso Operacional e Ciclo de Expiração ([spec](../specs/archived/2026-10-tenant-access-enforcement-and-lifecycle.md))

- **Middleware `EnsureTenantIsOperational` (`tenant.operational`):** Protege todas as rotas operacionais do laboratório em `routes/tenant.php`. O acesso só é liberado quando `$tenant->status === TenantStatus::Active` e o laboratório possui cobertura financeira válida: isenção ativa (`isExempt()`), `Trial` dentro do prazo (`current_period_end > now()`) ou assinatura `Active` vigente (não originada de isenção já expirada/revogada sem assinatura Stripe).
- **Redirecionamento para Regularização (`/perfil`):** Laboratórios com assinatura `Overdue`, `Cancelled`, `Trial` vencido ou `Exemption` encerrada/revogada têm suas rotas operacionais bloqueadas e são redirecionados para `/perfil`, onde o administrador visualiza o motivo da suspensão e pode cadastrar/atualizar seu cartão via Stripe para ativar/regularizar a assinatura imediatamente. Rotas de autenticação, `/perfil`, logout e impersonation de suporte (`impersonated_by_central`) permanecem acessíveis. A flag de sessão `impersonated_by_central` em `/impersonate/{token}` (`routes/tenant.php`) só é gravada após validar explicitamente que `$tokenModel->tenant_id === (string) tenant('id')` e concluir `UserImpersonation::makeResponse($tokenModel)` ([spec](../specs/archived/2026-11-impersonation-token-tenant-validation-before-session.md)), impedindo bypass do bloqueio operacional com tokens de outro laboratório ou expirados.
- **Restrição Estrita de Acesso ao Faturamento e Cartão em `/perfil` ([spec](../specs/archived/2026-11-profile-subscription-billing-authorization.md)):** Os componentes `profile.plan-usage` e `profile.subscription-billing` são montados em `/perfil` estritamente para usuários da administração do laboratório (`@can('viewAny', Dentist::class)` — perfis `Owner`, `Lab` e `LabManager`). O método `mount()` de `subscription-billing.blade.php` não instancia `SetupIntent` nem popula `$clientSecret` para Dentistas (`Rules::Dentist`) ou Parceiros (`Rules::LabPartner`), e as ações `openUpdateCardModal()`, `updateCard()` e `regularizeSubscription()` exigem autorização explícita (`abort_unless($this->isAuthorized, 403)`), impedindo que usuários externos visualizem dados da assinatura ou alterem o método de pagamento do laboratório.
- **Rotina Agendada (`subscriptions:expire-lifecycle`):** Comando agendado em `routes/console.php` que encerra períodos de `Trial` vencidos (`current_period_end <= now()`) e transiciona assinaturas cuja isenção (`Exemption`) atingiu `ends_at` sem método de pagamento recorrente ativo no Stripe (tentando ativar a cobrança caso o cliente já tenha cadastrado cartão, ou alterando o status para `Overdue` com geração de `Payment` pendente).
- **Revogação Imediata de Isenção:** Ao revogar manualmente uma isenção em `ExemptionService::revoke`, caso o tenant não possua assinatura paga no Stripe, sua assinatura transiciona imediatamente para `Overdue` e o acesso operacional é bloqueado até a regularização.

---

## 3. Aplicação de Desconto

`ApplyDiscountService` atua sobre o próximo pagamento (`pending` com menor `due_date`):

1. Rejeita desconto expirado ou já aplicado.
2. Calcula o abatimento (percentual: `floor(valor × pct / 100)`), nunca deixando o valor abaixo de 0.
3. Salva `metadata.discount = {discount_id, original_amount_in_cents, discount_in_cents}`.
4. Marca o desconto como aplicado. Um pagamento aceita no máximo um desconto.
5. **Preservação e Recálculo ao Trocar Plano no Checkout ([spec](../specs/archived/2026-10-checkout-discount-recalculation.md)):** Quando o cliente altera o plano na tela de checkout (`selectPlan`) ou confirma o pagamento (`confirmPaymentMethod`), `ApplyDiscountService::recalculateForPayment` recalcula o desconto ativo sobre o preço do plano selecionado (`$plan->price_in_cents`), atualizando `amount_in_cents` e `metadata.discount` (`original_amount_in_cents` e `discount_in_cents`). Caso o desconto tenha expirado antes da conclusão do checkout, os metadados de desconto são limpos e o valor integral do plano é restabelecido.
6. **Repasse de Desconto ao Stripe via Coupon:** Ao criar a assinatura no Stripe para um laboratório com desconto pendente/aplicado, o sistema cria um cupom (`coupon`) no Stripe com `percent_off` ou `amount_off` (em centavos de BRL) e duração única (`duration: once`). O cupom é informado no parâmetro `discounts: [['coupon' => $couponId]]` em `subscriptions.create`, garantindo que a primeira fatura da Stripe aplique o abatimento concedido pelos sócios.

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

1. **Tokenização Segura (Stripe Elements) e Restrição Estrita do Modo Simulado ([spec](../specs/active/2026-11-stripe-mock-mode-environment-restriction.md)):**
   - Dados de cartão nunca tocam o banco de dados em texto plano.
   - O checkout utiliza `SetupIntent` para validar e tokenizar o cartão no navegador via Stripe Elements de forma segura.
   - **Restrição de Mock Mode a `local` e `testing` (*Fail-Closed* em Produção/Staging):** O modo de simulação (`StripeService::isMock()` e `$isMockMode` em `checkout.blade.php` e `subscription-billing.blade.php`) é restrito **exclusivamente** a `app()->environment(['local', 'testing'])`. Fora desses ambientes (`production`, `staging`, etc.), caso `services.stripe.key` (`STRIPE_KEY`) ou `services.stripe.secret` (`STRIPE_SECRET`) estejam vazios ou contenham `'placeholder'`, o sistema **jamais** ativa o modo mock: registra `Log::critical` e lança `\RuntimeException` imediata, bloqueando qualquer aprovação gratuita de assinatura ou uso de identificadores simulados (`pm_mock_*`).
2. **Método de Pagamento Padrão:**
   - O `PaymentMethod` retornado pelo `SetupIntent` é anexado ao cliente no Stripe (`stripe_customer_id`) e registrado como método padrão de faturamento (`invoice_settings.default_payment_method`).
   - A bandeira (`card_brand`) e os últimos 4 dígitos (`card_last_four`) são persistidos no tenant central para exibição na UI (visível exclusivamente para administradores do laboratório em `/perfil`).
   - A geração de `SetupIntent` no perfil (`subscription-billing.blade.php`) e a execução de `updateCard()` / `regularizeSubscription()` são protegidas por autorização server-side restrita à administração do tenant ([spec](../specs/archived/2026-11-profile-subscription-billing-authorization.md)), com `$clientSecret`, `$stripeKey` e `$isMockMode` blindados via `#[Locked]` tanto em `subscription-billing.blade.php` quanto em `checkout.blade.php` ([spec](../specs/active/2026-11-stripe-mock-mode-environment-restriction.md)).
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
5. **Webhooks Idempotentes e Seguros ([spec](../specs/active/2026-10-webhook-security-and-lifecycle.md)):**
   - O endpoint central `POST /webhook/payment` valida a autenticidade criptográfica do cabeçalho `Stripe-Signature` via `\Stripe\Webhook::constructEvent` usando `config('services.stripe.webhook_secret')`. Requisições sem assinatura válida são rejeitadas com HTTP 400.
   - Processa notificações assíncronas do Stripe:
     - `invoice.payment_succeeded` (e equivalentes `charge.paid`, `payment.succeeded`): marca a cobrança como `paid`, garante status `active` na assinatura e renova `current_period_start` / `current_period_end` priorizando os timestamps do período da fatura no Stripe ou calculando via `Plan::addBillingCycles` (`+1 mês` para mensal, `+1 ano` para anual). Se houver reajuste ou migração agendada vencida (`price_change_effective_at <= now()`), promove os valores `scheduled_*` na renovação.
     - **Trava de Ciclo de Vida no Provisionamento:** O job `ProvisionTenant` (e o envio do e-mail de boas-vindas com token de primeiro acesso) só é disparado quando o tenant está em onboarding (`PendingPayment` ou `Failed` e ainda não verificado). Renovações subsequentes de laboratórios já ativos nunca re-executam o provisionamento nem resetam tokens de convite.
     - `invoice.payment_failed` (e equivalentes `charge.failed`, `payment.failed`): marca a cobrança como `failed` e altera a assinatura de tenants ativos não-isentos (`! $tenant->isExempt()`) para `overdue`.
     - `customer.subscription.deleted` (e `subscription.cancelled`): marca a assinatura local como `cancelled` e limpa agendamentos futuros (`scheduled_*`).
   - O processamento de webhooks é estritamente idempotente, evitando duplicações por retransmissão de eventos.

---

## 6. Primeiro Acesso Protegido e Convites de Usuários

- O cadastro (self-service ou sócio) **não coleta senhas** no banco central.
- A senha do dono do laboratório só é definida no **Primeiro Acesso** (`/completar-cadastro` no subdomínio do laboratório) após o tenant já estar provisionado.
- **Proteção das Rotas de Onboarding/Checkout e Não-Exposição de `first_access_url` ([spec](../specs/archived/2026-10-onboarding-checkout-and-first-access-url-protection.md)):**
  - As rotas centrais `/checkout/{tenant}` (`onboarding.checkout`) e `/bem-vindo/{tenant}` (`onboarding.show`) exigem sessão autorizada do criador do laboratório (`onboarding_authorized_tenants`), assinatura de URL válida (`signed` / `hasValidSignature()`) ou autenticação de sócio central (`auth:central`), retornando **HTTP 403** para acessos públicos arbitrários por slug.
  - Nos componentes `onboarding.blade.php` e `checkout.blade.php`, a propriedade `public Tenant $tenant` utiliza `#[Locked]` (`Livewire\Attributes\Locked`), impedindo substituição do tenant em requisições `POST /livewire/update`.
  - A URL assinada de primeiro acesso (`/completar-cadastro` com `token`, `email` e `signature`) **nunca é persistida** nos metadados do `Tenant` (`tenants.data['first_access_url']`) nem exposta no HTML ou em redirecionamentos de `/bem-vindo/{tenant}` e `/checkout/{tenant}`. O link de primeiro acesso é enviado exclusivamente por e-mail (`TenantWelcomeNotification`) ao `owner_email` via `ProvisionTenant` e `ResendFirstAccessLink`, enquanto os redirecionamentos pós-ativação apontam apenas para `route('login', ['tenant' => $tenant->id])`.
- **Vinculação Estrita ao Token, Proteção `#[Locked]` e Isolamento de Convites ([spec arquivada](../specs/archived/2026-10-partner-dentist-privilege-escalation-and-invitation-security.md), [spec arquivada](../specs/archived/2026-10-complete-registration-locked-properties-and-token-validation.md)):**
  - As propriedades públicas de estado e roteamento em `complete-registration.blade.php` (`$email`, `$token` e `$isExpired`) utilizam obrigatoriamente o atributo `#[Locked]` (`Livewire\Attributes\Locked`), impedindo que requisições subsequentes `POST /livewire/update` adulterem o e-mail, o token ou a flag de expiração após a validação inicial do middleware `signed`.
  - Tanto no `mount()` quanto no `completeRegistration()`, o sistema resolve primeiramente o `User` ativo pelo `$this->email` e valida `InvitationToken::where('token', $this->token)->where('user_id', $user->id)->whereNull('used_at')->where('expires_at', '>', now())->first()`, garantindo correspondência estrita entre o token, o `user_id` e a validade temporal antes de permitir a definição de senha.
  - A trava `tenant()->isVerified()` e a marcação `tenant()->markAsVerified()` (`verified_at = now()`) aplicam-se **estritamente ao proprietário/administrador inicial do laboratório (`owner_email` / `Rules::Lab` / `Rules::Owner`)**.
  - Convites enviados para **Parceiros (`LabPartner`) e Dentistas (`Dentist`)** via `SendInvitation` utilizam seus próprios `InvitationToken` individuais: permanecem funcionais mesmo após o laboratório já estar verificado pelo dono (`verified_at !== null`) e nunca marcam `tenants.verified_at` caso sejam aceitos antes do primeiro acesso do proprietário.
- **Geração Criptograficamente Segura de Tokens de Convite ([spec](../specs/archived/2026-10-invitation-token-cryptographic-entropy.md)):**
  - Todos os tokens persistidos em `invitation_tokens` (`ProvisionTenant`, `ResendFirstAccessLink` e `SendInvitation`) são gerados via CSPRNG com `Str::random(64)` (64 caracteres alfanuméricos URL-safe, ~381 bits de entropia), sendo vedado o uso de funções baseadas em timestamp como `uniqid()` ou `Hash::make(uniqid())`.
- Ao definir a senha com sucesso, o sistema marca `$user->markEmailAsVerified()` e invalida o `InvitationToken`, impedindo qualquer reutilização futura do link.

---

## 7. Limites do Plano

`Plan.limits` (JSON) define `users_limit`, `storage_gb_limit` e `monthly_exams_limit` (itens `RequestService` no mês). Chave ausente = ilimitado. Níveis: `normal` (<80%), `warning` (≥80%), `critical` (≥95%), `exceeded` (≥100%). Em `exceeded` apenas a criação é bloqueada (HTTP 403 / toast). Detalhes em [spec](../specs/archived/2026-10-plan-limits.md).

- **Política *Fail-Closed* para Inadimplentes/Expirados ([spec](../specs/active/2026-10-tenant-access-enforcement-and-lifecycle.md)):** `TenantLimitChecker` só aplica limites abertos quando o laboratório possui cobertura operacional ativa (`Active` vigente, `Trial` vigente com `current_period_end > now()`, ou isenção ativa). Se a assinatura estiver `Overdue`, `Cancelled`, com `Trial` vencido ou sem cobertura, `assertCanCreate` bloqueia imediatamente qualquer criação (`PlanLimitExceededException`) e os recursos são tratados com limite `0` (`UsageLevel::Exceeded`), impedindo que clientes inadimplentes recebam limites ilimitados. O fallback *fail-open* ocorre exclusivamente em caso de falha técnica de leitura no banco central.
- **Validação de Quota de Armazenamento (`PlanResource::Storage`) por Tamanho de Entrada ([spec](../specs/archived/2026-10-storage-limit-and-s3-upload-security.md)):** Tanto a geração de URL pré-assinada (`getPreSignedUploadUrl`) e a confirmação de upload direto para S3 (`addFilesToRequest`) quanto o envio de foto de conclusão de demanda (`finish-demand`) convertem o tamanho real dos arquivos de entrada para gigabytes ($\text{bytes} / 1024^3$) antes de consultar `guardPlanLimit(PlanResource::Storage, $incomingGb)`. Caso o incremento ultrapasse `storage_gb_limit`, o upload é bloqueado preventivamente (e eventuais objetos recém-enviados ao S3 sem registro no banco são removidos). Todas as fotos de conclusão de etapas (`PartnerDemand`) também geram registros polimórficos na tabela `files`, entrando no cálculo de consumo de `PlanResource::Storage` e nos custos de armazenamento da `PlanProfitabilityCalculator`.

---

## 8. Regras Gerais

- Valores sempre em centavos inteiros (ver [Convenções de Dados](../architecture/data-conventions.md)).
- Mudanças de status via webhook são idempotentes.

---

## 9. Gestão de Planos e Reajuste de Preço

> Especificações: [2026-10-plan-management-and-profitability](../specs/archived/2026-10-plan-management-and-profitability.md) e [2026-10-plan-deactivation-and-price-schedule-conflict](../specs/active/2026-10-plan-deactivation-and-price-schedule-conflict.md).

- Os sócios podem criar, editar e ativar/desativar planos. **Não há exclusão.** Plano inativo some do checkout e do cadastro pelo sócio. Quando um plano com assinaturas vigentes é desativado, o sócio escolhe um **plano ativo de destino com o mesmo `billing_cycle`**: os assinantes recebem aviso por e-mail e no painel de perfil (`/perfil`) informando que migrarão para o novo plano após **2 ciclos do plano** (`Plan::addBillingCycles($currentPeriodEnd, 2)`: 2 meses no mensal, 2 anos no anual). Se o plano for reativado antes da data (`ReactivatePlan`), a migração agendada é cancelada — e, caso o preço vigente do plano reativado divirja do preço contratado da assinatura, o agendamento de reajuste para o preço vigente do plano é restaurado.
- Nome e limites mudam na hora. `billing_cycle` não pode ser alterado se o plano tiver assinaturas `active`, `trial` ou `overdue`.
- **Preço efetivo da assinatura** = `subscriptions.price_in_cents ?? plans.price_in_cents`.
- **Reajuste:** cria um novo Price no Stripe, porque Prices são imutáveis, e o aplica de imediato a novas assinaturas. Assinantes atuais sem migração de desativação pendente (`scheduled_plan_id === null`) têm **carência de 2 ciclos**: `price_change_effective_at = current_period_end + 2 ciclos`. O período em curso não conta, e em trial a contagem parte do fim do trial. Um novo reajuste antes da virada substitui o valor agendado e mantém a data. Se o plano reajustado for o **destino** de uma migração agendada (`scheduled_plan_id = $plan->id`), as assinaturas que migrarão para ele têm seu `scheduled_price_in_cents` e `scheduled_stripe_price_id` atualizados para o novo preço do plano de destino.
- Um job diário (`plans:apply-scheduled-prices`) troca o price no Stripe (`proration_behavior: none`) e promove o preço/plano agendado para o contratado, garantindo consistência com o plano de destino. É idempotente e isola falhas por assinatura.
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
