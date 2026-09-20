<?php

use App\Models\Service;
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

    #[Computed, On('service::refresh')]
    public function services(): LengthAwarePaginator 
    {
        return Service::paginate(10);
    }
};
?>

<div>
    <div class="flex justify-between mb-5">
        <div>
            <flux:heading size="xl">Serviços</flux:heading>
            <flux:text class="mt-2">Os serviços oferecidos pelo laboratório.</flux:text>
        </div>
        <flux:modal.trigger name="store-service-modal">
            <flux:button icon="plus">Novo Serviço</flux:button>
        </flux:modal.trigger>
    </div>


    <flux:table :paginate="$this->services">
        <flux:table.columns>
            <flux:table.column>Serviço</flux:table.column>
            <flux:table.column>Descrição</flux:table.column>
            <flux:table.column>Preço</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @foreach ($this->services as $service)
                <flux:table.row :key="$service->id">
                    <flux:table.cell class="flex items-center gap-3">
                        {{ $service->name }}
                    </flux:table.cell>
                    <flux:table.cell class="whitespace-nowrap">{{ $service->description }}</flux:table.cell>
                    <flux:table.cell class="whitespace-nowrap">R$ {{ $service->price }}</flux:table.cell>
                    <flux:table.cell class="py-0">
                        <flux:button wire:click="dispatch('service::edit', '{{ $service->id }}')" icon="pencil" variant="ghost"></flux:button>
                        <flux:button wire:click="dispatch('service::delete', '{{ $service->id }}')" icon="trash" variant="ghost"></flux:button>
                    </flux:table.cell>
                </flux:table.row>
            @endforeach
        </flux:table.rows>
    </flux:table>

    <livewire:service.store/>
    <livewire:service.delete/>
</div>