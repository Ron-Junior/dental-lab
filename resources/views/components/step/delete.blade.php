<?php

use App\Models\ServiceStep;
use Flux\Flux;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component
{
    public ?int $deleteStepId = null;
    
    #[On('step::delete')]
    public function open(int $id): void
    {
        $this->deleteStepId = $id;
        Flux::modal('delete-step-modal')->show();
    }
    
    public function delete(): void
    {
        $step = ServiceStep::find($this->deleteStepId);
        if (! $step) {
            return;
        }

        $this->authorize('delete', $step);

        if ($step->services()->exists()) {
            Flux::modals()->close();
            Flux::toast(variant: 'danger', heading: 'Não é possível excluir!', text: 'A etapa está vinculada a serviços ativos. Remova a etapa dos serviços ativos antes de excluí-la.');

            return;
        }

        DB::transaction(function () use ($step) {
            $step->partners()->detach();
            $step->delete();
        });

        $this->deleteStepId = null;
        $this->dispatch('step::refresh');

        Flux::modals()->close();
        Flux::toast(variant: 'success', heading: 'Sucesso!', text: 'Etapa excluída com sucesso');
    }

    public function closeModal(): void
    {
        $this->deleteStepId = null;
        $this->resetErrorBag();
        Flux::modals()->close();
    }
};
?>

<div>
    <flux:modal class="backdrop:backdrop-blur-sm" name="delete-step-modal" @close="closeModal">
        <div class="space-y-6" wire:key="delete-step-modal">
            <flux:heading size="lg">Excluir Etapa</flux:heading>
            <flux:subheading>Tem certeza que deseja excluir esta etapa?</flux:subheading>
            <div class="flex items-center justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">Cancelar</flux:button>
                </flux:modal.close>
                <flux:button wire:click="delete">Excluir</flux:button>
            </div>
        </div>
    </flux:modal>
</div>