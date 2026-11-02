# Tech Stack & Convenções Técnicas

Este documento reúne a stack tecnológica oficial do sistema SaaS Dental Lab, os padrões de arquitetura, as bibliotecas utilizadas e as convenções de código adotadas.

---

## 1. Stack Tecnológica

### Backend & Core
- **Linguagem:** PHP 8.3+ (compatível com PHP 8.5)
- **Framework:** Laravel 13.x
- **Multitenancy:** `stancl/tenancy` v3 (Estratégia **Multi-schema no PostgreSQL**)
- **Fila & Cache:** Redis (`predis/predis`), Driver de fila assíncrono via Redis / Database
- **Monitoramento & Logs:** Sentry (`sentry/sentry-laravel`), Laravel Telescope (dev), Laravel Pail, Barryvdh Debugbar (dev). Logs de aplicação e webhooks operam sob o princípio de minimização de dados da LGPD: é vedado registrar payloads integrais ou dados pessoais identificáveis (PII, e-mails, endereços, cartões), registrando apenas identificadores de correlação (`event_id`, `type`, `tenant_id`, `payment_id`) ([spec](../specs/archived/2026-11-webhook-payment-log-pii-sanitization.md)).
- **Storage:** League Flysystem AWS S3 v3 (arquivos, uploads e anexos)

### Frontend & UI
- **Reatividade & Componentes:** Livewire v4 + Livewire Volt (Single-file components)
- **Design System & UI Kits:** Flux UI (`livewire/flux`) + Tailwind CSS
- **Bundler:** Vite + PostCSS

### Testes & Qualidade de Código
- **Test Runner:** PHPUnit 12.x (`vendor/bin/phpunit` ou `php artisan test`)
- **Formatador de Código:** Laravel Pint (Regras PSR-12 / Laravel Agent)
- **Faker & Mocks:** FakerPHP, Mockery

---

## 2. Padrões de Arquitetura & Multitenancy

O sistema é um **SaaS B2B Multitenant** projetado para atender laboratórios de prótese dentária com isolamento rígido de dados:

1. **Separação de Bancos/Schemas:**
   - **Banco Central (`public`):** Gerencia tenants, assinaturas, planos globais, webhooks centrais e usuários do painel mestre. Migrações em `database/migrations/`.
   - **Schemas de Tenants (`tenant_{slug}`):** Cada laboratório possui um schema isolado gerenciado pelo `stancl/tenancy` via `PostgreSQLSchemaManager`. Migrações operacionais em `database/migrations/tenant/`.

2. **Roteamento Isolado:**
   - **Rotas Centrais:** Declaradas em `routes/web.php` (Landing Page, cadastro, onboarding, webhooks).
   - **Rotas de Laboratório:** Declaradas em `routes/tenant.php` (Protegidas pelo middleware de inicialização de tenant).

3. **Provisionamento Assíncrono:**
   - O cadastro de novos laboratórios é 100% assíncrono disparado via Job (`ProvisionTenant`).
   - Etapas: criação de schema -> execução de migrations do tenant -> seeders padrão -> criação de admin -> ativação.

---

## 3. Convenções de Código

### 3.1. Nomenclatura e Idioma
- **Código (Backend/Database):** 100% em **Inglês** (nomes de tabelas, colunas, models, controllers, jobs, eventos, métodos e variáveis).
- **Interface e Mensagens:** 100% em **Português (PT-BR)** para textos visíveis, validações e alertas.

### 3.2. Modelos Eloquent & PHP 8 Attributes
- Prefira **decorators / PHP 8 Attributes** nativos do Laravel em vez de propriedades protegidas:
  ```php
  #[Fillable(['name', 'category_id', 'is_active'])]
  #[UsePolicy(ProductPolicy::class)]
  class Product extends Model
  {
      // ...
  }
  ```
- **Controle de tipos estritos:** Sempre utilize tipos de retorno explícitos e tipagem de argumentos em métodos:
  ```php
  public function isLowStock(): bool
  {
      return (float) $this->current_stock <= (float) $this->min_stock;
  }
  ```
- **Enums Nativos:** Use Enums tipados em PHP para status, tipos e categorias (ex: `StockMovementsTypes`, `PaymentStatus`, `TenantStatus`). Nomes de casos de enum em `TitleCase` ou `PascalCase`.

