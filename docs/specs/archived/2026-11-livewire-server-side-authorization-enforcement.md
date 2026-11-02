# Especificação: Autorização Server-Side (`$this->authorize`) e Prevenção de Escalação de Privilégio em Componentes Livewire

- **Identificador:** `2026-11-livewire-server-side-authorization-enforcement`
- **Status:** Concluído / Arquivado
- **Data:** Novembro de 2026
- **Módulos Afetados:**
  - Gestão de Dentistas (`resources/views/components/dentists/store.blade.php`, `resources/views/components/dentists/delete.blade.php`, `app/Policies/DentistPolicy.php`)
  - Gestão de Parceiros e Demandas (`resources/views/components/partners/store.blade.php`, `resources/views/components/partners/delete.blade.php`, `resources/views/components/partners/assign-demand.blade.php`, `resources/views/components/partners/start-demand.blade.php`, `resources/views/components/partners/finish-demand.blade.php`, `app/Policies/PartnerPolicy.php`, `app/Policies/PartnerDemandPolicy.php`)
  - Serviços Solicitados, Progresso e Arquivos (`resources/views/components/service/progress.blade.php`, `resources/views/components/service/request-files.blade.php`, `app/Policies/RequestServicePolicy.php`, `app/Policies/DentistRequestPolicy.php`, `app/Models/RequestService.php`)
  - Etapas de Serviços (`resources/views/components/step/store.blade.php`, `resources/views/components/step/delete.blade.php`, `resources/views/components/step/manage-partners.blade.php`, `app/Policies/ServiceStepPolicy.php`)
  - Catálogo de Produtos, Categorias e Movimentações de Estoque (`resources/views/components/products/store.blade.php`, `resources/views/components/products/delete.blade.php`, `resources/views/components/products/categories/store.blade.php`, `resources/views/components/products/categories/delete.blade.php`, `resources/views/components/products/stock/store.blade.php`, `resources/views/components/products/stock/out.blade.php`, `resources/views/components/stock/get.blade.php`, `app/Policies/ProductPolicy.php`, `app/Policies/ProductCategoryPolicy.php`)
  - Painel Central de Planos (`resources/views/livewire/pages/central/plans/index.blade.php`)
  - Testes Automatizados de Segurança e Controle de Acesso (`tests/Feature/LivewireServerSideAuthorizationTest.php`)

---

## 1. Contexto & Motivação

A demanda **3.4 ([ALTA] Falta de Autorização Server-Side (`$this->authorize`) e Escalação de Privilégio em Componentes Livewire)** identifica que, no Livewire, proteger apenas a rota HTTP `GET` inicial com `middleware('can:...')` não impede que usuários autenticados com perfis restritos (como `Rules::Dentist` ou `Rules::LabPartner`) invoquem métodos de componentes diretamente via `POST /livewire/update`, especialmente quando os componentes estão montados em páginas compartilhadas ou podem ser instanciados em chamadas subsequentes:

1. **Inspeção e Conclusão Indevida de Serviços em `service/progress.blade.php`:**
   - Montado em `resources/views/livewire/pages/services/planned.blade.php` (página que usuários `Rules::Dentist` acessam).
   - Os métodos `open(int|string $requestServiceId)` e `complete()` (linhas 17–30 e 71–83) **não executam `$this->authorize(...)`**.
   - Consequentemente, um `Dentist` pode abrir o modal de progresso de `RequestService` pertencente a outros dentistas (`open`), inspecionando detalhes das etapas e fotos comprobatórias (`result_photo_url`), ou marcar arbitrariamente serviços como concluídos (`complete`).
2. **Acesso a Arquivos de Solicitações em `service/request-files.blade.php` e `DentistRequestPolicy`:**
   - Montado em `planned.blade.php`.
   - Os métodos `loadRequest(int $requestId)` e `download(int $fileId)` devem exigir `$this->authorize('view', $dentistRequest)` de forma estrita, e `DentistRequestPolicy::view(User $user, DentistRequest $dentistRequest)` deve garantir que um `Dentist` só acesse pedidos vinculados ao seu próprio registro (`$dentistRequest->dentist?->user_id === $user->id` / `$dentistRequest->dentist_id === $user->dentist?->id`), bloqueando `LabPartner` e dentistas de terceiros com **HTTP 403**.
