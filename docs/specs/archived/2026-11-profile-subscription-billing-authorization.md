# Especificação: Restrição de Exposição e Alteração do Cartão de Crédito e Assinatura do Laboratório em `/perfil`

- **Identificador:** `2026-11-profile-subscription-billing-authorization`
- **Status:** Concluído / Implementado
- **Data de Conclusão:** 2026-11-01
- **Data:** Novembro de 2026
- **Módulos Afetados:**
  - Perfil e Faturamento do Laboratório (`resources/views/profile.blade.php`, `resources/views/livewire/profile/subscription-billing.blade.php`)
  - Integração Stripe e Gestão de Assinaturas (`app/Services/StripeService.php`, `app/Models/Tenant.php`)
  - Testes de Controle de Acesso e Ciclo de Vida (`tests/Feature/TenantAccessEnforcementAndLifecycleTest.php`)

---

## 1. Contexto & Motivação

A demanda **3.3 ([ALTA] Exposição e Alteração de Cartão de Crédito do Laboratório por Dentistas e Parceiros (`/perfil`))** identifica uma falha de controle de acesso e exposição de credenciais sensíveis do gateway de pagamento no fluxo de perfil do laboratório (`/perfil`):

1. **Montagem Incondicional de `subscription-billing.blade.php` em `/perfil` e Emissão de `SetupIntent` para Todos os Perfis:**
   - Em `resources/views/profile.blade.php` (linha 21), `<livewire:profile.subscription-billing />` é montado para qualquer usuário autenticado do tenant (incluindo `Rules::Dentist` e `Rules::LabPartner`).
   - Embora o template de `resources/views/livewire/profile/subscription-billing.blade.php` envolva o card visual com `@if ($this->isAuthorized)` (linha 162), o método `mount(StripeService $stripeService)` (linhas 22–32) **não realiza nenhuma verificação de permissão** antes de carregar `$this->centralTenant()` e chamar `$stripeService->createSetupIntent($tenant)`.
   - Como as propriedades `public string $clientSecret = ''` e `public string $stripeKey = ''` são públicas no componente Livewire, o snapshot JSON serializado do componente (`wire:snapshot`) no HTML de `/perfil` entrega um `client_secret` real de `SetupIntent` da conta Stripe do laboratório (`stripe_customer_id`) para qualquer Dentista ou Parceiro logado.
2. **Ausência de Autorização nas Ações `openUpdateCardModal()`, `updateCard()` e `regularizeSubscription()`:**
   - Em `resources/views/livewire/profile/subscription-billing.blade.php` (linhas 89–158), os métodos públicos `openUpdateCardModal()`, `updateCard(string $paymentMethodId, StripeService $stripeService)` e `regularizeSubscription(StripeService $stripeService)` não verificam se o usuário autenticado possui permissão administrativa (`$this->isAuthorized` / `abort_unless($this->isAuthorized, 403)`).
   - Consequentemente, qualquer Dentista ou Parceiro pode invocar `updateCard()` ou `regularizeSubscription()` diretamente via requisição `POST /livewire/update` (ou utilizando o `clientSecret` exposto no `mount()`), alterando o método de pagamento padrão do laboratório no Stripe (`attachPaymentMethod`), manipulando os dados de cartão (`card_brand`, `card_last_four`) do tenant central ou acionando cobranças/assinaturas indevidamente.

---

## 2. Objetivos

