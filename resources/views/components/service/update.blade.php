<?php

use App\Models\PartnerDemand;
use App\Models\RequestService;
use App\Models\Service;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Validate;
use Livewire\Component;

new class extends Component
{
    #[Locked]
    public ?int $requestServiceId = null;

    #[Validate('required|integer|exists:services,id')]
    public ?int $serviceId = null;

    #[Validate('required', 'Preço da Unidade', onUpdate: false)]
    public ?string $unitPrice = null;

    #[Validate('required|integer|min:1|max:1000000')]
    public ?int $quantity = null;

    #[On('service::update')]
    public function open(int $requestServiceId): void
    {
        $requestService = RequestService::findOrFail($requestServiceId);
        $this->authorize('update', $requestService);

        $this->requestServiceId = $requestServiceId;
        $this->serviceId = $requestService->service_id;
        $this->unitPrice = number_format($requestService->unit_price, 2, ',', '.');
        $this->quantity = $requestService->quantity;

        Flux::modal('edit-service-modal')->show();
    }

    #[Computed()]
    public function offerServices(): Collection
    {
        return Service::all();
    }

    public function updatedServiceId(int|string|null $value): void
    {
        if (! $value) {
            $this->unitPrice = null;

            return;
        }

        $service = Service::find($value);

        if (! $service) {
            $this->unitPrice = null;

            return;
        }

        $this->unitPrice = number_format($service->price, 2, ',', '.');
    }

    public function save(): void
    {
        $this->validate();

        $requestService = RequestService::findOrFail($this->requestServiceId);
        $this->authorize('update', $requestService);

        $hasStartedDemands = $requestService->partnerDemands()
            ->where(fn ($query) => $query->whereNotNull('started_at')->orWhereNotNull('ended_at'))
            ->exists();

        if ($requestService->completed_at !== null || $hasStartedDemands) {
            throw ValidationException::withMessages([
                'serviceId' => 'Não é possível editar este serviço pois as etapas já foram iniciadas pelos técnicos parceiros.',
            ]);
        }

        DB::transaction(function () use ($requestService) {
            $serviceChanged = (int) $requestService->service_id !== (int) $this->serviceId;

            if ($serviceChanged) {
                $requestService->partnerDemands()->delete();

                $newService = Service::with([
                    'serviceSteps' => fn ($query) => $query->orderBy('service_service_step.order'),
                ])->findOrFail($this->serviceId);

                $requestService->partnerDemands()->saveMany(
                    $newService->serviceSteps->map(fn ($step) => new PartnerDemand([
                        'request_service_id' => $requestService->id,
                        'service_service_step_id' => $step->pivot->id,
                        'order' => $step->pivot->order,
                    ]))
                );
            }

            $requestService->update([
                'unit_price' => currency_to_float($this->unitPrice),
                'quantity' => $this->quantity,
                'service_id' => $this->serviceId,
            ]);
        });

        Flux::toast(variant: 'success', heading: 'Sucesso!', text: 'Serviço atualizado com sucesso!');
        Flux::modal('edit-service-modal')->close();

        $this->reset();
        $this->dispatch('requests::refresh');
    }
};
?>

<div>
    <flux:modal class="backdrop:backdrop-blur-sm md:w-lg space-y-4" flyout variant="floating" name="edit-service-modal">
        <div>
            <flux:heading size="lg">Novo Serviço</flux:heading>
            <flux:text>Adicione um novo serviço ao laboratório.</flux:text>
        </div>

        <div class="space-y-4">
            <flux:select label="Serviço" wire:model.live="serviceId">
                <flux:select.option value="0">Selecione um Serviço</flux:select.option>
                @foreach ($this->offerServices as $offerService)
                    <flux:select.option value="{{ $offerService->id }}">{{ $offerService->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:input mask:dynamic="$money($input, ',', '.')" label="Preço Unitário" wire:model="unitPrice" readonly />            
            <flux:input label="Quantidade" type="number" wire:model="quantity" />

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">Cancelar</flux:button>
                </flux:modal.close>
                <flux:button wire:click="save">Salvar</flux:button>
            </div>
        </div>
    </flux:modal>
</div>