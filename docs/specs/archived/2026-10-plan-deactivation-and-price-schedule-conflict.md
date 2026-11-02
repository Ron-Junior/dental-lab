# Especificação: Resolução de Conflitos entre Desativação de Plano e Reajuste de Preço Agendado

- **Identificador:** `2026-10-plan-deactivation-and-price-schedule-conflict`
- **Status:** Concluído / Implementado
- **Data:** Outubro de 2026
- **Data de Conclusão:** 2026-10-31
- **Módulos Afetados:** Ações de Planos (`DeactivatePlan`, `ReactivatePlan`, `UpdatePlan`, `ApplyScheduledPlanPrices`), Notificações (`PlanDeactivatedMigrationNotification`), Painel Central (`resources/views/livewire/pages/central/plans/index.blade.php`), Modelos Centrais (`Plan`, `Subscription`).

---

## 1. Contexto & Motivação

Foram identificadas falhas de integridade nas transições entre **desativação/reativação de planos** e **reajustes de preço agendados** (`DeactivatePlan::handle`, `ReactivatePlan::handle` e `UpdatePlan::handle`):

1. **Carência fixa em 2 meses e ausência de validação de `billing_cycle` na desativação (`DeactivatePlan::handle`):**
   - `DeactivatePlan` calcula a data de migração usando `$periodEnd->copy()->addMonthsNoOverflow(2)` de forma fixa, ignorando se o plano sendo desativado é anual (`billing_cycle = 'annual'`), em vez de utilizar `$plan->addBillingCycles($periodEnd, 2)`.
   - `DeactivatePlan` (assim como o seletor `availableTargetPlans` em `/planos`) **não valida se o plano de destino possui o mesmo `billing_cycle`** do plano desativado. Trocar um `Price` mensal por anual (ou vice-versa) no Stripe via `StripeService::swapSubscriptionPrice` com `proration_behavior = 'none'` causa erro na API da Stripe ou cobrança imediata inesperada na mudança de intervalo de recorrência.
2. **Sobrescrita de migração agendada ao editar preço do plano antigo (`UpdatePlan::handle`):**
   - Se um plano foi desativado e suas assinaturas possuem uma **migração de desativação agendada** (`scheduled_plan_id` preenchido apontando para o plano de destino, com `scheduled_price_in_cents` e `scheduled_stripe_price_id` do plano de destino) e um sócio edita o preço do plano antigo em `UpdatePlan`:
   - `UpdatePlan` sobrescreve `scheduled_price_in_cents` e `scheduled_stripe_price_id` com o novo preço do plano antigo, **mas mantém `scheduled_plan_id` apontando para o plano de destino**.
   - Quando `ApplyScheduledPlanPrices` executa, a assinatura do cliente é migrada para o `scheduled_plan_id` (plano de destino), porém pagando o `price_in_cents` e o `stripe_price_id` do plano antigo.
3. **Desatualização de migrações agendadas quando o plano de destino sofre reajuste (`UpdatePlan::handle`):**
   - Se um plano ativo que é **destino** de uma migração pendente (`scheduled_plan_id = $plan->id`) tiver seu preço atualizado em `UpdatePlan`, as assinaturas agendadas para migrar para ele permanecem com o `scheduled_price_in_cents` e `scheduled_stripe_price_id` antigos daquele plano de destino.
4. **Perda de reajuste pendente ao reativar um plano (`ReactivatePlan::handle`):**
   - Se um plano possuía reajuste de preço agendado (ou teve seu preço alterado enquanto inativo, de modo que `$subscription->price_in_cents !== $plan->price_in_cents`), foi desativado e posteriormente reativado em `ReactivatePlan`, a limpeza incondicional de `scheduled_price_in_cents`, `scheduled_stripe_price_id` e `price_change_effective_at` descarta o reajuste pendente, mantendo o assinante indefinidamente no preço antigo.

---

## 2. Objetivos

- Exigir que o plano de destino na desativação (`DeactivatePlan`) tenha estritamente o **mesmo `billing_cycle`** (`monthly` ou `annual`) do plano sendo desativado, tanto na Action quanto na listagem da modal no painel central.
- Calcular a carência de migração na desativação respeitando o ciclo do plano via `$plan->addBillingCycles($periodEnd, 2)`.
- Impedir que `UpdatePlan` corrompa assinaturas com migração de plano agendada (`scheduled_plan_id !== null`) e garantir que reajustes no plano de destino atualizem os agendamentos que apontam para ele.
- Garantir que `ReactivatePlan` restaure o agendamento de reajuste de preço caso a assinatura possua preço contratado divergente do preço vigente do plano reativado.
- Adicionar trava defensiva em `ApplyScheduledPlanPrices` para garantir consistência entre `scheduled_plan_id` e o preço/Price efetivamente aplicado.

---

## 3. Regras de Negócio

### 3.1. RN-01: Compatibilidade de Ciclo e Carência na Desativação (`DeactivatePlan::handle`)

