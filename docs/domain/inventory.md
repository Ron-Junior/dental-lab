# Domínio: Estoque & Insumos (Inventory)

O módulo de estoque gerencia os materiais odontológicos e insumos utilizados na fabricação das próteses (gessos, resinas, blocos de zircônia, discos de fresagem, ligas metálicas, dentes pré-fabricados, etc.).

---

## 1. Entidades Principais

- **`Product`:** O material/insumo cadastrado no laboratório.
  - Possui `barcode`, `unit_of_measure`, estoque atual (`current_stock`) e estoque mínimo (`min_stock`).
  - Flag `has_batches`: indica se o produto exige controle por lote e validade sanitária (Anvisa).
  - Flag `is_active`: permite desativar produtos sem quebrar histórico.
- **`ProductCategory`:** Categoria organizacional do produto (ex: Cerâmicas, Resinas, Metais, Descartáveis).
- **`StockBatch`:** O lote físico de um determinado produto.
  - Registra `batch_number`, `expiration_date`, `initial_quantity`, `current_quantity` e `cost_price` (em centavos).
- **`StockMovement`:** Registro de qualquer alteração de quantidade no estoque (entrada ou saída).
  - Guarda `type` (Entrada/Saída), `reason` (Compra, Consumo em OS, Ajuste de Inventário, Perda/Descarte, Devolução).
  - Pode vincular-se a um `StockBatch`, a uma ordem (`dentist_request_id`) e ao usuário responsável (`created_by_user_id`).
- **`RequestServiceConsumption`:** Vínculo de consumo direto entre um serviço protético executado e os materiais baixados.

---

## 2. Tipos de Movimentação e Motivos

### Tipos (`StockMovementsTypes`)
- **`In` (Entrada):** Incrementa o saldo do produto/lote.
- **`Out` (Saída):** Decrementa o saldo do produto/lote.

### Motivos (`StockMovementsReasons`)
- **Entradas:**
  - `Purchase` (Compra de fornecedor)
  - `InventoryAdjustmentPositive` (Ajuste de inventário / contagem física para maior)
  - `Return` (Devolução)
- **Saídas:**
  - `DentistRequestConsumption` (Consumo na confecção de ordem de serviço)
  - `InventoryAdjustmentNegative` (Ajuste de inventário para menor)
  - `Expired` (Descarte por vencimento)
  - `Damaged` (Avaria / perda de material)

---

## 3. Regras de Negócio Críticas

1. **Rastreabilidade por Lote (Vigilância Sanitária / Anvisa):**
   - Para produtos com `has_batches = true`, toda entrada e saída deve obrigatoriamente associar um `StockBatch`.
   - Lotes vencidos (`expiration_date < now()`) não podem ser consumidos em novas ordens de serviço.
2. **Estratégia PEPS / FIFO (Primeiro que Expira, Primeiro que Sai):**
   - Na saída de produtos com múltiplos lotes válidos, a prioridade de consumo recomendada no sistema é o lote com data de validade mais próxima (`expiration_date` ascendente).
3. **Ponto de Reposição (Estoque Mínimo):**
   - Um produto é considerado com estoque baixo (`isLowStock()`) quando:
     $$\text{current\_stock} \le \text{min\_stock}$$
   - O dashboard e alertas de compras destacam imediatamente materiais que atingem essa condição.
4. **Precisão Fracionada:**
   - As quantidades utilizam 2 casas decimais (`DECIMAL(10,2)`), permitindo baixar por exemplo `0.25` gramas de maquiagem cerâmica ou `1.50` litros de líquido diluente.
5. **Histórico Imutável de Movimentações:**
   - Movimentações de estoque não são editadas nem excluídas fisicamente. Correções de contagem são realizadas através de novos registros de ajuste (`StockMovement` de inventário).