3. **Atribuição Não Autorizada de Demandas em `partners/assign-demand.blade.php`:**
   - Montado em `resources/views/livewire/pages/dentists/demands.blade.php` (acessível por `Rules::LabPartner`).
   - Os métodos `openModal(int $partnerDemandId)` e `assignDemand()` não verificam `$this->authorize('assign', $this->partnerDemand)`, dependendo apenas do atributo visual `:disabled="!Auth::user()->can('assign', $demand)"` no botão HTML da listagem. Um `LabPartner` não vinculado à etapa pode atribuir qualquer demanda a si mesmo ou a terceiros via payload Livewire.
4. **Baixa Não Autorizada de Estoque em `products/stock/out.blade.php` e `products/stock/store.blade.php`:**
   - `products/stock/out.blade.php` é montado em `demands.blade.php` (acessível por `Rules::LabPartner`). Seus métodos `open(int $requestServiceId)` e `save()` (baixa de estoque / `registerOut`) não verificam `$this->authorize('movementStock', Product::class)`.
   - Da mesma forma, `products/stock/store.blade.php` (`openForProduct()` e `save()`) e `stock/get.blade.php` (`open()` e `callStockOut()`) não validam `$this->authorize('movementStock', Product::class)`.
5. **Ausência de `$this->authorize(...)` em Componentes de CRUD Administrativo (`dentists`, `partners`, `step`, `products`, `categories`):**
   - `dentists/store.blade.php`: `edit()` e `save()` não chamam `$this->authorize('update', $dentist)` / `$this->authorize('create', Dentist::class)`, e `DentistPolicy` ainda não declara `create()` e `update()`. Em `dentists/delete.blade.php`, `confirmDelete()` não valida `$this->authorize('delete', $dentist)` ao abrir o modal. (A proteção contra `User::updateOrCreate` sobrescrevendo `rule_id` por e-mail deve ser preservada integralmente em conjunto com a autorização).
   - `partners/store.blade.php`: `edit()` e `save()` não chamam `$this->authorize('update', $this->partner)` / `$this->authorize('create', Partner::class)`. Em `partners/delete.blade.php`, `confirmDelete()` não valida `$this->authorize('delete', $this->partner)`.
   - `step/store.blade.php`: `edit()` e `store()` não chamam `$this->authorize('update', $this->editingStep)` / `$this->authorize('create', ServiceStep::class)`. Em `step/delete.blade.php`, `open()` não valida `$this->authorize('delete', $step)`. Em `step/manage-partners.blade.php`, `open()` não valida `$this->authorize('update', $this->serviceStep)`.
   - `products/store.blade.php`: `edit()` e `save()` não chamam `$this->authorize('update', $this->product)` / `$this->authorize('create', Product::class)`. Em `products/delete.blade.php`, `confirmDelete()` não valida `$this->authorize('delete', $this->product)`.
   - `products/categories/store.blade.php`: `edit()` e `save()` não chamam `$this->authorize('update', $this->category)` / `$this->authorize('create', ProductCategory::class)`. Em `products/categories/delete.blade.php`, `open()` não valida `$this->authorize('delete', $this->category)`.
6. **Ações Sem Verificação Explícita do Guard Central em `central/plans/index.blade.php`:**
   - Embora `plans()`, `togglePlanStatus()` e `confirmDeactivatePlan()` verifiquem `Auth::guard('central')->user() instanceof CentralUser`, os métodos `openCreatePlanModal()`, `openEditPlanModal(string $planId)`, `savePlan(...)` e `confirmSavePlan(...)` não executam essa guarda de autorização no início da ação.

---

## 2. Objetivos