1. **Restringir a montagem de componentes administrativos de assinatura/consumo em `profile.blade.php`:** Renderizar `<livewire:profile.plan-usage />` e `<livewire:profile.subscription-billing />` na view `/perfil` apenas para usuários autorizados da administração do laboratório (`@can('viewAny', \App\Models\Dentist::class)` — perfis `Rules::Owner`, `Rules::Lab` e `Rules::LabManager`), evitando até mesmo a instanciação e serialização Livewire para Dentistas (`Rules::Dentist`) e Parceiros (`Rules::LabPartner`).
2. **Blindar o `mount()` de `subscription-billing.blade.php`:** Impedir que `StripeService::createSetupIntent()` seja chamado e que `$clientSecret` / `$stripeKey` sejam preenchidos quando `! $this->isAuthorized`, garantindo que nenhum `SetupIntent` seja gerado ou exposto a usuários sem permissão administrativa.
3. **Exigir autorização explícita nas ações públicas de faturamento (`updateCard`, `regularizeSubscription`, `openUpdateCardModal`):** Validar `abort_unless($this->isAuthorized, 403)` no início de todas as ações mutáveis do componente `subscription-billing.blade.php` (e proteger a leitura de dados centrais em `centralTenant()`), bloqueando com **HTTP 403 (Forbidden)** qualquer tentativa de invocação direta via payload Livewire por Dentistas ou Parceiros.
4. **Proteger `$clientSecret` e `$stripeKey` contra manipulação client-side:** Aplicar `#[Locked]` (`Livewire\Attributes\Locked`) às propriedades públicas `$clientSecret`, `$stripeKey` e `$isMockMode` em `subscription-billing.blade.php`.
5. **Garantir cobertura de testes automatizados:** Validar via PHPUnit que Dentistas e Parceiros não recebem `clientSecret` em `/perfil` nem ao montar o componente e recebem **HTTP 403** ao tentar executar `updateCard()`, `regularizeSubscription()` ou `openUpdateCardModal()`, preservando intocados os dados de cartão e assinatura do laboratório.

---

## 3. Regras de Negócio & Segurança

### 3.1. RN-01: Elegibilidade para Gestão de Assinatura e Método de Pagamento do Laboratório

1. **Perfis Autorizados (Administração do Laboratório):**
   - Apenas usuários autenticados pertencentes à administração do laboratório — verificados de forma padronizada por `Auth::user()?->can('viewAny', Dentist::class)` (que contempla `Rules::Owner`, `Rules::Lab` e `Rules::LabManager` em `DentistPolicy::viewAny`) — possuem permissão para visualizar dados de plano/assinatura, gerar `SetupIntent` no Stripe e alterar ou regularizar o cartão de crédito do laboratório em `/perfil`.
2. **Perfis Não Autorizados (`Rules::Dentist` e `Rules::LabPartner`):**
   - Dentistas e Parceiros técnicos utilizam `/perfil` exclusivamente para gerenciar seus próprios dados pessoais, senha e conta (`update-profile-information-form`, `update-password-form`, `delete-user-form`).
   - Quando o laboratório estiver com acesso operacional suspenso, Dentistas e Parceiros continuam visualizando apenas a mensagem amigável de suspensão (`Tenant::operationalDenialMessage`), sem qualquer acesso a dados financeiros, `SetupIntent`, bandeira/final do cartão do laboratório ou ações de pagamento.

### 3.2. RN-02: Restrição no Carregamento (`mount`) e Não-Emissão de `SetupIntent` para Não-Administradores

1. **Renderização Condicional em `resources/views/profile.blade.php`:**
   - Os componentes `<livewire:profile.plan-usage />` e `<livewire:profile.subscription-billing />` só devem ser incluídos em `profile.blade.php` dentro da diretiva `@can('viewAny', \App\Models\Dentist::class)`.
2. **Guarda no `mount()` e em `centralTenant()` de `subscription-billing.blade.php`:**
   - Caso o componente `profile.subscription-billing` seja instanciado diretamente em testes ou requisições isoladas por um usuário não autorizado (`! $this->isAuthorized`), o método `mount(StripeService $stripeService)` deve retornar imediatamente (`if (! $this->isAuthorized) { return; }`) **antes** de consultar o tenant central ou chamar `$stripeService->createSetupIntent($tenant)`.
   - Da mesma forma, a propriedade computada `centralTenant()` deve retornar `null` caso `! $this->isAuthorized`, garantindo que nenhum dado da assinatura ou do cartão do laboratório seja hidratado em memória para usuários não autorizados.

### 3.3. RN-03: Autorização Server-Side Obrigatória em `updateCard()`, `regularizeSubscription()` e `openUpdateCardModal()`

1. **Bloqueio com HTTP 403 (`abort_unless($this->isAuthorized, 403)`):**
   - Os métodos `openUpdateCardModal()`, `updateCard(string $paymentMethodId, StripeService $stripeService)` e `regularizeSubscription(StripeService $stripeService)` devem validar `abort_unless($this->isAuthorized, 403);` na primeira linha de execução.
   - Se um usuário com perfil `Rules::Dentist` ou `Rules::LabPartner` tentar invocar qualquer um desses métodos via requisição Livewire, a execução deve ser imediatamente abortada com **HTTP 403**, sem chamar `StripeService::attachPaymentMethod`, `StripeService::createSubscription` nem alterar registros de `Tenant`, `Subscription` ou `Payment`.

