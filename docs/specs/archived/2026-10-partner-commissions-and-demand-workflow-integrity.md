# Especificação: Integridade de Comissões de Parceiros e Robustez no Fluxo de Demandas e Serviços

- **Identificador:** `2026-10-partner-commissions-and-demand-workflow-integrity`
- **Status:** Concluído / Implementado
- **Data de Conclusão:** 2026-10-31
- **Data:** Outubro de 2026
- **Módulos Afetados:** Terceirização & Parceiros (`PartnerDemand`, `PartnerDemandPolicy`, `resources/views/components/partners/start-demand.blade.php`, `resources/views/components/partners/finish-demand.blade.php`, `resources/views/livewire/pages/dentists/demands.blade.php`), Ordens & Serviços Solicitados (`RequestService`, `RequestServicePolicy`, `resources/views/livewire/pages/services/planned.blade.php`, `resources/views/components/service/progress.blade.php`, `CompleteService`), Relatórios Gerenciais (`resources/views/livewire/pages/reports/index.blade.php`), Migrations Tenant (`database/migrations/tenant/`).

---

## 1. Contexto & Motivação

Durante a operação diária do laboratório e a gestão de etapas terceirizadas delegadas a protéticos parceiros (`LabPartner`), foram detectadas cinco falhas críticas no fluxo de trabalho e na precisão financeira:

1. **Perda de Centavos/Decimais na Comissão de Parceiros:**
   - Na tabela pivô `service_step_partner`, a coluna `commission` é armazenada como `string`, permitindo tanto percentuais fracionados (ex.: `12.5%`) quanto valores fixos em reais com centavos (ex.: `R$ 45.50`).
   - Contudo, na tabela tenant `partner_demands` (`database/migrations/tenant/2026_09_27_140710_create_partner_demands_table.php`), a coluna `partner_commission` foi modelada como **`integer`** sem mutator de centavos.
   - Quando o parceiro inicia a demanda em `resources/views/components/partners/start-demand.blade.php`, o valor `45.50` vira `45` no banco de dados. No relatório gerencial (`resources/views/livewire/pages/reports/index.blade.php`), a comissão fixa é multiplicada por 100 (`45 * 100 = R$ 45,00`), gerando perda financeira de todos os centavos nas comissões fixas e percentuais fracionados (ex.: `12.5%` vira `12%`).

2. **Parceiro (`LabPartner`) Impedido de Iniciar ou Concluir suas Próprias Demandas na UI:**
   - Em `resources/views/livewire/pages/dentists/demands.blade.php` (linhas 198, 211, 303 e 316, tanto na tabela desktop quanto nos cards mobile), os botões de **Iniciar** (`start`) e **Completar** (`complete`) estão com `:disabled="!Auth::user()->can('assign', $demand)"` em vez de `can('start', $demand)` / `can('complete', $demand)`.
   - Como `PartnerDemandPolicy::assign` exige que o parceiro ainda conste na relação atual `serviceStep->partners` (ou perfil interno de gestão), e a semântica correta das ações operacionais é `start` e `complete` (que validam se a demanda está atribuída ao `user_id` do parceiro e na vez de execução), o parceiro fica bloqueado indevidamente na interface.

3. **Crash se o Parceiro for Desvinculado da Etapa Antes de Iniciar a Demanda:**
   - Em `resources/views/components/partners/start-demand.blade.php`, se uma demanda foi atribuída a um parceiro e depois esse parceiro foi removido da etapa em `manage-partners`, o encadeamento `->where('id', $this->partnerDemand->partner_id)->first()->pivot` tenta acessar `->pivot` em `null`, gerando erro 500.

4. **Demandas Não Concluem o Serviço Automaticamente e Botão Cancelar Inoperante:**
   - Quando a última etapa é concluída em `resources/views/components/partners/finish-demand.blade.php`, `CompleteService::handle` **não é chamado** (só é chamado se alguém abrir manualmente a modal de progresso em `resources/views/components/service/progress.blade.php` e clicar no botão "Completar").
   - Além disso, o botão "Cancelar" serviço em `resources/views/livewire/pages/services/planned.blade.php` (linhas 203 e 300) dispara o evento `service::canceled`, mas **não existe nenhum listener** para esse evento em todo o projeto (o botão não faz nada).