1. **Validação de `billing_cycle` do Plano de Destino:**
   - Quando o plano sendo desativado possuir assinaturas vigentes (`Plan::BILLABLE_STATUSES`), além de validar que `$targetPlan !== null`, `$targetPlan->id !== $plan->id` e `$targetPlan->is_active === true`, `DeactivatePlan::handle` deve exigir que:
     ```php
     $targetPlan->billing_cycle === $plan->billing_cycle
     ```
   - Caso os ciclos de cobrança sejam diferentes (`monthly` vs. `annual`), lançar `ValidationException::withMessages(['targetPlanId' => 'O plano de destino deve ter o mesmo ciclo de cobrança do plano que está sendo desativado.'])`.
2. **Cálculo da Data de Migração (`price_change_effective_at`):**
   - A data de efetivação da migração deve respeitar 2 ciclos do plano atual utilizando `$plan->addBillingCycles($periodEnd, 2)`:
     - Plano mensal (`monthly`): `$periodEnd->copy()->addMonthsNoOverflow(2)`.
     - Plano anual (`annual`): `$periodEnd->copy()->addYearsNoOverflow(2)`.
   - Onde `$periodEnd = $subscription->current_period_end !== null ? Carbon::instance($subscription->current_period_end) : $now`.

### 3.2. RN-02: Isolamento e Sincronização de Agendamentos em `UpdatePlan::handle`

1. **Proteção de Assinaturas em Processo de Migração (`scheduled_plan_id !== null`):**
   - Quando o preço de `$plan` é alterado em `UpdatePlan::handle`, a busca de assinaturas do próprio plano que receberão o agendamento de reajuste deve filtrar **apenas assinaturas que não estão agendadas para migrar para outro plano**:
     ```php
     Subscription::query()
         ->with('tenant')
         ->where('plan_id', $plan->id)
         ->whereNull('scheduled_plan_id')
         ->whereIn('status', Plan::BILLABLE_STATUSES)
         ->get();
     ```
   - Assinaturas com `scheduled_plan_id !== null` pertencentes ao plano antigo **não** devem ter `scheduled_price_in_cents` ou `scheduled_stripe_price_id` sobrescritos com o preço do plano antigo, nem devem receber `PlanPriceChangedNotification` referente ao plano descontinuado.
   - O método `buildPriceChangeSummary` em `pages/central/plans/index.blade.php` também deve considerar `whereNull('scheduled_plan_id')` para exibir a contagem exata de assinantes que sofrerão reajuste no plano.
2. **Atualização de Assinaturas que Migrarão para o Plano Reajustado (`scheduled_plan_id === $plan->id`):**
   - Caso `$plan` seja o **plano de destino** de migrações pendentes (`Subscription::where('scheduled_plan_id', $plan->id)->whereIn('status', Plan::BILLABLE_STATUSES)`), ao alterar o preço de `$plan`:
     - Atualizar `scheduled_price_in_cents = $newPriceInCents` e `scheduled_stripe_price_id = $newStripePriceId` nessas assinaturas, mantendo intactos `scheduled_plan_id = $plan->id` e `price_change_effective_at`.

### 3.3. RN-03: Restauração de Reajuste ao Reativar Plano (`ReactivatePlan::handle`)

1. Ao reativar um plano (`$plan->update(['is_active' => true])`), `ReactivatePlan::handle` deve inspecionar cada assinatura vinculada ao plano que possuía migração agendada (`where('plan_id', $plan->id)->whereNotNull('scheduled_plan_id')`):
   - **Caso A — Preço contratado igual ao preço vigente do plano (`(int) ($subscription->price_in_cents ?? $plan->price_in_cents) === (int) $plan->price_in_cents`):**
     - Limpar todos os campos de agendamento:
       - `scheduled_plan_id = null`
       - `scheduled_price_in_cents = null`
       - `scheduled_stripe_price_id = null`
       - `price_change_effective_at = null`
   - **Caso B — Preço contratado diferente do preço vigente do plano (`(int) ($subscription->price_in_cents ?? $plan->price_in_cents) !== (int) $plan->price_in_cents`):**
     - O plano sofreu reajuste antes ou durante o período em que esteve desativado. Ao cancelar a migração para o outro plano (`scheduled_plan_id = null`), a assinatura deve manter/restaurar o agendamento de reajuste para o preço vigente de `$plan`:
       - `scheduled_plan_id = null`
       - `scheduled_price_in_cents = (int) $plan->price_in_cents`
       - `scheduled_stripe_price_id = $plan->stripe_price_id ?? $this->stripeService->getOrCreatePrice($plan)`
       - `price_change_effective_at = $subscription->price_change_effective_at ?? $plan->addBillingCycles($periodEnd, 2)`

### 3.4. RN-04: Consistência Defensiva em `ApplyScheduledPlanPrices::handle`

