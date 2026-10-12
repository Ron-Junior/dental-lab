<?php

use App\Enums\StockMovementsReasons;
use App\Enums\StockMovementsTypes;
use App\Enums\UnitMeasure;
use App\Livewire\Forms\StockMovementForm;
use App\Models\Product;
use App\Models\StockBatch;
use Flux\Flux;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component
{
    public StockMovementForm $form;

    #[On('stock-movement::create')]
    public function openForProduct(?int $productId = null): void
    {
        $this->form->reset();
        $this->resetValidation();

        if ($productId) {
            $this->form->productId = $productId;
            $product = Product::find($productId);
            $this->form->hasBatches = $product?->has_batches ?? false;
        }

        Flux::modal('store-stock-movement-modal')->show();
    }

    public function closeModal(): void
    {
        $this->form->reset();
        $this->resetValidation();

        Flux::modals()->close();
    }

    #[Computed(true)]
    public function products(): Collection
    {
        return Product::select('id', 'name', 'unit_of_measure', 'current_stock', 'min_stock', 'has_batches')
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function selectedProduct(): ?Product
    {
        if (! $this->form->productId) {
            return null;
        }

        $product = Product::find($this->form->productId);
        $this->form->hasBatches = $product?->has_batches ?? false;

        return $product;
    }

    #[Computed]
    public function availableBatches(): Collection
    {
        if (! $this->form->productId) {
            return collect();
        }

        return StockBatch::where('product_id', $this->form->productId)
            ->where('current_quantity', '>', 0)
            ->orderBy('expiration_date')
            ->get();
    }

    #[Computed]
    public function calculatedTotalQuantity(): float
    {
        $amount = (float) ($this->form->amount ?? 0);
        $qtyPerUnit = (float) ($this->form->quantityPerUnit ?? 0);

        if ($this->selectedProduct?->unit_of_measure !== UnitMeasure::Unit && $qtyPerUnit > 0) {
            return round($amount * $qtyPerUnit, 2);
        }

        return round($amount, 2);
    }

    #[Computed]
    public function unitCost(): string
    {
        if (! $this->form->cost || ! $this->form->amount) {
            return '0,00';
        }

        $rawCost = str_replace(['R$', ' '], '', $this->form->cost);
        if (str_contains($rawCost, ',')) {
            $rawCost = str_replace('.', '', $rawCost);
            $rawCost = str_replace(',', '.', $rawCost);
        }
        $cost = (float) $rawCost;

        $totalQty = $this->calculatedTotalQuantity;
        if ($totalQty <= 0) {
            return '0,00';
        }

        return number_format($cost / $totalQty, 2, ',', '.');
    }

    public function updatedFormMovementType(): void
    {
        $this->form->reason = null;
        $this->form->stockBatchId = null;
    }

    public function save(): void
    {
        $this->form->save();

        $type = $this->form->getTypeEnum();
        $actionText = match ($type) {
            StockMovementsTypes::In => 'adicionados ao estoque',
            StockMovementsTypes::Out => 'removidos do estoque',
            default => 'ajustados no estoque',
        };

        $this->closeModal();

        Flux::toast(
            variant: 'success',
            text: "Itens {$actionText} com sucesso!"
        );

        $this->dispatch('movements::refresh');
        $this->dispatch('products::refresh');
    }
};
?>

<div>
    <flux:modal class="backdrop:backdrop-blur-sm md:w-xl" name="store-stock-movement-modal" flyout variant="floating" @close="closeModal">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">Nova Movimentação de Estoque</flux:heading>
                <flux:subheading>Registre entradas, saídas e ajustes de materiais do laboratório.</flux:subheading>
            </div>

            <form class="space-y-4" wire:submit="save">
                <!-- Seleção do Produto -->
                <flux:select label="Produto" name="productId" wire:model.live="form.productId" placeholder="Selecione um produto">
                    <option value="">Selecione um produto</option>
                    @foreach ($this->products as $product)
                        <option wire:key="prod-{{ $product->id }}" value="{{ $product->id }}">
                            {{ $product->name }} (Atual: {{ $product->current_stock }} {{ $product->unit_of_measure?->value }})
                        </option>
                    @endforeach
                </flux:select>

                <!-- Informações do Produto Selecionado -->
                @if ($this->selectedProduct)
                    <div class="rounded-xl bg-zinc-50 dark:bg-zinc-800/60 p-3.5 border border-zinc-200 dark:border-zinc-700/60 text-sm space-y-1">
                        <div class="flex justify-between items-center">
                            <span class="text-zinc-500 dark:text-zinc-400">Unidade de Medida:</span>
                            <span class="font-medium text-zinc-800 dark:text-zinc-200">{{ $this->selectedProduct->unit_of_measure?->label() }}</span>
                        </div>
                        <div class="flex justify-between items-center">
                            <span class="text-zinc-500 dark:text-zinc-400">Estoque Atual:</span>
                            <span class="font-semibold {{ $this->selectedProduct->current_stock <= $this->selectedProduct->min_stock ? 'text-amber-600 dark:text-amber-400' : 'text-zinc-800 dark:text-zinc-200' }}">
                                {{ $this->selectedProduct->current_stock }} {{ $this->selectedProduct->unit_of_measure?->value }}
                            </span>
                        </div>
                        <div class="flex justify-between items-center">
                            <span class="text-zinc-500 dark:text-zinc-400">Estoque Mínimo:</span>
                            <span class="text-zinc-700 dark:text-zinc-300">{{ $this->selectedProduct->min_stock }} {{ $this->selectedProduct->unit_of_measure?->value }}</span>
                        </div>
                        @if ($this->selectedProduct->has_batches)
                            <div class="flex items-center gap-1.5 pt-1 text-xs text-sky-600 dark:text-sky-400">
                                <flux:icon class="size-4" name="check-badge" />
                                <span>Controle de lote e validade ativo para este material</span>
                            </div>
                        @endif
                    </div>
                @endif

                <!-- Tipo de Movimentação -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <flux:select label="Tipo de Movimentação" name="movementType" wire:model.live="form.movementType">
                        @foreach (StockMovementsTypes::cases() as $type)
                            <option wire:key="type-{{ $type->value }}" value="{{ $type->value }}">
                                {{ $type->label() }}
                            </option>
                        @endforeach
                    </flux:select>

                    <!-- Motivo -->
                    <flux:select label="Motivo" name="reason" wire:model="form.reason">
                        <option value="">Selecione o motivo</option>
                        @foreach ($form->getAvailableReasons() as $reason)
                            <option wire:key="reason-{{ $reason->value }}" value="{{ $reason->value }}">
                                {{ $reason->label() }}
                            </option>
                        @endforeach
                    </flux:select>
                </div>

                <!-- Identificador Externo -->
                <flux:input 
                    label="Identificador Externo / Referência" 
                    wire:model="form.externalIdentifier" 
                    placeholder="Ex: NF 1042 / Dental Cremer / OS #452" 
                />

                <!-- Quantidades -->
                <div class="grid grid-cols-1 {{ $this->selectedProduct?->unit_of_measure !== UnitMeasure::Unit ? 'sm:grid-cols-2' : '' }} gap-4">
                    <flux:input 
                        label="{{ $this->selectedProduct?->unit_of_measure !== UnitMeasure::Unit ? 'Qtd. de Embalagens / Itens' : 'Quantidade' }}" 
                        wire:model.live.debounce.400ms="form.amount" 
                        type="number" 
                        step="0.01"
                        placeholder="Ex: 5" 
                    />

                    @if ($this->selectedProduct && $this->selectedProduct->unit_of_measure !== UnitMeasure::Unit)
                        <flux:input 
                            label="Conteúdo por Embalagem" 
                            wire:model.live.debounce.400ms="form.quantityPerUnit" 
                            type="number" 
                            step="0.01"
                            placeholder="Ex: 500"
                        >
                            <x-slot name="iconTrailing">
                                {{ $this->selectedProduct->unit_of_measure?->value }}
                            </x-slot>
                        </flux:input>
                    @endif
                </div>

                @if ($this->selectedProduct && $this->calculatedTotalQuantity > 0)
                    <div class="text-xs text-zinc-500 dark:text-zinc-400 bg-zinc-100 dark:bg-zinc-800/40 rounded-lg p-2.5 flex items-center justify-between">
                        <span>Total real a movimentar:</span>
                        <strong class="text-sm font-semibold text-zinc-800 dark:text-zinc-100">
                            {{ $this->calculatedTotalQuantity }} {{ $this->selectedProduct->unit_of_measure?->value }}
                        </strong>
                    </div>
                @endif

                <!-- Preço Total (para entradas ou compras) -->
                @if ($form->getTypeEnum() === StockMovementsTypes::In || $form->getTypeEnum() === StockMovementsTypes::Adjustment)
                    <div class="space-y-1">
                        <flux:input 
                            mask:dynamic="$money($input, ',', '.', 2)" 
                            wire:model.live.debounce.400ms="form.cost" 
                            label="Custo Total (R$)" 
                            placeholder="0,00"
                        />
                        <div class="flex justify-between text-xs text-zinc-500 pt-1">
                            <span>Custo por {{ $this->selectedProduct?->unit_of_measure?->value ?? 'un' }}:</span>
                            <span class="font-medium text-zinc-700 dark:text-zinc-300">R$ {{ $this->unitCost }}</span>
                        </div>
                    </div>
                @endif

                <!-- Controle de Lotes para Produtos com Lote -->
                @if ($this->selectedProduct?->has_batches)
                    <div class="border border-zinc-200 dark:border-zinc-700 rounded-xl p-4 space-y-3 bg-zinc-50/50 dark:bg-zinc-900/40">
                        <div class="flex items-center gap-2">
                            <flux:icon class="size-4 text-sky-500" name="archive-box" />
                            <flux:heading size="sm">Rastreabilidade de Lote (ANVISA)</flux:heading>
                        </div>

                        @if ($form->getTypeEnum() === StockMovementsTypes::In)
                            <!-- Cadastro de Novo Lote na Entrada -->
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <flux:input wire:model="form.batchNumber" label="Número do Lote" placeholder="Ex: LOT-2026-A" />
                                <flux:input wire:model="form.expirationDate" label="Data de Validade" type="date" />
                            </div>
                        @else
                            <!-- Seleção de Lote Existente na Saída ou Ajuste -->
                            <div>
                                <flux:select label="Lote de Origem" wire:model="form.stockBatchId" placeholder="Selecione o lote">
                                    <option value="">Selecione o lote do material</option>
                                    @foreach ($this->availableBatches as $batch)
                                        <option wire:key="batch-{{ $batch->id }}" value="{{ $batch->id }}">
                                            Lote: {{ $batch->batch_number }} | Saldo: {{ $batch->current_quantity }} {{ $this->selectedProduct->unit_of_measure?->value }} | Val: {{ $batch->expiration_date?->format('d/m/Y') }}
                                        </option>
                                    @endforeach
                                </flux:select>
                                @if ($this->availableBatches->isEmpty())
                                    <p class="text-xs text-amber-600 dark:text-amber-400 mt-1">
                                        Nenhum lote com saldo disponível para este produto.
                                    </p>
                                @endif
                            </div>
                        @endif
                    </div>
                @endif

                <!-- Observações -->
                <flux:textarea wire:model="form.notes" label="Observações adicionais" placeholder="Detalhes sobre a movimentação, lote ou motivo..." rows="2" />

                <!-- Ações do Modal -->
                <div class="flex items-center justify-end gap-2 pt-2">
                    <flux:modal.close>
                        <flux:button variant="ghost">Cancelar</flux:button>
                    </flux:modal.close>
                    <flux:button type="submit" variant="primary">Confirmar Movimentação</flux:button>
                </div>
            </form>
        </div>
    </flux:modal>
</div>