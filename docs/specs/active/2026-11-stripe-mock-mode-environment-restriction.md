# Especificação: Restrição Estrita do "Mock Mode" de Pagamento (Stripe) a Ambientes de Teste/Local e Política Fail-Closed em Produção

- **Identificador:** `2026-11-stripe-mock-mode-environment-restriction`
- **Status:** Em Andamento
- **Data:** Novembro de 2026
- **Módulos Afetados:**
  - Integração com Gateway de Pagamentos (`app/Services/StripeService.php`)
  - Checkout Central de Onboarding (`resources/views/livewire/pages/central/checkout.blade.php`)
  - Faturamento e Cartão no Perfil do Laboratório (`resources/views/livewire/profile/subscription-billing.blade.php`)
  - Testes Automatizados de Pagamento e Onboarding (`tests/Feature/StripeServiceTest.php`, `tests/Feature/TenantOnboardingAndBillingTest.php`)

---

## 1. Contexto & Motivação

A demanda **4.1 ([ALTA] Ativação Silenciosa de "Mock Mode" de Pagamento fora de Ambiente de Teste)** identifica uma vulnerabilidade crítica na validação de configuração do gateway de pagamentos Stripe e na ativação do modo simulado (`isMock` / `$isMockMode`):

1. **Ativação Silenciosa de Mock Mode em `StripeService.php` (linhas 18–25 e 441–444):**
   - No construtor de `App\Services\StripeService`, o cliente `$this->stripe` só é instanciado quando `config('services.stripe.secret')` não está vazio e não contém `'placeholder'`.
   - O método `StripeService::isMock()` é definido como:
     ```php
     protected function isMock(): bool
     {
         return $this->stripe === null || app()->environment('testing');
     }
     ```
   - Como `isMock()` retorna `true` sempre que `$this->stripe === null` — **independentemente do ambiente da aplicação (`APP_ENV`)** —, qualquer falha de configuração, variável de ambiente ausente (`STRIPE_SECRET` vazio) ou valor residual `'placeholder'` em ambientes de `production` ou `staging` faz com que o `StripeService` entre silenciosamente em modo simulado.
   - Quando em modo simulado, `createCustomer()`, `createSetupIntent()`, `attachPaymentMethod()` e `createSubscription()` retornam identificadores fictícios (`cus_mock_...`, `seti_mock_...`, `sub_mock_...`, `in_mock_...`) com status `'active'` e cartão `'visa'` final `'4242'`, sem realizar nenhuma comunicação ou cobrança real na Stripe.

2. **Exposição do Botão de Simulação e Aceitação de `pm_mock_test_card` no Checkout e no Perfil (`checkout.blade.php` e `subscription-billing.blade.php`):**
   - Em `resources/views/livewire/pages/central/checkout.blade.php` (linha 91) e `resources/views/livewire/profile/subscription-billing.blade.php` (linha 33), a flag `$this->isMockMode` é avaliada como:
     ```php
     $this->isMockMode = empty($this->stripeKey) || str_contains($this->stripeKey, 'placeholder') || app()->environment('testing');
     ```
   - Além disso, em `checkout.blade.php`, as propriedades públicas `$clientSecret`, `$stripeKey` e `$isMockMode` ainda não possuem o atributo `#[Locked]`.
   - Se `services.stripe.key` ou `services.stripe.secret` estiver ausente ou com `'placeholder'` em produção/staging, a interface de checkout renderiza o banner `"Ambiente de Testes / Simulação"` e permite que qualquer usuário acione `confirmPaymentMethod('pm_mock_test_card')` (linhas 187–268), marcando a fatura (`Payment`) como `Paid`, ativando a assinatura (`SubscriptionStatus::Active`) e disparando o provisionamento completo do laboratório (`CreateLaboratory::dispatchProvisioning($this->tenant)`) de forma 100% gratuita.

---

## 2. Objetivos

