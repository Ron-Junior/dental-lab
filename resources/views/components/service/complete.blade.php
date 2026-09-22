<?php

use App\Actions\CompleteService;
use App\Notifications\CompletedRequestNotification;
use App\Models\RequestService;
use Flux\Flux;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component
{
    public ?int $serviceId = null;

    #[On('service::completed')]
    public function open(int $serviceId): void
    {
        $this->serviceId = $serviceId;
        Flux::modal('step-complete-modal')->show();
    }
  
    public function complete(): void
    {
        CompleteService::handle($this->serviceId);
        Flux::modal('step-complete-modal')->close();
        Flux::toast(variant: 'success', heading: 'Sucesso!', text: 'Serviço concluído com sucesso!');
        $this->dispatch('requests::refresh');
    }
};
?>

<div>
    <flux:modal name="step-complete-modal">
        <div class="space-y-5">
            <flux:heading size="lg">Concluir Serviço</flux:heading>
            <flux:subheading>Tem certeza que deseja conluir este serviço?</flux:subheading>

            <div class="flex items-center justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">Cancelar</flux:button>
                </flux:modal.close>
                <flux:button wire:click="complete">Concluir</flux:button>
            </div>
        </div>
    </flux:modal>
</div>