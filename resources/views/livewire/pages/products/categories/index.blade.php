<?php

use App\Models\ProductCategory;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;
    
    #[Computed()]
    public function categories(): LengthAwarePaginator
    {
        return ProductCategory::paginate(15);
    }
};
?>

<div>
    <div class="flex justify-between mb-5">
        <div>
            <flux:heading size="xl">Categorias de Produtos</flux:heading>
            <flux:text class="mt-2">As categorias de produtos oferecidos pelo laboratório.</flux:text>
        </div>
        <flux:modal.trigger name="store-product-category-modal">
            <flux:button icon="plus">Nova Categoria</flux:button>
        </flux:modal.trigger>
    </div>

    <flux:table :paginate="$this->categories">
        <flux:table.columns>
            <flux:table.column>Nome</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @foreach ($this->categories as $category)
                <flux:table.row :key="$category->id">
                    <flux:table.cell class="flex items-center gap-3">
                        {{ $category->name }}
                    </flux:table.cell>
                    <flux:table.cell class="py-0">
                        <div class="flex justify-end gap-3">
                            <flux:button wire:click="dispatch('category::edit', '{{ $category->id }}')" icon="pencil" variant="ghost"></flux:button>
                            <flux:button wire:click="dispatch('category::delete', '{{ $category->id }}')" icon="trash" variant="ghost"></flux:button>
                        </div>
                    </flux:table.cell>
                </flux:table.row>
            @endforeach
        </flux:table.rows>
    </flux:table>

    <livewire:products.categories.store />
    <livewire:products.categories.delete />
</div>