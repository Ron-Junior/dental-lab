# Especificação: Preservação e Recálculo de Desconto ao Trocar Plano no Checkout

- **Identificador:** `2026-10-checkout-discount-recalculation`
- **Status:** Concluído / Implementado
- **Data:** Outubro de 2026
- **Data de Conclusão:** 2026-10-31
- **Módulos Afetados:** Checkout Público (`resources/views/livewire/pages/central/checkout.blade.php`), Serviço de Descontos (`app/Services/ApplyDiscountService.php`), Modelos Centrais (`Payment`, `Discount`, `Plan`).

---

## 1. Contexto & Motivação

Quando um sócio aplica um desconto comercial a um laboratório recém-cadastrado pelo painel central (`/laboratorios`), `ApplyDiscountService::apply` localiza a fatura pendente (`PaymentStatus::Pending`), reduz `amount_in_cents`, grava `metadata['discount']` (`discount_id`, `original_amount_in_cents`, `discount_in_cents`) e marca o registro `Discount` como aplicado (`applied_payment_id` e `applied_at` preenchidos).

No entanto, quando o cliente abre a tela de checkout (`/checkout/{tenant}`) e clica em qualquer card de plano (`selectPlan`):

1. **Sobrescrita do valor líquido pelo preço cheio:** `checkout.blade.php` executa um `update(['amount_in_cents' => $plan->price_in_cents])` direto na fatura pendente, descartando o abatimento em `amount_in_cents`.
2. **Metadados obsoletos e divergentes:** O array `metadata['discount']` permanece intacto com o `original_amount_in_cents` e o `discount_in_cents` do plano anterior. Na UI de resumo do checkout, a linha *"Desconto aplicado pelo sócio"* continua aparecendo, mas *"Total a pagar agora"* exibe o valor cheio do plano (`$pendingPayment->amount_in_cents`).
3. **Desconto não aplicado perdido na troca:** Se o laboratório possuir um `Discount` válido (não expirado) ainda não vinculado à fatura (`unapplied`), `selectPlan` não o aplica nem atualiza `metadata['discount']`.
4. **Expiração entre a concessão e o checkout:** Se o desconto vinculado à fatura tiver expirado (`$discount->is_expired === true`) no momento da troca/confirmação no checkout, os metadados antigos continuam salvos na fatura local embora `StripeService::createSubscription` ignore descontos expirados — gerando divergência entre o valor cobrado no Stripe e o registrado no `Payment`.

---

## 2. Objetivos

- Centralizar em `ApplyDiscountService` o cálculo e o recálculo de desconto sobre uma fatura pendente diante de um novo valor base de plano.
- Garantir que, ao selecionar qualquer plano no checkout (`selectPlan`) ou ao confirmar o pagamento (`confirmPaymentMethod`), o desconto ativo do laboratório seja recalculado sobre o preço do plano selecionado (`$plan->price_in_cents`), mantendo `amount_in_cents` e `metadata['discount']` rigorosamente sincronizados com o valor cobrado no Stripe.
- Garantir que descontos posteriores editados (`ApplyDiscountService::update`) ou removidos (`ApplyDiscountService::remove`) pelo sócio reflitam sempre o plano atualmente selecionado na fatura pendente.

---

## 3. Regras de Negócio

### 3.1. RN-01: Recálculo de Desconto na Fatura Pendente (`ApplyDiscountService`)

1. **Método de Recálculo (`recalculateForPayment` / sincronização por valor base):**
   - `ApplyDiscountService` deve expor uma operação transacional (`recalculateForPayment(Payment $payment, int $baseAmountInCents, ?Discount $discount = null): Payment`) responsável por atualizar o valor de uma fatura `Pending` para um novo preço base (`$baseAmountInCents`, correspondente a `$plan->price_in_cents`):
   - **Resolução do desconto aplicável:**
     1. Se `$discount` não for informado explicitamente, verificar se `$payment->metadata['discount']['discount_id']` aponta para um `Discount` existente.
     2. Caso contrário, buscar o desconto ativo (`unapplied()->notExpired()->latest()`) do tenant.
   - **Caso 1 — Desconto válido e não expirado (`$discount !== null && ! $discount->is_expired`):**
     - `original_amount_in_cents = $baseAmountInCents`.
     - Se `$discount->type === DiscountType::Percentage`:
       - `discount_in_cents = (int) floor(($baseAmountInCents * (int) $discount->value_in_cents) / 100)`.
     - Se `$discount->type === DiscountType::FixedAmount`:
       - `discount_in_cents = (int) $discount->value_in_cents`.
     - `discount_in_cents = max(0, $discount_in_cents)`.
     - `amount_in_cents = max(0, $baseAmountInCents - $discount_in_cents)`.
     - Atualizar `metadata['discount']`:
       ```php
       [
           'discount_id' => $discount->id,
           'original_amount_in_cents' => $baseAmountInCents,
           'discount_in_cents' => $discountInCents,
       ]
       ```
     - Garantir que o `Discount` esteja vinculado à fatura (`applied_payment_id = $payment->id`, `applied_at = $discount->applied_at ?? now()`).
   - **Caso 2 — Desconto inexistente ou expirado (`$discount === null || $discount->is_expired`):**
     - Se havia `metadata['discount']` referente a um desconto que expirou ou foi removido, limpar a chave `discount` de `metadata` (definindo `metadata = null` se ficar vazio) e desvincular `applied_payment_id` / `applied_at` do desconto expirado para não manter estado inconsistente.
     - Definir `amount_in_cents = $baseAmountInCents`.

