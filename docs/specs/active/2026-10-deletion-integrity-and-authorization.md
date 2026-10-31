# Especificação: Soft Delete Obrigatório, Autorização, Integridade Referencial e Regras de Negócio na Exclusão

- **Identificador:** `2026-10-deletion-integrity-and-authorization`
- **Status:** Em Planejamento / Ativo
- **Data:** Outubro de 2026
- **Módulos Afetados:**
  - Modelos Eloquent (`User`, `Dentist`, `Partner`, `Service`, `ServiceStep`, `Product`, `ProductCategory`, `DentistRequest`, `RequestService`, `PartnerDemand`, `ServiceServiceStep`, `StockBatch`, `StockMovement`, `RequestServiceConsumption`)
  - Componentes de Exclusão (`resources/views/components/dentists/delete.blade.php`, `resources/views/components/partners/delete.blade.php`, `resources/views/components/products/delete.blade.php`, `resources/views/components/products/categories/delete.blade.php`, `resources/views/components/service/delete.blade.php`, `resources/views/components/step/delete.blade.php`)
  - Componentes de Cadastro/Edição (`resources/views/components/dentists/store.blade.php`, `resources/views/components/partners/store.blade.php`, `resources/views/components/products/store.blade.php`, `resources/views/components/products/categories/store.blade.php`, `resources/views/components/service/store.blade.php`, `resources/views/components/step/store.blade.php`, `resources/views/components/service/requesting.blade.php`, `resources/views/components/step/manage-partners.blade.php`, `resources/views/components/partners/assign-demand.blade.php`)
  - Perfil & Conta de Usuário (`resources/views/livewire/profile/delete-user-form.blade.php`, `resources/views/livewire/profile/update-profile-information-form.blade.php`)
  - Relatórios Gerenciais (`resources/views/livewire/pages/reports/index.blade.php`)
  - Policies (`DentistPolicy`, `PartnerPolicy`, `ProductPolicy`, `ProductCategoryPolicy`, `ServicePolicy`, `ServiceStepPolicy`)
  - Migrations Tenant Existentes (`database/migrations/tenant/`)

---

## 1. Contexto & Motivação

Durante a revisão de segurança e integridade referencial do Dental Lab, foram identificados problemas críticos relacionados à exclusão de registros operacionais e contas de usuário:

1. **Ausência de Autorização (`$this->authorize`) nos Componentes de Exclusão:**
   - Os componentes Livewire `dentists/delete.blade.php`, `partners/delete.blade.php`, `products/delete.blade.php`, `products/categories/delete.blade.php` e `step/delete.blade.php` executam `delete()` diretamente sem verificar permissões via Policy (`$this->authorize(...)`).
   - Qualquer usuário autenticado no tenant (incluindo `Dentist` ou `LabPartner`) conseguia acionar a exclusão de registros de catálogo, estoque, dentistas ou parceiros.

2. **Uso de Hard Delete com `cascadeOnDelete()` Destruindo Histórico e Gerando Órfãos no S3:**
   - Todas as exclusões faziam **hard delete** combinadas com `cascadeOnDelete()` nas migrations.
   - Deletar um **Dentista** apagava em cascata todos os `dentist_requests`, `request_services`, `partner_demands` e `request_service_consumptions` daquele dentista (destruindo o faturamento histórico nos relatórios!), mas deixava os registros na tabela polimórfica `files` e os arquivos 3D/fotos órfãos no S3 (consumindo a franquia de storage permanentemente).
   - Deletar um **Parceiro** apagava em cascata todas as `partner_demands` executadas por ele, fazendo o CMV/Comissões do laboratório sumirem retroativamente dos relatórios e deixando pedidos concluídos com etapas faltando.
   - Deletar um **Serviço**, **Etapa** ou **Produto** apagava em cascata itens de pedidos, etapas ou todo o histórico auditável de lotes (`stock_batches`) e movimentações (`stock_movements`).

3. **Autoexclusão do Único Proprietário/Administrador do Tenant (`delete-user-form.blade.php`):**
   - O componente `delete-user-form.blade.php` permite que o único dono (`Lab` / `Owner`) delete seu próprio usuário, deixando o Tenant órfão e impedindo inclusive a impersonação pelo painel central (`ImpersonateTenantService`).

---

## 2. Diretrizes Arquiteturais Obrigatórias

