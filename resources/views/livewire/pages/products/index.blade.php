<?php

use App\Models\ProductCategory;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
};
?>

<div>
    <div class="flex justify-between mb-5">
        <div>
            <flux:heading size="xl">Produtos</flux:heading>
            <flux:text class="mt-2">Os produtos oferecidos pelo laboratório.</flux:text>
        </div>
        <flux:modal.trigger name="store-product-modal">
            <flux:button icon="plus">Novo Produto</flux:button>
        </flux:modal.trigger>
    </div>


</div>