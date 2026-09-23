<?php

use App\Models\Partner;
use App\Models\ServiceStep;
use Flux\Flux;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Validate;
use Livewire\Component;

new class extends Component
{
    public ?ServiceStep $serviceStep = null;

    #[Validate('required|array|min:1')]
    public array $selectedPartners = [];

    protected function rules(): array
    {
        return [
            'selectedPartners.*.partner_id' => 'required|exists:partners,id',
            'selectedPartners.*.commission' => 'required|numeric',
            'selectedPartners.*.commission_type' => 'required|in:percentage,fixed',
        ];
    }

    #[On('step::manage-partners')]
    public function open(int $stepId): void
    {
        $this->serviceStep = ServiceStep::with('partners')->find($stepId);
        
        $this->selectedPartners = $this->serviceStep->partners->map(fn($partner) => [
            'partner_id' => $partner->id,
            'commission' => $partner->pivot->commission,
            'commission_type' => $partner->pivot->commission_type,
        ])->toArray();

        Flux::modal('manage-partners-modal')->show();
    }

    #[Computed]
    public function partners(): Collection
    {
        return Partner::with('user')->get();
    }

    public function closeModal(): void
    {
        $this->reset();
        Flux::modals()->close();
    }

    public function addPartner(): void
    {
        $this->selectedPartners[] = [
            'partner_id' => null,
            'commission' => null,
            'commission_type' => null,
        ];
    }

    public function removePartner(int $index): void
    {
        unset($this->selectedPartners[$index]);
        $this->selectedPartners = array_values($this->selectedPartners);
    }

    public function save(): void
    {
        $this->authorize('update', $this->serviceStep);
        $this->validate();

        $syncData = collect($this->selectedPartners)
            ->filter(fn($partner) => !empty($partner['partner_id'])) // Remove parceiros sem ID
            ->mapWithKeys(fn($partner) => [
                $partner['partner_id'] => [
                    'commission' => $partner['commission'] ?? null,
                    'commission_type' => $partner['commission_type'] ?? null,
                ],
            ])
            ->toArray();
        
        $this->serviceStep->partners()->sync($syncData);

        $this->closeModal();
        Flux::toast(variant: 'success', heading: 'Sucesso!', text: 'Parceiros gerenciados com sucesso!');
    }
};
?>

@use(\App\Enums\ComissionTypes)

<div>
    <flux:modal class="md:w-lg" flyout variant="floating" name="manage-partners-modal" @close="closeModal">
        <flux:heading>Gerenciar Parceiros</flux:heading>
        <flux:subheading>Escolha os parceiros do laboratório que podem realizar essa etapa.</flux:subheading>

        <flux:separator class="my-4"/>

        <form wire:submit.prevent="save" class="space-y-2">
            <flux:heading class="mb-4">{{ $serviceStep->name ?? '...' }}</flux:heading>
            <div class="space-y-2">
                @forelse ($this->selectedPartners as $index => $partner)
                    <flux:card class="space-y-2">
                        <flux:select label="Profissional" wire:model="selectedPartners.{{ $index }}.partner_id">
                            <flux:select.option value="">Selecione um tipo de comissão</flux:select.option>
                            @foreach ($this->partners as $partner)
                                <flux:select.option value="{{ $partner->id }}" avatar="{{ $partner->user->profile_photo_url }}" label="{{ $partner->user->name }}" />
                            @endforeach
                        </flux:select>

                        <flux:select
                            label="Tipo de Comissão"
                            wire:model="selectedPartners.{{ $index }}.commission_type"
                        >
                            <flux:select.option value="">Selecione um tipo de comissão</flux:select.option>
                            @foreach (ComissionTypes::cases() as $type)
                                <flux:select.option :value="$type->value">{{ $type->getName() }}</flux:select.option>
                            @endforeach
                        </flux:select>

                        <flux:input
                            type="number"
                            label="Comissão"
                            placeholder="Digite o valor da comissão"
                            wire:model="selectedPartners.{{ $index }}.commission"
                        />

                        <flux:button class="w-full" wire:click="removePartner({{ $index }})">Remover</flux:button>
                    </flux:card>
                @empty
                    <flux:card class="text-center">Nenhum parceiro selecionado</flux:card>
                @endforelse
                <flux:button class="w-full mt-4" wire:click="addPartner">Adicionar Parceiro</flux:button>
            </div>

            <div class="flex justify-end gap-2 mt-4">
                <flux:modal.close>
                    <flux:button variant="ghost">Cancelar</flux:button>
                </flux:modal.close>
                <flux:button type="submit">Salvar</flux:button>
            </div>
        </form>
    </flux:modal>
</div>