<?php

use App\Models\Dentist;
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

    #[Computed, On('dentist::refresh')]
    public function dentists(): LengthAwarePaginator 
    {
        return Dentist::with('user')->paginate(10);
    }
};
?>

<div>
    <div class="flex justify-between mb-5">
        <div>
            <flux:heading size="xl">Dentistas</flux:heading>
            <flux:text class="mt-2">Os Dentistas cadastrados no sistema.</flux:text>
        </div>
        <flux:modal.trigger name="store-dentist-modal">
            <flux:button icon="plus">Novo Dentista</flux:button>
        </flux:modal.trigger>
    </div>


    <flux:table :paginate="$this->dentists">
        <flux:table.columns>
            <flux:table.column>Dentista</flux:table.column>
            <flux:table.column>Email</flux:table.column>
            <flux:table.column>Telefone</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @foreach ($this->dentists as $dentist)
                <flux:table.row :key="$dentist->id">
                    <flux:table.cell class="flex items-center gap-3">
                        {{ $dentist->user->name }}
                    </flux:table.cell>
                    <flux:table.cell class="whitespace-nowrap">{{ $dentist->user->email }}</flux:table.cell>
                    <flux:table.cell class="whitespace-nowrap">{{ $dentist->phone }}</flux:table.cell>
                    <flux:table.cell class="py-0">
                        <flux:button wire:click="dispatch('dentist::edit', '{{ $dentist->id }}')" icon="pencil" variant="ghost"></flux:button>
                        <flux:button wire:click="dispatch('dentist::delete', '{{ $dentist->id }}')" icon="trash" variant="ghost"></flux:button>
                    </flux:table.cell>
                </flux:table.row>
            @endforeach
        </flux:table.rows>
    </flux:table>

    <livewire:dentists.store/>
    <livewire:dentists.delete/>
</div>