5. **Divisão por Zero na Listagem de Serviços Solicitados:**
   - Em `resources/views/livewire/pages/services/planned.blade.php`, nos cálculos de progresso do cabeçalho da requisição (`$completedSteps / $totalSteps * 100` nas linhas 92 e 139) e na linha individual do serviço (`$requestService->demands_completed / $requestService->service->serviceSteps->count() * 100` na linha 160), se um serviço tiver `0` etapas vinculadas (`$totalSteps === 0` ou `serviceSteps->count() === 0`), ocorre `DivisionByZeroError` ao renderizar a página. Além disso, na visualização mobile do item (linha 267), o tooltip estava com texto fixo `content="test"`.

---

## 2. Objetivos

- **Preservação Monetária e Decimal:** Migrar a coluna `partner_commission` na tabela `partner_demands` para tipo decimal com 2 casas decimais (`DECIMAL(10, 2)`), garantindo precisão tanto para percentuais fracionários quanto para quantias fixas em reais.
- **Correção da Autorização de Ações na UI de Demandas:** Ajustar os atributos `:disabled` dos botões de Iniciar e Completar em `demands.blade.php` para respeitar as respectivas políticas (`can('start')` e `can('complete')`).
- **Resiliência a Mudanças Cadastrais:** Proteger o modal de início de demanda (`start-demand.blade.php`) contra referências nulas caso o parceiro tenha sido desvinculado da etapa no catálogo, alertando o usuário via toast amigável e bloqueando o início indevido.
- **Automação do Ciclo de Conclusão e Tratamento de Cancelamento de Serviço:**
  - Invocar automaticamente `CompleteService::handle` quando a última demanda de uma solicitação for finalizada em `finish-demand.blade.php`.
  - Implementar o listener e o fluxo de cancelamento de itens de serviço (`service::canceled`), garantindo controle de permissão, bloqueio de cancelamento em serviços já concluídos, reversão de insumos e estorno de demandas pendentes.
- **Blindagem Numérica Contra Divisão por Zero:** Tratar a renderização da barra de progresso e tooltip em `planned.blade.php` para serviços com 0 etapas cadastradas.

---

## 3. Regras de Negócio & Arquitetura de Solução

### 3.1. Migração e Precisão de Comissões (`partner_demands`)

- **Alteração Estrutural (Migration Tenant):**
  - Alterar a coluna `partner_commission` na tabela `partner_demands`:
    - De: `integer('partner_commission')->nullable()`
    - Para: `decimal('partner_commission', 10, 2)->nullable()`
- **Casts e Modelo Eloquent (`PartnerDemand.php`):**
  - Adicionar o cast no array `$casts` de `PartnerDemand`:
    `'partner_commission' => 'decimal:2'`
- **Cálculo nos Relatórios Gerenciais (`reports/index.blade.php`):**
  - Na query de soma ponderada de comissões (`partnerCommissionExpression`):
    - Tipo Fixo (`CommissionTypes::Fixed`): `ROUND(partner_demands.partner_commission * 100)` -> Converte reais para centavos sem perda fracionada (ex.: R$ 45,50 vira 4550 centavos).
    - Tipo Percentual (`CommissionTypes::Percentage`): `ROUND(partner_demands.partner_commission * request_services.unit_price * request_services.quantity / 100.0)` -> Aplica a taxa percentual exata (ex.: 12.50%) sobre o total do serviço em centavos.
  - Na tabela de detalhamento do relatório:
    - Fixo: `currency_from_int((int) round((float) $demand->partner_commission * 100), true)`
    - Percentual: `number_format((float) $demand->partner_commission, 2, ',', '.') . '%'`

### 3.2. Autorização e Habilitação de Botões (`resources/views/livewire/pages/dentists/demands.blade.php`)

- **Correção dos Botões na Tabela Desktop (linhas 198 e 211):**
  - Botão Iniciar:
    - De: `:disabled="!Auth::user()->can('assign', $demand)"`
    - Para: `:disabled="!Auth::user()->can('start', $demand)"`
  - Botão Completar:
    - De: `:disabled="!Auth::user()->can('assign', $demand)"`
    - Para: `:disabled="!Auth::user()->can('complete', $demand)"`
