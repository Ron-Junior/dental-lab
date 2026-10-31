# Especificação: Correção de Distorção de CMV, Custo Médio Ponderado de Lote e Integridade de Movimentações de Estoque

- **Identificador:** `2026-10-cmv-batch-cost-and-stock-consistency`
- **Status:** Concluído / Implementado
- **Data:** Outubro de 2026
- **Data de Conclusão:** 2026-10-31
- **Módulos Afetados:** Estoque & Insumos (`app/Actions/StockMovement.php`, `app/Livewire/Forms/StockMovementForm.php`, `app/Enums/StockMovementsTypes.php`, `app/Enums/StockMovementsReasons.php`, `app/Models/Product.php`, `app/Models/StockBatch.php`, `app/Models/StockMovement.php`, `resources/views/components/products/stock/store.blade.php`, `resources/views/components/products/stock/out.blade.php`, `resources/views/components/stock/get.blade.php`), Demandas de Parceiros (`resources/views/components/partners/start-demand.blade.php`), Relatórios Gerenciais (`resources/views/livewire/pages/reports/index.blade.php`), Migrations Tenant (`database/migrations/tenant/`).

---

## 1. Contexto & Motivação

Durante a revisão contábil e operacional do módulo de estoque e relatórios gerenciais (`reports/index.blade.php`), foram diagnosticadas 4 anomalias críticas que distorcem o Custo de Mercadoria Vendida (CMV), corrompem o alinhamento de saldo entre produtos e lotes e travam fluxos operacionais básicos de baixa de materiais:

1. **Tipo de Movimentação `Adjustment` (Ajuste) corrompe o saldo entre Lote e Produto:**
   - Em `app/Actions/StockMovement.php`, as variáveis `$isIncoming` e `$isOutgoing` determinam a direção do movimento checando `$type === StockMovementsTypes::In || in_array($reason, StockMovementsReasons::in(), true)` e `$type === StockMovementsTypes::Out || in_array($reason, StockMovementsReasons::out(), true)`.
   - Quando um movimento do tipo `StockMovementsTypes::Adjustment` é processado com motivos neutros ou de ajuste (ex.: `LaunchError`, `Other`, ou caso o motivo não esteja explicitamente mapeado em `in()`/`out()`), nem `$isIncoming` nem `$isOutgoing` resultam em `true`.
   - Para produtos com lote (`has_batches = true`), nenhum registro `StockMovement` é criado e nenhum `StockBatch` é atualizado, fazendo `StockMovement::handle()` retornar um array vazio `[]` — o que dispara um `TypeError` fatal no retorno tipado `: ModelsStockMovement` de `StockMovementForm::save()`.
   - Além disso, no formulário de movimentação (`components/products/stock/store.blade.php` e `StockMovementForm.php`), a validação de lote e a exibição dos campos de lote/custo tratam `Adjustment` de forma inconsistente: na UI, o seletor de lote existente aparece para `Adjustment` mesmo em sobra de inventário (`AdjustmentIn`), enquanto `StockMovementForm::rules()` só exige `batchNumber`/`expirationDate` quando `$type === In` e `stockBatchId` quando `$type === Out` (deixando `Adjustment` sem validação obrigatória de lote!).
2. **Produtos sem controle de lote (`has_batches = false`) têm CMV sempre ZERO nos relatórios:**
   - A tabela `products` não armazena o custo unitário médio/atual (`unit_cost` / `cost_price`) para materiais sem controle de lote.
   - Quando ocorre uma saída para consumo em ordem de serviço (`ServiceConsumption`), `unitCost` não é informado na saída e não existe `$batch->cost_price` para herdar.
   - Consequentemente, `stock_movements.unit_cost` é gravado como `NULL`. No relatório de lucratividade (`reports/index.blade.php`), o movimento é contabilizado em `unpriced_material_movements_count`, ignorando o custo do insumo na soma de `cmv_cents` e marcando permanentemente os pedidos com o alerta **"Custo parcial"** (bloqueando a exibição do Lucro Bruto e Margem).
3. **Sobrescrita de Custo de Lote sem Média Ponderada:**
   - Em `app/Actions/StockMovement.php`, ao registrar uma nova entrada (`$isIncoming`) em um `StockBatch` já existente com um novo custo unitário, o código executa `$stockBatch->cost_price = $unitCost`, sobrescrevendo integralmente o custo anterior do lote em vez de calcular o **Custo Médio Ponderado (CMP)** entre o saldo remanescente e a nova quantidade.
   - Adicionalmente, a coluna `initial_quantity` do lote não é incrementada nas entradas adicionais do mesmo lote, distorcendo o histórico de volume total recepcionado naquele lote.
