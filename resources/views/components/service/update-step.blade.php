<?php

use App\Models\RequestService;
use App\Models\ServiceStep;
use Flux\Flux;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Validate;
use Livewire\Component;

new class extends Component
{
    public ?RequestService $requestService = null;

    #[Validate('required|integer|exists:service_steps,id')]
    public ?int $stepId = null;

    #[On('service::step::open')]
    public function open(int $id): void
    {
        $this->requestService = RequestService::find($id);
        $this->stepId = $this->requestService?->step_id;
        Flux::modal('step-update-modal')->show();
    }

    #[Computed(persist: true)]
    public function steps(): Collection
    {
        if (!$this->requestService) {
            return collect();
        }

        return ServiceStep::where('service_id', $this->requestService->service_id)->get();
    }

    public function save(): void
    {
        $this->validate();

        $this->requestService->update([
            'step_id' => $this->stepId,
        ]);

        $this->reset();
        $this->dispatch('requests::refresh');
        Flux::toast(variant: 'success', heading: 'Sucesso!', text: 'Etapa atualizada com sucesso!');
        Flux::modal('step-update-modal')->close();
    }
};
?>

<div>
    <flux:modal flyout variant="floating" name="step-update-modal" class="md:w-lg">
        <div class="space-y-5">
            <flux:heading size="lg">Atualizar Etapa</flux:heading>
            <flux:subheading>Atualize a etapa do serviço</flux:subheading>

            <flux:select label="Etapa" wire:model="stepId">
                <flux:select.option value="">Selecione uma etapa</flux:select.option>
                    
                @foreach ($this->steps() as $step)
                    <flux:select.option :value="$step->id">
                        {{ $step->name }}
                    </flux:select.option>
                @endforeach
            </flux:select>

            <div class="flex items-center justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">Cancelar</flux:button>
                </flux:modal.close>
                <flux:button wire:click="save">Atualizar</flux:button>
            </div>
        </div>
    </flux:modal>
</div>