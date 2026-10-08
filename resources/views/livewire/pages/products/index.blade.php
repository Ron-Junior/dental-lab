<?php

use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    public string $filteringName = '';

    public array $filteringCategories = [];

    #[Computed()]
    public function products(): LengthAwarePaginator
    {
        return Product::query()
            ->search($this->filteringName)
            ->searchCategory($this->filteringCategories)
            ->paginate(15);
    }

    #[Computed]
    public function categories(): array
    {
        return ProductCategory::get()
            ->map(fn (ProductCategory $category) => [
                'value' => $category->id,
                'name' => $category->name,
            ])
            ->toArray();
    }
};
?>

<div>
    <div class="flex justify-between mb-5">
        <div>
            <flux:heading size="xl">Produtos</flux:heading>
            <flux:text class="mt-2">Os produtos oferecidos pelo laboratório.</flux:text>
        </div>
        <flux:modal.trigger name="store-product-modal">
            <flux:button icon="plus">Novo Produto</flux:button>
        </flux:modal.trigger>
    </div>

    <div class="w-full flex gap-2">
        <div class="flex-1">
            <flux:input placeholder="Digite o nome do produto" wire:model.live.debounce.500ms="filteringName" />
        </div>
        <div>
            <x-form.multiselect class="min-w-64 max-w-64" :options="$this->categories" placeholder="Categorias" wire:model.live.debounce.500ms="filteringCategories" />
        </div>
    </div>

    <flux:table :paginate="$this->products">
        <flux:table.columns>
            <flux:table.column>Nome</flux:table.column>
            <flux:table.column>Unidade de Medida</flux:table.column>
            <flux:table.column>Estoque Atual</flux:table.column>
            <flux:table.column>Estoque Mínimo</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @foreach ($this->products as $product)
                <flux:table.row :key="$product->id">
                    <flux:table.cell>
                        {{ $product->name }}
                    </flux:table.cell>
                    <flux:table.cell>
                        {{ $product->unit_of_measure }}
                    </flux:table.cell>
                    <flux:table.cell>
                        @if ($product->current_stock < $product->min_stock)
                            <flux:text class="text-red-500 flex items-center gap-2">
                                {{ $product->current_stock }} 
                                <flux:icon class="size-5" name="exclamation-triangle" />
                            </flux:text>
                        @else
                            {{ $product->current_stock }}
                        @endif
                    </flux:table.cell>
                    <flux:table.cell>
                        {{ $product->min_stock }}
                    </flux:table.cell>
                    <flux:table.cell class="py-0">
                        <div class="flex justify-end gap-3">
                            <flux:button wire:click="dispatch('product::edit', '{{ $product->id }}')" icon="pencil" variant="ghost"></flux:button>
                            <flux:button wire:click="dispatch('product::delete', '{{ $product->id }}')" icon="trash" variant="ghost"></flux:button>
                        </div>
                    </flux:table.cell>
                </flux:table.row>
            @endforeach
        </flux:table.rows>
    </flux:table>
</div>