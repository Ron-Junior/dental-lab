<?php

use App\Models\demand;
use App\Models\PartnerDemand;
use App\Models\Service;
use App\Models\ServiceServiceStep;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\WithPagination;

new  class extends Component
{
    use WithPagination;

    public ?int $editingServiceId = null;

    // order by service_service_step.order 
    #[Computed, On('demand::refresh')]
    public function demands(): LengthAwarePaginator 
    {
        return PartnerDemand::with(['requestService.service', 'serviceServiceStep.serviceStep'])
            ->orderBy('order')
            ->paginate(10);
    }
};
?>

<div>
    <div class="flex justify-between mb-5">
        <div>
            <flux:heading size="xl">Demandas</flux:heading>
            <flux:text class="mt-2">Os Demandas cadastrados no sistema.</flux:text>
        </div>
    </div>

    <flux:table :paginate="$this->demands">
        <flux:table.columns>
            <flux:table.column>Demandas</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @foreach ($this->demands as $demand)
                <flux:table.row :key="$demand->id">
                    <flux:table.cell class="flex items-center gap-3">
                        {{ $demand->requestService->service->name }} - {{ $demand->serviceServiceStep->serviceStep->name }}
                    </flux:table.cell>
                    <flux:table.cell class="py-0">
                        <flux:button wire:click="dispatch('demand::edit', '{{ $demand->id }}')" icon="pencil" variant="ghost"></flux:button>
                        <flux:button wire:click="dispatch('demand::delete', '{{ $demand->id }}')" icon="trash" variant="ghost"></flux:button>
                    </flux:table.cell>
                </flux:table.row>
            @endforeach
        </flux:table.rows>
    </flux:table>
{{-- 
    <livewire:demands.store/>
    <livewire:demands.delete/> --}}
</div>