1. **Padronizar e completar as Policies do domínio Tenant:**
   - Garantir que `DentistPolicy` implemente `view`, `create` e `update` (além de `viewAny`, `delete`, `forceDelete`).
   - Garantir que `DentistRequestPolicy::view(User $user, DentistRequest $dentistRequest)` autorize administradores (`Owner`, `Lab`, `LabManager`) e, para `Rules::Dentist`, verifique estritamente que o pedido pertence ao dentista autenticado (`$dentistRequest->dentist?->user_id === $user->id`).
   - Garantir que `ProductPolicy` implemente `movementStock(User $user)` restrito à administração do laboratório (`Owner`, `Lab`, `LabManager`).
   - Garantir que `RequestService` possua `#[UsePolicy(RequestServicePolicy::class)]` explicitamente declarado e que `RequestServicePolicy` contemple as ações de visualização/status e conclusão (`updateStatus`).
2. **Exigir `$this->authorize(...)` no início de todos os métodos de ação e abertura de modal nos componentes Livewire do Tenant:**
   - Proteger `save`, `store`, `edit`, `delete`, `confirmDelete`, `open`, `openModal`, `openForProduct`, `complete`, `assignDemand`, `registerOut` e `loadRequest` em todos os componentes listados.
3. **Blindar propriedades de identificação de modelos em modais contra manipulação client-side (`#[Locked]`):**
   - Garantir que IDs de entidades carregadas para edição/exclusão/progresso não possam ser trocados arbitrariamente entre a abertura do modal e a submissão.
4. **Exigir autorização central (`CentralUser`) em todas as ações de `central/plans/index.blade.php`:**
   - Garantir que `openCreatePlanModal`, `openEditPlanModal`, `savePlan`, `confirmSavePlan`, `togglePlanStatus` e `confirmDeactivatePlan` validem `abort_unless(Auth::guard('central')->user() instanceof CentralUser, 403)` no início de cada método.
5. **Assegurar cobertura completa de testes automatizados:**
   - Cobrir com testes PHPUnit todas as tentativas de invocação não autorizada por `Rules::Dentist`, `Rules::LabPartner` e usuários não autorizados, verificando o retorno **HTTP 403 (Forbidden)** e a integridade do banco de dados.

---

## 3. Regras de Negócio & Segurança

### 3.1. RN-01: Autorização Obrigatória em `service/progress.blade.php` e `RequestServicePolicy`

1. **Restrição de Acesso ao Progresso e Conclusão de Etapas (`updateStatus`):**
   - O componente `resources/views/components/service/progress.blade.php` exibe dados internos de produção (técnicos parceiros responsáveis, timestamps de início/término de cada etapa, fotos comprobatórias `result_photo_url`) e permite concluir o serviço (`complete()`).
   - Em `planned.blade.php`, o item de menu `"Ver Andamento"` já é exibido condicionalmente apenas para `@can('updateStatus', $requestService)` (`Owner`, `Lab`, `LabManager`).
   - Portanto, em `service/progress.blade.php`:
     - No início de `open(int|string $requestServiceId)`: carregar o `RequestService` (`findOrFail((int) $requestServiceId)`) e executar imediatamente `$this->authorize('updateStatus', $requestService)` **antes** de atribuir `$this->requestService` ou abrir a modal.
     - No início de `complete()`: carregar/validar a instância `$this->requestService` (ou buscar por `$this->requestServiceId`) e executar `$this->authorize('updateStatus', $this->requestService)` **antes** de verificar `canComplete()` ou invocar `CompleteService::handle($this->requestService->id)`.
   - Dessa forma, qualquer tentativa de um `Dentist` ou `LabPartner` de invocar `open()` ou `complete()` via `/livewire/update` é rejeitada com **HTTP 403**.

### 3.2. RN-02: Autorização de Visualização e Download de Arquivos em `service/request-files.blade.php` e `DentistRequestPolicy`

