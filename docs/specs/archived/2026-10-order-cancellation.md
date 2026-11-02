# Especificação: Cancelamento de Ordens de Serviço (Order Cancellation)

- **Identificador:** `2026-10-order-cancellation`
- **Status:** Concluído / Implementado
- **Data:** Outubro de 2026
- **Data de Conclusão:** 2026-11-02
- **Módulos Afetados:**
  - Ordens de Serviço (`DentistRequest`, `RequestService`)
  - Estoque (`StockMovement`, `RequestServiceConsumption`, `StockBatch`)
  - Parceiros (`PartnerDemand`, `DemandStatus`)
  - Relatórios Gerenciais (`resources/views/livewire/pages/reports/index.blade.php`)
  - Interface Livewire e Flux UI (`planned.blade.php`, novo modal de cancelamento)

---

## 1. Contexto & Motivação

Atualmente, quando uma ordem de serviço (`DentistRequest`) é criada pelo dentista ou pela recepção do laboratório, inexiste um fluxo unificado e transacional para o cancelamento completo da ordem.

Hoje, existe apenas uma rotina pontual em `CancelService::handle` que atua sobre itens individuais (`RequestService`). Quando todos os serviços de um pedido são cancelados individualmente, o sistema executa um `$dentistRequest->delete()` bruto, o que acarreta perda de histórico, inconsistência com demandas de protéticos terceirizados e falta de rastreabilidade cadastral e sanitária.

Eventos comuns na rotina odontológica exigem o cancelamento controlado e formal da ordem:
1. Mudança no plano de tratamento do paciente ou desistência da cirurgia/prótese.
2. Erro de cadastro ou duplicidade de lançamento no laboratório.
3. Moldagem/escaneamento inviável que impede a continuidade técnica.

> [!NOTE]
> Conforme definição de escopo, **o fluxo financeiro e de cobrança a dentistas não é contemplado nesta especificação** (o sistema não gerencia faturamento/boletos de dentistas neste momento, e o modelo `Payment` refere-se estritamente à assinatura SaaS central do laboratório). O foco desta especificação é restrito ao ciclo operacional da ordem (`DentistRequest`), estoque, parceiros e relatórios operacionais.

---

## 2. Objetivos

1. **Ciclo de Vida Centralizado:** Permitir o cancelamento da ordem de serviço em qualquer estágio anterior à entrega final (`completed_at === null`).
2. **Rastreabilidade e Auditoria:** Registrar data/hora do cancelamento (`cancelled_at`), usuário responsável (`cancelled_by_user_id`), categoria do motivo (`cancellation_category`) e justificativa textual (`cancellation_reason`).
3. **Gestão Transparente de Insumos:** Permitir ao operador decidir, no ato do cancelamento, quais materiais já consumidos devem ser estornados ao estoque (`StockMovementsReasons::Devolution`) e quais devem permanecer debitados como perda técnica (`StockMovementsReasons::WasteDamage`).
4. **Interrupção de Demandas de Parceiros:** Cancelar demandas pendentes ou em andamento vinculadas a técnicos parceiros (`PartnerDemand`), atualizando seu status para `Cancelled` e disparando notificação imediata para evitar retrabalho ou custos desnecessários.
5. **Preservação de Dados Históricos:** Não utilizar exclusão física (`delete`) nos serviços da ordem cancelada, preservando métricas de auditoria e cálculo de custos de insumos descartados.
6. **Integridade em Relatórios Gerenciais:** Excluir ordens canceladas do cálculo de receitas brutas e volume de pedidos concluídos em `reports/index.blade.php`.

---

## 3. Regras de Negócio & Estados Elegíveis

### 3.1. Matriz de Elegibilidade da Ordem

