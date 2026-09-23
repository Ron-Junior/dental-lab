<?php

use App\Models\Partner;
use Flux\Flux;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component
{
    public ?Partner $partner = null;

    #[On('partner::delete')]
    public function confirmDelete(int $partnerId): void
    {
        $this->partner = Partner::find($partnerId);

        Flux::modal('delete-partner-modal')->show();
    }

    public function delete(): void
    {
        Partner::whereId($this->partner->id)->delete();

        $this->dispatch('partners::refresh');
        Flux::toast(variant: 'success', heading: 'Sucesso!', text: 'Parceiro deletado com sucesso!');
        Flux::modals()->close();
    }
};
?>

<div>
    <flux:modal name="delete-partner-modal" title="Excluir Parceiro">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">Deletar Parceiro</flux:heading>
                <flux:subheading>Tem certeza de que deseja deletar este parceiro?</flux:subheading>
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