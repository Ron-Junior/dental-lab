<?php

use App\Models\Dentist;
use App\Models\Service;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\WithPagination;

new  class extends Component
{
    use WithPagination;

    public ?int $editingServiceId = null;

    #[Computed, On('dentist::refresh')]
    public function dentists(): LengthAwarePaginator 
    {
        return Dentist::with('user')->paginate(10);
    }
};
?>

<div>
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-5">
        <div>
            <flux:heading size="xl">Dentistas</flux:heading>
            <flux:text class="mt-1 sm:mt-2">Os Dentistas cadastrados no sistema.</flux:text>
        </div>
        <flux:modal.trigger name="store-dentist-modal">
            <flux:button icon="plus" class="w-full sm:w-auto">Novo Dentista</flux:button>
        </flux:modal.trigger>
    </div>

    <!-- Desktop Table View -->
    <div class="hidden md:block bg-white dark:bg-zinc-900 rounded-xl border border-zinc-200 dark:border-zinc-800 overflow-hidden shadow-xs">
        <flux:table :paginate="$this->dentists">
            <flux:table.columns>
                <flux:table.column>Dentista</flux:table.column>
                <flux:table.column>Email</flux:table.column>
                <flux:table.column>Telefone</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($this->dentists as $dentist)
                    <flux:table.row :key="$dentist->id">
                        <flux:table.cell class="flex items-center gap-3">
                            <span class="font-medium text-zinc-900 dark:text-zinc-100">{{ $dentist->user->name }}</span>
                        </flux:table.cell>
                        <flux:table.cell class="whitespace-nowrap">{{ $dentist->user->email }}</flux:table.cell>
                        <flux:table.cell class="whitespace-nowrap">{{ $dentist->phone }}</flux:table.cell>
                        <flux:table.cell class="py-0">
                            <div class="flex justify-end gap-2">
                                <flux:button wire:click="dispatch('dentist::edit', '{{ $dentist->id }}')" icon="pencil" variant="ghost" title="Editar Dentista"></flux:button>
                                <flux:button wire:click="dispatch('dentist::delete', '{{ $dentist->id }}')" icon="trash" variant="ghost" title="Excluir Dentista"></flux:button>
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="4">
                            <x-empty-state
                                inset
                                icon="user-circle"
                                title="Nenhum dentista cadastrado"
                                description="Cadastre os dentistas clientes do seu laboratório."
                            />
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </div>

    <!-- Mobile Cards View -->
    <div class="md:hidden space-y-3">
        @forelse ($this->dentists as $dentist)
            <div class="rounded-xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-4 space-y-3 shadow-xs">
                <div class="flex items-start justify-between gap-2">
                    <div class="flex items-center gap-3">
                        <flux:avatar src="{{ $dentist->user->profile_photo_url }}" size="md" circle />
                        <div>
                            <div class="font-semibold text-zinc-900 dark:text-zinc-100 text-base">
                                {{ $dentist->user->name }}
                            </div>
                            <span class="text-xs text-zinc-400">Cirurgião-Dentista</span>
                        </div>
                    </div>
                    <div class="flex items-center gap-1">
                        <flux:button wire:click="dispatch('dentist::edit', '{{ $dentist->id }}')" icon="pencil" size="sm" variant="ghost" title="Editar"></flux:button>
                        <flux:button wire:click="dispatch('dentist::delete', '{{ $dentist->id }}')" icon="trash" size="sm" variant="ghost" title="Excluir"></flux:button>
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 text-xs bg-zinc-50 dark:bg-zinc-800/50 p-2.5 rounded-lg">
                    <div class="flex items-center gap-2 text-zinc-600 dark:text-zinc-400 truncate">
                        <flux:icon class="size-3.5 shrink-0 text-zinc-400" name="envelope" />
                        <span class="truncate">{{ $dentist->user->email }}</span>
                    </div>
                    <div class="flex items-center gap-2 text-zinc-600 dark:text-zinc-400">
                        <flux:icon class="size-3.5 shrink-0 text-zinc-400" name="phone" />
                        <span>{{ $dentist->phone ?: 'Sem telefone' }}</span>
                    </div>
                </div>
            </div>
        @empty
            <x-empty-state
                icon="user-circle"
                title="Nenhum dentista cadastrado"
                description="Cadastre os dentistas clientes do seu laboratório."
            />
        @endforelse

        @if ($this->dentists->hasPages())
            <div class="pt-2">
                {{ $this->dentists->links() }}
            </div>
        @endif
    </div>

    <livewire:dentists.store/>
    <livewire:dentists.delete/>
</div>