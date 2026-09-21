<?php

use App\Models\DentistService;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    #[Computed]
    public function plannedServices(): LengthAwarePaginator
    {
        return DentistService::with('dentist', 'service')->paginate(10);
    }
};

?>
<div>
    <div class="flex justify-between mb-5">
        <div>
            <flux:heading size="xl">Serviços Solicitados</flux:heading>
            <flux:text class="mt-2">Os serviços oferecidos pelo laboratório.</flux:text>
        </div>
        <flux:modal.trigger name="store-service-modal">
            <flux:button icon="plus">Nova Solicitação</flux:button>
        </flux:modal.trigger>
    </div>

    <flux:table :pagination="$this->plannedServices">
        <flux:table.columns>
            <flux:table.column>
                Dentista
            </flux:table.column>
            <flux:table.column>
                Serviço
            </flux:table.column>
            <flux:table.column>
                Preço
            </flux:table.column>
            <flux:table.column>
                Quantidade
            </flux:table.column>
            <flux:table.column>
                Data
            </flux:table.column>
            <flux:table.column>
                Etapa Atual
            </flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @foreach ($this->plannedServices as $plannedService)
                <flux:table.row>
                    <flux:table.cell>
                        {{ $plannedService->dentist->user->name }}
                    </flux:table.cell>
                    <flux:table.cell>
                        {{ $plannedService->service->name }}
                    </flux:table.cell>
                    <flux:table.cell>
                        {{ $plannedService->unit_price }}
                    </flux:table.cell>
                    <flux:table.cell>
                        {{ $plannedService->quantity }}
                    </flux:table.cell>
                    <flux:table.cell>
                        {{ $plannedService->date }}
                    </flux:table.cell>
                    <flux:table.cell>
                        {{ $plannedService->step->name }}
                    </flux:table.cell>
                    <flux:table.cell>
                        <flux:button icon="bolt" wire:click="dispatch('service::step::open', {{ $plannedService->id }})"></flux:button>
                        <flux:button icon="check" wire:click="completeStep({{ $plannedService->id }})"></flux:button>
                    </flux:table.cell>
                </flux:table.row>
            @endforeach
        </flux:table.rows>
    </flux:table>

    <livewire:service.requesting/>
</div>