4. **Trava, seleção invertida de lote (anti-FEFO) e falta de concorrência na retirada rápida (`components/products/stock/out.blade.php`):**
   - **Impossibilidade de consumir o saldo total (`lt` vs `lte`):** Na linha 36 de `out.blade.php`, a regra `'selectedProducts.*.quantity' => 'required|lt:selectedProducts.*.current_stock'` usa `lt` (estritamente menor) em vez de `lte` (menor ou igual), impedindo que o operador retire a última unidade disponível no estoque (ex.: se `current_stock` for `5`, só permite retirar até `4`).
   - **Bloqueio de produtos sem lote:** Na linha 38, `'selectedProducts.*.batch_number' => 'required'` exige número de lote incondicionalmente, tornando impossível dar baixa rápida em produtos com `has_batches = false`.
   - **Seleção invertida de lote e omissão no salvamento:** Na linha 117, a pré-seleção automática usa `$product->batches->sortByDesc('expiration_date')->first()`, selecionando o lote que vence **mais tarde** (contrariando a regra sanitária FEFO/PEPS) e sem filtrar lotes esgotados (`current_quantity <= 0`) ou vencidos (`expiration_date < today()`). Pior: no método `save()` (linhas 135-141), `stockBatchId` e `batchNumber` sequer são enviados para `StockMovement::handle()`.
   - **Falta de Lock Pessimista (`lockForUpdate`):** No fluxo de início de demanda (`start-demand.blade.php` -> `stock::out` -> `out.blade.php` -> `StockMovement::handle`), a leitura e dedução de `Product` e `StockBatch` dentro da transação não utilizam `lockForUpdate()`, permitindo condições de corrida (race conditions) e saldo negativo ou divergente em retiradas simultâneas.

---

## 2. Objetivos

- Garantir que toda movimentação de estoque (seja `In`, `Out` ou `Adjustment`) tenha direção contábil e física estritamente definida (`$isIncoming` ou `$isOutgoing`), mantendo sincronia absoluta entre `products.current_stock` e `SUM(stock_batches.current_quantity)`.
- Persistir e atualizar o custo médio/atual em produtos sem controle de lote (`products.unit_cost`) e propagá-lo automaticamente nas saídas de consumo (`ServiceConsumption`), eliminando falsos positivos de *"Custo parcial"* nos relatórios de lucratividade.
- Implementar o cálculo de **Custo Médio Ponderado (CMP)** nas entradas adicionais em lotes existentes e para produtos sem lote, além de atualizar adequadamente `initial_quantity` nos lotes.
- Corrigir o componente de retirada rápida (`components/products/stock/out.blade.php`) para permitir baixa total (`lte`), suportar produtos com e sem lote, aplicar estritamente a regra FEFO (lotes válidos com vencimento mais próximo e saldo positivo) e enviar o lote selecionado ao `StockMovement::handle()`.
- Proteger todas as operações de estoque em `StockMovement::handle()` com bloqueio pessimista (`lockForUpdate()`) no produto e nos lotes envolvidos dentro da transação de banco de dados.

---

## 3. Regras de Negócio & Arquitetura de Solução

### 3.1. Direção de Movimentação e Consistência do Tipo `Adjustment`

1. **Determinação Estrita de Direção (`$isIncoming` vs `$isOutgoing`):**
   - Todo motivo em `StockMovementsReasons` deve pertencer inequivocamente ao grupo de entradas (`StockMovementsReasons::in()`) ou ao grupo de saídas (`StockMovementsReasons::out()`).
   - Caso novos motivos de ajuste sejam suportados (ex.: `AdjustmentIn`, `AdjustmentOut`, `LaunchError`, `LossDamage`, `Expiration`, `Other`), a direção de uma movimentação de tipo `StockMovementsTypes::Adjustment` será determinada pelo seu `reason` (ou por flag explícita caso o motivo seja neutro, sendo que todo motivo de `Adjustment` deve mapear para entrada ou saída):
     - **Entrada (`$isIncoming = true`):** `$type === StockMovementsTypes::In` OU `($type === StockMovementsTypes::Adjustment && in_array($reason, StockMovementsReasons::in(), true))`.
     - **Saída (`$isOutgoing = true`):** `$type === StockMovementsTypes::Out` OU `($type === StockMovementsTypes::Adjustment && in_array($reason, StockMovementsReasons::out(), true))`.
   - **Guarda de Segurança:** Se após avaliar `$type` e `$reason`, nem `$isIncoming` nem `$isOutgoing` forem `true` (ou ambos forem `true`), `StockMovement::handle()` deve lançar imediatamente uma `ValidationException` no campo `form.reason` informando que o motivo selecionado é incompatível com o tipo de movimentação. Nunca deve prosseguir silenciosamente retornando `[]`.
