<?php

use App\Models\ServiceStep;
use Flux\Flux;
use Livewire\Attributes\Validate;
use Livewire\Component;

new class extends Component
{
    public ?ServiceStep $editingStep = null;

    #[Validate('required|string|min:3|max:255')]
    public ?string $name = null;

    #[Validate('required|string|min:3|max:255')]
    public ?string $description = null;

    public function edit(int $id): void
    {
        $this->editingStep = ServiceStep::find($id);

        $this->name = $this->editingStep->name;
        $this->description = $this->editingStep->description;
    }

    public function store(): void
    {
        $this->validate();

        ServiceStep::updateOrCreate(
            ['id' => $this->editingStep?->id],
            [
                'name' => $this->name,
                'description' => $this->description,
            ]
        );

        $this->reset();
        $this->dispatch('step::refresh');

        Flux::modals()->close();
        Flux::toast(variant: 'success', heading: 'Sucesso!', text: str(__('Etapa :action com sucesso'))->replace(':action', $this->editingStep ? 'atualizada' : 'criada'));
    }

    public function closeModal(): void
    {
        $this->reset();
        $this->resetErrorBag();
        Flux::modals()->close();
    }
};
?>

<div>
    <flux:modal flyout variant="floating" class="md:w-lg" name="store-step-modal" @close="closeModal">
        <form wire:submit="store" class="space-y-6" wire:key="store-step-modal">
            <flux:heading size="lg">Nova Etapa</flux:heading>
            <flux:subheading>Adicione uma nova etapa ao serviço.</flux:subheading>
            
            <flux:input wire:model="name" label="Nome" placeholder="Nome do serviço" />
            <flux:textarea wire:model="description" label="Descrição" placeholder="Descrição do serviço" />

            <div class="flex items-center justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">Cancelar</flux:button>
                </flux:modal.close>
                <flux:button type="submit" wire:loading.remove>Salvar</flux:button>
            </div>
        </form>
    </flux:modal>
</div>