| Estado da OS | Condição Sistêmica | Pode Cancelar? | Comportamento do Sistema |
| :--- | :--- | :---: | :--- |
| **Pendente (Sem Início)** | `started_at` nulo em todas as demandas e nenhum consumo registrado em estoque. | **Sim** | Cancelamento direto. Demandas não iniciadas são canceladas. Estoque não é alterado (nada foi consumido). |
| **Em Produção (Parcial)** | Ao menos uma demanda com `started_at != null` ou insumos consumidos via `consumptions`. | **Sim** | Exige justificativa e categoria. Apresenta lista de insumos consumidos para decisão de estorno vs descarte. Cancela demandas pendentes e em andamento. Notifica parceiros com demandas interrompidas. |
| **Concluída / Entregue** | `completed_at != null` | **Não** | **Ação bloqueada.** Uma ordem finalizada não pode ser cancelada diretamente (deve seguir fluxo de garantia/retrabalho). |
| **Já Cancelada** | `cancelled_at != null` | **Não** | **Ação bloqueada.** Acesso idempotente; exibe estado cancelado na interface. |

### 3.2. Matriz de Autorização (`DentistRequestPolicy::cancel`)

1. **Equipe Interna do Laboratório (`Rules::Owner`, `Rules::Lab`, `Rules::LabManager`):**
   - Podem cancelar qualquer ordem de serviço do laboratório, desde que elegível (`completed_at === null && cancelled_at === null`).
2. **Cirurgião-Dentista (`Rules::Dentist`):**
   - Pode cancelar **apenas** ordens vinculadas ao seu próprio cadastro (`$dentistRequest->dentist?->user_id === $user->id`).
   - Permitido **apenas** se a ordem estiver no estado **Pendente (Sem Início)**. Uma vez que qualquer técnico parceiro tenha iniciado uma etapa ou que qualquer insumo tenha sido baixado, o dentista perde o privilégio de auto-cancelamento e deve solicitar o cancelamento à equipe do laboratório.
3. **Protético Parceiro (`Rules::LabPartner`):**
   - **Nunca** pode cancelar ordens (`return false`).

### 3.3. Tratamento de Insumos e Estoque

Caso a ordem possua itens em `request_service_consumptions`:
1. **Opção A - Material Reutilizável (Estorno ao Estoque):**
   - Gera movimentação de entrada (`StockMovementsTypes::In`) com motivo `StockMovementsReasons::Devolution` ("Devolução de Cliente / OS Cancelada").
   - O saldo é devolvido ao lote de origem (`StockBatch.current_quantity`) quando aplicável, incrementando também `Product.current_stock`.
2. **Opção B - Material Customizado / Inutilizado (Descarte / Perda Técnica):**
   - O insumo não retorna ao estoque.
   - A movimentação de estoque mantém-se como saída, mas é documentada como perda operacional por cancelamento de pedido, garantindo precisão no cálculo do CMV (Custo de Mercadorias Vendidas) e relatórios de perdas.

### 3.4. Tratamento de Demandas de Parceiros (`PartnerDemand`)

Ao cancelar a ordem:
1. **Demandas Não Iniciadas (`started_at === null`):**
   - Marcadas com `cancelled_at = now()`. Status passa a ser `DemandStatus::Cancelled`. Não geram direito a comissão.
2. **Demandas Em Andamento (`started_at !== null && ended_at === null`):**
   - Marcadas com `cancelled_at = now()`. Status passa a ser `DemandStatus::Cancelled`.
   - O técnico parceiro atribuído recebe notificação imediata (`PartnerDemandCancelledNotification`) informando a interrupção do trabalho.
3. **Demandas Já Concluídas (`ended_at !== null`):**
   - Permanecem como `DemandStatus::Done`. O parceiro executou sua parte e mantém a comissão devida pelo trabalho já finalizado.

### 3.5. Arquivos Anexos e Cota de Armazenamento

- Arquivos STL, DICOM e fotos anexados à ordem em `files` **são preservados** para fins de rastreabilidade legal, comprovação técnica e histórico perante o dentista.
- O espaço utilizado continua contabilizado na cota de armazenamento (`PlanResource::Storage`). O laboratório pode remover arquivos manualmente após o encerramento formal, caso deseje liberar espaço.

### 3.6. Impacto em Relatórios Gerenciais (`reports/index.blade.php`)

- As consultas analíticas de faturamento (`revenueLines`) e volume de pedidos (`ordersQuery`) devem ignorar ordens canceladas (`whereNull('dentist_requests.cancelled_at')`).
- Insumos descartados (Opção B) continuam contabilizados nas saídas de estoque do período para apuração correta do custo de perdas do laboratório.

