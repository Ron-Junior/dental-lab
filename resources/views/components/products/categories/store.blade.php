<?php

use App\Models\ProductCategory;
use Flux\Flux;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component
{
    public ?string $name = null;

    #[Locked]
    public ?ProductCategory $category = null;

    protected function rules(): array
    {
        return [
            'name' => [
                'required',
                'min:3',
                'max:255',
                Rule::unique('product_categories', 'name')->whereNull('deleted_at')->ignore($this->category?->id),
            ],
        ];
    }

    #[On('category::edit')]
    public function edit(?int $id = null): void
    {
        $category = ProductCategory::findOrFail($id);
        $this->authorize('update', $category);

        $this->category = $category;
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
        if ($this->category) {
            $this->authorize('update', $this->category);
        } else {
            $this->authorize('create', ProductCategory::class);
        }

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