1. **Política de Visualização (`DentistRequestPolicy::view`):**
   - `DentistRequestPolicy::view(User $user, DentistRequest $dentistRequest): bool` deve retornar:
     - `true` para `Rules::Owner`, `Rules::Lab` e `Rules::LabManager`.
     - Para `Rules::Dentist`: `true` **somente se** a solicitação pertencer ao dentista autenticado (`$dentistRequest->dentist?->user_id === $user->id` e `$dentistRequest->dentist_id !== null`).
     - `false` para qualquer outro perfil (como `Rules::LabPartner`).
2. **Autorização em `loadRequest` e `download` (`service/request-files.blade.php`):**
   - Em `loadRequest(int $requestId)`: buscar a solicitação com `DentistRequest::with(['files', 'dentist'])->find($requestId)`. Caso exista, executar `$this->authorize('view', $dentistRequest)` **antes** de atribuir `$this->dentistRequest = $dentistRequest` e de exibir `show-request-files-modal`.
   - Em `download(int $fileId)`: garantir que `$this->dentistRequest` exista, executar `$this->authorize('view', $this->dentistRequest)` e buscar o arquivo estritamente através do relacionamento `$this->dentistRequest->files()->findOrFail($fileId)`.
   - Na listagem `planned.blade.php`, o botão `"Ver Arquivos"` deve usar `@can('view', $dentistRequest)` (permitindo ao dentista dono do pedido baixar seus próprios arquivos e à equipe administrativa baixar de qualquer pedido, enquanto bloqueia o acesso cruzado entre dentistas).

### 3.3. RN-03: Autorização na Atribuição de Demandas (`partners/assign-demand.blade.php`)

1. **Validação via `PartnerDemandPolicy::assign`:**
   - Em `openModal(int $partnerDemandId)`: carregar a demanda com `PartnerDemand::with('serviceServiceStep.serviceStep.partners.user')->findOrFail($partnerDemandId)` e chamar `$this->authorize('assign', $partnerDemand)` antes de armazenar em `$this->partnerDemand` e abrir `assign-demand-modal`.
   - Em `assignDemand()`: garantir que `$this->partnerDemand` esteja carregada (`abort_if(! $this->partnerDemand, 404)`) e executar `$this->authorize('assign', $this->partnerDemand)` na primeira linha do método, antes de `$this->validate()` e de salvar `partner_id`.
   - Adicionalmente, validar que o `$this->partnerId` informado pertence efetivamente aos parceiros habilitados para aquela etapa (`serviceStep->partners`), impedindo que um parceiro habilitado atribua a demanda para um parceiro não vinculado à etapa.
   - Em `partners/start-demand.blade.php` e `partners/finish-demand.blade.php`, garantir que `openModal(int $partnerDemandId)` também valide `$this->authorize('start', $partnerDemand)` e `$this->authorize('complete', $partnerDemand)` respectivamente, além das chamadas já presentes em `startDemand()` e `finishDemand()`.

### 3.4. RN-04: Autorização na Movimentação e Retirada de Estoque (`ProductPolicy::movementStock`)

1. **Habilidade `movementStock` em `ProductPolicy`:**
   - Adicionar `public function movementStock(User $user): bool` em `ProductPolicy`, retornando `true` apenas para `Rules::Owner`, `Rules::Lab` e `Rules::LabManager`.