2. **Validação e UI no Formulário (`StockMovementForm` & `store.blade.php`):**
   - `StockMovementForm` deve expor helpers claros `isIncoming(): bool` e `isOutgoing(): bool` baseados na combinação de `movementType` e `reason`.
   - Quando o movimento for uma **Entrada** (`In` ou `Adjustment` com motivo de entrada como `AdjustmentIn`):
     - Se `hasBatches === true`, pode selecionar um lote existente para incrementar ou informar `batchNumber` e `expirationDate` obrigatórios.
     - O campo `cost` (Custo Total) é exibido e opcional/habilitado.
   - Quando o movimento for uma **Saída** (`Out` ou `Adjustment` com motivo de saída como `AdjustmentOut`, `WasteDamage`, `Expiration`):
     - Se `hasBatches === true`, `stockBatchId` é obrigatório (`required|exists:stock_batches,id`).
     - O campo de Custo Total **não** deve ser exigido na UI para saídas/baixas, pois o custo unitário da baixa é herdado automaticamente do lote (`$batch->cost_price`) ou do produto (`$product->unit_cost`).
3. **Tipagem de Retorno em `StockMovementForm::save()`:**
   - Como uma saída em múltiplos lotes pode retornar `array<int, ModelsStockMovement>` ou `ModelsStockMovement` em `StockMovement::handle()`, a assinatura de `StockMovementForm::save()` deve ser `public function save(): array|ModelsStockMovement` para evitar `TypeError` quando um consumo ou baixa abranger mais de um lote via FEFO.

### 3.2. Custo Unitário para Produtos sem Lote (`has_batches = false`) e Herança no CMV

1. **Nova Coluna `unit_cost` na Tabela `products` (Migration Tenant):**
   - Adicionar coluna `unit_cost` (`integer`, nullable, armazenada em centavos seguindo a convenção global de `data-conventions.md`) na tabela `products`:
     ```php
     Schema::table('products', function (Blueprint $table) {
         $table->integer('unit_cost')->nullable()->after('min_stock');
     });
     ```
   - No model `Product`, adicionar `unit_cost` em `#[Fillable(...)]` e configurar o mutator/accessor `unitCost(): Attribute` convertendo entre `float` (reais) e `int` (centavos), idêntico a `StockBatch::costPrice()` e `StockMovement::unitCost()`.
2. **Atualização do Custo do Produto nas Entradas (`$isIncoming`):**
   - Sempre que ocorrer uma entrada (`In` ou `AdjustmentIn`) com custo informado (`$unitCost !== null && $unitCost > 0`):
     - Calcular o **Custo Médio Ponderado (CMP)** do produto considerando o estoque atual antes da entrada (`$currentStock = max(0, (float) $product->current_stock)`) e o custo atual (`$currentUnitCost = $product->unit_cost`):
       $$\text{novo\_custo} = \begin{cases} \dfrac{(\text{estoque\_atual} \times \text{custo\_atual}) + (\text{qtd\_entrada} \times \text{custo\_entrada})}{\text{estoque\_atual} + \text{qtd\_entrada}} & \text{se } \text{estoque\_atual} > 0 \text{ e } \text{custo\_atual} \neq \text{null} \\ \text{custo\_entrada} & \text{caso contrário} \end{cases}$$
     - Persistir o novo custo médio em `$product->unit_cost`.
   - Caso seja uma entrada de ajuste (`AdjustmentIn`) sem custo informado (`$unitCost === null`), a movimentação herda o `$product->unit_cost` vigente (ou do lote correspondente) para não gerar entradas sem valor quando o produto já possui custo conhecido.
3. **Herança de Custo nas Saídas sem Lote (`$isOutgoing`):**
   - Ao registrar uma saída (`Out` ou `AdjustmentOut`) de um produto sem lote (`has_batches = false`), o `unit_cost` gravado em `stock_movements` deve ser:
     ```php
     'unit_cost' => $unitCost ?? $product->unit_cost,
     ```
   - Assim, toda saída de consumo em OS (`ServiceConsumption`) de produto sem lote terá seu `unit_cost` preenchido automaticamente com o custo médio vigente do produto, permitindo que `reports/index.blade.php` calcule `cmv_cents` com exatidão e classifique o pedido como **"Completo"**.

