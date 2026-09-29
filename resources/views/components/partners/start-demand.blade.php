<?php

use App\Enums\ComissionTypes;
use App\Models\PartnerDemand;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component
{
    public ?PartnerDemand $partnerDemand = null;

    public ?float $partnerCommissionValue = null;

    public ?string $partnerCommissionType = null;

    #[On('start-demand::open')]
    public function openModal(int $partnerDemandId): void
    {
        $partnerDemand = PartnerDemand::with(['partner.user', 'serviceServiceStep.serviceStep.partners'])->find($partnerDemandId);

        if (!$partnerDemand) {
            Flux::toast(heading: 'Demanda não encontrada!', variant: 'error', text: "A demanda solicitada não foi encontrada.");
            return;
        }

        $this->partnerDemand = $partnerDemand;

        $partnerDemandCommission = $partnerDemand->serviceServiceStep
            ->serviceStep
            ->partners
            ->where('id', $this->partnerDemand->partner_id)
            ->first()
            ->pivot;

        $this->partnerCommissionValue = $partnerDemandCommission->commission;
        $this->partnerCommissionType = $partnerDemandCommission->commission_type;

        Flux::modal('start-demand-modal')->show();
    }

    #[Computed()]
    public function commission(): string
    {
        if ($this->partnerCommissionType === ComissionTypes::Percentage->value) {
            return $this->partnerCommissionValue . '%';
        } else {
            return 'R$ ' .number_format( $this->partnerCommissionValue, 2, ',', '.');
        }
    }

    public function closeModal(): void
    {
        $this->partnerDemand = null;
    }

    public function startDemand()
    {
        $this->authorize('start', $this->partnerDemand);

        $this->partnerDemand->partner_commission = $this->partnerCommissionValue;
        $this->partnerDemand->partner_commission_type = $this->partnerCommissionType;
        $this->partnerDemand->started_at = now();
        $this->partnerDemand->save();

        $this->closeModal();
        $this->dispatch('demand::refresh');
        Flux::toast(heading: 'Serviço iniciado com sucesso!', variant: 'success', text: "");
        Flux::modals()->close();
    }
};
?>

<div>
    <flux:modal class="md:w-lg space-y-4" name="start-demand-modal" @close="closeModal">
        <div>
            <flux:heading>Iniciar demanda</flux:heading>
            <flux:text>Você está dando inicio ao serviço de {{ $partnerDemand?->serviceServiceStep->serviceStep->name }} para o assistente {{ $partnerDemand?->partner->user->name }}</flux:text>
        </div>
        <div>
            <flux:text variant="strong">Data e Hora do início:</flux:text>
            <flux:text variant="subtle">{{ now()->format('d/m/Y H:i') }}</flux:text>
        </div>
        <div>
            <flux:text variant="strong">Comissão do assistente:</flux:text>
            <flux:text variant="subtle">{{ $this->commission }}</flux:text>
        </div>

        <flux:text variant="strong">Deseja continuar com o serviço?</flux:text>

        <div class="flex justify-end gap-2">
            <flux:modal.close>
                <flux:button variant="ghost">Cancelar</flux:button>
            </flux:modal.close>
            <flux:button wire:click="startDemand">Continuar</flux:button>
        </div>
    </flux:modal>
</div>