---

## 4. Requisitos de Interface (UI / UX)

### 4.1. Gatilho na Listagem de Ordens (`planned.blade.php`)

- **Visibilidade:** O botão ou item de menu "Cancelar Ordem" deve ser exibido tanto na visão desktop quanto na visão mobile de cada card de `DentistRequest`, condicionado a `@can('cancel', $dentistRequest)`.
- **Ordens Já Canceladas:**
  - O cabeçalho da ordem exibe uma badge visual vermelha: `<flux:badge color="red">Cancelada</flux:badge>`.
  - Botões de alteração de status, edição de serviços ou upload de novos arquivos ficam desabilitados.
  - O motivo e a data do cancelamento ficam visíveis ao expandir os detalhes da ordem.

### 4.2. Modal de Confirmação (`<livewire:service.cancel-order />`)

Construído estritamente com `<flux:modal>` e abordagem **Mobile-First**:
1. **Cabeçalho:** Ícone de aviso em tom destrutivo (`variant="danger"`), destacando o código da ordem (`#code`) e o nome do dentista.
2. **Seletor de Categoria (`cancellation_category`):**
   - Opções: "Erro de lançamento / duplicidade", "Desistência do dentista / paciente", "Moldagem / escaneamento inviável", "Outros".
3. **Justificativa (`cancellation_reason`):**
   - Campo de texto (`<flux:textarea>`) obrigatório (mínimo de 10 caracteres).
4. **Seção de Decisão de Materiais (Renderizada apenas se houver consumos):**
   - Lista os insumos baixados na ordem (produto, lote, quantidade).
   - Para cada insumo, permite selecionar via radio ou switch:
     - `Estornar ao Estoque` (padrão se o produto não for customizado).
     - `Registrar como Perda / Descarte`.
5. **Ações:**
   - Botão "Voltar / Cancelar" (`variant="ghost"`).
   - Botão "Confirmar Cancelamento da OS" (`variant="danger"`, com estado de carregamento `wire:loading`).

---

## 5. Detalhamento Técnico

### 5.1. Migrações de Banco de Dados (Tenant)

#### 1. Alterar `dentist_requests`
Criar migração `database/migrations/tenant/YYYY_MM_DD_HHMMSS_add_cancellation_fields_to_dentist_requests_table.php`:
```php
Schema::table('dentist_requests', function (Blueprint $table) {
    $table->timestamp('cancelled_at')->nullable()->after('completed_at');
    $table->foreignId('cancelled_by_user_id')->nullable()->after('cancelled_at')->constrained('users')->nullOnDelete();
    $table->string('cancellation_category', 100)->nullable()->after('cancelled_by_user_id');
    $table->text('cancellation_reason')->nullable()->after('cancellation_category');
});
```

#### 2. Alterar `partner_demands`
Criar migração `database/migrations/tenant/YYYY_MM_DD_HHMMSS_add_cancelled_at_to_partner_demands_table.php`:
```php
Schema::table('partner_demands', function (Blueprint $table) {
    $table->timestamp('cancelled_at')->nullable()->after('ended_at');
});
```

### 5.2. Modelos e Enums

1. **`App\Enums\DemandStatus`:**
   - Adicionar o case:
     ```php
     case Cancelled = 'cancelled';
     ```
   - Atualizar `label()`:
     ```php
     self::Cancelled => 'Cancelado',
     ```

2. **`App\Models\PartnerDemand`:**
   - Atualizar `$casts`: `'cancelled_at' => 'datetime'`.
   - Atualizar `$fillable`: incluir `'cancelled_at'`.
   - Atualizar o acessor `status()`:
     ```php
     protected function status(): Attribute
     {
         return Attribute::make(
             get: function () {
                 if ($this->cancelled_at) {
                     return DemandStatus::Cancelled;
                 }
                 if ($this->ended_at) {
                     return DemandStatus::Done;
                 }
                 if ($this->started_at) {
                     return DemandStatus::InProgress;
                 }
                 if ($this->partner_id) {
                     return DemandStatus::Assigned;
                 }
                 return DemandStatus::Pending;
             }
         );
     }
     ```
   - Atualizar scope `withStatus(Builder $query, ?string $status)` para suportar `'cancelled'`.

