<?php

use App\Models\DentistRequest;
use App\Models\RequestService;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    #[Computed, On('requests::refresh')]
    public function dentistRequests(): LengthAwarePaginator
    {
        return DentistRequest::with('dentist.user', 'requestServices.service.serviceSteps')->paginate(10);
    }

    public function getColor(int $completed, int $total): string
    {
        if ($completed === 0) {
            return 'blue';
        }

        return $completed === $total ? 'green' : 'blue';
    }
};

?>
<div>
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-5">
        <div>
            <flux:heading size="xl">Serviços Solicitados</flux:heading>
            <flux:text class="mt-1 sm:mt-2">Os serviços oferecidos pelo laboratório.</flux:text>
        </div>
        <flux:modal.trigger name="store-service-modal">
            <flux:button icon="plus" class="w-full sm:w-auto">Nova Solicitação</flux:button>
        </flux:modal.trigger>
    </div>

    <div>
        @if ($this->dentistRequests->count() > 0)
            <div class="hidden md:grid grid-cols-[minmax(180px,2fr)_minmax(140px,1.5fr)_100px_minmax(110px,1.2fr)_40px] xl:grid-cols-[minmax(180px,2fr)_minmax(150px,1.5fr)_100px_80px_minmax(120px,1.2fr)_40px] gap-4 font-semibold text-sm px-6 py-2">
                <flux:heading>Dentista/#Requisição</flux:heading>
                <flux:heading>Serviço</flux:heading>
                <flux:heading>Preço</flux:heading>
                <flux:heading class="hidden xl:block text-center">Quantidade</flux:heading>
                <flux:heading>Progresso</flux:heading>
                <div></div>
            </div>
        @endif

        <div class="space-y-4">
            @forelse ($this->dentistRequests as $dentistRequest)
                @php
                    $completedSteps = $dentistRequest->requestServices->sum(fn($r) => $r->completed_steps);
                    $totalSteps = $dentistRequest->requestServices->sum(fn($r) => $r->service->serviceSteps->count());
                @endphp
                <flux:card x-data="{open: false}" class="!p-0 overflow-hidden">
                    
                    <div class="hidden md:grid grid-cols-[minmax(180px,2fr)_minmax(140px,1.5fr)_100px_minmax(110px,1.2fr)_40px] xl:grid-cols-[minmax(180px,2fr)_minmax(150px,1.5fr)_100px_80px_minmax(120px,1.2fr)_40px] items-center w-full gap-4 px-6 py-4">
                        <div>
                            <flux:text class="font-medium truncate">{{ $dentistRequest->dentist->user->name }}</flux:text>
                            <flux:text class="text-xs">{{ $dentistRequest->code }}</flux:text>
                        </div>
                        
                        <div></div>

                        <flux:text class="truncate">R$ {{ number_format($dentistRequest->requestServices->sum(fn ($requestService) => $requestService->unit_price * $requestService->quantity), 2, ',', '.') }}</flux:text>
                        
                        <flux:text class="hidden xl:block text-center">{{ $dentistRequest->requestServices->sum('quantity') }}</flux:text>
                        
                        <div class="pr-2">
                            <flux:progress 
                                color="{{ $this->getColor($completedSteps, $totalSteps) }}"
                                value="{{ $completedSteps }}" 
                                max="{{ $totalSteps }}"
                            />
                        </div>
                        
                        <div class="justify-self-end">
                            <flux:button 
                                size="xs"
                                icon="chevron-down" 
                                variant="ghost" 
                                x-on:click="open = !open"
                                ::class="open ? 'rotate-180 transition-transform duration-200' : 'transition-transform duration-200'"
                            ></flux:button>
                        </div>
                    </div>

                    <div class="md:hidden p-4 space-y-3">
                        <div class="flex items-center justify-between gap-2 cursor-pointer" x-on:click="open = !open">
                            <div>
                                <flux:heading size="base"># {{ $dentistRequest->code }}</flux:heading>
                                <flux:text size="sm" class="text-zinc-500">{{ $dentistRequest->dentist->user->name }} - {{ $dentistRequest->requestServices->count() }} {{ $dentistRequest->requestServices->count() === 1 ? 'serviço' : 'serviços' }}</flux:text>
                            </div>
                            <flux:button 
                                size="xs"
                                icon="chevron-down" 
                                variant="ghost" 
                                x-on:click.stop="open = !open"
                                ::class="open ? 'rotate-180 transition-transform duration-200' : 'transition-transform duration-200'"
                            ></flux:button>
                        </div>

                        <div class="grid grid-cols-2 gap-2 text-sm">
                            <div class="bg-zinc-50 dark:bg-zinc-800/50 rounded-lg p-2.5">
                                <flux:text size="xs" class="text-zinc-500 uppercase tracking-wider">Total</flux:text>
                                <flux:heading size="sm" class="mt-0.5">R$ {{ number_format($dentistRequest->requestServices->sum(fn($requestService) => $requestService->unit_price * $requestService->quantity), 2, ',', '.') }}</flux:heading>
                            </div>
                            <div class="bg-zinc-50 dark:bg-zinc-800/50 rounded-lg p-2.5">
                                <flux:text size="xs" class="text-zinc-500 uppercase tracking-wider">Quantidade</flux:text>
                                <flux:heading size="sm" class="mt-0.5">{{ $dentistRequest->requestServices->sum('quantity') }} un</flux:heading>
                            </div>
                        </div>

                        <div class="space-y-1">
                            <div class="flex justify-between items-center text-xs">
                                <flux:text size="xs" class="text-zinc-500">Progresso</flux:text>
                                <flux:text size="xs" class="font-medium">{{ $completedSteps }} / {{ $totalSteps }}</flux:text>
                            </div>
                            <flux:progress 
                                color="{{ $this->getColor($completedSteps, $totalSteps) }}"
                                value="{{ $completedSteps }}" 
                                max="{{ $totalSteps }}"
                            />
                        </div>
                    </div>

                    <div x-show="open" x-collapse.duration.300ms style="display: none;" class="border-t border-zinc-100 dark:border-zinc-800">
                        @foreach ($dentistRequest->requestServices as $requestService)
                            <div class="hidden md:grid grid-cols-[minmax(180px,2fr)_minmax(140px,1.5fr)_100px_minmax(110px,1.2fr)_40px] xl:grid-cols-[minmax(180px,2fr)_minmax(150px,1.5fr)_100px_80px_minmax(120px,1.2fr)_40px] items-center w-full gap-4 px-6 py-3 border-b last:border-b-0 border-zinc-100 dark:border-zinc-800/50" wire:key="request-service-desktop-{{ $requestService->id }}">
                                <div></div>

                                <flux:text class="truncate">{{ $requestService->service->name }}</flux:text>

                                <flux:text class="truncate">R$ {{ number_format($requestService->unit_price * $requestService->quantity, 2, ',', '.') }}</flux:text>

                                <flux:text class="hidden xl:block text-center">{{ $requestService->quantity }}</flux:text>

                                <div class="content-center pr-2">
                                    @if ($requestService->completed_at === null)
                                        <flux:progress 
                                            :color="$this->getColor($requestService->completedSteps, $requestService->service->serviceSteps->count())"
                                            value="{{ $requestService->completedSteps }}" 
                                            max="{{ $requestService->service->serviceSteps->count() }}"
                                        />
                                    @endif

                                    @if ($requestService->completed_at)
                                        <flux:badge color="green">Concluído</flux:badge>
                                    @endif
                                </div>

                                <div class="justify-self-end">
                                    <flux:dropdown position="bottom" align="end">
                                        <flux:button 
                                            size="xs"
                                            icon="ellipsis-vertical" 
                                            variant="ghost"
                                            aria-label="Ações"
                                        />

                                        <flux:menu>
                                            @can('updateStatus', $requestService)
                                                <flux:menu.item
                                                    :disabled="(bool)$requestService->completed_at"
                                                    icon="bolt"
                                                    wire:click="dispatch('service::step::open', '{{ $requestService->id }}')"
                                                >
                                                    Atualizar status
                                                </flux:menu.item>
                                                <flux:menu.item
                                                    :disabled="(bool)$requestService->completed_at"
                                                    icon="check"
                                                    wire:click="dispatch('service::completed', '{{ $requestService->id }}')"
                                                >
                                                    Concluir
                                                </flux:menu.item>
                                                <flux:menu.item
                                                    :disabled="(bool)$requestService->completed_at"
                                                    icon="no-symbol"
                                                    variant="danger"
                                                    wire:click="dispatch('service::canceled', '{{ $requestService->id }}')"
                                                >
                                                    Cancelar
                                                </flux:menu.item>
                                                <flux:menu.separator />
                                            @endcan

                                            <flux:menu.item
                                                wire:key="edit-service-menu-{{ $requestService->id }}"
                                                :disabled="(bool)($requestService->completed_at || $requestService->step_id)"
                                                icon="pencil"
                                                wire:click="dispatch('service::update', '{{ $requestService->id }}')"
                                            >
                                                Editar
                                            </flux:menu.item>
                                        </flux:menu>
                                    </flux:dropdown>
                                </div>
                            </div>

                            <div class="md:hidden p-3 border-b last:border-b-0 border-zinc-200 dark:border-zinc-800 space-y-2.5" wire:key="request-service-mobile-{{ $requestService->id }}">
                                <div class="flex items-start justify-between gap-2">
                                    <div>
                                        <flux:heading size="sm">{{ $requestService->service->name }}</flux:heading>
                                        <div class="flex items-center gap-2 mt-1">
                                            <flux:text size="sm" class="font-medium">R$ {{ number_format($requestService->unit_price * $requestService->quantity, 2, ',', '.') }}</flux:text>
                                            <flux:text size="sm" class="text-zinc-400">•</flux:text>
                                            <flux:text size="sm" class="text-zinc-500">Qtd: {{ $requestService->quantity }}</flux:text>
                                        </div>
                                    </div>
                                    <div>
                                        @if ($requestService->completed_at)
                                            <flux:badge color="green" size="sm">Concluído</flux:badge>
                                        @endif
                                    </div>
                                </div>

                                @if ($requestService->completed_at === null)
                                    <div class="space-y-1">
                                        <div class="flex justify-between items-center text-xs">
                                            <flux:text size="xs" class="text-zinc-500">Etapas</flux:text>
                                            <flux:text size="xs" class="font-medium">{{ $requestService->completedSteps }} / {{ $requestService->service->serviceSteps->count() }}</flux:text>
                                        </div>
                                        <flux:progress 
                                            :color="$this->getColor($requestService->completedSteps, $requestService->service->serviceSteps->count())"
                                            value="{{ $requestService->completedSteps }}" 
                                            max="{{ $requestService->service->serviceSteps->count() }}"
                                        />
                                    </div>
                                @endif

                                <div class="flex items-center justify-end gap-1 pt-2 border-t border-zinc-200/50 dark:border-zinc-700/50">
                                    @can('updateStatus', $requestService)
                                        <flux:button
                                            :disabled="$requestService->completed_at"
                                            size="xs"
                                            icon="bolt"
                                            variant="ghost"
                                            wire:click="dispatch('service::step::open', '{{ $requestService->id }}')"
                                        >Status</flux:button>
                                        <flux:button 
                                            :disabled="$requestService->completed_at"
                                            size="xs"
                                            icon="check"
                                            variant="ghost"
                                            wire:click="dispatch('service::completed', '{{ $requestService->id }}')"
                                        >Concluir</flux:button>
                                        <flux:button
                                            :disabled="$requestService->completed_at"
                                            size="xs"
                                            icon="no-symbol"
                                            variant="ghost"
                                            wire:click="dispatch('service::canceled', '{{ $requestService->id }}')"
                                        >Cancelar</flux:button>
                                    @endcan

                                    <flux:button 
                                        wire:key="edit-service-mobile-{{ $requestService->id }}"
                                        :disabled="$requestService->completed_at || $requestService->step_id"
                                        size="xs"
                                        icon="pencil"
                                        variant="ghost"
                                        wire:click="dispatch('service::update', '{{ $requestService->id }}')"
                                    >Editar</flux:button>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </flux:card>
            @empty
                <flux:card class="text-center py-12">
                    <flux:heading size="lg">Nenhuma solicitação encontrada</flux:heading>
                    <flux:text class="mt-2 text-zinc-500">Não há solicitações de serviços registradas no momento.</flux:text>
                </flux:card>
            @endforelse
        </div>

        @if ($this->dentistRequests->hasPages())
            <div class="mt-4">
                {{ $this->dentistRequests->links() }}
            </div>
        @endif
    </div>

    <livewire:service.update />
    <livewire:service.requesting />
    <livewire:service.update-step />
    <livewire:service.complete />
</div>