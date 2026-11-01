# Especificação: Proteção das Rotas de Onboarding/Checkout e Eliminação da Exposição Pública de `first_access_url`

- **Identificador:** `2026-10-onboarding-checkout-and-first-access-url-protection`
- **Status:** Planejado / Em Andamento
- **Data:** Outubro de 2026
- **Módulos Afetados:**
  - Rotas Centrais e Middleware (`routes/web.php`, `app/Http/Middleware/EnsureTenantIsOperational.php`)
  - Fluxo Central de Onboarding e Checkout (`resources/views/livewire/pages/central/onboarding.blade.php`, `resources/views/livewire/pages/central/checkout.blade.php`, `resources/views/livewire/pages/auth/central-registration.blade.php`, `resources/views/livewire/pages/central/admin/laboratories/create.blade.php`)
  - Provisionamento e Primeiro Acesso (`app/Actions/CreateLaboratory.php`, `app/Jobs/ProvisionTenant.php`, `app/Actions/ResendFirstAccessLink.php`, `app/Models/Tenant.php`)
  - Testes de Segurança e Onboarding (`tests/Feature/Auth/RegistrationTest.php`, `tests/Feature/TenantRegistrationFlowTest.php`, `tests/Feature/ResendFirstAccessTest.php`)

---

## 1. Contexto & Motivação

A demanda **2.3 ([CRÍTICA] Exposição Pública da URL Assinada de Primeiro Acesso em `/bem-vindo/{tenant}` e `/checkout/{tenant}`)** identifica uma vulnerabilidade crítica de tomada de conta (*Account Takeover*) do proprietário do laboratório durante a janela entre o provisionamento e a conclusão do primeiro acesso:

1. **Rotas `/bem-vindo/{tenant}` (`onboarding.show`) e `/checkout/{tenant}` (`onboarding.checkout`) Públicas e Previsíveis:**
   - Em `routes/web.php` (linhas 29-31), ambas as rotas estão registradas sem middleware `signed`, sem verificação de sessão do criador do laboratório e sem autenticação.
   - Como o parâmetro `{tenant}` corresponde ao `slug` previsível do laboratório, qualquer visitante não autenticado pode acessar `/bem-vindo/{slug}` ou `/checkout/{slug}` de terceiros.
2. **Exposição Direta da URL Assinada (`first_access_url`) no HTML e em Redirecionamentos:**
   - `ProvisionTenant` (linha 155) e `ResendFirstAccessLink` (linha 57) armazenam a URL assinada completa de `/completar-cadastro` (contendo `email`, `token`, `expires` e `signature` do proprietário) nos metadados do tenant (`$tenant->first_access_url` / `tenants.data['first_access_url']`).
   - Em `resources/views/livewire/pages/central/onboarding.blade.php` (linhas 17-26 e 49), a propriedade computada `$this->firstAccessUrl` lê `$this->tenant->first_access_url` e:
     - Redireciona imediatamente qualquer visitante em `mount()` para `$this->firstAccessUrl` caso `$this->tenant->status === TenantStatus::Active`;
     - Renderiza `<flux:button href="{{ $this->firstAccessUrl }}">` diretamente no HTML quando o polling (`refreshStatus`) detecta status `Active`.
   - Em `resources/views/livewire/pages/central/checkout.blade.php` (linhas 41-44), o método `mount()` também redireciona qualquer visitante para `$this->tenant->first_access_url` quando o tenant está `Active`.
3. **Ausência de `#[Locked]` na Propriedade Pública `$tenant`:**
   - Nos componentes `onboarding.blade.php` e `checkout.blade.php`, a propriedade `public Tenant $tenant` não possui o atributo `#[Locked]`, permitindo tentativas de troca do modelo hidratado em chamadas subsequentes `POST /livewire/update`.

### Impacto
Qualquer pessoa que acesse `/bem-vindo/{slug-do-lab}` ou `/checkout/{slug-do-lab}` enquanto o laboratório ainda não concluiu o primeiro acesso (`verified_at === null`) obtém a URL assinada completa de `/completar-cadastro` do proprietário, podendo definir a senha inicial e assumir controle administrativo total do laboratório. Além disso, terceiros não autenticados podem acessar `/checkout/{slug-do-lab}` de um laboratório recém-criado e manipular a escolha de planos ou o checkout pendente.

