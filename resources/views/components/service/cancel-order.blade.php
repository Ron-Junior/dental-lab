<?php

use App\Actions\CancelDentistRequest;
use App\Models\DentistRequest;
use App\Models\RequestServiceConsumption;
use Flux\Flux;
use Illuminate\Support\Collection;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component
{
    #[Locked]
    public ?int $requestId = null;

    public ?DentistRequest $dentistRequest = null;

    public string $cancellationCategory = 'Erro de lançamento / duplicidade';

    public string $cancellationReason = '';

    /**
     * @var array<int, string>
     */
    public array $materialActions = [];

    /**
     * @var array<int, array{id: int, product_name: string, batch_code: ?string, quantity: string|float, unit: string}>
     */
    public array $consumptionsList = [];

    protected function rules(): array
    {
        return [
            'cancellationCategory' => ['required', 'string', 'in:Erro de lançamento / duplicidade,Desistência do dentista / paciente,Moldagem / escaneamento inviável,Outros'],
            'cancellationReason' => ['required', 'string', 'min:10'],
            'materialActions' => ['array'],
            'materialActions.*' => ['required', 'in:devolution,waste'],
        ];
    }

    protected function messages(): array
    {
        return [
            'cancellationCategory.required' => 'Selecione uma categoria para o cancelamento.',
            'cancellationCategory.in' => 'Categoria de cancelamento inválida.',
            'cancellationReason.required' => 'Informe a justificativa do cancelamento.',
            'cancellationReason.min' => 'A justificativa deve conter no mínimo :min caracteres.',
        ];
    }

    #[On('service-request::cancel::open')]
    public function open(int|string $requestId): void
    {
        $dentistRequest = DentistRequest::with([
            'dentist.user',
            'requestServices.consumptions.product',
            'requestServices.consumptions.stockBatch',
        ])->findOrFail((int) $requestId);

        $this->authorize('cancel', $dentistRequest);

        $this->requestId = (int) $requestId;
        $this->dentistRequest = $dentistRequest;
        $this->cancellationCategory = 'Erro de lançamento / duplicidade';
        $this->cancellationReason = '';
        $this->materialActions = [];
        $this->consumptionsList = [];

        $consumptions = $dentistRequest->requestServices->flatMap->consumptions;

        foreach ($consumptions as $consumption) {
            $this->materialActions[$consumption->id] = 'devolution';
            $this->consumptionsList[] = [
                'id' => $consumption->id,
                'product_name' => $consumption->product?->name ?? 'Insumo',
                'batch_code' => $consumption->stockBatch?->batch_number,
                'quantity' => $consumption->quantity_used,
                'unit' => $consumption->product?->unit_of_measure?->value ?? 'un',
            ];
        }

        $this->resetErrorBag();
        Flux::modal('cancel-order-modal')->show();
    }

    public function confirmCancel(): void
    {
        abort_if(! $this->requestId, 404);

        $dentistRequest = DentistRequest::with([
            'dentist.user',
            'requestServices.consumptions',
        ])->findOrFail($this->requestId);

        $this->authorize('cancel', $dentistRequest);

        $this->validate();

        try {
            CancelDentistRequest::handle(
                $dentistRequest,
                [
                    'category' => $this->cancellationCategory,
                    'reason' => $this->cancellationReason,
                    'material_actions' => $this->materialActions,
                ],
                auth()->user()
            );

            Flux::modal('cancel-order-modal')->close();
            $this->dispatch('requests::refresh');
            $this->dispatch('demand::refresh');
            Flux::toast(variant: 'success', heading: 'Ordem cancelada', text: 'A ordem de serviço foi cancelada com sucesso.');
        } catch (\Throwable $e) {
            Flux::toast(variant: 'danger', heading: 'Erro ao cancelar ordem', text: $e->getMessage());
        }
    }
};
?>

<div>
    <flux:modal class="w-full max-w-lg" name="cancel-order-modal">
        <form wire:submit="confirmCancel" class="space-y-6">
            <div>
                <div class="flex items-center gap-3 text-red-600 dark:text-red-400">
                    <flux:icon name="exclamation-triangle" class="size-6" />
                    <flux:heading size="lg">Cancelar Ordem de Serviço</flux:heading>
                </div>
                @if ($dentistRequest)
                    <flux:subheading class="mt-1">
                        OS <span class="font-semibold text-zinc-900 dark:text-zinc-100">#{{ $dentistRequest->code }}</span>
                        @if ($dentistRequest->dentist?->user)
                            — Dentista: <span class="font-medium text-zinc-900 dark:text-zinc-100">{{ $dentistRequest->dentist->user->name }}</span>
                        @endif
                    </flux:subheading>
                @endif
            </div>

            <div class="space-y-4">
                <flux:select
                    wire:model="cancellationCategory"
                    label="Categoria do Cancelamento"
                    placeholder="Selecione o motivo..."
                >
                    <flux:select.option value="Erro de lançamento / duplicidade">Erro de lançamento / duplicidade</flux:select.option>
                    <flux:select.option value="Desistência do dentista / paciente">Desistência do dentista / paciente</flux:select.option>
                    <flux:select.option value="Moldagem / escaneamento inviável">Moldagem / escaneamento inviável</flux:select.option>
                    <flux:select.option value="Outros">Outros</flux:select.option>
                </flux:select>

                <flux:textarea
                    wire:model="cancellationReason"
                    label="Justificativa"
                    placeholder="Explique detalhadamente o motivo do cancelamento (mínimo de 10 caracteres)..."
                    rows="3"
                />

                @if (count($consumptionsList) > 0)
                    <div class="space-y-3 pt-2 border-t border-zinc-200 dark:border-zinc-700">
                        <div>
                            <flux:heading size="sm">Decisão sobre Insumos Consumidos</flux:heading>
                            <flux:text size="xs" class="text-zinc-500">
                                Escolha o destino de cada material consumido nesta ordem.
                            </flux:text>
                        </div>

                        <div class="space-y-3 max-h-60 overflow-y-auto pr-1">
                            @foreach ($consumptionsList as $item)
                                <div class="p-3 rounded-lg border border-zinc-200 dark:border-zinc-800 bg-zinc-50 dark:bg-zinc-900/50 space-y-2">
                                    <div class="flex items-center justify-between text-xs">
                                        <span class="font-medium text-zinc-800 dark:text-zinc-200">{{ $item['product_name'] }}</span>
                                        <span class="text-zinc-500">{{ $item['quantity'] }} {{ $item['unit'] }} @if($item['batch_code']) (Lote: {{ $item['batch_code'] }}) @endif</span>
                                    </div>
                                    <flux:radio.group wire:model="materialActions.{{ $item['id'] }}" variant="cards" class="flex flex-col sm:flex-row gap-2">
                                        <flux:radio value="devolution" label="Estornar ao Estoque" />
                                        <flux:radio value="waste" label="Registrar Perda / Descarte" />
                                    </flux:radio.group>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>

            <div class="flex items-center justify-end gap-2 pt-2">
                <flux:modal.close>
                    <flux:button variant="ghost">Voltar</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="danger" wire:loading.attr="disabled">
                    <span wire:loading.remove>Confirmar Cancelamento da OS</span>
                    <span wire:loading>Processando...</span>
                </flux:button>
            </div>
        </form>
    </flux:modal>
</div>