2. **Proteção em `products/stock/out.blade.php`, `products/stock/store.blade.php` e `stock/get.blade.php`:**
   - Em `products/stock/out.blade.php`:
     - Executar `$this->authorize('movementStock', Product::class)` no início de `open(int $requestServiceId)`, `save()` e `registerOut()` (alias para `save()`), garantindo que `Rules::LabPartner` (que acessa `demands.blade.php` onde o componente está montado) e `Rules::Dentist` recebam **HTTP 403** caso tentem retirar produtos do estoque.
   - Em `products/stock/store.blade.php`:
     - Executar `$this->authorize('movementStock', Product::class)` no início de `openForProduct(?int $productId = null)` e `save()`.
   - Em `stock/get.blade.php`:
     - Executar `$this->authorize('movementStock', Product::class)` (ou verificar `Auth::user()?->can('movementStock', Product::class)` antes de abrir o modal quando disparado automaticamente pelo evento `stock::out` após `startDemand` por um parceiro, e exigir `$this->authorize('movementStock', Product::class)` em `callStockOut()`).
     - *Nota importante sobre `start-demand.blade.php` -> `stock::out`:* Quando um `LabPartner` inicia sua demanda em `start-demand.blade.php`, o método `startDemand()` dispara `$this->dispatch('stock::out', $this->partnerDemand->request_service_id)`. Se `stock/get.blade.php` lançasse 403 em `open()` durante o evento automático de `startDemand`, o parceiro receberia um erro ao iniciar a demanda! Portanto:
       - Em `start-demand.blade.php`: só despachar `$this->dispatch('stock::out', ...)` se `Auth::user()?->can('movementStock', Product::class)` for `true`.
       - Em `stock/get.blade.php`: exigir `$this->authorize('movementStock', Product::class)` tanto em `open()` quanto em `callStockOut()`.

### 3.5. RN-05: Autorização Server-Side e Prevenção de Escalação de Privilégio em `dentists`, `partners`, `step`, `products` e `categories`

1. **Gestão de Dentistas (`dentists/store.blade.php`, `dentists/delete.blade.php`, `DentistPolicy`):**
   - Completar `DentistPolicy` com os métodos `view`, `create` e `update` restritos a `[Rules::Owner, Rules::Lab, Rules::LabManager]`.
   - Em `dentists/store.blade.php`:
     - Em `edit($id)`: carregar `$dentist = Dentist::with('user')->findOrFail($id)` e chamar `$this->authorize('update', $dentist)` no início do método.
     - Em `save()`: se `$this->dentistId` estiver preenchido, carregar `$dentist = Dentist::with('user')->findOrFail($this->dentistId)` e chamar `$this->authorize('update', $dentist)`; caso contrário, chamar `$this->authorize('create', Dentist::class)` antes de `$this->validate()`.
     - Manter a proibição estrita de `User::updateOrCreate(['email' => $this->email], ...)`: na criação, usar `User::create` com `Rules::Dentist`; na edição, atualizar exclusivamente o `$dentist->user` vinculado sem alterar seu `rule_id`.
   - Em `dentists/delete.blade.php`:
     - Em `confirmDelete(int $dentistId)`: carregar `$dentist = Dentist::findOrFail($dentistId)` e chamar `$this->authorize('delete', $dentist)` antes de abrir o modal.
     - Em `delete()`: manter `$this->authorize('delete', $dentist)`.

2. **Gestão de Parceiros (`partners/store.blade.php`, `partners/delete.blade.php`):**
   - Em `partners/store.blade.php`:
     - Em `edit($id)`: carregar `$partner = Partner::with('user')->findOrFail($id)` e chamar `$this->authorize('update', $partner)` no início do método.
     - Em `save()`: se `$this->partner` existir, chamar `$this->authorize('update', $this->partner)`; caso contrário, chamar `$this->authorize('create', Partner::class)` antes de `$this->validate()`.
     - Manter a proibição estrita de `User::updateOrCreate(['email' => $this->email], ...)`: na criação, usar `User::create` com `Rules::LabPartner`; na edição, atualizar exclusivamente o `$partner->user` vinculado sem alterar seu `rule_id`.
   - Em `partners/delete.blade.php`:
     - Em `confirmDelete(int $partnerId)`: carregar `$this->partner = Partner::findOrFail($partnerId)` e chamar `$this->authorize('delete', $this->partner)` antes de abrir o modal.
     - Em `delete()`: manter `$this->authorize('delete', $this->partner)`.

