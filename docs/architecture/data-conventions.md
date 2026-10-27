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

// Exemplo em StockMovement / StockBatch / RequestService / Payment:
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
- `currency_from_int($value, $formatted = false)`: Converte centavos para float ou string formatada em moeda brasileira (`R$ 150,50`).

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

## 5. Soft Deletes e Integridade Relacional

- Registros financeiros, movimentações de estoque e ordens com faturamento não devem ser removidos fisicamente sem auditoria.
- Deleções em cascata são restritas estritamente ao escopo da mesma entidade pai (ex: deletar itens temporários não confirmados), enquanto entidades consolidadas devem usar status (ex: status cancelado, arquivado).