---

## 2. Objetivos

1. **Proteger o acesso às rotas `/checkout/{tenant}` e `/bem-vindo/{tenant}`:** Exigir assinatura de URL válida (`signed` / `hasValidSignature()`), sessão autorizada do criador do laboratório (`onboarding_authorized_tenants`) ou autenticação de administrador central (`auth:central`), negando acesso público arbitrário com **HTTP 403**.
2. **Eliminar o armazenamento e a exposição pública de `first_access_url`:** Nunca persistir a URL assinada de `/completar-cadastro` nos metadados públicos do `Tenant` (`tenants.data['first_access_url']`) nem entregá-la em HTML ou redirecionamentos de `/bem-vindo/{tenant}` e `/checkout/{tenant}`. O link assinado de primeiro acesso com o `InvitationToken` deve ser entregue exclusivamente via canal seguro de e-mail (`TenantWelcomeNotification`) ao `owner_email`.
3. **Blindar o estado Livewire nos componentes de Onboarding e Checkout:** Aplicar `#[Locked]` à propriedade `public Tenant $tenant` em `onboarding.blade.php` e `checkout.blade.php` e revalidar a autorização de acesso ao tenant.

---

## 3. Regras de Negócio & Segurança

### 3.1. RN-01: Controle de Acesso Estrito a `/checkout/{tenant}` e `/bem-vindo/{tenant}`

1. **Critérios de Autorização:**
   O acesso às rotas `GET /checkout/{tenant}` (`onboarding.checkout`) e `GET /bem-vindo/{tenant}` (`onboarding.show`) — bem como a montagem dos componentes `pages.central.checkout` e `pages.central.onboarding` — só é permitido quando **pelo menos uma** das condições abaixo for satisfeita:
   - **Sessão do Criador do Laboratório:** A sessão HTTP atual contém o vínculo explícito de criação daquele tenant (`session("onboarding_authorized_tenants.{$tenant->id}") === true`), registrado no momento em que o laboratório é criado em `CreateLaboratory::handle()` / `central-registration.blade.php` / `admin/laboratories/create.blade.php`; **ou**
   - **URL Assinada Válida (`signed`):** A requisição possui assinatura criptográfica válida (`request()->hasValidSignature()`) gerada via `URL::signedRoute` ou `URL::temporarySignedRoute`; **ou**
   - **Sócio / Administrador Central Autenticado:** O usuário atual está autenticado no guard `central` (`Auth::guard('central')->check()`).
2. **Bloqueio de Acesso Não Autorizado:**
   - Qualquer requisição para `/checkout/{tenant}` ou `/bem-vindo/{tenant}` que não atenda a nenhum dos critérios acima deve ser imediatamente rejeitada com **HTTP 403 (Forbidden)**, sem renderizar dados do laboratório, planos ou status de provisionamento.
3. **Preservação da Autorização na Transição entre Etapas:**
   - Ao concluir o cadastro inicial (`/cadastro` ou `/admin/laboratorios/cadastrar`), a sessão do criador é autorizada para o `$tenant->id` criado e o redirecionamento para `onboarding.checkout` ou `onboarding.show` utiliza URL assinada (`URL::temporarySignedRoute`) e/ou sessão autorizada.
   - Ao redirecionar de `checkout.blade.php` para `onboarding.show` (seja por conclusão de pagamento em `confirmPaymentMethod` ou por bypass de isenção/trial em `mount`), a autorização de sessão do criador é mantida/renovada para `onboarding.show`.

### 3.2. RN-02: Proibição de Armazenamento e Exposição Pública da `first_access_url`

1. **Entrega Exclusiva por E-mail (`ProvisionTenant` e `ResendFirstAccessLink`):**
   - A URL assinada temporária de primeiro acesso (`URL::temporarySignedRoute('complete-signup.show', ...)`) contém o `InvitationToken` do proprietário e **não deve ser persistida** em `$tenant->first_access_url` / `tenants.data['first_access_url']`.
   - `ProvisionTenant` e `ResendFirstAccessLink` devem gerar a URL assinada apenas em memória para enviá-la via `TenantWelcomeNotification` ao `owner_email` do laboratório.
   - Caso exista qualquer chave residual `first_access_url` em `tenants.data`, ela deve ser ignorada e removida quando o tenant for verificado (`Tenant::markAsVerified()`).