1. Ao efetivar o agendamento de uma assinatura em `ApplyScheduledPlanPrices::handle`:
   - Quando `$subscription->scheduled_plan_id !== null`, carregar o plano de destino (`$targetPlan = $subscription->scheduledPlan`).
   - Se `$targetPlan !== null`, validar que o ciclo de cobrança é compatível e garantir que, caso `scheduled_price_in_cents` ou `scheduled_stripe_price_id` estejam ausentes ou apontem acidentalmente para o `stripe_price_id` do plano antigo (`$subscription->plan?->stripe_price_id`), sejam utilizados `(int) $targetPlan->price_in_cents` e `$targetPlan->stripe_price_id` (ou `StripeService::getOrCreatePrice($targetPlan)`).

---

## 4. Requisitos de Interface (UI / UX) e Notificações

1. **Listagem de Planos de Destino Compatíveis (`resources/views/livewire/pages/central/plans/index.blade.php`):**
   - Na propriedade computada `availableTargetPlans`, filtrar apenas planos ativos (`$plan->is_active`), diferentes do plano em desativação (`$plan->id !== $this->deactivatingPlanId`) **e que possuam o mesmo `billing_cycle`** do plano que está sendo desativado.
   - Se não houver nenhum plano ativo compatível com o mesmo ciclo de cobrança, exibir aviso na modal `deactivate-plan` informando que é necessário possuir outro plano ativo com o mesmo ciclo (mensal ou anual) para migrar os assinantes atuais, desabilitando o botão de submissão.
2. **Textos de Carência Conforme o Ciclo (`index.blade.php` e `PlanDeactivatedMigrationNotification`):**
   - Na modal `deactivate-plan`, no toast de confirmação e no e-mail `PlanDeactivatedMigrationNotification`, ajustar a descrição do prazo de carência conforme o ciclo do plano (`2 meses` para plano mensal / `2 ciclos anuais` para plano anual, sempre destacando a data exata `effectiveAt->format('d/m/Y')`).

---

## 5. Critérios de Aceite (Testes Automatizados)

1. **Bloqueio de Migração entre Ciclos Diferentes (`DeactivatePlan`):**
   - Tentar desativar um plano `monthly` com assinantes indicando um plano de destino `annual` (ou vice-versa) lança `ValidationException` em `targetPlanId` e não desativa o plano nem altera as assinaturas.
2. **Carência de 2 Ciclos no Plano Anual (`DeactivatePlan`):**
   - Ao desativar um plano `annual` com assinante cujo `current_period_end` é `2027-05-10`, indicando outro plano `annual` ativo como destino, `price_change_effective_at` é agendado para `2029-05-10` (`addYearsNoOverflow(2)`), e não `2027-07-10`.
3. **Proteção de Migração Agendada contra Edição de Preço do Plano Antigo (`UpdatePlan`):**
   - Plano A (desativado) tem assinatura agendada para migrar para o Plano B (`scheduled_plan_id = B`, `scheduled_price_in_cents = 30000`, `scheduled_stripe_price_id = price_B`).
   - Sócio edita o preço do Plano A para `25000` em `UpdatePlan`:
     - A assinatura em migração mantém `scheduled_plan_id = B`, `scheduled_price_in_cents = 30000` e `scheduled_stripe_price_id = price_B`.
     - Quando `ApplyScheduledPlanPrices` executa, a assinatura migra para o Plano B pagando `30000` (`price_B`).
4. **Atualização Automática quando o Plano de Destino Sofre Reajuste (`UpdatePlan`):**
   - Assinatura do Plano A (desativado) está agendada para migrar para o Plano B (`30000`).
   - Antes da data de migração, o sócio reajusta o preço do Plano B para `35000` (`price_B_new`):
     - A assinatura agendada para migrar para o Plano B tem `scheduled_price_in_cents` atualizado para `35000` e `scheduled_stripe_price_id` atualizado para `price_B_new`, mantendo `scheduled_plan_id = B` e a data `price_change_effective_at`.
5. **Restauração de Reajuste ao Reativar Plano (`ReactivatePlan`):**
   - Plano A (`R$ 200,00`) sofre reajuste para `R$ 250,00` (assinante fica com `price_in_cents = 20000` e `scheduled_price_in_cents = 25000`).
   - Em seguida, Plano A é desativado com migração para Plano B (`R$ 300,00`).
   - Antes da virada, Plano A é reativado via `ReactivatePlan`:
     - `scheduled_plan_id` volta a ser `null`, mas `scheduled_price_in_cents` é restaurado para `25000` (e `scheduled_stripe_price_id` para o Price vigente do Plano A), preservando a data de efetivação `price_change_effective_at`.
6. **Filtro de Planos de Destino na UI (`/planos`):**
   - Ao abrir a modal de desativação para um plano `monthly` com assinantes, `availableTargetPlans` lista apenas planos ativos com `billing_cycle = 'monthly'`.

---

## 6. Documentação Relacionada

- [Domínio: Cobrança & Assinaturas (Billing)](../../domain/billing.md)
- [Especificação Arquivada: Gestão de Planos e Calculadora de Rentabilidade](../archived/2026-10-plan-management-and-profitability.md)
- [Especificação: Segurança e Ciclo de Vida no Webhook de Pagamento](2026-10-webhook-security-and-lifecycle.md)

