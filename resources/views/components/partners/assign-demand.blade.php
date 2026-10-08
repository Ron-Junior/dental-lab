<?php
 
use App\Models\Partner;
use App\Models\PartnerDemand;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Validate;
use Livewire\Component;

new class extends Component
{
    public ?PartnerDemand $partnerDemand = null;

    #[Validate('required|exists:partners,id')]
    public ?int $partnerId = null;

    #[On('assign-demand::open')]
    public function openModal(int $partnerDemandId): void
    {
        $this->partnerDemand = PartnerDemand::with('serviceServiceStep.serviceStep.partners:id')->find($partnerDemandId);

        Flux::modal('assign-demand-modal')->show();
    }

    #[Computed()]
    public function partners(): array
    {
        if (!$this->partnerDemand) {
            return [];
        }

        return Partner::with('user')
            ->when(
                $this->partnerDemand, 
                fn ($query) => $query->whereIn('id', $this->partnerDemand->serviceServiceStep->serviceStep->partners->pluck('id'))
            )
            ->get()
            ->map(fn ($partner) => [
                'id' => $partner->id,
                'name' => $partner->user->name ?? "Parceiro #{$partner->id}",
            ])->toArray();
    }

    public function closeModal(): void
    {
        $this->partnerDemand = null;
        $this->partnerId = null;
    }

    public function assignDemand()
    {
        $this->validate();

        $this->partnerDemand->partner_id = $this->partnerId;
        $this->partnerDemand->save();

        $this->closeModal();
        $this->dispatch('demand::refresh');
        Flux::modals()->close();
        Flux::toast(heading: 'Demanda atribuída com sucesso!', variant: 'success', text: "O parceiro foi notificado sobre a demanda.");
    }
};
?>

<div>
    <flux:modal class="backdrop:backdrop-blur-sm md:w-lg space-y-4" flyout variant="floating" name="assign-demand-modal" @close="closeModal">
        <div>
            <flux:heading size="lg">Atribuir demanda</flux:heading>
            <flux:subheading>Selecione um parceiro para atribuir a demanda.</flux:subheading>
        </div>

        <flux:select label="Parceiro" wire:model="partnerId">
            <flux:select.option value="">Selecione um parceiro</flux:select.option>
            @forelse ($this->partners as $partner) 
                <flux:select.option :value="$partner['id']">{{ $partner['name'] }}</flux:select.option>
            @empty
                <flux:select.option disabled>Nenhum parceiro disponível</flux:select.option>
            @endforelse
        </flux:select>
        <div class="flex justify-end gap-2">
            <flux:modal.close>
                <flux:button variant="ghost">Cancelar</flux:button>
            </flux:modal.close>
            <flux:button wire:click="assignDemand">Atribuir</flux:button>
        </div>
    </flux:modal>
</div>