3. **Gestão de Etapas (`step/store.blade.php`, `step/delete.blade.php`, `step/manage-partners.blade.php`):**
   - Em `step/store.blade.php`:
     - Em `edit(int $id)`: carregar `$step = ServiceStep::findOrFail($id)` e chamar `$this->authorize('update', $step)`.
     - Em `store()`: se `$this->editingStep` existir, chamar `$this->authorize('update', $this->editingStep)`; caso contrário, chamar `$this->authorize('create', ServiceStep::class)` antes de `$this->validate()`.
   - Em `step/delete.blade.php`:
     - Em `open(int $id)`: carregar `$step = ServiceStep::findOrFail($id)` e chamar `$this->authorize('delete', $step)` antes de abrir o modal.
     - Em `delete()`: manter `$this->authorize('delete', $step)`.
   - Em `step/manage-partners.blade.php`:
     - Em `open(int $stepId)`: carregar `$step = ServiceStep::with('partners')->findOrFail($stepId)` e chamar `$this->authorize('update', $step)` antes de abrir o modal.
     - Em `save()`: manter `$this->authorize('update', $this->serviceStep)`.

4. **Gestão de Produtos e Categorias (`products/store.blade.php`, `products/delete.blade.php`, `products/categories/store.blade.php`, `products/categories/delete.blade.php`):**
   - Em `products/store.blade.php`:
     - Em `edit(?int $productId = null)`: carregar `$product = Product::findOrFail($productId)` e chamar `$this->authorize('update', $product)`.
     - Em `save()`: se `$this->product` existir, chamar `$this->authorize('update', $this->product)`; caso contrário, chamar `$this->authorize('create', Product::class)` antes de `$this->validate()`.
   - Em `products/delete.blade.php`:
     - Em `confirmDelete(int $productId)`: carregar `$this->product = Product::findOrFail($productId)` e chamar `$this->authorize('delete', $this->product)`.
     - Em `delete()`: manter `$this->authorize('delete', $this->product)`.
   - Em `products/categories/store.blade.php`:
     - Em `edit(?int $id = null)`: carregar `$category = ProductCategory::findOrFail($id)` e chamar `$this->authorize('update', $category)`.
     - Em `save()`: se `$this->category` existir, chamar `$this->authorize('update', $this->category)`; caso contrário, chamar `$this->authorize('create', ProductCategory::class)` antes de `$this->validate()`.
   - Em `products/categories/delete.blade.php`:
     - Em `open(?int $id = null)`: carregar `$this->category = ProductCategory::findOrFail($id)` e chamar `$this->authorize('delete', $this->category)`.
     - Em `delete()`: manter `$this->authorize('delete', $this->category)`.

5. **Gestão de Serviços do Catálogo (`service/store.blade.php` e `service/delete.blade.php`):**
   - Em `service/store.blade.php`: adicionar `$this->authorize('update', $service)` também no início de `edit($id)` (além do `store()` que já possui).
   - Em `service/delete.blade.php`: adicionar `$this->authorize('delete', $service)` também em `confirmDelete($id)` (além do `delete()` que já possui).

### 3.6. RN-06: Autorização Server-Side no Painel Central de Planos (`central/plans/index.blade.php`)

1. **Verificação Padronizada de `CentralUser`:**
   - Criar um método auxiliar privado `ensureCentralAdmin(): CentralUser` (ou validar diretamente `abort_unless($user instanceof CentralUser, 403)`) e invocá-lo no início de **todas** as ações públicas e propriedades computadas de `resources/views/livewire/pages/central/plans/index.blade.php`:
     - `openCreatePlanModal()`
     - `openEditPlanModal(string $planId)`
     - `savePlan(...)`
     - `confirmSavePlan(...)`
     - `togglePlanStatus(...)`
     - `confirmDeactivatePlan(...)`
     - `plans()`

---

## 4. Detalhamento Técnico por Arquivo

### 4.1. Policies e Models (`app/Policies/` e `app/Models/`)

1. **`app/Policies/DentistPolicy.php`:**
   - Adicionar `view(User $user, ?Dentist $dentist = null): bool`, `create(User $user): bool` e `update(User $user, ?Dentist $dentist = null): bool`, todos validando `in_array($user->rule?->name, [Rules::Owner, Rules::Lab, Rules::LabManager], true)`.