1. **Restringir o modo simulado (`isMock` / `$isMockMode`) exclusivamente aos ambientes `local` e `testing`:**
   - Garantir que `StripeService::isMock()`, `checkout.blade.php` e `subscription-billing.blade.php` só possam ativar o modo simulado quando `app()->environment(['local', 'testing'])` for `true`.
   - Em qualquer outro ambiente (`production`, `staging`, etc.), o modo mock deve ser **estritamente proibido** (`false`).
2. **Implementar política *Fail-Closed* com lançamento de exceção quando as chaves do Stripe estiverem ausentes ou inválidas fora de `['local', 'testing']`:**
   - Lançar `RuntimeException` explícita (com registro em `Log::critical`) caso `services.stripe.secret` ou `services.stripe.key` estejam vazios ou contenham `'placeholder'` fora dos ambientes `['local', 'testing']`, bloqueando imediatamente a operação em vez de aprovar assinaturas falsas.
3. **Bloquear identificadores de métodos de pagamento simulados (`pm_mock_*`) fora do modo mock:**
   - Rejeitar com exceção (`InvalidArgumentException` / `RuntimeException`) qualquer chamada a `attachPaymentMethod()` (ou `confirmPaymentMethod` / `updateCard`) com `paymentMethodId` iniciado por `pm_mock_` quando o serviço não estiver em modo mock autorizado (`! $this->isMock()`).
4. **Blindar propriedades sensíveis do Checkout com `#[Locked]`:**
   - Anotar `$clientSecret`, `$stripeKey` e `$isMockMode` em `resources/views/livewire/pages/central/checkout.blade.php` com `#[Locked]` (`Livewire\Attributes\Locked`), alinhando-o ao padrão já aplicado em `subscription-billing.blade.php`.
5. **Assegurar cobertura de testes automatizados (PHPUnit):**
   - Validar que em ambiente `production` ou `staging` sem chaves (ou com `'placeholder'`), `StripeService`, `checkout.blade.php` e `subscription-billing.blade.php` jamais entram em modo mock, lançam `RuntimeException` e não ativam assinaturas nem provisionam laboratórios gratuitamente.

---

## 3. Regras de Negócio & Segurança

### 3.1. RN-01: Restrição Estrita de Ambiente para o Modo Simulado (`['local', 'testing']`)

1. **Condição Única de Elegibilidade de Ambiente:**
   - O modo de simulação de pagamentos (`isMock()` no `StripeService` e `$isMockMode` nos componentes Livewire) é um recurso exclusivo para desenvolvimento local e execução de testes automatizados.
   - Nenhuma condição baseada na ausência de chaves (`empty($secret)`, `empty($stripeKey)`, `$this->stripe === null`) ou na presença da substring `'placeholder'` pode ativar o modo simulado se `app()->environment(['local', 'testing'])` for `false`.
2. **Definição em `StripeService::isMock()`:**
   - `isMock()` deve retornar `true` **somente** quando `app()->environment(['local', 'testing'])` for verdadeiro **e** (`app()->environment('testing')` ou `$this->stripe === null`):
     ```php
     public function isMockMode(): bool
     {
         return app()->environment(['local', 'testing'])
             && ($this->stripe === null || app()->environment('testing'));
     }
     ```
   - `protected function isMock(): bool` delega para `isMockMode()`, permitindo que os componentes Livewire consultem a mesma regra centralizada ou apliquem exatamente a mesma restrição de ambiente.

### 3.2. RN-02: Política *Fail-Closed* e Exceção Obrigatória por Falha de Configuração em Produção/Staging

1. **Validação de Credenciais (`STRIPE_KEY` e `STRIPE_SECRET`):**
   - Uma credencial do Stripe é considerada **inválida/ausente** quando estiver vazia (`! is_string($value) || trim($value) === ''`) ou contiver `'placeholder'` (`str_contains(strtolower($value), 'placeholder')`).
