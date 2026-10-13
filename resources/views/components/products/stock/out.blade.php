<?php

use App\Actions\StockMovement;
use App\Enums\StockMovementsReasons;
use App\Enums\StockMovementsTypes;
use App\Models\DentistRequest;
use App\Models\Product;
use App\Models\RequestService;
use Flux\Flux;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component
{
    public ?DentistRequest $dentistRequest = null;

    public array $selectedProducts = [
        [
            'id' => null,
            'quantity' => null,
            'unit_of_measure' => null,
            'batch_number' => null,
            'min_stock' => null,
            'has_batches' => false,
            'current_stock' => null,
        ]
    ];

    public function rules()
    {
        return [
            'selectedProducts' => 'array',
            'selectedProducts.*.id' => 'required',
            'selectedProducts.*.quantity' => 'required|lt:selectedProducts.*.current_stock',
            'selectedProducts.*.unit_of_measure' => 'required',
            'selectedProducts.*.batch_number' => 'required',
        ];
    }

    public function messages()
    {
        return [
            'selectedProducts.*.id.required' => 'Produto é obrigatório',
            'selectedProducts.*.quantity.required' => 'Quantidade é obrigatória',
            'selectedProducts.*.quantity.lte' => 'Quantidade deve ser menor ou igual ao estoque atual',
            'selectedProducts.*.unit_of_measure.required' => 'Unidade de medida é obrigatória',
            'selectedProducts.*.batch_number.required' => 'Lote é obrigatório',
        ];
    }
    
    #[Computed()]
    public function products(): Collection
    {
        return Product::with('batches')->orderBy('name')->get();
    }

    #[On('product::stock-out')]
    public function open(int $requestServiceId): void
    {
        $requestService = RequestService::with('dentistRequest')->find($requestServiceId);

        $this->dentistRequest = $requestService?->dentistRequest;
        
        Flux::modal('product-stock-out-modal')->show();
    }

    public function closeModal(): void
    {
        $this->reset('dentistRequest');
        $this->resetValidation();

        Flux::modals()->close();
    }

    public function removeProduct(int $index): void
    {
        unset($this->selectedProducts[$index]);
        $this->selectedProducts = array_values($this->selectedProducts);
    }

    public function addProduct(): void
    {
        $this->selectedProducts[] = [
            'id' => null,
            'quantity' => null,
            'unit_of_measure' => null,
            'stock_batch_id' => null,
            'batch_number' => null,
            'min_stock' => null,
            'has_batches' => false,
            'current_stock' => null,
        ];
    }

    public function updatedSelectedProducts($value, $key): void
    {
        [$index, $property] = explode('.', $key);
        
        if ($property !== 'id') {
            return;
        }
        
        $product = $this->products->firstWhere('id', $value);

        if (!$product) {
            return ;
        }

        $this->selectedProducts[$index]['unit_of_measure'] = $product->unit_of_measure;
        $this->selectedProducts[$index]['has_batches'] = $product->has_batches;
        $this->selectedProducts[$index]['min_stock'] = $product->min_stock;
        $this->selectedProducts[$index]['current_stock'] = $product->current_stock;

        if ($product->has_batches && $product->batches->count()) {
            $batch = $product->batches->sortByDesc('expiration_date')->first();
            
            $this->selectedProducts[$index]['stock_batch_id'] = $batch->id;
            $this->selectedProducts[$index]['batch_number'] = $batch->batch_number;
        }

        $currentStock = (float) $product->current_stock;
        
        if ($currentStock < 1 || !$currentStock) {
            $this->addError('selectedProducts.' . $index . '.id', 'Estoque insuficiente');
        }
    }

    public function save(): void
    {
        $this->validate();

        foreach ($this->selectedProducts as $product) {
            StockMovement::handle($product['id'], [
                'dentist_request_id' => $this->dentistRequest?->id,
                'movementType' => StockMovementsTypes::Out,
                'reason'       => StockMovementsReasons::ServiceConsumption,
                'amount'       => $product['quantity'],
                'notes'        => 'Retirada para uso no setor A',
            ]);
        }

        Flux::toast(text: 'Produto retirado do estoque com sucesso!', variant: 'success');
        
        $this->closeModal();
    }
};
?>

<div>
    <flux:modal class="backdrop:backdrop-blur-sm md:w-lg space-y-4" flyout variant="floating" name="product-stock-out-modal" @close="closeModal">
        <form wire:submit="save" class="space-y-2">
            <flux:heading size="lg">Quais produtos você gostaria de retirar do estoque?</flux:heading>
            @foreach ($selectedProducts as $index => $product)
                <flux:card class="space-y-4">
                    <flux:select label="Produto" wire:model.live="selectedProducts.{{ $index }}.id">
                        <flux:select.option>Selecione um Produto</flux:select.option>
                        @foreach ($this->products as $product)
                            <flux:select.option :value="$product->id">{{ $product->name }}</flux:select.option>
                        @endforeach
                    </flux:select>

                    @if ($selectedProducts[$index]['id'])
                        <div class="rounded-xl bg-zinc-50 dark:bg-zinc-800/60 p-3.5 border border-zinc-200 dark:border-zinc-700/60 text-sm space-y-1">
                            <div class="flex justify-between items-center">
                                <span class="text-zinc-500 dark:text-zinc-400">Unidade de Medida:</span>
                                <span class="font-medium text-zinc-800 dark:text-zinc-200">{{ $selectedProducts[$index]['unit_of_measure'] }}</span>
                            </div>
                            <div class="flex justify-between items-center">
                                <span class="text-zinc-500 dark:text-zinc-400">Estoque Atual:</span>
                                <span class="font-semibold {{ $selectedProducts[$index]['current_stock'] <= $selectedProducts[$index]['min_stock'] ? 'text-amber-600 dark:text-amber-400' : 'text-zinc-800 dark:text-zinc-200' }}">
                                    {{ $selectedProducts[$index]['current_stock'] }} {{ $selectedProducts[$index]['unit_of_measure'] }}
                                </span>
                            </div>
                            <div class="flex justify-between items-center">
                                <span class="text-zinc-500 dark:text-zinc-400">Estoque Mínimo:</span>
                                <span class="text-zinc-700 dark:text-zinc-300">{{ $selectedProducts[$index]['min_stock'] }} {{ $selectedProducts[$index]['unit_of_measure'] }}</span>
                            </div>


                            @if ($selectedProducts[$index]['has_batches'] && $selectedProducts[$index]['batch_number'])
                                <div>
                                    <div class="flex gap-2">
                                        <flux:icon class="size-4" name="archive-box-arrow-down" />
                                        <span>Lote n°:</span>
                                    </div>
                                    <div>
                                        <flux:text class="text-sky-600 dark:text-sky-400">#{{ $selectedProducts[$index]['batch_number'] }}</flux:text>
                                    </div>
                                </div>
                            @endif
                        </div>
                    @endif
    
                    <flux:input label="Quantidade retirada" type="number" wire:model="selectedProducts.{{ $index }}.quantity" />
                    @if (count($selectedProducts) > 1)
                        <flux:button class="w-full" icon="trash" wire:click="removeProduct">
                            Descartar Retirada
                        </flux:button>
                    @endif
                </flux:card>
            @endforeach
            <flux:button class="w-full my-4" wire:key="{{ $index }}" icon="plus" wire:click="addProduct">
                Adicionar Retirada
            </flux:button>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">Cancelar</flux:button>
                </flux:modal.close>
                <flux:button type="submit">Realizar Retirada</flux:button>
            </div>
        </form>
    </flux:modal>
</div>