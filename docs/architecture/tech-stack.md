# Tech Stack & Convenções Técnicas

Este documento reúne a stack tecnológica oficial do sistema SaaS Dental Lab, os padrões de arquitetura, as bibliotecas utilizadas e as convenções de código adotadas.

---

## 1. Stack Tecnológica

### Backend & Core
- **Linguagem:** PHP 8.3+ (compatível com PHP 8.5)
- **Framework:** Laravel 13.x
- **Multitenancy:** `stancl/tenancy` v3 (Estratégia **Multi-schema no PostgreSQL**)
- **Fila & Cache:** Redis (`predis/predis`), Driver de fila assíncrono via Redis / Database
- **Monitoramento & Logs:** Sentry (`sentry/sentry-laravel`), Laravel Telescope (dev), Laravel Pail, Barryvdh Debugbar (dev)
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
- Use componentes Flux UI para botões, modais, formulários e dropdowns, preservando acessibilidade e estados de loading.

### 3.4. Pint & Formatação
- Antes de submeter código, sempre execute:
  ```bash
  vendor/bin/pint --format agent
  ```
- Sempre utilize chaves `{}` para blocos de controle, mesmo com uma única linha.
