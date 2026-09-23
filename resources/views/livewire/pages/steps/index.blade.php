<?php

use App\Models\ServiceStep;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;
use Livewire\Attributes\On;
use Livewire\WithPagination;

new  class extends Component
{
    use WithPagination;

    public ?int $editingServiceId = null;

    #[Computed, On('service::refresh')]
    public function steps(): LengthAwarePaginator 
    {
        return ServiceStep::paginate(10);
    }
};
?>

<div>
    <div class="flex justify-between mb-5">
        <div>
            <flux:heading size="xl">Etapas de Serviços</flux:heading>
            <flux:text class="mt-2">As etapas que compõem cada serviço.</flux:text>
        </div>
        <flux:modal.trigger name="store-step-modal">
            <flux:button icon="plus">Nova Etapa</flux:button>
        </flux:modal.trigger>
    </div>


    <flux:table :paginate="$this->steps">
        <flux:table.columns>
            <flux:table.column>Descrição</flux:table.column>
            <flux:table.column>Preço</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @foreach ($this->steps as $step)
                <flux:table.row :key="$step->id">
                    <flux:table.cell class="flex items-center gap-3">
                        {{ $step->name }}
                    </flux:table.cell>
                    <flux:table.cell class="whitespace-nowrap">{{ $step->description }}</flux:table.cell>
                    <flux:table.cell class="py-0">
                        <flux:button wire:click="dispatch('step::edit', '{{ $step->id }}')" icon="pencil" variant="ghost"></flux:button>
                        <flux:button wire:click="dispatch('step::delete', '{{ $step->id }}')" icon="trash" variant="ghost"></flux:button>
                    </flux:table.cell>
                </flux:table.row>
            @endforeach
        </flux:table.rows>
    </flux:table>

    <livewire:service.store/>
    <livewire:service.delete/>
</div>