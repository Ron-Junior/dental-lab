<?php

use App\Models\RequestService;
use App\Models\Service;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Number;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Validate;
use Livewire\Component;

new class extends Component
{
    public ?int $requestServiceId = null;

    public ?RequestService $requestService = null;

    #[Validate('required|integer|exists:services,id')]
    public ?int $serviceId = null;

    #[Validate('required', 'Preço da Unidade')]
    public ?string $unitPrice = null;

    #[Validate('required|integer|min:1|max:1000000')]
    public ?int $quantity = null;

    #[On('service::update')]
    public function open(int $requestServiceId): void
    {
        $this->requestServiceId = $requestServiceId;

        $this->requestService = RequestService::find($requestServiceId);

        $this->serviceId = $this->requestService->service_id;
        $this->unitPrice = number_format($this->requestService->unit_price, 2, ',', '.');
        $this->quantity = $this->requestService->quantity;

        Flux::modal('edit-service-modal')->show();
    }

    #[Computed()]
    public function offerServices(): Collection
    {
        return Service::all();
    }

    public function updatedServiceId(string $value): void
    {
        if (!$value) {
            $this->unitPrice = null;
            return;
        }

        $service = Service::find($value);

        $this->unitPrice = number_format($service->price, 2, ',', '.');
    }

    public function save(): void
    {
        $this->validate();

        $this->requestService->unit_price = (float) str_replace(',', '.', str_replace(' ', '', $this->unitPrice));
        $this->requestService->quantity = $this->quantity;
        $this->requestService->service_id = $this->serviceId;

        $this->requestService->save();
   
        Flux::toast(variant: 'success', heading: 'Sucesso!', text: 'Serviço atualizado com sucesso!');
        Flux::modal('edit-service-modal')->close();

        $this->reset();
        $this->dispatch('requests::refresh');
    }
};
?>

<div>
    <flux:modal class="md:w-lg space-y-4" flyout variant="floating" name="edit-service-modal">
        <div>
            <flux:heading size="lg">Novo Serviço</flux:heading>
            <flux:text>Adicione um novo serviço ao laboratório.</flux:text>
            {{ $this->unitPrice }}
        </div>

        <div class="space-y-4">
            <flux:select label="Serviço" wire:model.live="serviceId">
                <flux:select.option value="0">Selecione um Serviço</flux:select.option>
                @foreach ($this->offerServices as $offerService)
                    <flux:select.option value="{{ $offerService->id }}">{{ $offerService->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:input mask:dynamic="$money($input, ',', ' ')" label="Preço Unitário" wire:model="unitPrice" readonly />            
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