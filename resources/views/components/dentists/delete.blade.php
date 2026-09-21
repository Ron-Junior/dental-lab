<?php

use App\Models\Dentist;
use Flux\Flux;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component
{
    public ?int $dentistId = null;

    #[On('dentist::delete')]
    public function confirmDelete(int $dentistId): void
    {
        $this->dentistId = $dentistId;

        Flux::modal('delete-dentist-modal')->show();
    }

    public function delete(): void
    {
        Dentist::whereId($this->dentistId)->delete();
        $this->dentistId = null;
        $this->dispatch('dentist::refresh');

        Flux::toast(variant: 'success', heading: 'Sucesso!', text: 'Dentista deletado com sucesso!');
        Flux::modals()->close();
    }
};
?>

<div>
    <flux:modal name="delete-dentist-modal" title="Excluir Dentista">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">Deletar Dentista</flux:heading>
                <flux:subheading>Tem certeza de que deseja deletar este dentista?</flux:subheading>
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