3. **`App\Models\DentistRequest`:**
   - Adicionar ao `#[Fillable]`: `'cancelled_at'`, `'cancelled_by_user_id'`, `'cancellation_category'`, `'cancellation_reason'`.
   - Adicionar `$casts`: `'cancelled_at' => 'datetime'`.
   - Adicionar relação:
     ```php
     public function cancelledBy(): BelongsTo
     {
         return $this->belongsTo(User::class, 'cancelled_by_user_id');
     }
     ```
   - Métodos auxiliares:
     ```php
     public function isCancelled(): bool
     {
         return $this->cancelled_at !== null;
     }

     public function isCompleted(): bool
     {
         return $this->completed_at !== null;
     }

     public function hasStartedProduction(): bool
     {
         return $this->partnerDemands()->whereNotNull('started_at')->exists()
             || $this->requestServices()->whereHas('consumptions')->exists();
     }
     ```

### 5.3. Política de Autorização (`App\Policies\DentistRequestPolicy`)

Adicionar o método `cancel`:
```php
public function cancel(User $user, DentistRequest $dentistRequest): bool
{
    $user->loadMissing('rule');

    // Não pode cancelar se já estiver cancelada ou concluída
    if ($dentistRequest->isCancelled() || $dentistRequest->isCompleted()) {
        return false;
    }

    // Administração do laboratório
    if (in_array($user->rule?->name, [Rules::Owner, Rules::Lab, Rules::LabManager], true)) {
        return true;
    }

    // Dentista só pode cancelar suas próprias ordens antes do início da produção
    if ($user->rule?->name === Rules::Dentist) {
        $dentistRequest->loadMissing('dentist');

        $isOwnRequest = $dentistRequest->dentist !== null
            && $dentistRequest->dentist->user_id === $user->id;

        return $isOwnRequest && ! $dentistRequest->hasStartedProduction();
    }

    return false;
}
```

### 5.4. Action de Negócio (`App\Actions\CancelDentistRequest`)

Criar `app/Actions/CancelDentistRequest.php`:
```php
namespace App\Actions;

use App\Enums\StockMovementsReasons;
use App\Enums\StockMovementsTypes;
use App\Models\DentistRequest;
use App\Models\User;
use App\Notifications\OrderCancelledNotification;
use App\Notifications\PartnerDemandCancelledNotification;
use DomainException;
use Illuminate\Support\Facades\DB;

class CancelDentistRequest
{
    /**
     * @param array{
     *     reason: string,
     *     category: string,
     *     material_actions?: array<int, string> // [consumption_id => 'devolution'|'waste']
     * } $data
     */
    public static function handle(DentistRequest $dentistRequest, array $data, User $cancelledBy): void
    {
        if ($dentistRequest->isCompleted()) {
            throw new DomainException('Não é possível cancelar uma ordem de serviço já concluída.');
        }

        if ($dentistRequest->isCancelled()) {
            throw new DomainException('Esta ordem de serviço já se encontra cancelada.');
        }

        DB::transaction(function () use ($dentistRequest, $data, $cancelledBy) {
            $now = now();

            // 1. Atualizar a DentistRequest
            $dentistRequest->update([
                'cancelled_at' => $now,
                'cancelled_by_user_id' => $cancelledBy->id,
                'cancellation_category' => $data['category'],
                'cancellation_reason' => $data['reason'],
            ]);

            // 2. Tratar Insumos de Estoque
            $materialActions = $data['material_actions'] ?? [];
            $consumptions = $dentistRequest->requestServices()
                ->with(['consumptions.product', 'consumptions.stockBatch'])
                ->get()
                ->flatMap->consumptions;

            foreach ($consumptions as $consumption) {
                $action = $materialActions[$consumption->id] ?? 'devolution';

                if ($action === 'devolution') {
                    StockMovement::handle($consumption->product_id, [
                        'dentist_request_id' => $dentistRequest->id,
                        'movementType' => StockMovementsTypes::In,
                        'reason' => StockMovementsReasons::Devolution,
                        'amount' => $consumption->quantity_used,
                        'stockBatchId' => $consumption->stock_batch_id,
                        'notes' => "Estorno por cancelamento da OS #{$dentistRequest->code}",
                    ]);
                }
            }

            // 3. Atualizar Demandas de Parceiros e Notificar
            $demandsToCancel = $dentistRequest->partnerDemands()
                ->whereNull('ended_at')
                ->whereNull('cancelled_at')
                ->with(['partner.user', 'serviceServiceStep.serviceStep'])
                ->get();

            foreach ($demandsToCancel as $demand) {
                $demand->update(['cancelled_at' => $now]);

                if ($demand->partner?->user && $demand->started_at !== null) {
                    $demand->partner->user->notify(new PartnerDemandCancelledNotification($demand));
                }
            }

            // 4. Notificar o Dentista (caso cancelado pela equipe do laboratório)
            if ($dentistRequest->dentist?->user && $cancelledBy->id !== $dentistRequest->dentist->user_id) {
                $dentistRequest->dentist->user->notify(new OrderCancelledNotification($dentistRequest));
            }
        });
    }
}
```