### 3.3. Custo Médio Ponderado (CMP) e `initial_quantity` em Lotes (`StockBatch`)

1. **Entradas Adicionais em Lote Existente:**
   - Quando `$isIncoming` encontrar um `StockBatch` já existente (`$stockBatch->exists === true`) para o par `(product_id, batch_number)` (ou `stockBatchId`):
     - **Cálculo de Custo Médio Ponderado do Lote:**
       - Se `$unitCost !== null`:
         - Se `(float) $stockBatch->current_quantity > 0` e `$stockBatch->cost_price !== null`:
           $$\text{cost\_price} = \text{round}\left(\frac{(\text{current\_quantity} \times \text{cost\_price}) + (\text{totalQuantity} \times \text{unitCost})}{\text{current\_quantity} + \text{totalQuantity}}, 2\right)$$
         - Caso contrário (`current_quantity <= 0` ou `cost_price === null`), `$stockBatch->cost_price = $unitCost`.
       - Se `$unitCost === null` (ex.: ajuste positivo de inventário em lote existente), manter `$stockBatch->cost_price` atual e usar `$stockBatch->cost_price` como `unit_cost` do `StockMovement`.
     - **Atualização de Quantidades do Lote:**
       - Incrementar o volume inicial/total histórico do lote:
         `$stockBatch->initial_quantity = round((float) $stockBatch->initial_quantity + $totalQuantity, 2);`
       - Incrementar o saldo disponível atual do lote:
         `$stockBatch->current_quantity = round((float) $stockBatch->current_quantity + $totalQuantity, 2);`
2. **Herança de Custo nas Saídas com Lote:**
   - Na baixa de lote, se o lote específico tiver `cost_price === null` (legado), fazer fallback para `$product->unit_cost`:
     `'unit_cost' => $unitCost ?? $batch->cost_price ?? $product->unit_cost`.

### 3.4. Retirada Rápida de Estoque (`components/products/stock/out.blade.php`) e Concorrência

1. **Regras de Validação Corrigidas (`out.blade.php`):**
   - Permitir retirar até a totalidade do estoque disponível usando `lte` (menor ou igual) em vez de `lt`, e exigir quantidade maior que zero (`gt:0`):
     `'selectedProducts.*.quantity' => ['required', 'numeric', 'gt:0', 'lte:selectedProducts.*.current_stock']`
   - Tornar o lote obrigatório **apenas** quando o produto selecionado possuir controle de lotes (`has_batches = true`):
     `'selectedProducts.*.batch_number' => ['nullable', 'required_if:selectedProducts.*.has_batches,true']`
2. **Seleção Automática de Lote por FEFO (First-Expired, First-Out):**
   - Ao selecionar um produto com `has_batches = true` em `out.blade.php`:
     - Filtrar apenas lotes com saldo disponível (`current_quantity > 0`) e não vencidos (`expiration_date >= today()` ou nulo).
     - Ordenar de forma **ascendente** pela data de validade (`sortBy('expiration_date')->first()`), selecionando o lote que vence **mais cedo** (FEFO).
     - Caso o produto com controle de lotes não possua nenhum lote válido com saldo, adicionar erro de validação imediato indicando indisponibilidade de lote válido.
3. **Envio do Lote no `save()` de `out.blade.php`:**
   - Passar explicitamente `stockBatchId` e `batchNumber` para `StockMovement::handle()`:
     ```php
     StockMovement::handle($product['id'], [
         'dentist_request_id' => $this->dentistRequest?->id,
         'movementType' => StockMovementsTypes::Out,
         'reason' => StockMovementsReasons::ServiceConsumption,
         'amount' => $product['quantity'],
         'stockBatchId' => $product['stock_batch_id'] ?? null,
         'batchNumber' => $product['batch_number'] ?? null,
         'notes' => 'Retirada para uso no setor A',
     ]);
     ```
4. **Controle de Concorrência com `lockForUpdate()` em `StockMovement::handle()`:**
   - Dentro do `DB::transaction(...)` em `app/Actions/StockMovement.php`:
     - Recarregar e travar a linha do produto com bloqueio pessimista antes de checar o saldo:
       `$product = Product::whereKey($product->id)->lockForUpdate()->firstOrFail();`
     - Nas operações de lote (tanto entrada quanto saída FEFO), consultar os registros de `StockBatch` aplicando `->lockForUpdate()` antes de verificar `current_quantity` e deduzir/incrementar saldos.
     - Nas saídas de lotes para consumo (`ServiceConsumption`), priorizar lotes não vencidos (`whereDate('expiration_date', '>=', today())`) ordenados por `expiration_date ASC`.

