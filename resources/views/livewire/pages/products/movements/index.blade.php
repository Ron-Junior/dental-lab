<?php

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\StockMovement;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    public string $filteringName = '';

    public array $filteringCategories = [];

    #[Computed, On('movements::refresh')]
    public function stockMovements(): LengthAwarePaginator
    {
        return StockMovement::query()
            ->orderBy('created_at')
            ->paginate(15);
    }
};
?>

<div>
    <div class="flex justify-between mb-5">
        <div>
            <flux:heading size="xl">Movimentações do Estoque</flux:heading>
            <flux:text class="mt-2">As movimentações de entrada e saída de produtos do estoque.</flux:text>
        </div>
        <flux:modal.trigger name="store-product-modal">
            <flux:button icon="plus">Nova Movimentação</flux:button>
        </flux:modal.trigger>
    </div>

    <flux:table :paginate="$this->stockMovements">
        <flux:table.columns>
            <flux:table.column>Produto</flux:table.column>
            <flux:table.column>Tipo</flux:table.column>
            <flux:table.column>Quantidade</flux:table.column>
            <flux:table.column>Valor</flux:table.column>
            <flux:table.column>Usuário</flux:table.column>
            <flux:table.column>Data</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @foreach ($this->stockMovements as $movement)
                <flux:table.row :key="$movement->id">
                    <flux:table.cell>
                        {{ $movement->name }}
                    </flux:table.cell>
                    <flux:table.cell>
                        {{ $movement->unit_of_measure }}
                    </flux:table.cell>
                    <flux:table.cell>
                        @if ($movement->current_stock < $movement->min_stock)
                            <flux:text class="text-red-500 flex items-center gap-2">
                                {{ $movement->current_stock }} 
                                <flux:icon class="size-5" name="exclamation-triangle" />
                            </flux:text>
                        @else
                            {{ $movement->current_stock }}
                        @endif
                    </flux:table.cell>
                    <flux:table.cell>
                        {{ $movement->min_stock }}
                    </flux:table.cell>
                    <flux:table.cell class="py-0">
                        <div class="flex justify-end gap-3">
                            <flux:button wire:click="dispatch('movement::edit', '{{ $movement->id }}')" icon="pencil" variant="ghost"></flux:button>
                            <flux:button wire:click="dispatch('movement::delete', '{{ $movement->id }}')" icon="trash" variant="ghost"></flux:button>
                        </div>
                    </flux:table.cell>
                </flux:table.row>
            @endforeach
        </flux:table.rows>
    </flux:table>

    <livewire:products.store />
    <livewire:products.delete />
</div>