### 5.5. Notificações

1. **`App\Notifications\OrderCancelledNotification`:**
   - Enviada ao cirurgião-dentista informando o cancelamento do pedido e o motivo registrado.
2. **`App\Notifications\PartnerDemandCancelledNotification`:**
   - Enviada ao técnico protético parceiro caso ele possuísse uma etapa em andamento na ordem cancelada.

### 5.6. Componente Livewire do Modal (`resources/views/components/service/cancel-order.blade.php`)

- Declarado como componente Livewire/Volt.
- Escuta o evento `service-request::cancel::open` passando o ID da `DentistRequest`.
- No método `open(int $requestId)`:
  - Carrega a ordem com relacionamentos (`requestServices.consumptions.product`).
  - Executa `$this->authorize('cancel', $this->dentistRequest)`.
  - Exibe o modal via `Flux::modal('cancel-order-modal')->show()`.
- No método `confirmCancel()`:
  - Executa `$this->authorize('cancel', $this->dentistRequest)`.
  - Valida `category` e `reason`.
  - Chama `CancelDentistRequest::handle(...)`.
  - Emite toasts e dispara `requests::refresh` e `demand::refresh`.
  - Fecha o modal.

---

## 6. Critérios de Aceite (Testes Automatizados)

Os testes devem ser implementados em `tests/Feature/OrderCancellationTest.php`, cobrindo:

1. **Autorização por Perfil:**
   - Usuário `Owner`, `Lab` ou `LabManager` consegue cancelar uma ordem pendente ou em produção.
   - Dentista consegue cancelar **apenas** suas próprias ordens enquanto pendentes (`started_at == null && consumptions == 0`).
   - Dentista recebe HTTP 403 / negação ao tentar cancelar ordem própria já em produção.
   - Dentista recebe HTTP 403 ao tentar cancelar ordem de outro dentista.
   - Técnico parceiro (`LabPartner`) recebe HTTP 403 ao tentar cancelar qualquer ordem.
2. **Validação de Estados e Idempotência:**
   - Tentativa de cancelar ordem com `completed_at` preenchido lança `DomainException`.
   - Tentativa de cancelar ordem já cancelada lança `DomainException`.
3. **Integridade de Estoque:**
   - Insumos selecionados para estorno (`devolution`) retornam com exatidão ao saldo do lote (`StockBatch.current_quantity`) e geram `StockMovement` de entrada tipo `In` com motivo `Devolution`.
   - Insumos marcados para descarte não alteram o estoque e mantêm o débito original.
4. **Demandas de Parceiros:**
   - Demandas não iniciadas e em andamento recebem `cancelled_at = now()` e status `Cancelled`.
   - Demandas já concluídas (`ended_at != null`) permanecem com status `Done`.
   - Notificação é despachada para o parceiro que tinha etapa em andamento.
5. **Relatórios Gerenciais:**
   - A ordem cancelada é desconsiderada das consultas de receita bruta em `reports/index.blade.php`.
6. **Auditoria na Ordem:**
   - A `DentistRequest` cancelada exibe corretamente `cancelled_at`, `cancelled_by_user_id`, categoria e motivo na interface.