2. **`app/Policies/DentistRequestPolicy.php`:**
   - Em `view(User $user, DentistRequest $dentistRequest): bool`:
     ```php
     public function view(User $user, DentistRequest $dentistRequest): bool
     {
         $user->loadMissing('rule');

         if (in_array($user->rule?->name, [
             Rules::Owner,
             Rules::Lab,
             Rules::LabManager,
         ], true)) {
             return true;
         }

         if ($user->rule?->name === Rules::Dentist) {
             $dentistRequest->loadMissing('dentist');

             return $dentistRequest->dentist !== null
                 && $dentistRequest->dentist->user_id === $user->id;
         }

         return false;
     }
     ```
3. **`app/Policies/ProductPolicy.php`:**
   - Adicionar `movementStock(User $user): bool`:
     ```php
     public function movementStock(User $user): bool
     {
         $user->loadMissing('rule');

         return in_array($user->rule?->name, [Rules::Owner, Rules::Lab, Rules::LabManager], true);
     }
     ```
4. **`app/Policies/PartnerDemandPolicy.php`:**
   - Garantir `$user->loadMissing('rule')` em `assign`, `start` e `complete`, e verificar em `assign` com `===` estrito:
     ```php
     public function assign(User $user, PartnerDemand $partnerDemand): bool
     {
         $user->loadMissing('rule');

         if (in_array($user->rule?->name, [Rules::Owner, Rules::Lab, Rules::LabManager], true)) {
             return true;
         }

         $partnerDemand->loadMissing('serviceServiceStep.serviceStep.partners.user');

         return $partnerDemand->serviceServiceStep
             ?->serviceStep
             ?->partners
             ?->pluck('user.id')
             ->contains($user->id) ?? false;
     }
     ```
5. **`app/Models/RequestService.php`:**
   - Adicionar `#[UsePolicy(RequestServicePolicy::class)]` na classe `RequestService`.

### 4.2. Componentes Livewire Afetados

| Componente | Métodos Protegidos | Habilidade / Policy |
| :--- | :--- | :--- |
| `dentists/store.blade.php` | `edit()`, `save()` | `update` (`$dentist`) / `create` (`Dentist::class`) |
| `dentists/delete.blade.php` | `confirmDelete()`, `delete()` | `delete` (`$dentist`) |
| `partners/store.blade.php` | `edit()`, `save()` | `update` (`$this->partner`) / `create` (`Partner::class`) |
| `partners/delete.blade.php` | `confirmDelete()`, `delete()` | `delete` (`$this->partner`) |
| `partners/assign-demand.blade.php` | `openModal()`, `assignDemand()` | `assign` (`$this->partnerDemand`) |
| `service/progress.blade.php` | `open()`, `complete()` | `updateStatus` (`$this->requestService`) |
| `service/request-files.blade.php` | `loadRequest()`, `download()` | `view` (`$dentistRequest`) |
| `step/store.blade.php` | `edit()`, `store()`, `save()` | `update` (`$this->editingStep`) / `create` (`ServiceStep::class`) |
| `step/delete.blade.php` | `open()`, `delete()` | `delete` (`$step`) |
| `products/store.blade.php` | `edit()`, `save()` | `update` (`$this->product`) / `create` (`Product::class`) |
| `products/delete.blade.php` | `confirmDelete()`, `delete()` | `delete` (`$this->product`) |
| `products/categories/store.blade.php` | `edit()`, `save()` | `update` (`$this->category`) / `create` (`ProductCategory::class`) |
| `products/categories/delete.blade.php` | `open()`, `delete()` | `delete` (`$this->category`) |
| `products/stock/store.blade.php` | `openForProduct()`, `save()` | `movementStock` (`Product::class`) |
| `products/stock/out.blade.php` | `open()`, `save()`, `registerOut()` | `movementStock` (`Product::class`) |
| `central/plans/index.blade.php` | `openCreatePlanModal()`, `openEditPlanModal()`, `savePlan()`, `confirmSavePlan()`, `togglePlanStatus()`, `confirmDeactivatePlan()` | `Auth::guard('central')->user() instanceof CentralUser` |

