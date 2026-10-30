<?php

use App\Models\ProductCategory;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;
    
    #[Computed(), On('category::refresh')]
    public function categories(): LengthAwarePaginator
    {
        return ProductCategory::orderBy('name')->paginate(15);
    }
};
?>

<div>
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-5">
        <div>
            <flux:heading size="xl">Categorias de Produtos</flux:heading>
            <flux:text class="mt-1 sm:mt-2">As categorias de produtos oferecidos pelo laboratório.</flux:text>
        </div>
        <flux:modal.trigger name="store-product-category-modal">
            <flux:button icon="plus" class="w-full sm:w-auto">Nova Categoria</flux:button>
        </flux:modal.trigger>
    </div>

    <!-- Desktop Table View -->
    <div class="hidden md:block bg-white dark:bg-zinc-900 rounded-xl border border-zinc-200 dark:border-zinc-800 overflow-hidden shadow-xs">
        <flux:table :paginate="$this->categories">
            <flux:table.columns>
                <flux:table.column>Nome</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($this->categories as $category)
                    <flux:table.row :key="$category->id">
                        <flux:table.cell class="flex items-center gap-3">
                            <span class="font-medium text-zinc-900 dark:text-zinc-100">{{ $category->name }}</span>
                        </flux:table.cell>
                        <flux:table.cell class="py-0">
                            <div class="flex justify-end gap-2">
                                <flux:button wire:click="dispatch('category::edit', '{{ $category->id }}')" icon="pencil" variant="ghost" title="Editar Categoria"></flux:button>
                                <flux:button wire:click="dispatch('category::delete', '{{ $category->id }}')" icon="trash" variant="ghost" title="Excluir Categoria"></flux:button>
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="2">
                            <x-empty-state
                                inset
                                icon="tag"
                                title="Nenhuma categoria encontrada"
                                description="Cadastre uma nova categoria para organizar seus produtos."
                            />
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </div>

    <!-- Mobile Cards View -->
    <div class="md:hidden space-y-3">
        @forelse ($this->categories as $category)
            <div class="rounded-xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-4 flex items-center justify-between gap-3 shadow-xs">
                <div class="flex items-center gap-2.5 min-w-0">
                    <div class="rounded-lg bg-zinc-100 dark:bg-zinc-800 p-2 text-zinc-500 shrink-0">
                        <flux:icon class="size-4" name="tag" />
                    </div>
                    <span class="font-semibold text-zinc-900 dark:text-zinc-100 truncate text-base">
                        {{ $category->name }}
                    </span>
                </div>
                <div class="flex items-center gap-1 shrink-0">
                    <flux:button wire:click="dispatch('category::edit', '{{ $category->id }}')" icon="pencil" size="sm" variant="ghost" title="Editar"></flux:button>
                    <flux:button wire:click="dispatch('category::delete', '{{ $category->id }}')" icon="trash" size="sm" variant="ghost" title="Excluir"></flux:button>
                </div>
            </div>
        @empty
            <x-empty-state
                icon="tag"
                title="Nenhuma categoria encontrada"
                description="Cadastre uma nova categoria para organizar seus produtos."
            />
        @endforelse

        @if ($this->categories->hasPages())
            <div class="pt-2">
                {{ $this->categories->links() }}
            </div>
        @endif
    </div>

    <livewire:products.categories.store />
    <livewire:products.categories.delete />
</div>