<?php

use App\Models\ServiceStep;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;
use Livewire\Attributes\On;
use Livewire\WithPagination;

new  class extends Component
{
    use WithPagination;

    public ?int $editingServiceId = null;

    #[Computed, On('step::refresh')]
    public function steps(): LengthAwarePaginator 
    {
        return ServiceStep::orderBy('name')->paginate(10);
    }
};
?>

<div>
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-5">
        <div>
            <flux:heading size="xl">Etapas de Serviços</flux:heading>
            <flux:text class="mt-1 sm:mt-2">As etapas que compõem cada serviço.</flux:text>
        </div>
        <flux:modal.trigger name="store-step-modal">
            <flux:button icon="plus" class="w-full sm:w-auto">Nova Etapa</flux:button>
        </flux:modal.trigger>
    </div>

    <!-- Desktop Table View -->
    <div class="hidden px-6 md:block bg-white dark:bg-zinc-900 rounded-xl border border-zinc-200 dark:border-zinc-800 overflow-hidden shadow-xs">
        <flux:table :paginate="$this->steps">
            <flux:table.columns>
                <flux:table.column>Nome da Etapa</flux:table.column>
                <flux:table.column>Descrição</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($this->steps as $step)
                    <flux:table.row :key="$step->id">
                        <flux:table.cell class="flex items-center gap-3">
                            <span class="font-medium text-zinc-900 dark:text-zinc-100">{{ $step->name }}</span>
                        </flux:table.cell>
                        <flux:table.cell class="whitespace-nowrap text-ellipsis max-w-44 overflow-hidden">{{ $step->description }}</flux:table.cell>
                        <flux:table.cell class="py-0">
                            <div class="flex justify-end gap-2">
                                <flux:button wire:click="dispatch('step::manage-partners', '{{ $step->id }}')" icon="user-group" variant="ghost" title="Gerenciar Parceiros"></flux:button>
                                <flux:button wire:click="dispatch('step::edit', '{{ $step->id }}')" icon="pencil" variant="ghost" title="Editar Etapa"></flux:button>
                                <flux:button wire:click="dispatch('step::delete', '{{ $step->id }}')" icon="trash" variant="ghost" title="Excluir Etapa"></flux:button>
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    </div>

    <!-- Mobile Cards View -->
    <div class="md:hidden space-y-3">
        @forelse ($this->steps as $step)
            <div class="rounded-xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-4 space-y-3 shadow-xs">
                <div class="flex items-start justify-between gap-2">
                    <div class="flex items-center gap-2.5">
                        <div class="rounded-lg bg-zinc-100 dark:bg-zinc-800 p-2 text-zinc-500 shrink-0">
                            <flux:icon class="size-4" name="numbered-list" />
                        </div>
                        <div>
                            <div class="font-semibold text-zinc-900 dark:text-zinc-100 text-base">
                                {{ $step->name }}
                            </div>
                            @if ($step->description)
                                <p class="text-xs text-zinc-500 dark:text-zinc-400 mt-0.5 line-clamp-2">
                                    {{ $step->description }}
                                </p>
                            @endif
                        </div>
                    </div>
                </div>

                <div class="flex items-center justify-between gap-2 pt-2 border-t border-zinc-100 dark:border-zinc-800">
                    <flux:button wire:click="dispatch('step::manage-partners', '{{ $step->id }}')" icon="user-group" size="sm" variant="outline" class="flex-1">
                        Parceiros
                    </flux:button>
                    <div class="flex items-center gap-1">
                        <flux:button wire:click="dispatch('step::edit', '{{ $step->id }}')" icon="pencil" size="sm" variant="ghost" title="Editar"></flux:button>
                        <flux:button wire:click="dispatch('step::delete', '{{ $step->id }}')" icon="trash" size="sm" variant="ghost" title="Excluir"></flux:button>
                    </div>
                </div>
            </div>
        @empty
            <x-empty-state
                icon="numbered-list"
                title="Nenhuma etapa encontrada"
                description="Cadastre as etapas para dividir o processo de produção dos serviços."
            />
        @endforelse

        @if ($this->steps->hasPages())
            <div class="pt-2">
                {{ $this->steps->links() }}
            </div>
        @endif
    </div>

    <livewire:step.store/>
    <livewire:step.delete/>
    <livewire:step.manage-partners/>
</div>