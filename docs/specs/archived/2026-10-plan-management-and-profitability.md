# Especificação: Gestão de Planos e Calculadora de Rentabilidade

- **Identificador:** `2026-10-plan-management-and-profitability`
- **Status:** Concluído / Implementado
- **Data:** Outubro de 2026
- **Data de Conclusão:** 2026-10-30
- **Módulos Afetados:** Banco Central (`plans`, `subscriptions`, `plan_price_changes`, `operational_costs`), Painel Central (`pages.central.plans.*`), Cobrança (`StripeService`, `CreateLaboratory`, `PaymentWebhookController`), Limites de Plano (`TenantLimitChecker`), Agendamento (`routes/console.php`).

---

## 1. Contexto & Motivação

Hoje os planos (`Plan`) só existem via `PlanSeeder`. Os sócios não têm como:

1. **Criar ou ajustar planos** (preço, limites, disponibilidade) sem deploy.
2. **Reajustar preços com segurança** para quem já assina. O Stripe não permite editar um `Price`, e a assinatura local não guarda o valor contratado.
3. **Saber se os planos cobrem os custos operacionais** (infraestrutura, ferramentas, taxas do gateway, armazenamento).

---

## 2. Objetivos

- Tela central para **listar, criar, editar e ativar/desativar** planos. Não há exclusão, porque planos têm assinaturas vinculadas.
- **Reajuste de preço com carência de 2 ciclos** para os assinantes atuais. O preço novo vale de imediato para novas assinaturas.
- **Aviso de impacto** antes de reduzir limites, mostrando quantos laboratórios ficariam acima do novo limite.
- **Cadastro persistido de custos operacionais** (fixos, por laboratório, por GB e percentuais sobre a receita).
- **Calculadora de rentabilidade** com duas visões: **margem por plano** e **visão real** (receita atual × custos totais com armazenamento real).

---

## 3. Regras de Negócio

### 3.1. Cadastro e edição de planos

1. Campos: `name`, `price_in_cents`, `billing_cycle` (`monthly` | `annual`), `limits` (`users_limit`, `storage_gb_limit`, `monthly_exams_limit`; vazio = ilimitado) e `is_active`.
2. Criar um plano gera o Produto/Preço no Stripe (`StripeService::getOrCreatePrice`).
3. Mudança de **nome** e de **limites** vale de imediato. Os limites já são lidos ao vivo do plano pelo `TenantLimitChecker`. Mudança de nome atualiza o nome do produto no Stripe.
4. **Ciclo de cobrança travado:** não é permitido alterar `billing_cycle` de um plano com assinaturas `active`, `trial` ou `overdue`. A saída é criar um plano novo.
5. **Desativar** um plano o retira do checkout e do cadastro de laboratórios pelo sócio, que já filtram por `is_active`. Se o plano possuir assinaturas vigentes (`active`, `trial` ou `overdue`), o sócio escolhe um **plano ativo de destino**: as assinaturas recebem `scheduled_plan_id`, `scheduled_price_in_cents`, `scheduled_stripe_price_id` e `price_change_effective_at = current_period_end + 2 meses`, o responsável (`owner_email`) recebe um e-mail de aviso (`PlanDeactivatedMigrationNotification`) e o card de consumo do perfil exibe um banner informando a data e o plano de destino. Se o plano for reativado antes da data, a migração pendente é cancelada.
6. Não há exclusão de planos.

### 3.2. Reajuste de preço (carência de 2 ciclos)

1. Cada alteração de preço cria um **novo `Price` no Stripe**, porque Prices são imutáveis, e atualiza `plans.stripe_price_id`. Novas assinaturas já usam o preço novo.
2. A assinatura passa a guardar o **preço contratado** (`price_in_cents`, `stripe_price_id`). O valor efetivo é `price_in_cents ?? plan.price_in_cents`.
3. Para cada assinatura `active`, `trial` ou `overdue` do plano, o reajuste é **agendado**:
   - `scheduled_price_in_cents`, `scheduled_stripe_price_id`;
   - `price_change_effective_at = current_period_end + 2 ciclos do plano`.
4. **Contagem da carência:** o período em curso, já pago, não conta. O assinante paga mais **2 cobranças no preço antigo**; a 3ª já vem com o preço novo.
   - Exemplo: período atual até 15/11 e reajuste hoje. As cobranças de 15/11 e 15/12 saem no preço antigo; a partir de 15/01 vale o preço novo.
   - Em **trial**, a contagem parte do fim do trial (`current_period_end`), com a mesma fórmula.
   - Em plano **anual**, a carência é de 2 anuidades (ver §7).