### 3.3. Livewire & Volt
- Componentes single-file em `resources/views/livewire/`.
- Priorize manter o estado e as validações no lado do servidor.
- **Proteção de Estado Sensível com `#[Locked]` ([spec tenant](../specs/archived/2026-10-complete-registration-locked-properties-and-token-validation.md), [spec central](../specs/archived/2026-10-central-register-invite-account-takeover-protection.md), [spec onboarding](../specs/archived/2026-10-onboarding-checkout-and-first-access-url-protection.md), [spec faturamento perfil](../specs/archived/2026-11-profile-subscription-billing-authorization.md), [spec mock stripe](../specs/archived/2026-11-stripe-mock-mode-environment-restriction.md)):** Propriedades públicas que armazenam identificadores ou models de contexto (`$tenant` em `onboarding.blade.php` e `checkout.blade.php`), tokens de convite/autenticação, e-mails de contexto validados via URL assinada (`signed`), segredos e flags de pagamento (`$clientSecret`, `$stripeKey` e `$isMockMode` em `checkout.blade.php` e `subscription-billing.blade.php`) ou flags de controle de segurança/expiração (como `$token`, `$email` e `$isExpired` em `complete-registration.blade.php` e `register-invite.blade.php`) devem utilizar obrigatoriamente o atributo `#[Locked]` (`Livewire\Attributes\Locked`) para impedir adulteração no payload de requisições `POST /livewire/update`. Além disso, modos de simulação de pagamento (`$isMockMode` / `StripeService::isMock()`) devem ser estritamente restritos a `app()->environment(['local', 'testing'])` (lançando `\RuntimeException` *fail-closed* caso `STRIPE_KEY`/`STRIPE_SECRET` estejam ausentes ou com `'placeholder'` em produção/staging), e componentes que geram tokens ou `SetupIntent` no `mount()` e expõem métodos mutáveis (como `updateCard` e `regularizeSubscription`) devem validar explicitamente a autorização do usuário no servidor (`abort_unless($this->isAuthorized, 403)`) e nunca depender apenas de ocultação condicional no Blade.
- **Autorização Server-Side Obrigatória (`$this->authorize`) em Componentes Livewire ([spec](../specs/archived/2026-11-livewire-server-side-authorization-enforcement.md)):** Como qualquer método público de um componente Livewire pode ser acionado via `POST /livewire/update` (inclusive componentes auxiliares montados em páginas compartilhadas por `Dentist` ou `LabPartner`), todos os métodos de ação e abertura/carregamento de modal (`save`, `store`, `edit`, `delete`, `confirmDelete`, `open`, `openModal`, `openForProduct`, `complete`, `assignDemand`, `registerOut`, `loadRequest`, `download`) devem executar `$this->authorize(...)` na entrada do método (ou validar `CentralUser` no guard `central` em páginas do painel dos sócios). Restrições visuais no HTML (`@can`, `:disabled`) nunca substituem a validação no servidor.
- **Resolução Server-Side de Valores Financeiros e Metadados de Armazenamento ([spec preços](../specs/archived/2026-11-dentist-request-service-price-tampering-protection.md), [spec storage](../specs/archived/2026-11-s3-server-side-file-size-quota-validation.md)):** Campos exibidos como `readonly` na interface (como `services.*.unit_price` em `requesting.blade.php` e `unitPrice` em `update.blade.php`) ou metadados sensíveis a limites enviados pelo cliente após uploads diretos (como `files.*.size` em `addFilesToRequest`) jamais devem ser usados como fonte de verdade na persistência. O servidor deve ignorar o valor enviado pelo cliente e consultar a fonte autoritativa diretamente no backend (`Service::whereKey(...)->value('price')` no banco para preços e `Storage::disk($disk)->size($path)` no S3 para tamanho real de arquivos).
- Use componentes Flux UI para botões, modais, formulários e dropdowns, preservando acessibilidade e estados de loading.

### 3.4. Pint & Formatação
- Antes de submeter código, sempre execute:
  ```bash
  vendor/bin/pint --format agent
  ```
- Sempre utilize chaves `{}` para blocos de controle, mesmo com uma única linha.

### 3.5. Logs de Aplicação e Minimização de Dados (LGPD)
- Chamadas a `Log::*` em controladores e webhooks públicos (como `PaymentWebhookController`) nunca devem despejar payloads integrais (`$payload`, `$request->all()`) nos arquivos de log da aplicação.
- O contexto de logging deve registrar estritamente identificadores de correlação técnica (`event_id`, `type`, `tenant_id`, `payment_id`), preservando a privacidade de clientes e a conformidade com a LGPD e PCI-DSS ([spec](../specs/archived/2026-11-webhook-payment-log-pii-sanitization.md)).

### 3.6. Consultas SQL Raw e Prevenção de Injeção (Bindings Obrigatórios - OWASP A03:2021)
- É estritamente vedada a interpolação direta de variáveis, parâmetros de requisição ou constantes de Enums em strings SQL passadas para métodos `Raw` do Query Builder (`whereRaw`, `orderByRaw`, `selectRaw`, `havingRaw`, `groupByRaw` ou `DB::raw`).
- Toda consulta com predicados ou projeções condicionais deve utilizar *parameter bindings* posicionais (`?`) com passagem de valores pelo array de bindings do Eloquent (ex.: `$batchQuery->orderByRaw('id = ? DESC', [$batchId])`, `$query->whereRaw('LOWER(name) LIKE ?', ['%'.$search.'%'])`, ou `->selectRaw('SUM('.$expression.') AS total', $bindings)`).
- O uso de parameter bindings garante conformidade com as diretrizes de segurança contra injeção SQL (OWASP A03:2021), assegurando que o PostgreSQL trate todos os valores exclusivamente como dados primitivos, independentemente de provirem de inputs do usuário ou de enums internos ([spec](../specs/archived/2026-11-sql-injection-prevention-and-parameter-bindings.md)).
