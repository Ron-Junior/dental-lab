<?php

use App\Models\Product;
use App\Models\ProductCategory;
use Flux\Flux;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component
{
    public ?ProductCategory $category = null;

    #[On('category::delete')]
    public function open(?int $id = null): void
    {
        $this->category = ProductCategory::find($id);
        Flux::modal('delete-product-category-modal')->show();
    }

    public function closeModal(): void
    {
        $this->reset('category');
        Flux::modals()->close();
    }

    public function delete(): void
    {
        if (! $this->category) {
            return;
        }

        $this->authorize('delete', $this->category);

        DB::transaction(function () {
            Product::withTrashed()->where('category_id', $this->category->id)->update(['category_id' => null]);
            $this->category->delete();
        });

        $this->dispatch('category::refresh');
        Flux::toast(variant: 'success', text: 'Categoria excluída com sucesso!');
        $this->closeModal();
    }
};
?>

<div>
    <flux:modal class="backdrop:backdrop-blur-sm" name="delete-product-category-modal" title="Excluir Categoria" @close="closeModal">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">Deletar Categoria</flux:heading>
                <flux:subheading>Tem certeza de que deseja deletar esta categoria?</flux:subheading>
            </div>

            <div class="flex items-center justify-end gap-2">
                <flux:spacer />
                <flux:modal.close>
                    <flux:button variant="ghost">Cancelar</flux:button>
                </flux:modal.close>
                <flux:button variant="danger" wire:click="delete">Deletar</flux:button>
            </div>
        </div>    
    </flux:modal>
</div>