### 3.2. RN-02: Troca de Plano no Checkout (`checkout.blade.php::selectPlan`)

1. Ao executar `selectPlan(string $planId)` para um plano ativo:
   - Atualizar a `Subscription` do tenant com `plan_id`, `price_in_cents`, `stripe_price_id` e `current_period_end` calculado conforme o ciclo do plano.
   - Para a fatura pendente (`$this->pendingPayment`), delegar a atualização de valor ao `ApplyDiscountService::recalculateForPayment($pendingPayment, $plan->price_in_cents, $this->appliedDiscount)` em vez de sobrescrever `amount_in_cents` diretamente com `$plan->price_in_cents`.
   - Limpar o cache das propriedades computadas (`unset($this->pendingPayment, $this->appliedDiscount, $this->selectedPlan)`) para que o card **"Resumo da Assinatura"** reflita imediatamente:
     - O preço de tabela do novo plano (`$plan->price_in_cents`);
     - O valor atualizado do abatimento (`metadata['discount']['discount_in_cents']`);
     - O total líquido a pagar (`$pendingPayment->amount_in_cents`).

### 3.3. RN-03: Consistência na Confirmação do Pagamento (`checkout.blade.php::confirmPaymentMethod`)

1. Antes de criar a assinatura no Stripe e liquidar a fatura (`PaymentStatus::Paid`):
   - Caso exista fatura pendente, executar `recalculateForPayment` com o preço do `$plan` selecionado para garantir que `amount_in_cents` e `metadata['discount']` reflitam o estado mais recente do plano e do desconto (inclusive se um desconto tiver sido criado/editado pelo sócio enquanto o cliente estava com a tela aberta, ou se tiver expirado).
   - Se o laboratório não possuía fatura pendente prévia mas possuía um `Discount` ativo (`unapplied()->notExpired()`), o pagamento criado na confirmação deve registrar `amount_in_cents` já com o abatimento e `metadata['discount']` preenchido, além de marcar o `Discount` como aplicado.

---

## 4. Critérios de Aceite (Testes Automatizados)

1. **Desconto Percentual Preserved e Recalculado ao Trocar de Plano:**
   - Sócio aplica desconto de `25%` a um tenant no Plano A (`R$ 200,00` -> fatura pendente em `R$ 150,00`, desconto de `R$ 50,00`).
   - No checkout, o cliente clica em `selectPlan` para o Plano B (`R$ 400,00`) e depois volta ao Plano A (`R$ 200,00`):
     - Ao selecionar o Plano B: `amount_in_cents` passa a `30000` (`R$ 300,00`), `metadata.discount.original_amount_in_cents = 40000` e `metadata.discount.discount_in_cents = 10000`.
     - Ao voltar para o Plano A: `amount_in_cents` volta a `15000` (`R$ 150,00`), `metadata.discount.original_amount_in_cents = 20000` e `metadata.discount.discount_in_cents = 5000`.
2. **Desconto de Valor Fixo Preserved com Piso em Zero ao Trocar de Plano:**
   - Sócio aplica desconto fixo de `R$ 120,00` (`12000`) a um tenant no Plano B (`R$ 200,00` -> fatura em `R$ 80,00`).
   - Cliente seleciona um Plano Básico de `R$ 100,00` (`10000`):
     - `amount_in_cents` é recalculado para `0` (piso em zero), `metadata.discount.original_amount_in_cents = 10000` e `metadata.discount.discount_in_cents = 12000`.
   - Cliente seleciona um Plano Pro de `R$ 300,00` (`30000`):
     - `amount_in_cents` passa a `18000` (`R$ 180,00`), `metadata.discount.original_amount_in_cents = 30000` e `metadata.discount.discount_in_cents = 12000`.
3. **Remoção e Edição pelo Sócio Após Troca de Plano:**
   - Após o cliente trocar para o Plano B (`R$ 400,00`), se o sócio remover o desconto (`ApplyDiscountService::remove`), a fatura pendente é restaurada para `40000` (`R$ 400,00`, preço do Plano B, e não do Plano A original).
4. **Desconto Expirado Limpo na Troca/Confirmação:**
   - Se o desconto vinculado à fatura pendente expirar antes da troca de plano ou confirmação do checkout, `selectPlan` / `confirmPaymentMethod` limpa `metadata['discount']` e cobra o valor integral do plano selecionado.
5. **Confirmação de Pagamento Sincronizada com o Stripe:**
   - Ao concluir `confirmPaymentMethod` após trocar de plano com desconto ativo, o `Payment` liquidado (`Paid`) mantém `amount_in_cents` com o desconto aplicado sobre o novo plano e `metadata['discount']` consistente com o cupom enviado ao Stripe.

