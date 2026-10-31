# Domínio: Estoque & Insumos (Inventory)

O módulo de estoque gerencia os materiais odontológicos e insumos utilizados na fabricação das próteses (gessos, resinas, blocos de zircônia, discos de fresagem, ligas metálicas, dentes pré-fabricados, etc.).

---

## 1. Entidades Principais

- **`Product`:** O material/insumo cadastrado no laboratório.
  - Possui `barcode`, `unit_of_measure`, estoque atual (`current_stock`), estoque mínimo (`min_stock`) e custo unitário médio (`unit_cost`, em centavos).
  - Flag `has_batches`: indica se o produto exige controle por lote e validade sanitária (Anvisa). Quando `has_batches = false`, o custo para apuração de CMV nas ordens de serviço é herdado diretamente de `products.unit_cost`.
  - Flag `is_active`: permite desativar produtos sem quebrar histórico.
- **`ProductCategory`:** Categoria organizacional do produto (ex: Cerâmicas, Resinas, Metais, Descartáveis).
- **`StockBatch`:** O lote físico de um determinado produto.
  - Registra `batch_number`, `expiration_date`, `initial_quantity` (acumulado histórico de entradas no lote), `current_quantity` (saldo disponível atual) e `cost_price` (custo unitário médio ponderado do lote, em centavos).
- **`StockMovement`:** Registro de qualquer alteração de quantidade no estoque (entrada, saída ou ajuste).
  - Guarda `type` (`In`, `Out`, `Adjustment`), `reason` (`StockMovementsReasons`) e `unit_cost` (custo unitário em centavos no momento da movimentação).
  - Pode vincular-se a um `StockBatch`, a uma ordem (`dentist_request_id`) e ao usuário responsável (`created_by_user_id`).

---

## 2. Tipos de Movimentação e Motivos

### Tipos (`StockMovementsTypes`)
- **`In` (Entrada):** Incrementa o saldo do produto e do lote.
- **`Out` (Saída):** Decrementa o saldo do produto e do lote.
- **`Adjustment` (Ajuste):** Corrige divergências de inventário, incrementando (`AdjustmentIn`) ou decrementando (`AdjustmentOut`) sincronizadamente o saldo do produto e do lote.

### Motivos (`StockMovementsReasons`)
- **Entradas (`in()`):**
  - `Purchase` (Compra de Fornecedor)
  - `ProductionSurplus` (Retorno / Sobra de Produção)
  - `Devolution` (Devolução de Cliente / OS Cancelada)
  - `InitialBalance` (Carga Inicial de Estoque)
  - `AdjustmentIn` (Ajuste de Inventário - Sobra)
- **Saídas (`out()`):**
  - `ServiceConsumption` (Consumo em OS - Uso Normal)
  - `ReworkProduction` (Refazimento / Erro de Produção)
  - `Expiration` (Vencimento de Validade)
  - `WasteDamage` (Perda / Avaria / Quebra)
  - `AdjustmentOut` (Ajuste de Inventário - Falta)
  - `SampleTest` (Teste Técnico / Calibração)

---

## 3. Regras de Negócio Críticas

1. **Rastreabilidade por Lote (Vigilância Sanitária / Anvisa):**
   - Para produtos com `has_batches = true`, toda entrada, saída ou ajuste de inventário deve obrigatoriamente associar e atualizar um `StockBatch`, mantendo o saldo total do produto (`products.current_stock`) em estrita paridade com a soma dos lotes (`SUM(stock_batches.current_quantity)`).
   - Lotes vencidos (`expiration_date < today()`) ou sem saldo (`current_quantity <= 0`) não são selecionados automaticamente para consumo em novas ordens de serviço.
2. **Estratégia FEFO / PEPS (Primeiro que Expira, Primeiro que Sai):**
   - Na saída rápida (`components/products/stock/out.blade.php`) e no processamento de baixas em `StockMovement::handle()` para produtos com múltiplos lotes válidos, a prioridade de consumo seleciona o lote válido com data de validade mais próxima (`expiration_date` ascendente).
   - Na retirada rápida, é permitido consumir até 100% do saldo disponível (`quantity <= current_stock`), e a exigência de lote aplica-se apenas quando `has_batches = true`.
3. **Custo Médio Ponderado (CMP) e Apuração de CMV ([spec](../specs/archived/2026-10-cmv-batch-cost-and-stock-consistency.md)):**
   - **Em Lotes (`StockBatch`):** Quando um lote já existente recebe uma entrada adicional com novo custo, seu `cost_price` é recalculado pela média ponderada entre o saldo atual remanescente (`current_quantity * cost_price`) e a nova entrada (`totalQuantity * unitCost`), e `initial_quantity` é incrementada com o volume adicionado.
   - **No Produto (`Product`):** Toda entrada com custo informado atualiza `products.unit_cost` via média ponderada com o saldo anterior.
   - **Herança nas Saídas / Consumo em OS (`ServiceConsumption`):** Saídas de produtos com lote herdam `unit_cost` de `$batch->cost_price` (com fallback para `$product->unit_cost`); saídas de produtos sem lote (`has_batches = false`) herdam automaticamente `$product->unit_cost`. Isso garante que os relatórios de lucratividade (`reports/index.blade.php`) apurem o CMV real sem falsos alertas de *"Custo parcial"*.
4. **Concorrência e Bloqueio Pessimista (`lockForUpdate`):**
   - Toda operação em `StockMovement::handle()` executa dentro de transação (`DB::transaction`) com bloqueio pessimista (`lockForUpdate()`) sobre o registro de `Product` e sobre os registros de `StockBatch` afetados, prevenindo condições de corrida em baixas simultâneas (como no início de demandas por múltiplos técnicos parceiros).
5. **Ponto de Reposição (Estoque Mínimo):**
   - Um produto é considerado com estoque baixo (`isLowStock()`) quando:
     $$\text{current\_stock} \le \text{min\_stock}$$
   - O dashboard e alertas de compras destacam imediatamente materiais que atingem essa condição.
6. **Precisão Fracionada:**
   - As quantidades utilizam 2 casas decimais (`DECIMAL(10,2)`), permitindo baixar por exemplo `0.25` gramas de maquiagem cerâmica ou `1.50` litros de líquido diluente.
7. **Histórico Imutável de Movimentações e Soft Delete de Produtos/Categorias ([spec](../specs/active/2026-10-deletion-integrity-and-authorization.md)):**
   - Movimentações de estoque não são editadas nem excluídas fisicamente. Correções de contagem são realizadas através de novos registros de ajuste (`StockMovement` com tipo `Adjustment` e motivos `AdjustmentIn` ou `AdjustmentOut`).
   - A exclusão de produtos (`Product`) e categorias (`ProductCategory`) exige autorização via Policy (`Owner`, `Lab`, `LabManager`) e utiliza exclusivamente **Soft Delete (`SoftDeletes`)**, sendo proibido o hard delete (`forceDelete` bloqueado e `restrictOnDelete` em `stock_batches.product_id`, `stock_movements.product_id` e `request_service_consumptions.product_id`).
   - As relações `StockBatch::product()`, `StockMovement::product()`, `RequestServiceConsumption::product()` e `Product::category()` utilizam `->withTrashed()` para preservar a rastreabilidade sanitária e o histórico de CMV mesmo para produtos ou categorias excluídos via Soft Delete.
   - A unicidade de nome de produtos e categorias é validada no formulário apenas contra registros ativos (`Rule::unique(...)->whereNull('deleted_at')`), permitindo criar um novo produto ou categoria com o mesmo nome de um registro anteriormente excluído.