2. **Comportamento Fora de `['local', 'testing']`:**
   - Quando `! app()->environment(['local', 'testing'])`:
     - Se `config('services.stripe.secret')` ou `config('services.stripe.key')` for inválido/ausente no momento de instanciar ou operar o `StripeService` (ou ao montar/submeter `checkout.blade.php` e `subscription-billing.blade.php`), o sistema deve registrar `Log::critical(...)` e lançar imediatamente uma `\RuntimeException`.
     - Nenhuma operação de `createCustomer`, `createSetupIntent`, `attachPaymentMethod`, `createSubscription`, `createCouponForDiscount` ou `createPrice` pode prosseguir sem um `StripeClient` válido e credenciais reais configuradas.
3. **Rejeição de Tokens Simulados (`pm_mock_*`):**
   - Se `attachPaymentMethod(Tenant $tenant, string $paymentMethodId)` (ou `confirmPaymentMethod` / `updateCard`) receber um `$paymentMethodId` começando com `'pm_mock_'` quando `$this->isMock()` for `false`, deve lançar `\RuntimeException` antes de realizar qualquer alteração local ou chamada de rede.

### 3.3. RN-03: Blindagem dos Componentes Livewire (`checkout.blade.php` e `subscription-billing.blade.php`)

1. **Propriedades `#[Locked]` no Checkout Central:**
   - Em `resources/views/livewire/pages/central/checkout.blade.php`, as propriedades públicas `$clientSecret`, `$stripeKey` e `$isMockMode` devem receber o atributo `#[Locked]`, impedindo adulteração via payload `POST /livewire/update`.
2. **Determinação de `$isMockMode` Restrita a `['local', 'testing']`:**
   - Tanto em `checkout.blade.php` quanto em `subscription-billing.blade.php`:
     ```php
     $isAllowedMockEnv = app()->environment(['local', 'testing']);
     $hasValidStripeKey = ! empty($this->stripeKey) && ! str_contains(strtolower($this->stripeKey), 'placeholder');

     if (! $isAllowedMockEnv && ! $hasValidStripeKey) {
         Log::critical('Checkout Stripe bloqueado: STRIPE_KEY ausente ou inválida fora de ambiente local/testing.', [
             'environment' => app()->environment(),
         ]);

         throw new \RuntimeException('Configuração de pagamento (STRIPE_KEY) ausente ou inválida para este ambiente.');
     }

     $this->isMockMode = $isAllowedMockEnv && (! $hasValidStripeKey || app()->environment('testing') || $stripeService->isMockMode());
     ```
3. **Guarda Adicional em `confirmPaymentMethod` (`checkout.blade.php`) e `updateCard` (`subscription-billing.blade.php`):**
   - Caso `! app()->environment(['local', 'testing'])` e `str_starts_with($paymentMethodId, 'pm_mock_')`, lançar `\RuntimeException` imediatamente, garantindo que nenhum payload forjado ative assinatura ou altere cartão com identificador de simulação.

---

## 4. Detalhamento Técnico

### 4.1. `app/Services/StripeService.php`

1. **Validação no Construtor e Método `ensureConfigured()`:**
   - No construtor (`__construct()`), verificar se `services.stripe.secret` é válido (`! empty($secret) && ! str_contains(strtolower((string) $secret), 'placeholder')`).
   - Se for válido, instanciar `$this->stripe = new StripeClient($secret)`.
   - Se **não** for válido e `! app()->environment(['local', 'testing'])`, registrar `Log::critical` e lançar `\RuntimeException('Configuração do Stripe (STRIPE_SECRET) ausente ou inválida em ambiente produtivo.')`.
2. **Restrição em `isMock()` / `isMockMode()`:**
   ```php
   public function isMockMode(): bool
   {
       return $this->isMock();
   }

   protected function isMock(): bool
   {
       return app()->environment(['local', 'testing'])
           && ($this->stripe === null || app()->environment('testing'));
   }
   ```
3. **Verificação Defensiva de Cliente e Rejeição de `pm_mock_*`:**
   - Em `attachPaymentMethod()`, quando `! $this->isMock()` e `str_starts_with($paymentMethodId, 'pm_mock_')`, lançar `\RuntimeException('Método de pagamento simulado não é permitido fora de ambiente de testes.')`.
   - Sempre que `! $this->isMock()` e `$this->stripe === null`, lançar `\RuntimeException('Cliente Stripe não configurado.')`.

