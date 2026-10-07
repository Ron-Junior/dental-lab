<?php

use App\Models\PartnerDemand;
use Flux\Flux;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithFileUploads;

new class extends Component
{
    use WithFileUploads;

    public ?PartnerDemand $partnerDemand = null;

    public $image;

    #[On('finish-demand::open')]
    public function openModal(int $partnerDemandId): void
    {
        $this->partnerDemand = PartnerDemand::with('requestService.partnerDemands')->find($partnerDemandId);

        Flux::modal('finish-demand-modal')->show();
    }

    public function closeModal(): void
    {
        $this->partnerDemand = null;
        Flux::modals()->close();
    }

    public function finishDemand()
    {
        $this->authorize('complete', $this->partnerDemand);
        
        $this->validate([
            'image' => 'nullable|image|max:5128',
        ]);
        
        if ($this->image) {
            $path = $this->image->store('requests/'. $this->partnerDemand->request_service_id);
            $this->partnerDemand->result_photo_path = $path;
        }

        $this->partnerDemand->ended_at = now();
        $this->partnerDemand->save();

        $this->closeModal();
        $this->dispatch('demand::refresh');
        Flux::toast(heading: 'Serviço finalizado com sucesso!', variant: 'success', text: "");
    }
};
?>

<div>
    <flux:modal class="md:w-lg space-y-4" name="finish-demand-modal" @close="closeModal">
        <div>
            <flux:heading>Finalizar demanda</flux:heading>
            <flux:text>Você está dando inicio ao serviço de {{ $partnerDemand?->serviceServiceStep->serviceStep->name }} para o assistente {{ $partnerDemand?->partner->user->name }}</flux:text>
        </div>
        <div>
            <flux:text variant="strong">Data e Hora do início:</flux:text>
            <flux:text variant="subtle">{{ $partnerDemand?->started_at->format('d/m/Y H:i') }}</flux:text>
        </div>
        <div>
            <flux:text variant="strong">Data e Hora do término:</flux:text>
            <flux:text variant="subtle">{{ now()->format('d/m/Y H:i') }}</flux:text>
        </div>

        <x-form.image-upload wire:model="image" />
        @error('image')
            <flux:text variant="error">{{ $message }}</flux:text>
        @enderror

        <flux:text variant="strong">Deseja finalizar com o serviço?</flux:text>

        <div class="flex justify-end gap-2">
            <flux:modal.close>
                <flux:button variant="ghost">Cancelar</flux:button>
            </flux:modal.close>
            <flux:button wire:click="finishDemand">Continuar</flux:button>
        </div>
    </flux:modal>
</div>