<?php

use App\Models\Product;
use App\Models\ProductCategory;
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

    #[Computed, On('products::refresh')]
    public function products(): LengthAwarePaginator
    {
        return Product::query()
            ->search($this->filteringName)
            ->searchCategory($this->filteringCategories)
            ->orderBy('name')
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
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-5">
        <div>
            <flux:heading size="xl">Produtos</flux:heading>
            <flux:text class="mt-1 sm:mt-2">Os produtos oferecidos pelo laboratório.</flux:text>
        </div>
        <flux:modal.trigger name="store-product-modal">
            <flux:button icon="plus" class="w-full sm:w-auto">Novo Produto</flux:button>
        </flux:modal.trigger>
    </div>

    <div class="flex flex-col sm:flex-row gap-3 mb-5">
        <div class="flex-1">
            <flux:input placeholder="Digite o nome do produto" wire:model.live.debounce.500ms="filteringName" icon="magnifying-glass" />
        </div>
        <div class="w-full sm:w-64">
            <x-form.multiselect class="w-full" :options="$this->categories" placeholder="Categorias" wire:model.live.debounce.500ms="filteringCategories" />
        </div>
    </div>

    <!-- Desktop Table View -->
    <div class="hidden md:block bg-white dark:bg-zinc-900 rounded-xl border border-zinc-200 dark:border-zinc-800 overflow-hidden shadow-xs">
        <flux:table :paginate="$this->products">
            <flux:table.columns>
                <flux:table.column>Nome</flux:table.column>
                <flux:table.column>Unidade de Medida</flux:table.column>
                <flux:table.column>Estoque Atual</flux:table.column>
                <flux:table.column>Estoque Mínimo</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($this->products as $product)
                    <flux:table.row :key="$product->id">
                        <flux:table.cell>
                            <div class="flex items-center gap-2">
                                <span class="font-medium text-zinc-900 dark:text-zinc-100">{{ $product->name }}</span>
                                @if ($product->has_batches)
                                    <flux:badge size="xs" color="sky" icon="archive-box">Lote</flux:badge>
                                @endif
                            </div>
                        </flux:table.cell>
                        <flux:table.cell>
                            {{ $product->unit_of_measure?->label() }}
                        </flux:table.cell>
                        <flux:table.cell>
                            @if ($product->current_stock <= 0)
                                <flux:badge color="red" size="sm" icon="exclamation-circle">
                                    0.00 {{ $product->unit_of_measure?->value }} (Sem Estoque)
                                </flux:badge>
                            @elseif ($product->current_stock <= $product->min_stock)
                                <flux:badge color="amber" size="sm" icon="exclamation-triangle">
                                    {{ $product->current_stock }} {{ $product->unit_of_measure?->value }} (Baixo)
                                </flux:badge>
                            @else
                                <span class="font-medium text-zinc-800 dark:text-zinc-200">
                                    {{ $product->current_stock }} {{ $product->unit_of_measure?->value }}
                                </span>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell>
                            {{ $product->min_stock }} {{ $product->unit_of_measure?->value }}
                        </flux:table.cell>
                        <flux:table.cell class="py-0">
                            <div class="flex justify-end gap-1">
                                <flux:button wire:click="dispatch('stock-movement::create', { productId: {{ $product->id }} })" icon="arrows-up-down" variant="ghost" title="Movimentar Estoque"></flux:button>
                                <flux:button wire:click="dispatch('product::edit', '{{ $product->id }}')" icon="pencil" variant="ghost" title="Editar Produto"></flux:button>
                                <flux:button wire:click="dispatch('product::delete', '{{ $product->id }}')" icon="trash" variant="ghost" title="Excluir Produto"></flux:button>
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="5">
                            <x-empty-state
                                inset
                                icon="rectangle-stack"
                                title="Nenhum produto encontrado"
                                description="Verifique os filtros ou cadastre um novo produto."
                            />
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </div>

    <!-- Mobile Cards View -->
    <div class="md:hidden space-y-3">
        @forelse ($this->products as $product)
            <div class="rounded-xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-4 space-y-3 shadow-xs">
                <div class="flex items-start justify-between gap-2">
                    <div>
                        <div class="font-semibold text-zinc-900 dark:text-zinc-100 text-base leading-snug">
                            {{ $product->name }}
                        </div>
                        @if ($product->category)
                            <span class="text-xs text-zinc-400 mt-0.5 block">{{ $product->category->name }}</span>
                        @endif
                    </div>
                    @if ($product->has_batches)
                        <flux:badge size="xs" color="sky" icon="archive-box" class="shrink-0">Lote</flux:badge>
                    @endif
                </div>

                <div class="grid grid-cols-3 gap-2 text-xs bg-zinc-50 dark:bg-zinc-800/50 p-2.5 rounded-lg">
                    <div>
                        <span class="text-zinc-400 uppercase tracking-wider block font-medium">Unidade</span>
                        <span class="font-medium text-xs text-zinc-700 dark:text-zinc-300 block mt-0.5">
                            {{ $product->unit_of_measure?->label() }}
                        </span>
                    </div>
                    <div>
                        <span class="text-zinc-400 uppercase tracking-wider block font-medium">Estoque</span>
                        <span class="font-bold text-sm block mt-0.5 {{ $product->current_stock <= 0 ? 'text-rose-600 dark:text-rose-400' : ($product->current_stock <= $product->min_stock ? 'text-amber-600 dark:text-amber-400' : 'text-zinc-900 dark:text-zinc-100') }}">
                            {{ $product->current_stock }} {{ $product->unit_of_measure?->value }}
                        </span>
                    </div>
                    <div>
                        <span class="text-zinc-400 uppercase tracking-wider block font-medium">Mínimo</span>
                        <span class="font-medium text-xs text-zinc-600 dark:text-zinc-400 block mt-0.5">
                            {{ $product->min_stock }} {{ $product->unit_of_measure?->value }}
                        </span>
                    </div>
                </div>

                @if ($product->current_stock <= 0)
                    <div class="flex items-center gap-1.5 text-xs text-rose-600 dark:text-rose-400 bg-rose-50 dark:bg-rose-950/40 p-2 rounded-lg">
                        <flux:icon class="size-4 shrink-0" name="exclamation-circle" />
                        <span>Material esgotado no laboratório</span>
                    </div>
                @elseif ($product->current_stock <= $product->min_stock)
                    <div class="flex items-center gap-1.5 text-xs text-amber-600 dark:text-amber-400 bg-amber-50 dark:bg-amber-950/40 p-2 rounded-lg">
                        <flux:icon class="size-4 shrink-0" name="exclamation-triangle" />
                        <span>Estoque abaixo do nível mínimo recomendado</span>
                    </div>
                @endif

                <div class="flex items-center justify-between gap-2 pt-2 border-t border-zinc-100 dark:border-zinc-800">
                    <flux:button 
                        wire:click="dispatch('stock-movement::create', { productId: {{ $product->id }} })" 
                        icon="arrows-up-down" 
                        size="sm" 
                        variant="outline" 
                        class="flex-1"
                    >
                        Movimentar
                    </flux:button>
                    <div class="flex items-center gap-1">
                        <flux:button wire:click="dispatch('product::edit', '{{ $product->id }}')" icon="pencil" size="sm" variant="ghost" title="Editar Produto"></flux:button>
                        <flux:button wire:click="dispatch('product::delete', '{{ $product->id }}')" icon="trash" size="sm" variant="ghost" title="Excluir Produto"></flux:button>
                    </div>
                </div>
            </div>
        @empty
            <x-empty-state
                icon="rectangle-stack"
                title="Nenhum produto encontrado"
                description="Verifique os filtros ou cadastre um novo produto."
            />
        @endforelse

        @if ($this->products->hasPages())
            <div class="pt-2">
                {{ $this->products->links() }}
            </div>
        @endif
    </div>

    <livewire:products.store />
    <livewire:products.stock.store />
    <livewire:products.delete />
</div>