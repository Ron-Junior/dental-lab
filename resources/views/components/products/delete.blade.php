<?php

use App\Models\Product;
use Flux\Flux;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component
{
    public ?Product $product = null;

    #[On('product::delete')]
    public function confirmDelete(int $productId): void
    {
        $product = Product::findOrFail($productId);
        $this->authorize('delete', $product);

        $this->product = $product;

        Flux::modal('delete-product-modal')->show();
    }

    public function delete(): void
    {
        abort_if(! $this->product, 404);

        $this->authorize('delete', $this->product);

        $this->product->delete();

        $this->dispatch('products::refresh');
        Flux::toast(variant: 'success', heading: 'Sucesso!', text: 'Produto deletado com sucesso!');
        Flux::modals()->close();
    }
};
?>

<div>
    <flux:modal class="backdrop:backdrop-blur-sm" name="delete-product-modal" title="Excluir Produto">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">Deletar Produto</flux:heading>
                <flux:subheading>Tem certeza de que deseja deletar este produto?</flux:subheading>
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