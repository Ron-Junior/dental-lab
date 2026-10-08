<?php

use App\Enums\UnitMeasure;
use App\Models\Product;
use App\Models\ProductCategory;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rules\Enum;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Validate;
use Livewire\Component;

new class extends Component
{
    #[Validate('required|string|min:3|max:255')]
    public ?string $name = null;

    #[Validate('required|exists:product_categories,id')]
    public ?int $categoryId = null;

    #[Validate(['required', new Enum(UnitMeasure::class)])]
    public ?string $unit_of_measure = null;

    #[Validate('required|numeric|min:0')]
    public ?float $minimumStock = 0.00;

    public ?Product $product = null;

    #[On('product::edit')]
    public function edit(?int $productId = null): void
    {
        $this->product = Product::find($productId);
        $this->name = $this->product->name;
        $this->minimumStock = $this->product->min_stock;
        $this->unit_of_measure = $this->product->unit_of_measure->value;
        $this->categoryId = $this->product->category_id;
        
        Flux::modal('store-product-modal')->show();
    }

    #[Computed()]
    public function categories(): Collection
    {
        return ProductCategory::select('id', 'name')->get();
    }

    public function closeModal(): void
    {
        $this->reset();
        $this->resetValidation();

        Flux::modals()->close();
    }

    public function save(): void
    {
        $this->validate();
        
        Product::updateOrCreate(
            ['id' => $this->product?->id],
            [
                'category_id' => $this->categoryId,
                'name' => $this->name,
                'unit_of_measure' => $this->unit_of_measure,
                'min_stock' => $this->minimumStock,
            ],
        );

        $this->closeModal();
        Flux::toast(variant: 'success', text: "Produto salvo com sucesso");
        $this->dispatch('products::refresh');
    }
}
?>

<div>
    <flux:modal class="backdrop:backdrop-blur-sm md:w-lg" name="store-product-modal" flyout variant="floating" @close="closeModal">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">Novo Produto</flux:heading>
                <flux:subheading>Adicione um novo produto em seu catálogo.</flux:subheading>
            </div>
            <form class="space-y-4" wire:submit="save">
                <flux:input
                    wire:model="name"
                    label="Nome"
                    name="name"
                    placeholder="Digite o nome"
                />
                <flux:select
                    wire:model="categoryId"
                    label="Categoria"
                    name="category_id"
                >
                    <flux:select.option value="">Selecione...</flux:select.option>
                    @foreach ($this->categories as $category)
                        <flux:select.option value="{{ $category->id }}">{{ $category->name }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:input
                    wire:model="minimumStock"
                    label="Estoque Mínimo"
                    name="minimumStock"
                    placeholder="Digite o estoque mínimo"
                    step="0.01"
                    type="number"
                />
                <flux:select
                    wire:model="unit_of_measure"
                    label="Unidade de Medida"
                >
                    <flux:select.option value="">Selecione...</flux:select.option>
                    @foreach (UnitMeasure::cases() as $unit)
                        <flux:select.option value="{{ $unit }}">{{ $unit->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
                
                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button variant="ghost">Cancelar</flux:button>
                    </flux:modal.close>
                    <flux:button type="submit">Salvar</flux:button>
                </div>
            </form>
        </div>
    </flux:modal>
</div>