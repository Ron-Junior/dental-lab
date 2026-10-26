<?php

use App\Models\Dentist;
use App\Models\Partner;
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

    #[Computed, On('partners::refresh')]
    public function partners(): LengthAwarePaginator 
    {
        return Partner::with('user')->paginate(10);
    }
};
?>

<div>
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-5">
        <div>
            <flux:heading size="xl">Parceiros</flux:heading>
            <flux:text class="mt-1 sm:mt-2">Os Parceiros cadastrados no sistema.</flux:text>
        </div>
        <flux:modal.trigger name="store-partner-modal">
            <flux:button icon="plus" class="w-full sm:w-auto">Novo Parceiro</flux:button>
        </flux:modal.trigger>
    </div>

    <!-- Desktop Table View -->
    <div class="hidden px-6 md:block bg-white dark:bg-zinc-900 rounded-xl border border-zinc-200 dark:border-zinc-800 overflow-hidden shadow-xs">
        <flux:table :paginate="$this->partners">
            <flux:table.columns>
                <flux:table.column>Parceiro</flux:table.column>
                <flux:table.column>Email</flux:table.column>
                <flux:table.column>Telefone</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($this->partners as $partner)
                    <flux:table.row :key="$partner->id">
                        <flux:table.cell class="flex items-center gap-3">
                            <span class="font-medium text-zinc-900 dark:text-zinc-100">{{ $partner->user->name }}</span>
                        </flux:table.cell>
                        <flux:table.cell class="whitespace-nowrap">{{ $partner->user->email }}</flux:table.cell>
                        <flux:table.cell class="whitespace-nowrap">{{ $partner->phone }}</flux:table.cell>
                        <flux:table.cell class="py-0">
                            <div class="flex justify-end gap-2">
                                <flux:button wire:click="dispatch('partner::edit', '{{ $partner->id }}')" icon="pencil" variant="ghost" title="Editar Parceiro"></flux:button>
                                <flux:button wire:click="dispatch('partner::delete', '{{ $partner->id }}')" icon="trash" variant="ghost" title="Excluir Parceiro"></flux:button>
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    </div>

    <!-- Mobile Cards View -->
    <div class="md:hidden space-y-3">
        @forelse ($this->partners as $partner)
            <div class="rounded-xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-4 space-y-3 shadow-xs">
                <div class="flex items-start justify-between gap-2">
                    <div class="flex items-center gap-3">
                        <flux:avatar src="{{ $partner->user->profile_photo_url }}" size="md" circle />
                        <div>
                            <div class="font-semibold text-zinc-900 dark:text-zinc-100 text-base">
                                {{ $partner->user->name }}
                            </div>
                            <span class="text-xs text-zinc-400">Parceiro / Protesista</span>
                        </div>
                    </div>
                    <div class="flex items-center gap-1">
                        <flux:button wire:click="dispatch('partner::edit', '{{ $partner->id }}')" icon="pencil" size="sm" variant="ghost" title="Editar"></flux:button>
                        <flux:button wire:click="dispatch('partner::delete', '{{ $partner->id }}')" icon="trash" size="sm" variant="ghost" title="Excluir"></flux:button>
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 text-xs bg-zinc-50 dark:bg-zinc-800/50 p-2.5 rounded-lg">
                    <div class="flex items-center gap-2 text-zinc-600 dark:text-zinc-400 truncate">
                        <flux:icon class="size-3.5 shrink-0 text-zinc-400" name="envelope" />
                        <span class="truncate">{{ $partner->user->email }}</span>
                    </div>
                    <div class="flex items-center gap-2 text-zinc-600 dark:text-zinc-400">
                        <flux:icon class="size-3.5 shrink-0 text-zinc-400" name="phone" />
                        <span>{{ $partner->phone ?: 'Sem telefone' }}</span>
                    </div>
                </div>
            </div>
        @empty
            <x-empty-state
                icon="user-group"
                title="Nenhum parceiro encontrado"
                description="Cadastre parceiros para vincular a execução de etapas."
            />
        @endforelse

        @if ($this->partners->hasPages())
            <div class="pt-2">
                {{ $this->partners->links() }}
            </div>
        @endif
    </div>

    <livewire:partners.store/>
    <livewire:partners.delete/>
</div>