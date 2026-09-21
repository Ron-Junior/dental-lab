<?php

use App\Models\RequestService;
use App\Models\ServiceStep;
use Flux\Flux;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component
{
    public ?RequestService $requestService;

    #[On('service::step::open')]
    public function open(int $id): void
    {
        $this->requestService = RequestService::find($id);
        Flux::modal('step-update-modal')->open();
    }

    #[Computed(persist: true)]
    public function steps(): Collection
    {
        return ServiceStep::where('service_id', $this->requestService->service_id)->orderBy('order')->get();
    }
};
?>

<div>
    <flux:modal flyout variant="floating" name="step-update-modal">
        <div class="space-y-5">
            <flux:heading size="lg">Atualizar Etapa</flux:heading>
            <flux:subheading>Atualize a etapa do serviço</flux:subheading>
            <flux:dropdown label="Status">
                @foreach ($this->steps as $step)
                    <flux:dropdown.item value="{{ $step->id }}">{{ $step->name }}</flux:dropdown.item>
                @endforeach
            </flux:dropdown>
            <div class="flex items-center justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">Cancelar</flux:button>
                </flux:modal.close>
                <flux:button>Atualizar</flux:button>
            </div>
        </div>
    </flux:modal>
</div>