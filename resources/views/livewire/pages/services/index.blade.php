<?php

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

    #[Computed, On('service::refresh')]
    public function services(): LengthAwarePaginator 
    {
        return Service::paginate(10);
    }
};
?>

<div>
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-5">
        <div>
            <flux:heading size="xl">Serviços</flux:heading>
            <flux:text class="mt-1 sm:mt-2">Os serviços oferecidos pelo laboratório.</flux:text>
        </div>
        <flux:modal.trigger name="store-service-modal">
            <flux:button icon="plus" class="w-full sm:w-auto">Novo Serviço</flux:button>
        </flux:modal.trigger>
    </div>

    <!-- Desktop Table View -->
    <div class="hidden px-6 md:block bg-white dark:bg-zinc-900 rounded-xl border border-zinc-200 dark:border-zinc-800 overflow-hidden shadow-xs">
        <flux:table :paginate="$this->services">
            <flux:table.columns>
                <flux:table.column>Serviço</flux:table.column>
                <flux:table.column>Descrição</flux:table.column>
                <flux:table.column>Preço</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($this->services as $service)
                    <flux:table.row :key="$service->id">
                        <flux:table.cell class="flex items-center gap-3">
                            <span class="font-medium text-zinc-900 dark:text-zinc-100">{{ $service->name }}</span>
                        </flux:table.cell>
                        <flux:table.cell class="whitespace-nowrap text-ellipsis overflow-hidden max-w-80">{{ $service->description }}</flux:table.cell>
                        <flux:table.cell class="whitespace-nowrap font-medium text-zinc-800 dark:text-zinc-200">
                            R$ {{ $service->price }}
                        </flux:table.cell>
                        <flux:table.cell class="py-0">
                            <div class="flex justify-end gap-2">
                                <flux:button wire:click="dispatch('service::edit', '{{ $service->id }}')" icon="pencil" variant="ghost" title="Editar Serviço"></flux:button>
                                <flux:button wire:click="dispatch('service::delete', '{{ $service->id }}')" icon="trash" variant="ghost" title="Excluir Serviço"></flux:button>
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    </div>

    <!-- Mobile Cards View -->
    <div class="md:hidden space-y-3">
        @forelse ($this->services as $service)
            <div class="rounded-xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-4 space-y-3 shadow-xs">
                <div class="flex items-start justify-between gap-2">
                    <div>
                        <div class="font-semibold text-zinc-900 dark:text-zinc-100 text-base">
                            {{ $service->name }}
                        </div>
                        @if ($service->description)
                            <p class="text-xs text-zinc-500 dark:text-zinc-400 mt-1 line-clamp-2">
                                {{ $service->description }}
                            </p>
                        @endif
                    </div>
                    <div class="text-right shrink-0">
                        <span class="text-sm font-bold text-zinc-900 dark:text-zinc-100">
                            R$ {{ $service->price }}
                        </span>
                    </div>
                </div>

                <div class="flex items-center justify-end gap-2 pt-2 border-t border-zinc-100 dark:border-zinc-800">
                    <flux:button wire:click="dispatch('service::edit', '{{ $service->id }}')" icon="pencil" size="sm" variant="ghost" title="Editar">
                        Editar
                    </flux:button>
                    <flux:button wire:click="dispatch('service::delete', '{{ $service->id }}')" icon="trash" size="sm" variant="ghost" title="Excluir">
                        Excluir
                    </flux:button>
                </div>
            </div>
        @empty
            <x-empty-state
                icon="inbox"
                title="Nenhum serviço cadastrado"
                description="Cadastre os serviços oferecidos pelo laboratório de próteses."
            />
        @endforelse

        @if ($this->services->hasPages())
            <div class="pt-2">
                {{ $this->services->links() }}
            </div>
        @endif
    </div>

    <livewire:service.store/>
    <livewire:service.delete/>
</div>