1. **Proibição Absoluta de Hard Delete (Uso Exclusivo de `SoftDeletes`):**
   - **Não deve mais ser possível realizar hard delete** de `User`, `Dentist`, `Partner`, `Service`, `ServiceStep`, `Product` e `ProductCategory`.
   - Todos esses modelos devem utilizar a trait `Illuminate\Database\Eloquent\SoftDeletes` e possuir a coluna `deleted_at` (`$table->softDeletes()`).
   - Nas Policies correspondentes, `forceDelete()` deve retornar `false`.
   - As chaves estrangeiras que apontam para esses cadastros devem usar `restrictOnDelete()` no banco de dados como camada adicional de segurança contra qualquer tentativa de deleção física direta no banco.

2. **Reutilização Estrita das Migrations Existentes (Zero Novas Migrations):**
   - **Não devem ser criadas novas migrations** para esta especificação.
   - Todas as adições de `$table->softDeletes()`, ajustes de índices de unicidade e substituições de `cascadeOnDelete()` por `restrictOnDelete()` devem ser aplicadas **diretamente nas migrations originais já existentes** em `database/migrations/tenant/`.

---

## 3. Problemas de Regra de Negócio Decorrentes do Soft Delete & Soluções

A adoção de `SoftDeletes` em todo o domínio introduz efeitos colaterais importantes que precisam ser tratados nas regras de negócio:

### 3.1. Conflito de Unicidade ao Recriar Registros com Mesmo Nome ou E-mail
- **Problema:** Se um registro (`Service`, `ServiceStep`, `Product`, `ProductCategory` ou `User` de um `Dentist`/`Partner`) sofrer Soft Delete (`deleted_at !== null`) e o laboratório tentar cadastrar um novo registro ativo com o **mesmo nome** ou **mesmo e-mail**:
  1. Um índice `UNIQUE` irrestrito no banco de dados (como `$table->string('email')->unique()` em `users`) barra o `INSERT` porque a linha antiga continua fisicamente na tabela.
  2. Uma validação `Rule::unique('services', 'name')` ou `Rule::unique('users', 'email')` sem filtro de `deleted_at` barra o formulário alegando que o nome/e-mail já existe, mesmo que o registro anterior tenha sido excluído!
- **Solução de Regra de Negócio:**
  - **No Banco de Dados (`0001_01_01_000000_create_users_table.php`):** Remover o `->unique()` irrestrito da coluna `email` na tabela `users`, mantendo apenas `->index()` (nenhuma tabela de catálogo terá constraint `unique` cega de banco em `name` ou `email`).
  - **Na Validação dos Formulários Livewire:** Validar unicidade **exclusivamente contra registros ativos (`whereNull('deleted_at')`)**, ignorando o próprio ID quando em modo de edição:
    - **Serviços (`service/store.blade.php`):** `Rule::unique('services', 'name')->whereNull('deleted_at')->ignore($this->serviceId)`
    - **Etapas (`step/store.blade.php`):** `Rule::unique('service_steps', 'name')->whereNull('deleted_at')->ignore($this->editingStep?->id)`
    - **Produtos (`products/store.blade.php`):** `Rule::unique('products', 'name')->whereNull('deleted_at')->ignore($this->product?->id)`
    - **Categorias de Produto (`products/categories/store.blade.php`):** `Rule::unique('product_categories', 'name')->whereNull('deleted_at')->ignore($this->category?->id)`
    - **E-mail de Usuários (`dentists/store.blade.php`, `partners/store.blade.php`, `update-profile-information-form.blade.php`):** `Rule::unique('users', 'email')->whereNull('deleted_at')->ignore($editingUserId)`

### 3.2. Quebra de Relacionamentos Históricos (`BelongsTo` retornando `null`) em Pedidos, Demandas e Relatórios
- **Problema:** O Eloquent aplica o escopo global `whereNull('deleted_at')` em qualquer consulta aos modelos com `SoftDeletes`. Se um `Dentist`, `Partner`, `Service`, `ServiceStep`, `Product` ou `ProductCategory` for excluído com Soft Delete:
  - As telas de **Serviços Solicitados** (`planned.blade.php`), **Demandas** (`demands.blade.php`), **Progresso do Serviço** (`progress.blade.php`), **Movimentações de Estoque** (`movements/index.blade.php`) e **Relatórios Gerenciais** (`reports/index.blade.php`) tentam acessar `$dentistRequest->dentist->user->name`, `$requestService->service->name`, `$demand->partner->user->name`, `$demand->serviceServiceStep->serviceStep->name` ou `$movement->product->name`.
  - Sem `withTrashed()`, essas relações `BelongsTo` retornam `null`, lançando `ErrorException: Attempt to read property "name" on null` (Erro 500) ou ocultando dados históricos!