### 4.2. `resources/views/livewire/pages/central/checkout.blade.php`

1. Adicionar `#[Locked]` às propriedades `$clientSecret`, `$stripeKey` e `$isMockMode`.
2. No `mount()`, validar que fora de `app()->environment(['local', 'testing'])` a chave `services.stripe.key` é obrigatória e não pode conter `'placeholder'` (lançando `\RuntimeException` caso contrário), e calcular `$this->isMockMode` restrito a `app()->environment(['local', 'testing'])`.
3. Em `confirmPaymentMethod()`, bloquear qualquer `$paymentMethodId` iniciado por `'pm_mock_'` quando `! app()->environment(['local', 'testing'])` (ou `! $this->isMockMode`), lançando `\RuntimeException`.

### 4.3. `resources/views/livewire/profile/subscription-billing.blade.php`

1. No `mount()`, aplicar a mesma validação de `services.stripe.key` fora de `app()->environment(['local', 'testing'])` e restringir `$this->isMockMode` exclusivamente a `app()->environment(['local', 'testing'])`.
2. Em `updateCard()`, rejeitar `$paymentMethodId` iniciado por `'pm_mock_'` quando `! app()->environment(['local', 'testing'])`.

---

## 5. Critérios de Aceite (Testes Automatizados)

1. **Bloqueio de Mock Mode em Produção/Staging com Chaves Ausentes ou `'placeholder'` (`StripeService`):**
   - Quando o ambiente é definido como `production` ou `staging` e `services.stripe.secret` está vazio (`''`/`null`) ou contém `'placeholder'`, instanciar `StripeService` (ou invocar qualquer operação de cobrança) lança `\RuntimeException` e **não** gera IDs `cus_mock_*`, `seti_mock_*` ou `sub_mock_*`.
2. **Bloqueio de Checkout Gratuito Fora de Ambiente de Teste (`checkout.blade.php`):**
   - Em ambiente `production` ou `staging` com `services.stripe.key` ou `services.stripe.secret` vazio/placeholder, a montagem de `/checkout/{tenant}` falha com `\RuntimeException` (HTTP 500) e nunca renderiza o botão `"Simular Pagamento"` (`$isMockMode === false`).
   - Em ambiente `production` ou `staging` (mesmo com chaves configuradas), chamar `confirmPaymentMethod('pm_mock_test_card')` lança `\RuntimeException`, mantendo a `Subscription` não-ativa, o `Payment` como `Pending` e sem despachar `ProvisionTenant`.
3. **Proteção `#[Locked]` nas Propriedades do Checkout (`checkout.blade.php`):**
   - Tentativa de modificar `$isMockMode`, `$clientSecret` ou `$stripeKey` via payload Livewire em `pages.central.checkout` é rejeitada (`CannotUpdateLockedPropertyException`).
4. **Preservação do Funcionamento nos Ambientes `testing` e `local`:**
   - Nos ambientes `testing` e `local` (sem chaves reais ou com `'placeholder'`), `StripeService` e o fluxo de checkout/perfil continuam operando normalmente em modo simulado para permitir o desenvolvimento local e a suíte de testes automatizados.

---

## 6. Documentação Relacionada

- [Domínio: Cobrança & Assinaturas (Billing) — §5 Integração com Stripe e Assinaturas Recorrentes](../../domain/billing.md)
- [Arquitetura: Tech Stack & Convenções Técnicas — §3.3 Livewire & Volt](../../architecture/tech-stack.md)
- [Especificação Arquivada: Onboarding do Laboratório, Checkout Stripe e Primeiro Acesso Protegido](../archived/2026-10-tenant-onboarding-and-billing.md)
- [Especificação Arquivada: Restrição de Exposição e Alteração do Cartão de Crédito e Assinatura do Laboratório em `/perfil`](../archived/2026-11-profile-subscription-billing-authorization.md)