5. **Reajuste sobre reajuste:** se já houver um reajuste agendado e ainda não aplicado, ele é substituído pelo preço novo. A data de virada original é mantida, para não gerar carência extra.
6. Todo reajuste gera um registro em `plan_price_changes`: sócio, preços antigo e novo, Prices antigo e novo, nº de assinaturas afetadas.
7. **Aplicação:** um job diário (`plans:apply-scheduled-prices`, às 03:00) pega as assinaturas com `price_change_effective_at <= agora + 3 dias` e:
   - troca o price do item da assinatura no Stripe com `proration_behavior: none`, e o preço novo entra na próxima fatura;
   - promove `scheduled_*` para `price_in_cents`/`stripe_price_id` e limpa os campos agendados.

   A rotina é idempotente. A falha de uma assinatura é registrada em log e não trava as demais.
8. **Notificação:** o responsável (`owner_email`) de cada laboratório afetado recebe um e-mail com o valor atual, o valor novo e a data da virada (ver §7).

### 3.3. Redução de limites

1. Reduzir um limite é permitido. Antes de salvar, porém, o sistema mostra, por recurso, quantos laboratórios (e quais) ficariam acima do novo limite.
2. Depois de salvo, valem as regras de limites já existentes: em `exceeded`, só a criação de novos itens é bloqueada (ver [billing.md §7](../../domain/billing.md#7-limites-do-plano)).

### 3.4. Custos operacionais

Entidade `OperationalCost` (banco central), com `name`, `type`, `amount`, `is_active` e `notes`.

| Tipo (`OperationalCostType`) | Significado | Unidade de `amount` | Exemplo |
| :--- | :--- | :--- | :--- |
| `fixed` | Custo mensal total do SaaS | centavos/mês | Servidor R\$ 300 |
| `per_tenant` | Custo mensal por laboratório | centavos/mês por laboratório | Taxa fixa Stripe R\$ 0,39 |
| `per_storage_gb` | Custo mensal por GB armazenado | centavos/GB/mês | Storage R\$ 0,15/GB |
| `revenue_percentage` | Percentual sobre a receita | pontos-base (399 = 3,99%) | Taxa Stripe 3,99% |

As taxas do Stripe são cadastradas como custos comuns, editáveis, sem valor fixo no código.

### 3.5. Calculadora de rentabilidade

Tudo é normalizado para **valor mensal**; plano anual entra como preço ÷ 12. Só entram custos com `is_active = true`.

**Margem por plano** (para cada plano ativo):

$$
\text{margem} = P - P \cdot r - C_{lab} - G \cdot C_{GB} - \frac{C_{fixo}}{N_{pagantes}}
$$

- `P`: preço mensal do plano; `r`: soma dos custos `revenue_percentage`; `C_lab`: soma dos `per_tenant`; `C_GB`: soma dos `per_storage_gb`; `C_fixo`: soma dos `fixed`.
- `G` é calculado em dois cenários:
  - **Uso médio real:** média de GB consumidos pelos laboratórios do plano;
  - **Pior caso:** o `storage_gb_limit` do plano. Se o plano for ilimitado, exibe "—".
- `N_pagantes`: total de laboratórios com assinatura `active` e sem isenção. Se for 0, a tela mostra "rateio indisponível" e a margem sai sem o fixo.
- Status: **Prejuízo** (margem < 0), **Apertado** (margem < 20% do preço) ou **Saudável**.

**Visão real (mês corrente):**

- **Receita (MRR):** soma do preço efetivo (mensalizado) das assinaturas `active` de laboratórios **sem isenção ativa**.
- **Custos:**

$$
C_{fixo} + C_{lab} \cdot N_{consumidores} + GB_{real} \cdot C_{GB} + MRR \cdot r
$$

  onde `N_consumidores` são os laboratórios `active`, `trial` ou isentos (todos consomem infraestrutura) e `GB_real` é a soma do armazenamento real de todos os laboratórios ativos.
- **Resultado:** lucro ou prejuízo mensal, margem % e decomposição por tipo de custo.
- O armazenamento real exige consultar o schema de cada tenant, o que é caro. O resultado fica em cache por **1 hora**, com o botão **"Recalcular"** para invalidar.
- O consumo é medido mesmo em planos com limite ilimitado. O cálculo de uso existente (`TenantLimitChecker::usage`) não muda.

---

## 4. Modelo de Dados (banco central)

| Tabela | Alteração |
| :--- | :--- |
| `subscriptions` | + `price_in_cents`, `stripe_price_id`, `scheduled_price_in_cents`, `scheduled_stripe_price_id`, `price_change_effective_at` (indexado). Backfill com os valores atuais do plano. |
| `plan_price_changes` | Nova: `plan_id` (FK), `central_user_id` (FK, `nullOnDelete`), `old_price_in_cents`, `new_price_in_cents`, `old_stripe_price_id`, `new_stripe_price_id`, `affected_subscriptions_count`, timestamps. |
| `operational_costs` | Nova: `name`, `type`, `amount`, `is_active`, `notes`, timestamps. |

Os fluxos que criam assinaturas (`CreateLaboratory`, `PaymentWebhookController`) passam a gravar `price_in_cents` e `stripe_price_id` do plano vigente.

---

## 5. Arquitetura

- **Actions:** `CreatePlan`, `UpdatePlan` (inclui o agendamento do reajuste e o registro do histórico), `ApplyScheduledPlanPrices`.
- **Services:** `PlanLimitImpactAnalyzer` (aviso de redução de limite), `Billing\PlanProfitabilityCalculator` (DTOs `PlanMargin` e `ProfitabilityOverview`).
- **`TenantLimitChecker`:** expõe `consumptionFor(PlanResource, Tenant)` para medir consumo sem depender do limite.
- **`StripeService`:** novos `createPrice`, `updateProductName` e `swapSubscriptionPrice`, todos com suporte ao modo mock.
- **Agendamento:** `plans:apply-scheduled-prices` diário em `routes/console.php`. Exige o scheduler (`schedule:work`/`schedule:run`) ativo no Compose local e no deploy.
- **Notificação:** `PlanPriceChangedNotification`, enviada por e-mail ao `owner_email`.

---

## 6. Interface (Painel Central)

Rotas no grupo `['auth:central', 'central.admin']` de `routes/web.php`:

| Rota | Nome | Componente Volt |
| :--- | :--- | :--- |
| `/planos` | `central.plans.index` | `pages.central.plans.index` |
| `/planos/rentabilidade` | `central.plans.profitability` | `pages.central.plans.profitability` |

**Planos (`/planos`):**
- Cards responsivos (`grid-cols-1 sm:grid-cols-2 lg:grid-cols-3`) com nome, preço, ciclo, limites, nº de assinantes, badge ativo/inativo e badge "reajuste agendado".
- `<flux:modal name="plan-form">` para criar e editar, com a opção "ilimitado" por limite.
- Ao salvar com **preço alterado** ou **limite reduzido**, abre a `<flux:modal name="plan-confirm">` com:
  - "X assinantes pagarão R\$ A até dd/mm e R\$ B a partir de dd/mm";
  - "Y laboratórios ficarão acima do novo limite de …".
- Ação de ativar/desativar.

**Rentabilidade (`/planos/rentabilidade`):**
- Lista de custos operacionais com totais por tipo e CRUD em `<flux:modal name="cost-form">`.
- Cards da visão real (Receita, Custos, Resultado, Margem), decomposição dos custos e botão "Recalcular".
- Tabela de margem por plano (vira cards no mobile) com uso médio, pior caso e badge de status.

**Navegação:** botões "Planos" e "Rentabilidade" no cabeçalho de `laboratories/index`.

---

## 7. Decisões Pendentes de Confirmação

Até que haja confirmação, vale o padrão proposto:

| Tema | Padrão adotado |
| :--- | :--- |
| Carência em plano anual | 2 ciclos (2 anuidades). Alternativa: limitar a ~60 dias. |
| Notificação de reajuste | Enviar e-mail ao `owner_email` no momento do reajuste. |
| Carência em trial | Contada a partir do fim do trial. |
| Taxas do Stripe | Cadastradas como custos operacionais editáveis. |

---

## 8. Critérios de Aceite

- [x] Sócio cria, edita e ativa/desativa planos em `/planos`; quem não é sócio recebe 403.
- [x] Mudança de nome/limites é refletida de imediato; mudança de ciclo é bloqueada quando há assinantes.
- [x] Mudança de preço cria um Price novo no Stripe, registra `plan_price_changes` e agenda `current_period_end + 2 ciclos` nas assinaturas, sem alterar o preço contratado atual.
- [x] Um segundo reajuste antes da virada mantém a data de virada original.
- [x] O job diário aplica somente os reajustes dentro da janela, é idempotente e isola falhas.
- [x] Novas assinaturas gravam o preço contratado.
- [x] Reduzir um limite exibe quantos laboratórios ficarão acima dele antes de salvar.
- [x] Plano desativado não aparece no checkout nem no cadastro pelo sócio.
- [x] CRUD de custos operacionais funciona com os 4 tipos.
- [x] A margem por plano trata: sem pagantes, plano anual, plano com storage ilimitado.
- [x] Na visão real, laboratórios isentos ficam fora da receita, mas entram nos custos; o storage real vem em cache e pode ser recalculado.
- [x] Interface 100% responsiva (mobile-first) com `<flux:modal>`.