- **Solução de Regra de Negócio:**
  - Todas as relações `BelongsTo` de entidades históricas/operacionais que apontam para modelos com `SoftDeletes` devem incluir **`->withTrashed()`** por padrão na definição do relacionamento no Model:
    - `Dentist::user()` -> `$this->belongsTo(User::class)->withTrashed()`
    - `Partner::user()` -> `$this->belongsTo(User::class)->withTrashed()`
    - `DentistRequest::dentist()` -> `$this->belongsTo(Dentist::class)->withTrashed()`
    - `RequestService::service()` -> `$this->belongsTo(Service::class)->withTrashed()`
    - `PartnerDemand::partner()` -> `$this->belongsTo(Partner::class)->withTrashed()`
    - `ServiceServiceStep::service()` -> `$this->belongsTo(Service::class)->withTrashed()`
    - `ServiceServiceStep::serviceStep()` -> `$this->belongsTo(ServiceStep::class)->withTrashed()`
    - `Product::category()` -> `$this->belongsTo(ProductCategory::class, 'category_id')->withTrashed()`
    - `StockBatch::product()` -> `$this->belongsTo(Product::class)->withTrashed()`
    - `StockMovement::product()` -> `$this->belongsTo(Product::class)->withTrashed()`
    - `RequestServiceConsumption::product()` -> `$this->belongsTo(Product::class)->withTrashed()`
  - Dessa forma, **registros excluídos desaparecem automaticamente das listagens de cadastro ativo e dos seletores de novas operações** (pois `Dentist::query()`, `Service::query()`, `Product::query()`, etc. respeitam o `SoftDeletes`), mas **permanecem 100% íntegros e visíveis em todos os pedidos antigos, demandas, movimentações e relatórios financeiros**!

### 3.3. Sincronização do `User` Vinculado, Sessões Ativas, Convites e Limite de Plano ao Excluir `Dentist` ou `Partner`
- **Problema:** `Dentist` e `Partner` possuem um registro `User` próprio na tabela `users`. Se apenas o registro em `dentists` ou `partners` receber Soft Delete (`deleted_at = now()`), mas o `User` continuar ativo (`users.deleted_at === null`):
  1. O dentista ou parceiro excluído continua conseguindo fazer login no sistema!
  2. O e-mail dele continua bloqueado pela validação de usuários ativos.
  3. No caso de `Partner`, o `User` ativo continua consumindo 1 assento no limite `PlanResource::Users` (`TenantLimitChecker::activeTeamUsersQuery()`).
- **Solução de Regra de Negócio:**
  - Ao executar o Soft Delete de um `Dentist` ou `Partner` (dentro de `DB::transaction`):
    1. Executar Soft Delete também no `User` vinculado (`$dentist->user?->delete()` / `$partner->user?->delete()`).
    2. Invalidar imediatamente eventuais sessões ativas daquele usuário (`DB::table('sessions')->where('user_id', $userId)->delete()`) e remover tokens de convite pendentes (`InvitationToken::where('user_id', $userId)->delete()`).
  - No `LoginForm` e middlewares de autenticação, como `User` utiliza `SoftDeletes`, qualquer tentativa futura de login ou resolução de sessão para um usuário com `deleted_at !== null` é automaticamente negada.

### 3.4. Impacto Operacional em Demandas Pendentes/Em Andamento e Catálogo ao Excluir Parceiro, Etapa ou Categoria
- **Problema:**
  1. **Parceiro (`Partner`):** Se um parceiro for excluído (Soft Delete) enquanto possui demandas **em andamento** (`started_at !== null && ended_at === null`), a ordem de serviço fica travada porque a etapa foi iniciada mas o parceiro foi removido. E se ele possuir demandas apenas **atribuídas e não iniciadas** (`started_at === null && ended_at === null`) ou vínculos na tabela `service_step_partner`, ele continuaria aparecendo como atribuído em demandas futuras.
  2. **Etapa (`ServiceStep`):** Se uma etapa vinculada a serviços ativos for excluída com Soft Delete, os serviços daquele catálogo passariam a ter etapas órfãs/invisíveis na relação `serviceSteps()` (já que `BelongsToMany` filtra `service_steps.deleted_at IS NULL`), alterando silenciosamente o fluxo produtivo dos serviços!
  3. **Categoria de Produto (`ProductCategory`):** Se uma categoria recebe Soft Delete, os produtos ativos que apontavam para ela continuariam com `category_id` preenchido apontando para uma categoria excluída nos filtros de estoque.
