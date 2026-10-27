# Especificação: Lab Manager - Etapa 1: Planos, Assinaturas e Persistência Monetária

- **Identificador:** `2026-10-lab-manager-step1`
- **Status:** Implementado
- **Data:** Outubro de 2026
- **Módulos Afetados:** Banco Central (`database/migrations/`), Modelos Centrais (`Plan`, `Tenant`, `Subscription`, `Payment`), Helper de Moeda (`app/Helpers/currency.php`).

---

## 1. Contexto & Motivação

Para monetizar o SaaS Dental Lab, a plataforma necessita de uma estrutura central de planos de assinatura, cobranças e rastreamento do ciclo de vida contratual de cada laboratório (tenant).

Esta especificação define o esquema de tabelas do banco central (`public`) e os modelos Eloquent com suporte a regras monetárias em centavos inteiros.

---

## 2. Regras de Dados & Persistência

### 2.1. Regra Monetária
- Todo valor monetário é persistido no banco como número inteiro (`INTEGER` ou `BIGINT`) representando centavos (ex: R$ 100,00 = `10000`).
- Modelos usam casts e decorators PHP 8 (`Attribute`) integrados com o helper `currency_from_int` / `currency_to_int`.

---

## 3. Estrutura de Tabelas & Schemas Centrais

### 3.1. `plans`
- `id` (UUID / BIGSERIAL, PK)
- `name` (string)
- `price_in_cents` (bigInteger) - Valor em centavos
- `billing_cycle` (enum: `monthly`, `annual`)
- `limits` (jsonb / array) - Limites do plano (ex: `{"users_limit": 10, "storage_gb": 50}`)
- `is_active` (boolean)
- `timestamps`

### 3.2. `tenants`
- `id` (UUID / string, PK)
- `name` (string)
- `domain` (string, unique)
- `status` (enum: `pending_payment`, `provisioning`, `active`, `suspended`, `cancelled`)
- `db_name` (string, nullable)
- `domain_reserved_until` (timestamp, nullable)
- `timestamps`

### 3.3. `subscriptions`
- `id` (UUID / BIGSERIAL, PK)
- `tenant_id` (FK para `tenants`, cascade delete)
- `plan_id` (FK para `plans`)
- `status` (enum: `trial`, `active`, `overdue`, `canceled`)
- `current_period_start` (timestamp)
- `current_period_end` (timestamp)
- `timestamps`

### 3.4. `payments`
- `id` (UUID / BIGSERIAL, PK)
- `tenant_id` (FK para `tenants`)
- `gateway_transaction_id` (string, nullable)
- `amount_in_cents` (bigInteger) - Valor em centavos
- `status` (enum: `pending`, `paid`, `failed`, `expired`)
- `due_date` (timestamp)
- `paid_at` (timestamp, nullable)
- `timestamps`

---

## 4. Convenções dos Modelos Eloquent

- Utilização de PHP 8 Attributes (`#[Fillable([...])]`, `#[Scope]`).
- Conversão de centavos automática nos atributos monetários via helper `Currency`.
- Relacionamentos mapeados: `Tenant hasMany Subscriptions`, `Tenant hasMany Payments`, `Plan hasMany Subscriptions`.