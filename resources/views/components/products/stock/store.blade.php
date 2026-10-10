<?php

use App\Enums\UnitMeasure;
use App\Enums\StockMovementsTypes;
use App\Livewire\Forms\StockMovementForm;
use App\Models\Product;
use Flux\Flux;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Validate;
use Livewire\Component;

new class extends Component
{
    public StockMovementForm $form;

    public function closeModal(): void
    {
        $this->reset();
        $this->resetValidation();

        Flux::modals()->close();
    }

    #[Computed(true)]
    public function products(): Collection
    {
        return Product::select('name', 'id', 'unit_of_measure', 'current_stock', 'has_batches')->orderBy('name')->get();
    }

    #[Computed]
    public function selectedProduct(): ?Product
    {
        $product = $this->products->firstWhere('id', $this->form->productId);
        $this->form->hasBatches = $product?->has_batches ?? false;

        return $product;
    }

    #[Computed]
    public function unitCost(): string
    {
        if (!$this->form->cost || !$this->form->amount) {
            return number_format(0, 2, ',', '.');
        }

        $cost = (float) str_replace(',', '.', str_replace('.', '', $this->form->cost));
        $cost = $cost / $this->form->amount;

        if ($this->form->quantityPerUnit) {
            $cost = $cost / $this->form->quantityPerUnit;
        }

        return number_format($cost, 2, ',', '.');
    }
    
    public function save(): void
    {
        $this->validate();

    }
}
?>

<div>
    <flux:modal class="backdrop:backdrop-blur-sm md:w-lg" name="store-stock-movement-modal" flyout variant="floating" @close="closeModal">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">Nova Movimentação</flux:heading>
                <flux:subheading>Adicione uma nova movimentação ao estoque.</flux:subheading>
            </div>
            <form class="space-y-4" wire:submit="save">
                <flux:select label="Product" name="productId" wire:model.live="form.productId">
                    <option value="" selected>Selecione um produto</option>
                    @foreach ($this->products as $product)
                        <option wire:key="{{ $product->id }}" value="{{ $product->id }}">{{ $product->name }}</option>
                    @endforeach
                </flux:select>
                @if ($this->selectedProduct)
                    <div>
                        <flux:text>Unidade de Medida: {{ $this->selectedProduct?->unit_of_measure }}</flux:text>
                        <flux:text>Estoque Atual: {{ $this->selectedProduct->current_stock }}</flux:text>
                    </div>
                @endif

                <flux:select label="Tipo" name="type" wire:model.live="form.movementType">
                    <option value="" selected >Selecione o Tipo</option>
                    @foreach (StockMovementsTypes::cases() as $type)
                        <option wire:key="{{ $type->value }}" value="{{ $type }}">{{ $type->label() }}</option>
                    @endforeach
                </flux:select>

                <flux:select label="Motivo" name="reason" wire:model="form.reason" :disabled="!$form->movementType">
                    <option value="" selected>Selecione o Motivo</option>
                    @foreach ($form->movementType->getReasons() as $reason)
                        <option wire:key="{{ $reason->value }}" value="{{ $reason }}">{{ $reason->label() }}</option>
                    @endforeach
                </flux:select>

                <flux:input label="Identificador Externo" wire:model="form.externalIdentifier" placeholder="Ex: Fornecedor / Nota Fiscal" />

                <flux:input label="Quantidade de unidades" wire:model.live.debounce.500ms="form.amount" type="number" />
                @if ($this->selectedProduct?->unit_of_measure !== UnitMeasure::Unit)
                    <flux:input label="Quantidade por Unidade" wire:model.live.debounce.500ms="form.quantityPerUnit" type="number">
                        <x-slot name="iconTrailing">
                            {{ $this->selectedProduct?->unit_of_measure->label() }}
                        </x-slot>
                    </flux:input>
                @endif

                <flux:input mask:dynamic="$money($input, ',', '.', 2)" wire:model.live.debounce.500ms="form.cost" label="Preço Total" />

                @if ($this->selectedProduct?->has_batches)
                    <flux:heading size="lg">Lote (Obrigatório para esse produto)</flux:heading>
                    <div class="border rounded-2xl p-4 space-y-4">
                        <flux:input wire:model="form.batchNumber" label="Número do Lote" placeholder="Ex: LOTE-001" />
                        <flux:input wire:model="form.expirationDate" label="Data de Validade" type="date" />
                    </div>
                @endif

                <flux:text>Preço unitário: {{ $this->unitCost }}</flux:text>
            </form>
        </div>
    </flux:modal>
</div>