---

## 5. Critérios de Aceite (Testes Automatizados)

1. **Bloqueio de `service.progress` (`open` e `complete`) para Dentistas e Parceiros:**
   - Um usuário `Rules::Dentist` (mesmo para um pedido próprio ou de outro dentista) ou `Rules::LabPartner` que invoque `open($requestServiceId)` ou `complete()` no componente `service.progress` recebe **HTTP 403 (Forbidden)**; `$requestService->completed_at` permanece `null`.
   - Usuários `Rules::Owner`, `Rules::Lab` e `Rules::LabManager` conseguem abrir o progresso (`open`) e concluir o serviço (`complete`) quando todas as etapas estão finalizadas.
2. **Isolamento de Solicitações em `service.request-files` (`loadRequest` e `download`) e `DentistRequestPolicy::view`:**
   - Um `Dentist` A consegue chamar `loadRequest` e `download` para sua própria `DentistRequest`.
   - Se o `Dentist` A chamar `loadRequest($requestOfDentistB->id)` ou `download($fileOfDentistB->id)`, recebe **HTTP 403 (Forbidden)** e `$component->get('dentistRequest')` permanece `null`.
   - Um `LabPartner` que chame `loadRequest` ou `download` recebe **HTTP 403 (Forbidden)**.
3. **Bloqueio de Atribuição de Demanda (`partners.assign-demand`) por Usuários Não Autorizados:**
   - Um `Dentist` ou um `LabPartner` não vinculado à etapa que chame `openModal($demand->id)` ou `assignDemand()` recebe **HTTP 403 (Forbidden)** e `partner_id` da demanda não é alterado.
4. **Bloqueio de Movimentação e Baixa de Estoque (`products.stock.out` e `products.stock.store`) por `LabPartner` e `Dentist`:**
   - Um `LabPartner` ou `Dentist` que chame `open()`, `save()` ou `registerOut()` em `products.stock.out`, ou `openForProduct()` / `save()` em `products.stock.store`, recebe **HTTP 403 (Forbidden)** e o saldo `current_stock` do produto permanece inalterado.
5. **Bloqueio de CRUD Administrativo (`dentists`, `partners`, `step`, `products`, `categories`) por `Dentist` e `LabPartner`:**
   - `Dentist` e `LabPartner` recebem **HTTP 403 (Forbidden)** ao chamar `edit`, `save`, `store`, `open`, `confirmDelete` ou `delete` em `dentists.store`, `dentists.delete`, `partners.store`, `partners.delete`, `step.store`, `step.delete`, `products.store`, `products.delete`, `products.categories.store` e `products.categories.delete`.
   - Tentar cadastrar `Dentist` ou `Partner` com e-mail já existente nunca sobrescreve o `rule_id` do usuário existente.
6. **Bloqueio de Gestão de Planos Centrais (`pages.central.plans.index`) sem `CentralUser`:**
   - Invocar `openCreatePlanModal`, `openEditPlanModal`, `savePlan`, `confirmSavePlan`, `togglePlanStatus` ou `confirmDeactivatePlan` sem estar autenticado no guard `central` (`CentralUser`) retorna **HTTP 403 (Forbidden)**.

---

## 6. Documentação e Diretrizes Relacionadas

- [Diretrizes de IA: Interface Livewire e Volt (`.ai/rules/boost/livewire-ui.md`)](../../../.ai/rules/boost/livewire-ui.md)
- [Arquitetura: Tech Stack & Convenções Técnicas — §3.3 Livewire & Volt](../../architecture/tech-stack.md)
- [Arquitetura: Acesso Central, Impersonation & Auditoria — §1 Autorização do Painel Central](../../architecture/central-admin-access.md)
- [Domínio: Pedidos & Ordens de Serviço (Orders) — §3 Regras de Negócio Críticas](../../domain/orders.md)
- [Domínio: Estoque & Insumos (Inventory) — §3 Regras de Negócio Críticas](../../domain/inventory.md)