- **Solução de Regra de Negócio:**
  - **Ao excluir `Partner` (`partners/delete.blade.php`):**
    - Se o parceiro possuir demandas **em andamento** (`started_at !== null && ended_at === null`), bloquear a exclusão com toast de erro orientando a concluir ou reatribuir a demanda antes de excluir o parceiro.
    - Caso não possua demandas em andamento: desatribuir demandas pendentes ainda não iniciadas (`PartnerDemand::where('partner_id', $partner->id)->whereNull('started_at')->whereNull('ended_at')->update(['partner_id' => null])`), desvincular o parceiro das etapas do catálogo (`$partner->serviceSteps()->detach()`) e aplicar Soft Delete no `Partner` e em seu `User` (preservando intactas todas as `partner_demands` já concluídas para os relatórios de comissão!).
  - **Ao excluir `ServiceStep` (`step/delete.blade.php`):**
    - Se a etapa estiver vinculada a qualquer serviço ativo (`$step->services()->exists()`), bloquear a exclusão com toast de erro orientando o gestor a remover a etapa dos serviços ativos antes de excluí-la.
    - Caso não esteja vinculada a nenhum serviço ativo, desvincular os parceiros (`$step->partners()->detach()`) e aplicar o Soft Delete (`$step->delete()`), mantendo os registros de `service_service_step` históricos intactos para os serviços já excluídos ou demandas passadas.
  - **Ao excluir `ProductCategory` (`products/categories/delete.blade.php`):**
    - Desvincular os produtos que referenciam a categoria (`Product::withTrashed()->where('category_id', $category->id)->update(['category_id' => null])`) e aplicar o Soft Delete na categoria (`$category->delete()`).

### 3.5. Validação `Rule::exists` nos Formulários de Seleção
- **Problema:** Regras de validação como `'exists:dentists,id'`, `'exists:services,id'`, `'exists:service_steps,id'`, `'exists:partners,id'` e `'exists:product_categories,id'` consultam diretamente a tabela SQL sem o escopo Eloquent `SoftDeletes`, permitindo que um ID de registro excluído via Soft Delete passe na validação.
- **Solução de Regra de Negócio:**
  - Nos formulários de criação/edição e atribuição (`requesting.blade.php`, `service/store.blade.php`, `products/store.blade.php`, `manage-partners.blade.php`, `assign-demand.blade.php`), adicionar `Rule::exists('<tabela>', 'id')->whereNull('deleted_at')` para garantir que apenas registros ativos possam ser vinculados em novas operações.
  - Nos filtros do **Relatório Gerencial** (`reports/index.blade.php`), manter `exists:dentists,id` e `exists:services,id` (e carregar `Dentist::withTrashed()` / `Service::withTrashed()` nos selects de filtro do relatório) para que o gestor possa filtrar o faturamento histórico mesmo de dentistas ou serviços que já foram excluídos do catálogo ativo.

### 3.6. Autoexclusão de Conta (`delete-user-form.blade.php`)
- **Problema:**
  1. Se o único administrador/proprietário (`Rules::Lab` ou `Rules::Owner`) sofrer Soft Delete ao excluir a própria conta em `delete-user-form.blade.php`, o Tenant fica órfão (sem administrador ativo capaz de logar) e a impersonação central (`ImpersonateTenantService`) falha por não encontrar um usuário `Rules::Lab` ativo.
  2. Se um usuário `Dentist` ou `LabPartner` excluir a própria conta em `delete-user-form.blade.php`, apenas o `User` sofreria Soft Delete e o registro `Dentist` / `Partner` continuaria ativo nas listagens do laboratório!