---

## 4. Requisitos de Interface (UI / UX)

- **Modal de Movimentação (`components/products/stock/store.blade.php`):**
  - Ao selecionar `Tipo de Movimentação = Ajuste`, exibir os campos de lote de acordo com o motivo selecionado:
    - Para **Ajuste de Inventário (Sobra)** (`AdjustmentIn`): permitir selecionar um lote existente ou informar o lote para entrada, e exibir opcionalmente o Custo Total.
    - Para **Ajuste de Inventário (Falta)** (`AdjustmentOut`): exigir seleção de um lote existente com saldo disponível e ocultar o campo de Custo Total (pois usa o custo do lote/produto).
- **Modal de Retirada Rápida (`components/products/stock/out.blade.php`):**
  - Permitir que o usuário retire exatamente 100% do saldo restante (ex.: retirar 5 de 5 unidades).
  - Não exigir nem exibir alerta de lote para produtos configurados com `has_batches = false`.
  - Exibir o lote sugerido pelo critério FEFO (vencimento mais próximo) para produtos com `has_batches = true`.

---

## 5. Critérios de Aceite & Matriz de Testes

1. **Movimentações de Ajuste (`Adjustment`):**
   - [x] Realizar um ajuste de entrada (`AdjustmentIn`) e de saída (`AdjustmentOut`) em produto com lote atualiza simultaneamente `products.current_stock` e `stock_batches.current_quantity`, criando o `StockMovement` e retornando instância válida sem `TypeError`.
   - [x] Tentar executar `StockMovement::handle()` com combinação inválida de tipo e motivo que não seja nem entrada nem saída lança `ValidationException` em vez de corromper o saldo ou retornar array vazio.
2. **CMV de Produtos sem Lote (`has_batches = false`):**
   - [x] Dar entrada de compra em produto sem lote calcula e armazena `products.unit_cost` (em centavos no banco).
   - [x] Realizar saída de consumo (`ServiceConsumption`) vinculada a uma `DentistRequest` para produto sem lote preenche automaticamente `stock_movements.unit_cost` com o custo do produto.
   - [x] No relatório `reports/index.blade.php`, o pedido com consumo de produto sem lote exibe o CMV calculado, o Lucro Bruto, a Margem e o status **"Completo"** (sem alerta indevido de *"Custo parcial"*).
3. **Custo Médio Ponderado (CMP) e `initial_quantity` em Lotes:**
   - [x] Ao dar uma primeira entrada de `100` un. a `R$ 10,00` (total `R$ 1.000,00`) no lote `LOTE-01` e depois uma segunda entrada de `100` un. a `R$ 20,00` (total `R$ 2.000,00`) no mesmo `LOTE-01`, o lote passa a ter `initial_quantity = 200`, `current_quantity = 200` e `cost_price = 15.00` (R$ 15,00 de média ponderada, ou `1500` centavos).
   - [x] Caso parte do lote já tenha sido consumida antes da nova entrada (ex.: restavam `50` un. a `R$ 10,00` e entram `50` un. a `R$ 20,00`), o novo `cost_price` pondera pelo saldo remanescente (`current_quantity = 100`, `initial_quantity = 150`, `cost_price = 15.00`).
4. **Retirada Rápida de Estoque (`out.blade.php`) e Concorrência:**
   - [x] É possível retirar a última unidade disponível (`quantity == current_stock`) sem erro de validação.
   - [x] É possível retirar produtos com `has_batches = false` pelo modal de retirada rápida sem erro de `batch_number` obrigatório.
   - [x] Para produtos com `has_batches = true`, o componente seleciona automaticamente o lote não vencido de vencimento mais próximo (FEFO) com saldo positivo e envia `stockBatchId`/`batchNumber` para `StockMovement::handle()`.
   - [x] `StockMovement::handle()` executa `lockForUpdate()` no produto e nos lotes dentro da transação.

---

## 6. Documentação Relacionada

- [Domínio: Estoque & Insumos (Inventory)](../../domain/inventory.md)
- [Domínio: Pedidos & Ordens de Serviço (Orders)](../../domain/orders.md)
- [Convenções de Dados Globais](../../architecture/data-conventions.md)

