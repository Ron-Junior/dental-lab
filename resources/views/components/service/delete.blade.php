<?php

use App\Enums\Rules;
use App\Models\Service;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component
{
    public ?int $serviceId = null;

    #[On('service::delete')]
    public function confirmDelete($id): void
    {
        $this->serviceId = $id;
        Flux::modal('delete-service-modal')->show();
    }

    public function delete(): void
    {
        $this->authorize('delete', Service::class);
        
        Service::find($this->serviceId)->delete();
        $this->dispatch('service::refresh');
        Flux::modals()->close();
        Flux::toast(variant: 'success', heading: 'Sucesso!', text: 'Serviço deletado com sucesso!');
    }
};
?>

<div>
    <flux:modal name="delete-service-modal" >
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">Deletar Serviço</flux:heading>
                <flux:subheading>Tem certeza de que deseja deletar este serviço?</flux:subheading>
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