- **Solução de Regra de Negócio:**
  - **Bloqueio para Último Administrador (`Rules::Lab` / `Rules::Owner`):**
    - Verificar se o usuário autenticado possui role `Rules::Lab` ou `Rules::Owner`.
    - Contar se existe outro usuário ativo (`deleted_at IS NULL`) com role administrativa (`Rules::Lab` / `Rules::Owner`) e garantir que reste sempre ao menos um usuário `Rules::Lab` ativo no tenant.
    - Caso seja o único administrador ativo, bloquear a autoexclusão com mensagem de erro na validação (`password`) e toast de erro: *"Não é possível excluir a conta do único administrador do laboratório."*
  - **Sincronização ao Autoexcluir Conta de `Dentist` ou `LabPartner`:**
    - Se o usuário for um `LabPartner` e possuir demandas em andamento (`started_at !== null && ended_at === null`), bloquear a autoexclusão até a conclusão das etapas.
    - Ao efetivar a autoexclusão (`SoftDelete` do `User`), aplicar dentro da mesma transação o `SoftDelete` no registro `Dentist` (`$user->dentist?->delete()`) ou `Partner` (`$user->partner?->delete()`, limpando demandas não iniciadas e `service_step_partner`), evitando que um dentista/parceiro com conta desativada continue listando como ativo para o laboratório.

---

## 4. Detalhamento Técnico por Camada

### 4.1. Alterações nas Migrations Existentes (`database/migrations/tenant/`)

Editar exclusivamente as migrations existentes abaixo (sem criar novos arquivos de migration):

1. `0001_01_01_000000_create_users_table.php`:
   - Na tabela `users`: alterar `$table->string('email')->index()->unique();` para `$table->string('email')->index();` e adicionar `$table->softDeletes();`.
2. `2026_09_20_002138_create_services_table.php`:
   - Na tabela `services`: adicionar `$table->softDeletes();`.
3. `2026_09_20_151002_create_dentists_table.php`:
   - Na tabela `dentists`: alterar `$table->foreignId('user_id')->index()->constrained()->cascadeOnDelete();` para `$table->foreignId('user_id')->index()->constrained()->restrictOnDelete();` e adicionar `$table->softDeletes();`.
4. `2026_09_20_170920_create_service_steps_table.php`:
   - Na tabela `service_steps`: adicionar `$table->softDeletes();`.
5. `2026_09_21_130537_create_dentist_requests_table.php`:
   - Na tabela `dentist_requests`: alterar `$table->foreignIdFor(Dentist::class)->constrained()->cascadeOnDelete();` para `$table->foreignIdFor(Dentist::class)->constrained()->restrictOnDelete();`.
6. `2026_09_21_133414_create_dentist_services_table.php`:
   - Na tabela `request_services`: alterar `$table->foreignId('service_id')->constrained()->onDelete('cascade');` para `$table->foreignId('service_id')->constrained()->restrictOnDelete();`.
7. `2026_09_22_125514_create_partners_table.php`:
   - Na tabela `partners`: alterar `$table->foreignId('user_id')->constrained()->cascadeOnDelete();` para `$table->foreignId('user_id')->constrained()->restrictOnDelete();` e adicionar `$table->softDeletes();`.
8. `2026_09_23_132717_create_service_service_step_table.php`:
   - Na tabela `service_service_step`: alterar `service_id` e `service_step_id` de `cascadeOnDelete()` para `restrictOnDelete()`.
9. `2026_09_27_140710_create_partner_demands_table.php`:
   - Na tabela `partner_demands`: alterar `partner_id` de `cascadeOnDelete()` para `restrictOnDelete()`.
10. `2026_10_07_204539_create_product_categories_table.php`:
    - Na tabela `product_categories`: adicionar `$table->softDeletes();`.
11. `2026_10_07_204540_create_products_table.php`:
    - Na tabela `products`: adicionar `$table->softDeletes();`.
12. `2026_10_07_211231_create_stock_batches_table.php`:
    - Na tabela `stock_batches`: alterar `product_id` de `onDelete('cascade')` para `restrictOnDelete()`.
13. `2026_10_07_211232_request_service_consumptions_table.php`:
    - Na tabela `request_service_consumptions`: definir `->restrictOnDelete()` explícito em `product_id`.
14. `2026_10_08_181819_create_stock_movements_table.php`:
    - Na tabela `stock_movements`: alterar `product_id` de `cascadeOnDelete()` para `restrictOnDelete()`.

### 4.2. Policies e Autorização (`$this->authorize`)