2. **Comportamento Seguro em `/bem-vindo/{tenant}` (`onboarding.blade.php`):**
   - O componente `onboarding.blade.php` **não deve** possuir propriedade ou método que exponha `$tenant->first_access_url` no HTML ou em redirecionamentos.
   - Quando `$tenant->status === TenantStatus::Active`:
     - Em `mount()`: se o acesso ocorrer quando o laboratório já está ativo, redireciona para a rota de login do subdomínio do laboratório (`route('login', ['tenant' => $this->tenant->id])`), nunca para a URL assinada de `/completar-cadastro`.
     - Na interface (após o polling `refreshStatus` detectar `TenantStatus::Active`): exibe a confirmação de que o laboratório está pronto, informa que o link seguro para definição de senha foi enviado para o e-mail do proprietário (`$tenant->owner_email`) e disponibiliza o botão **"Acessar plataforma"** apontando exclusivamente para `route('login', ['tenant' => $this->tenant->id])` (onde o proprietário também dispõe do atalho de reenvio de link para o `owner_email` caso necessário).
3. **Comportamento Seguro em `/checkout/{tenant}` (`checkout.blade.php`):**
   - Em `mount()`, caso `$this->tenant->status === TenantStatus::Active`, o redirecionamento deve apontar exclusivamente para `route('login', ['tenant' => $this->tenant->id])`, sem jamais consultar ou redirecionar para `$this->tenant->first_access_url`.

### 3.3. RN-03: Imutabilidade de `$tenant` via `#[Locked]` no Livewire

1. Em `resources/views/livewire/pages/central/onboarding.blade.php` e `resources/views/livewire/pages/central/checkout.blade.php`, a propriedade pública `$tenant` deve ser declarada com `#[Locked]` (`Livewire\Attributes\Locked`):
   ```php
   #[Locked]
   public Tenant $tenant;
   ```
2. Qualquer tentativa de alterar `tenant` no payload de `POST /livewire/update` lançará `CannotUpdateLockedPropertyException`.

---

## 4. Detalhamento Técnico

### 4.1. Rotas e Autorização de Sessão/Assinatura
1. Em `app/Actions/CreateLaboratory.php`:
   - Ao criar o `Tenant` em `handle()`, registrar na sessão ativa a autorização do criador: `session()->put("onboarding_authorized_tenants.{$tenant->id}", true)`.
   - Expor método auxiliar na model `Tenant` ou classe de suporte (ex.: `Tenant::authorizeOnboardingSession(string $tenantId): void` e `Tenant::isOnboardingAuthorizedForCurrentRequest(Request $request, Tenant $tenant): bool`) que verifica:
     - `$request->hasValidSignature()`
     - `Auth::guard('central')->check()`
     - `$request->hasSession() && $request->session()->get("onboarding_authorized_tenants.{$tenant->id}") === true`
2. Em `routes/web.php`:
   - Proteger as rotas `/checkout/{tenant}` (`onboarding.checkout`) e `/bem-vindo/{tenant}` (`onboarding.show`) com middleware de verificação de acesso ao onboarding (ou validação combinada na rota e no `mount()` dos componentes Volt), retornando `abort(403)` para acessos públicos não autorizados.
3. Em `app/Http/Middleware/EnsureTenantIsOperational.php`:
   - Ao redirecionar de um subdomínio em `PendingPayment` ou `Provisioning` para `onboarding.checkout` ou `onboarding.show`, gerar URL assinada temporária (`URL::temporarySignedRoute(..., now()->addMinutes(30), ['tenant' => $tenant->getTenantKey()])`) ou validar sessão, mantendo compatibilidade com os redirecionamentos operacionais.

