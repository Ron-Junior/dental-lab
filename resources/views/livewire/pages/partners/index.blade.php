<?php

use App\Models\Dentist;
use App\Models\Partner;
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

    #[Computed, On('partners::refresh')]
    public function partners(): LengthAwarePaginator 
    {
        return Partner::with('user')->paginate(10);
    }
};
?>

<div>
    <div class="flex justify-between mb-5">
        <div>
            <flux:heading size="xl">Parceiros</flux:heading>
            <flux:text class="mt-2">Os Parceiros cadastrados no sistema.</flux:text>
        </div>
        <flux:modal.trigger name="store-partner-modal">
            <flux:button icon="plus">Novo Parceiro</flux:button>
        </flux:modal.trigger>
    </div>


    <flux:table :paginate="$this->partners">
        <flux:table.columns>
            <flux:table.column>Parceiro</flux:table.column>
            <flux:table.column>Email</flux:table.column>
            <flux:table.column>Telefone</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @foreach ($this->partners as $partner)
                <flux:table.row :key="$partner->id">
                    <flux:table.cell class="flex items-center gap-3">
                        {{ $partner->user->name }}
                    </flux:table.cell>
                    <flux:table.cell class="whitespace-nowrap">{{ $partner->user->email }}</flux:table.cell>
                    <flux:table.cell class="whitespace-nowrap">{{ $partner->phone }}</flux:table.cell>
                    <flux:table.cell class="py-0">
                        <flux:button wire:click="dispatch('partner::edit', '{{ $partner->id }}')" icon="pencil" variant="ghost"></flux:button>
                        <flux:button wire:click="dispatch('partner::delete', '{{ $partner->id }}')" icon="trash" variant="ghost"></flux:button>
                    </flux:table.cell>
                </flux:table.row>
            @endforeach
        </flux:table.rows>
    </flux:table>

    <livewire:partners.store/>
    <livewire:partners.delete/>
</div>