- **Perfis Autorizados para Exclusão (`delete`):** `Rules::Owner`, `Rules::Lab` e `Rules::LabManager`.
- **Bloqueio de Hard Delete (`forceDelete`):** Retorna sempre `false` em todas as Policies.
- **Mapeamento via Atributo `#[UsePolicy(...)]` nos Models:**
  - `Dentist` -> `#[UsePolicy(DentistPolicy::class)]`
  - `Partner` -> `#[UsePolicy(PartnerPolicy::class)]`
  - `Product` -> `#[UsePolicy(ProductPolicy::class)]`
  - `ProductCategory` -> `#[UsePolicy(ProductCategoryPolicy::class)]`
  - `Service` -> `#[UsePolicy(ServicePolicy::class)]`
  - `ServiceStep` -> `#[UsePolicy(ServiceStepPolicy::class)]`
- **Componentes Livewire (`delete.blade.php`):**
  - Todos os componentes (`dentists/delete`, `partners/delete`, `products/delete`, `products/categories/delete`, `service/delete`, `step/delete`) devem carregar a instância do modelo, chamar `$this->authorize('delete', $model)` e executar o Soft Delete na instância (`$model->delete()`), nunca usando `whereId(...)->delete()` sem autorização.

---

## 5. Critérios de Aceite (Testes Automatizados)

1. **Autorização por Policy (`403 Forbidden`) em Todos os Componentes de Exclusão:**
   - Usuários com perfil `Dentist` ou `LabPartner` recebem `403 Forbidden` ao acionar `delete()` em `dentists.delete`, `partners.delete`, `products.delete`, `products.categories.delete`, `service.delete` e `step.delete`.
   - Usuários `Owner`, `Lab` e `LabManager` conseguem executar a exclusão.
2. **Soft Delete Obrigatório e Preservação Integral do Histórico:**
   - Excluir um `Dentist` preenche `dentists.deleted_at` e `users.deleted_at` (Soft Delete), mantendo 100% dos registros de `dentist_requests`, `request_services`, `partner_demands`, `files` e relatórios de faturamento intactos.
   - Excluir um `Partner` sem demandas em andamento preenche `partners.deleted_at` e `users.deleted_at`, desatribui demandas ainda não iniciadas, preserva todas as `partner_demands` concluídas e mantém o CMV/comissões intactos nos relatórios.
   - Tentar excluir um `Partner` que possua demanda em andamento (`started_at !== null && ended_at === null`) é bloqueado com toast de erro.
   - Excluir um `Service` ou `Product` realiza Soft Delete (`deleted_at !== null`), mantendo `request_services`, `stock_batches`, `stock_movements` e `request_service_consumptions` preservados e acessíveis nos históricos e relatórios via `withTrashed()`.
   - Tentar excluir uma `ServiceStep` vinculada a um serviço ativo é bloqueado; se desvinculada de serviços ativos, realiza Soft Delete preservando o histórico de `service_service_step` e `partner_demands`.
3. **Recriação de Registros com Mesmo Nome ou E-mail após Soft Delete:**
   - Após excluir (Soft Delete) um `Service`, `ServiceStep`, `Product` ou `ProductCategory`, o sistema permite cadastrar um novo registro ativo com exatamente o mesmo `name`.
   - Tentar cadastrar ou editar um `Service`, `ServiceStep`, `Product` ou `ProductCategory` com o mesmo `name` de outro registro **ativo** (`deleted_at IS NULL`) falha na validação do formulário.
   - Após excluir (Soft Delete) um `Dentist` ou `Partner`, o sistema permite cadastrar um novo `Dentist` ou `Partner` com o mesmo `email`.
   - Tentar cadastrar com o `email` de um usuário **ativo** falha na validação do formulário.
4. **Proteção contra Autoexclusão do Único Dono (`delete-user-form.blade.php`):**
   - O único usuário `Rules::Lab` / `Rules::Owner` ativo do tenant é impedido de excluir a própria conta em `delete-user-form.blade.php`, garantindo que o tenant jamais fique órfão e que `ImpersonateTenantService` continue funcional.
   - Quando há mais de um administrador `Rules::Lab` ativo, a exclusão da conta realiza Soft Delete do usuário.

---

## 6. Documentação Relacionada

- [Domínio: Pedidos & Ordens de Serviço (Orders)](../../domain/orders.md)
- [Domínio: Estoque & Insumos (Inventory)](../../domain/inventory.md)
- [Convenções de Dados Globais](../../architecture/data-conventions.md)
- [Acesso Central, Impersonation & Auditoria](../../architecture/central-admin-access.md)
