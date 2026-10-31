# Convenções de Dados Globais

Este documento define os padrões fundamentais para representação e manipulação de dados em todo o ecossistema do Dental Lab (banco central e schemas de tenants).

---

## 1. Valores Monetários (Dinheiro em Centavos)

Para eliminar erros de arredondamento inerentes a tipos de ponto flutuante (`float`/`double`), **todos os valores monetários no banco de dados são armazenados como inteiros (`INTEGER` ou `BIGINT`) representando centavos**.

### Regras:
- R$ 10,00 é persistido como `1000`.
- R$ 150,50 é persistido como `15050`.

### Casts nos Modelos Eloquent:
Utilize o padrão com `Attribute` ou o helper global de moeda:
```php
use App\Facades\Currency;
use Illuminate\Database\Eloquent\Casts\Attribute;

// Exemplo em Product / StockMovement / StockBatch / RequestService / Payment:
protected function unitCost(): Attribute
{
    return Attribute::make(
        set: fn (?float $value) => $value !== null ? (int) round($value * 100) : null,
        get: fn (?int $value) => $value !== null ? $value / 100 : null
    );
}
```

### Helpers Globais:
Disponíveis em `app/Helpers/currency.php`:
- `currency_to_int($value)`: Converte float/string para inteiro (centavos).
- `currency_to_float($value)`: Converte string monetária formatada (ex.: `R$ 1.500.000,50`) em float decimal em reais (`1500000.50`), com suporte resiliente a múltiplos separadores de milhar e símbolos de moeda.
- `currency_from_int($value, $formatted = false)`: Converte centavos para float ou string formatada em moeda brasileira (`R$ 150,50`).

### Comissões Híbridas de Parceiros (Percentual vs. Valor Fixo):
O sistema permite configurar comissões de técnicos parceiros como percentual (ex.: `12.5%`) ou quantia fixa em reais (ex.: `R$ 45,50`).
- **Persistência em `partner_demands.partner_commission`:** Armazenada como `DECIMAL(10, 2)` (com cast Eloquent `'decimal:2'`) para prevenir o truncamento indevido de centavos e frações decimais de porcentagem decorrentes de tipos inteiros.
- **Cálculo Consolidado em Relatórios:** 
  - Fixo: convertido para centavos via SQL `ROUND(partner_demands.partner_commission * 100)`.
  - Percentual: calculado sobre o montante em centavos via `ROUND(partner_demands.partner_commission * request_services.unit_price * request_services.quantity / 100.0)`.

---

## 2. Datas, Horários e Timezone

- **Persistência no Banco:** Todos os timestamps (`created_at`, `updated_at`, `completed_at`, transações) são persistidos em **UTC**.
- **Apresentação ao Usuário:** A interface exibe datas no fuso horário do usuário/laboratório (padrão Brasil: `America/Sao_Paulo` ou timezone configurado pelo tenant).
- **Datas de Validade / Vencimento:** Utilizam colunas do tipo `date` sem componente de horário e são castadas como `date` no Eloquent (`'expiration_date' => 'date'`).

---

## 3. Quantidades e Unidades de Medida

- **Quantidades em Estoque / Movimentação:**
  - Armazenadas como colunas `DECIMAL(10, 2)` para suportar tanto itens unitários (ex: 2 coroas) quanto fracionados (ex: 15.5 gramas de resina/liga metálica, 2.5 ml).
  - Casts Eloquent configurados como `'decimal:2'`.
- **Quantidades de Itens Solicitados (`RequestService`):**
  - Armazenadas como `unsignedInteger` para suportar grandes tiragens (até 1.000.000 de unidades) sem estourar o limite de `smallint` do PostgreSQL.
- **Unidades de Medida (`UnitMeasure` Enum):**
  - Padronizadas via Enum: `UN` (Unidade), `GR` (Grama), `ML` (Mililitro), `KG` (Quilo), `CX` (Caixa), etc.

---

## 4. Identificadores Únicos e Códigos de Rastreamento

- **Chaves Primárias Internas:** `BIGSERIAL` / `id()` padrão do Laravel para integridade referencial rápida.
- **Códigos Externos e Rastreamento de Ordens:**
  - Ordens de Serviço (`DentistRequest`): Código público gerado com `Ulid::generate()`, garantindo identificadores ordenáveis no tempo, sem colisão e sem expor IDs sequenciais para clientes/dentistas.
  - Lotes de Estoque: `batch_number` gerado ou informado pelo fornecedor, rastreável por movimentações.
  - Arquivos: `Ulid` ou `UUID` associados à chave do S3 para evitar sobrescritas acidentais.

---

## 5. Soft Deletes, Unicidade Ativa e Integridade Relacional

- **Proibição de Hard Delete (`SoftDeletes` Obrigatório) ([spec](../specs/active/2026-10-deletion-integrity-and-authorization.md)):**
  - As entidades `User`, `Dentist`, `Partner`, `Service`, `ServiceStep`, `Product` e `ProductCategory` utilizam obrigatoriamente `SoftDeletes` (`deleted_at`), nunca sendo removidas fisicamente do banco de dados (`forceDelete` retorna `false` nas Policies).
  - Relacionamentos `BelongsTo` que partem de registros históricos/transacionais (`DentistRequest`, `RequestService`, `PartnerDemand`, `ServiceServiceStep`, `StockBatch`, `StockMovement`, `RequestServiceConsumption`) declaram `->withTrashed()` para preservar a exibição de nomes e cálculos em pedidos concluídos e relatórios gerenciais.
- **Unicidade Escopada a Registros Ativos (`whereNull('deleted_at')`):**
  - Colunas de nome (`services.name`, `service_steps.name`, `products.name`, `product_categories.name`) e e-mail (`users.email`) não utilizam constraint `UNIQUE` irrestrita no banco que impeça o recadastro após Soft Delete. A unicidade é garantida na validação de formulário considerando apenas registros ativos (`Rule::unique(...)->whereNull('deleted_at')`).
- **Restrições Referenciais (`restrictOnDelete`) no Banco de Dados:**
  - `dentists.user_id`, `partners.user_id`, `dentist_requests.dentist_id` e `request_services.service_id`: utilizam `restrictOnDelete` para impedir qualquer deleção física em cascata no banco que apague faturamento ou deixe arquivos órfãos em `files`/S3.
  - `partner_demands.service_service_step_id`, `partner_demands.partner_id`, `service_service_step.service_id` e `service_service_step.service_step_id`: utilizam `restrictOnDelete` para blindar demandas executadas e comissões passadas.
  - `stock_batches.product_id`, `stock_movements.product_id` e `request_service_consumptions.product_id`: utilizam `restrictOnDelete` para proteger o histórico de movimentações e lotes.