- **Correção dos Botões nos Cards Mobile (linhas 303 e 316):**
  - Replicar a mesma correção nas linhas equivalentes da visualização mobile (`can('start', $demand)` e `can('complete', $demand)`).
- **Garantia de Regra:** Um usuário com role `LabPartner` atribuído à demanda conseguirá acionar Iniciar e Completar diretamente pela interface sem ser barrado por checagens de atribuição (`assign`).

### 3.3. Resiliência Cadastral na Inicialização de Demandas (`resources/views/components/partners/start-demand.blade.php`)

- **Navegação Segura de Coleção:**
  - Substituir o encadeamento direto `->where('id', $this->partnerDemand->partner_id)->first()->pivot` por verificação explícita do parceiro vinculado:
    ```php
    $stepPartner = $partnerDemand->serviceServiceStep
        ?->serviceStep
        ?->partners
        ?->firstWhere('id', $this->partnerDemand->partner_id);

    if (! $stepPartner || ! $stepPartner->pivot) {
        $this->partnerDemand = null;
        Flux::toast(
            heading: 'Parceiro desvinculado da etapa!',
            variant: 'error',
            text: 'O parceiro atribuído não possui mais vínculo ativo com esta etapa no catálogo. Reatribua a demanda antes de iniciá-la.'
        );
        return;
    }
    ```
- **Persistência Segura:**
  - O valor da comissão e o tipo devem ser convertidos com segurança (`(float) $stepPartner->pivot->commission`), protegendo o cálculo da computed property `commission()`.

### 3.4. Conclusão Automática de Serviços & Listener de Cancelamento

#### 3.4.1. Conclusão Automática (`resources/views/components/partners/finish-demand.blade.php`)
- Imediatamente após a finalização da demanda (após a transação de upload e preenchimento de `ended_at`):
  - Chamar `CompleteService::handle($this->partnerDemand->request_service_id)`.
  - A action `CompleteService::handle`:
    1. Verifica se todas as demandas de `RequestService` possuem `ended_at !== null`.
    2. Se verdadeiro, atualiza `request_services.completed_at = now()`.
    3. Verifica se todos os serviços da ordem `DentistRequest` estão concluídos (`completed_at !== null`).
    4. Se verdadeiro, atualiza `dentist_requests.completed_at = now()` e dispara `CompletedRequestNotification` para o dentista solicitante.
  - Emitir eventos de UI: `$this->dispatch('demand::refresh')` e `$this->dispatch('requests::refresh')`.

#### 3.4.2. Fluxo de Cancelamento de Serviço (`service::canceled`)
- **Listener:** Implementar método listener `#[On('service::canceled')]` em `resources/views/livewire/pages/services/planned.blade.php` (ou componente modal associado).
- **Autorização:** Validar permissão através de `RequestServicePolicy::updateStatus` (`$this->authorize('updateStatus', $requestService)` — restrito a `Owner`, `Lab` e `LabManager`).
- **Validação de Negócio:**
  - Rejeitar cancelamento de serviço com `completed_at !== null`.
  - Caso haja demandas em andamento ou concluídas (`started_at !== null` ou `ended_at !== null`), bloquear ou tratar conforme a regra de integridade produtiva.
- **Ações ao Cancelar o Serviço:**
  - Se insumos foram consumidos em estoque (`RequestServiceConsumption`): estornar as quantidades correspondentes nos lotes (`StockBatch`) criando movimentações de entrada (`In`) com motivo `Return` / `Correction`.
  - Cancelar ou remover as `PartnerDemand` pendentes associadas.
  - Excluir o registro de `RequestService` (ou registrar status cancelado).
  - Caso a ordem de serviço (`DentistRequest`) tenha todos os demais serviços restantes já concluídos (`completed_at !== null`), acionar o fechamento da ordem pai; caso não restem mais serviços na ordem, remover ou encerrar a solicitação conforme regra de domínio.
  - Notificar o usuário via toast de sucesso e atualizar a tela (`$this->dispatch('requests::refresh')` e `$this->dispatch('demand::refresh')`).

### 3.5. Proteção Numérica Contra Divisão por Zero (`resources/views/livewire/pages/services/planned.blade.php`)