### 4.2. Componentes `onboarding.blade.php` e `checkout.blade.php`
1. Em `resources/views/livewire/pages/central/onboarding.blade.php`:
   - Adicionar `#[Locked]` em `public Tenant $tenant`.
   - Validar autorização no `mount(Tenant $tenant)` com `abort_unless(...)`.
   - Remover o uso de `$this->tenant->first_access_url` / `$this->tenant->data['first_access_url']`; fazer `loginUrl()` retornar exclusivamente `route('login', ['tenant' => $this->tenant->id])`.
   - Atualizar o botão e o texto de conclusão para direcionar a `route('login', ['tenant' => $this->tenant->id])` e orientar o acesso ao link enviado por e-mail.
2. Em `resources/views/livewire/pages/central/checkout.blade.php`:
   - Adicionar `#[Locked]` em `public Tenant $tenant`.
   - Validar autorização no `mount(Tenant $tenant)` com `abort_unless(...)`.
   - Quando `$this->tenant->status === TenantStatus::Active`, redirecionar exclusivamente para `route('login', ['tenant' => $this->tenant->id])`.

### 4.3. `ProvisionTenant` e `ResendFirstAccessLink`
1. Em `app/Jobs/ProvisionTenant.php`:
   - Remover `$tenant->first_access_url = $firstAccessUrl;` antes de `$tenant->save()`, mantendo apenas o envio da `TenantWelcomeNotification` com `$firstAccessUrl`.
2. Em `app/Actions/ResendFirstAccessLink.php`:
   - Remover `$tenant->first_access_url = $firstAccessUrl; $tenant->save();`, mantendo a geração do token, a URL assinada temporária e o disparo da `TenantWelcomeNotification`.

---

## 5. Critérios de Aceite (Testes Automatizados)

1. **Bloqueio de Acesso Público Não Autorizado a `/bem-vindo/{tenant}` e `/checkout/{tenant}`:**
   - Uma requisição `GET /bem-vindo/{slug}` ou `GET /checkout/{slug}` feita por um visitante anônimo (sem sessão de criação daquele tenant, sem assinatura de URL válida e sem login no guard `central`) deve retornar **HTTP 403**.
   - Um visitante que criou o `lab-a` na sua sessão não consegue acessar `/bem-vindo/lab-b` nem `/checkout/lab-b` de outro laboratório (**HTTP 403**).
2. **Acesso Permitido para Sessão do Criador, URL Assinada ou Sócio Central:**
   - O usuário que acabou de cadastrar o laboratório em `/cadastro` (sessão autorizada) acessa normalmente `/checkout/{tenant}` e `/bem-vindo/{tenant}`.
   - Um sócio autenticado (`actingAs($centralUser, 'central')`) ou uma requisição com `URL::temporarySignedRoute` acessa normalmente `/checkout/{tenant}` e `/bem-vindo/{tenant}`.
3. **Eliminação do Armazenamento e Exposição de `first_access_url`:**
   - Após a execução de `ProvisionTenant` ou `ResendFirstAccessLink`, `tenants.data` **não** contém `first_access_url` (`$tenant->first_access_url === null`).
   - A página `/bem-vindo/{tenant}` quando `Active` (e o redirecionamento de `/bem-vindo/{tenant}` ou `/checkout/{tenant}` quando `Active`) aponta exclusivamente para `route('login', ['tenant' => $tenant->id])` e jamais contém `/completar-cadastro`, `token=` ou `signature=` no HTML ou no cabeçalho `Location`.
4. **Proteção `#[Locked]` em `$tenant`:**
   - Tentar modificar a propriedade `tenant` via `POST /livewire/update` em `pages.central.onboarding` ou `pages.central.checkout` lança `CannotUpdateLockedPropertyException`.

---

## 6. Documentação Relacionada

- [Domínio: Cobrança & Assinaturas — §6 Primeiro Acesso Protegido e Convites de Usuários](../../domain/billing.md)
- [Arquitetura: Tech Stack & Convenções Técnicas — §3.3 Livewire & Volt](../../architecture/tech-stack.md)
- [Especificação Arquivada: Onboarding do Laboratório, Checkout Stripe e Primeiro Acesso Protegido](../archived/2026-10-tenant-onboarding-and-billing.md)
- [Especificação Arquivada: Proteção de Propriedades Livewire (`#[Locked]`) e Validação Cruzada de Token em `complete-registration`](../archived/2026-10-complete-registration-locked-properties-and-token-validation.md)

