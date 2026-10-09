<?php

use App\Models\ProductCategory;
use Flux\Flux;
use Livewire\Attributes\On;
use Livewire\Attributes\Validate;
use Livewire\Component;

new class extends Component
{
    #[Validate('required|min:3|max:255')]
    public ?string $name = null;

    public ?ProductCategory $category = null;

    #[On('category::edit')]
    public function edit(?int $id = null): void
    {
        $this->category = ProductCategory::find($id);
        $this->name = $this->category->name;

        Flux::modal('store-product-category-modal')->show();
    }

    public function closeModal(): void
    {
        $this->reset('name', 'category');
        $this->resetValidation();
        Flux::modals()->close();
    }

    public function save(): void
    {
        $this->validate();

        $this->category 
            ? $this->category->update(['name' => $this->name]) 
            : ProductCategory::create(['name' => $this->name]);

        $this->dispatch('category::refresh');
        Flux::toast(variant: 'success', text: 'Categoria salva com sucesso!');
        $this->closeModal();
    }
};
?>

<div>
    <flux:modal class="backdrop:backdrop-blur-sm md:w-lg" name="store-product-category-modal" flyout variant="floating" @close="closeModal">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">Nova Categoria</flux:heading>
                <flux:subheading>Adicione uma nova categoria em seu catálogo.</flux:subheading>
            </div>
            <form class="flex flex-col gap-5" wire:submit="save">
                <flux:input wire:model="name" label="Nome" placeholder="Digite o nome da categoria" />

                <div class="self-end">
                    <flux:button type="submit">Salvar</flux:button>
                </div>
            </form>

        </div>
    </flux:modal>
</div>