- **Cálculo Seguro da Porcentagem (Ordem Pai e Item Individual):**
  - No nível da requisição (`DentistRequest`, desktop linha 92 e mobile linha 139):
    ```blade
    @php
        $completedSteps = (int) $dentistRequest->requestServices->sum('demands_completed');
        $totalSteps = (int) $dentistRequest->requestServices->sum('service.service_steps_count');
        $progressPercentage = $totalSteps > 0 ? (int) round(($completedSteps / $totalSteps) * 100) : 0;
    @endphp
    ```
  - No nível do item (`RequestService`, desktop linha 160 e mobile linha 267):
    ```blade
    @php
        $serviceTotalSteps = (int) ($requestService->service->service_steps_count ?? $requestService->service->serviceSteps->count());
        $serviceCompletedSteps = (int) $requestService->demands_completed;
        $serviceProgressPercentage = $serviceTotalSteps > 0 ? (int) round(($serviceCompletedSteps / $serviceTotalSteps) * 100) : 0;
    @endphp
    ```
- **Apresentação Visual:**
  - Tooltip da ordem: `content="{{ $progressPercentage }}%"`
  - Tooltip do item (desktop e mobile, corrigindo também o placeholder `content="test"` no mobile): `content="{{ $serviceProgressPercentage }}%"`
  - `<flux:progress :value="$completedSteps" :max="$totalSteps > 0 ? $totalSteps : 1" ... />` (e equivalente `:max="$serviceTotalSteps > 0 ? $serviceTotalSteps : 1"` no item).
  - Método `getColor(int $completed, int $total)`: Retorna `blue` quando `$total === 0` ou `$completed === 0`, e `green` apenas quando `$total > 0 && $completed === $total`.

---

## 4. Requisitos de Interface (UI / UX)

- **Grid e Tabela de Demandas (`demands.blade.php`):**
  - Protéticos parceiros logados visualizam os botões "Iniciar" e "Completar" habilitados quando a etapa estiver no momento correto da esteira produtiva (`canStartNow` / `canCompleteNow`).
- **Modal de Início (`start-demand.blade.php`):**
  - Exibição de comissões fixas no formato monetário padrão (`R$ 45,50`) e percentuais (`12,50%`).
  - Toast de erro amigável caso a etapa tenha sofrido alterações de parceiro pelo gestor.
- **Listagem de Serviços (`planned.blade.php`):**
  - Renderização 100% livre de crashes quando novos serviços sem etapas definidas forem adicionados.
  - Ação de cancelamento operacional via menu dropdown e botões mobile com feedback imediato de sucesso ou erro de permissão.

---

## 5. Critérios de Aceite (Testes Automatizados)

1. **Precisão de Comissões Fracionadas:**
   - Iniciar uma demanda com comissão fixa de `R$ 45,50` persiste exatamente `45.50` em `partner_demands.partner_commission`.
   - O relatório financeiro consolida `4550` centavos de custo de parceiro, sem arredondar para `4500`.
   - Iniciar uma demanda com comissão percentual de `12.5%` calcula com precisão sobre o preço unitário e quantidade.
2. **Permissão de LabPartner na UI:**
   - Teste de componente / política garantindo que usuário com perfil `LabPartner` tem acesso liberado aos botões de Iniciar e Concluir em suas demandas.
3. **Resiliência a Desvinculação de Parceiro:**
   - Chamar `start-demand::open` para uma demanda cujo parceiro foi removido da tabela pivô `service_step_partner` não lança 500, exibe toast de aviso e mantém o estado limpo.
4. **Conclusão Automática e Cancelamento de Serviço:**
   - Ao finalizar a última `PartnerDemand` em `finish-demand.blade.php`, o `RequestService` pai tem seu campo `completed_at` preenchido automaticamente via `CompleteService::handle`.
   - Havendo apenas este serviço na solicitação, a ordem `DentistRequest` também é marcada como concluída (`completed_at !== null`) e a notificação é despachada.
   - Acionar o evento `service::canceled` em `planned.blade.php` executa o listener autorizado, bloqueia serviços já concluídos e estorna/cancela demandas e insumos pendentes.
5. **Divisão por Zero em Serviços sem Etapas:**
   - Renderizar `planned.blade.php` para uma requisição com serviço que possua 0 etapas não lança `DivisionByZeroError` nem no progresso consolidado da requisição nem no progresso individual do serviço, exibindo `0%`.

