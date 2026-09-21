<?php

use App\Models\Dentist;
use App\Models\Service;
use Flux\Flux;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Validate;
use Livewire\Component;

new class extends Component
{
    #[Validate(['required', 'exists:dentists,id'])]
    public ?int $dentistId = null;

    #[Validate(['required', 'exists:services,id'])]
    public ?int $serviceId = null;

    #[Validate(['required', 'numeric', 'min:0', 'max:2000000'])]
    public ?float $unitPrice = null;

    #[Validate(['required', 'integer', 'min:1', 'max:1000000'])]
    public ?int $quantity = null;

    public string $searchDentist = '';

    public string $searchService = '';

    #[Computed(true)]
    public function dentists(): Collection
    {
        return Dentist::with('user')->whereRelation('user', 'name', 'like', '%' . $this->searchDentist . '%')->get();
    }

    #[Computed(true)]
    public function services(): Collection
    {
        return Service::all();
    }

    public function updatedServiceId(): void
    {
        $this->unitPrice = $this->services->find($this->serviceId)->price;
    }

    public function addService(): void
    {
        
    }

    
};

?>
<div>
    <flux:modal flyout name="store-service-modal" class="md:w-lg">
        <div class="space-y-6" wire:key="store-service-modal">
            <flux:heading size="lg">Nova Solicitação</flux:heading>
            <flux:subheading>Adicione uma nova solicitação de serviço.</flux:subheading>

            <flux:select label="Dentista" wire:model="dentistId">
                <flux:select.option>Selecione um Dentista</flux:select.option>
                @foreach ($this->dentists as $dentist)
                    <flux:select.option value="{{ $dentist->id }}">{{ $dentist->user->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:heading size="lg">Serviços</flux:heading>
            <flux:card>
                <div class="space-y-4">

                    <flux:select label="Serviço" wire:model.live="serviceId">
                        <flux:select.option>Selecione um Serviço</flux:select.option>
                        @foreach ($this->services as $service)
                            <flux:select.option value="{{ $service->id }}">{{ $service->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
        
                    @if ($serviceId)
                        <flux:input mask:dynamic="$money($input)" label="Preço Unitário" type="number" wire:model.number="unitPrice" readonly :value="(string) $unitPrice"/>
                    @endif
        
                    <flux:input label="Quantidade" type="number" wire:model="quantity" />

                    <flux:button wire:click="removeService" variant="outline" icon="trash" class="w-full">
                        Remover Serviço
                    </flux:button>
                </div>
            </flux:card>

            <flux:button class="w-full" icon="plus" variant="outline" wire:click="addService">Adicionar Serviço</flux:button>

            <div class="flex items-center justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">Cancelar</flux:button>
                </flux:modal.close>
                <flux:button type="submit">Salvar</flux:button>
            </div>
        </div>
    </flux:modal>
</div>