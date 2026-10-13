<?php

use Flux\Flux;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component
{
    public int $requestServiceId;

    #[On('stock::out')]
    public function open(int $requestServiceId): void
    {
        $this->requestServiceId = $requestServiceId;
        Flux::modal('get-stock-modal')->show();
    }

    public function callStockOut(): void
    {
        Flux::modal('get-stock-modal')->close();
        $this->dispatch('product::stock-out', $this->requestServiceId);
    }
};
?>

<div>
    <flux:modal class="backdrop:backdrop-blur-sm" name="get-stock-modal">
        <div class="space-y-2">
            <flux:text>Gostaria de retirar os itens do estoque?</flux:text>
            <div class="flex justify-end gap-2 mt-4">
                <flux:modal.close>
                    <flux:button variant="ghost">Não</flux:button>
                </flux:modal.close>
                <flux:button wire:click="callStockOut">Sim</flux:button>
            </div>
        </div>
    </flux:modal>
</div>