---

## 4. Detalhamento Técnico

### 4.1. `resources/views/profile.blade.php`

1. Envolver a montagem dos componentes de consumo do plano e faturamento com `@can('viewAny', \App\Models\Dentist::class)`:
   ```blade
   @can('viewAny', \App\Models\Dentist::class)
       <livewire:profile.plan-usage />
       <livewire:profile.subscription-billing />
   @endcan
   ```

### 4.2. `resources/views/livewire/profile/subscription-billing.blade.php`

1. **Proteção de Propriedades com `#[Locked]`:**
   - Importar `Livewire\Attributes\Locked` e anotar `$clientSecret`, `$stripeKey` e `$isMockMode` com `#[Locked]`.
2. **Restrição em `mount(StripeService $stripeService)` e `centralTenant()`:**
   ```php
   public function mount(StripeService $stripeService): void
   {
       if (! $this->isAuthorized) {
           return;
       }

       $this->stripeKey = (string) config('services.stripe.key');
       $this->isMockMode = empty($this->stripeKey) || str_contains($this->stripeKey, 'placeholder') || app()->environment('testing');

       $tenant = $this->centralTenant();
       if ($tenant) {
           $intent = $stripeService->createSetupIntent($tenant);
           $this->clientSecret = $intent['client_secret'];
       }
   }
   ```
   - Em `centralTenant()`:
     ```php
     #[Computed]
     public function centralTenant(): ?Tenant
     {
         if (! $this->isAuthorized) {
             return null;
         }
         // ...
     }
     ```
3. **Verificação de Permissão nas Ações Mutáveis:**
   - Adicionar `abort_unless($this->isAuthorized, 403);` no início de `openUpdateCardModal()`, `updateCard()` e `regularizeSubscription()`.

---

## 5. Critérios de Aceite (Testes Automatizados)

1. **Não-Exposição de Dados de Faturamento e `SetupIntent` em `/perfil` para Dentistas e Parceiros:**
   - Ao acessar `GET /perfil` autenticado como `Rules::Dentist` ou `Rules::LabPartner`, a resposta HTTP 200 não renderiza `#profile-subscription-billing-card` nem contém `clientSecret` / `seti_` no HTML.
   - Ao montar diretamente `Volt::test('profile.subscription-billing')` autenticado como `Rules::Dentist` ou `Rules::LabPartner`, `clientSecret` permanece vazio (`''`), `centralTenant` retorna `null` e nenhum `SetupIntent` é gerado no Stripe.
2. **Bloqueio de Alteração de Cartão (`updateCard`) e Regularização (`regularizeSubscription`) por Dentistas e Parceiros:**
   - Quando um usuário com perfil `Rules::Dentist` ou `Rules::LabPartner` chama `updateCard('pm_malicious_card')`, `regularizeSubscription()` ou `openUpdateCardModal()` em `profile.subscription-billing`, a chamada retorna status **403 (Forbidden)**.
   - O `card_last_four`, `card_brand` e o status da `Subscription` do `Tenant` no banco central permanecem inalterados.
3. **Preservação do Fluxo para Administradores do Laboratório (`Owner`, `Lab`, `LabManager`):**
   - Usuários administrativos continuam visualizando normalmente o card `Assinatura & Faturamento` em `/perfil`, recebendo o `clientSecret` do `SetupIntent` no `mount()` e conseguindo atualizar o cartão (`updateCard`) e regularizar a assinatura (`regularizeSubscription`).

---

## 6. Documentação Relacionada

- [Domínio: Cobrança & Assinaturas (Billing) — §2.1 Bloqueio de Acesso Operacional e §5 Integração com Stripe](../../domain/billing.md)
- [Arquitetura: Tech Stack & Convenções Técnicas — §3.3 Livewire & Volt](../../architecture/tech-stack.md)
- [Especificação Arquivada: Bloqueio de Acesso e Ciclo de Vida de Inadimplência, Expiração e Cancelamento](../archived/2026-10-tenant-access-enforcement-and-lifecycle.md)
- [Especificação Arquivada: Onboarding do Laboratório, Checkout Stripe e Primeiro Acesso Protegido](../archived/2026-10-tenant-onboarding-and-billing.md)

