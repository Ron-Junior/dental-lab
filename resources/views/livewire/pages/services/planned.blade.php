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
        return DentistRequest::with('dentist.user', 'requestServices.service.steps')->paginate(10);
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
    <div class="flex justify-between mb-5">
        <div>
            <flux:heading size="xl">Serviços Solicitados</flux:heading>
            <flux:text class="mt-2">Os serviços oferecidos pelo laboratório.</flux:text>
        </div>
        <flux:modal.trigger name="store-service-modal">
            <flux:button icon="plus">Nova Solicitação</flux:button>
        </flux:modal.trigger>
    </div>

    <div>
        <div class="px-6 py-2 grid grid-cols-6">
            <flux:heading>
                Dentista
            </flux:heading>
            <flux:heading>
                Serviço
            </flux:heading>
            <flux:heading>
                Preço
            </flux:heading>
            <flux:heading>
                Quantidade
            </flux:heading>
            <flux:heading>
                Progresso
            </flux:heading>
        </div>

        <div class="space-y-4">
            @foreach ($this->dentistRequests as $dentistRequest)
                <flux:card x-data="{open: false}" class="px-6 py-4">
                    <div class="justify-between items-center w-full grid grid-cols-6">
                        <flux:text>{{ $dentistRequest->dentist->user->name }}</flux:text>
                        <flux:text>{{ $dentistRequest->requestServices->count() }}</flux:text>
                        <flux:text>R$ {{ $dentistRequest->requestServices->sum('unit_price') }}</flux:text>
                        <flux:text>{{ $dentistRequest->requestServices->sum('quantity') }}</flux:text>
                        <flux:progress 
                            color="{{ $this->getColor($dentistRequest->requestServices->sum(fn($r) => $r->completed_steps), $dentistRequest->requestServices->sum(fn($r) => $r->service->steps->count())) }}"
                            value="{{ $dentistRequest->requestServices->sum(fn($r) => $r->completed_steps) }}" 
                            max="{{ $dentistRequest->requestServices->sum(fn($r) => $r->service->steps->count()) }}"
                        />
                        <div class="justify-self-end">
                            <flux:button 
                                size="xs"
                                icon="chevron-down" 
                                variant="ghost" 
                                x-on:click="open = !open"
                            ></flux:button>
                        </div>
                    </div>
                    <div x-show="open" x-collapse.duration.500ms style="display: none;">
                        @foreach ($dentistRequest->requestServices as $requestService)
                            <div class="grid grid-cols-6 justify-between w-full mt-4" wire:key="request-service-{{ $requestService->id }}">
                                <flux:text></flux:text>
                                <flux:text>{{ $requestService->service->name }}</flux:text>
                                <flux:text>R$ {{ $requestService->unit_price }}</flux:text>
                                <flux:text>{{ $requestService->quantity }}</flux:text>
                                <div class="content-center">
                                    @if ($requestService->completed_at === null)
                                        <flux:progress 
                                            :color="$this->getColor($requestService->completedSteps, $requestService->service->steps->count())"
                                            value="{{ $requestService->completedSteps }}" 
                                            max="{{ $requestService->service->steps->count() }}"
                                        />
                                    @endif

                                    @if ($requestService->completed_at)
                                        <flux:badge color="green">Concluído</flux:badge>
                                    @endif
                                </div>
                                <div class="justify-self-end">
                                    @can('update-status', $requestService)
                                        <flux:tooltip content="Atualizar status">
                                            <flux:button
                                                size="sm"
                                                icon="bolt"
                                                variant="ghost"
                                                wire:click="dispatch('service::step::open', '{{ $requestService->id }}')"
                                            >
                                            </flux:button>
                                        </flux:tooltip>
                                        <flux:tooltip content="Concluir">
                                            <flux:button 
                                                size="sm"
                                                icon="check"
                                                variant="ghost"
                                                wire:click="dispatch('service::completed', '{{ $requestService->id }}')"
                                            >
                                            </flux:button>
                                        </flux:tooltip>
                                        <flux:tooltip content="Cancelar">
                                            <flux:button 
                                                size="sm"
                                                icon="no-symbol"
                                                variant="ghost"
                                                wire:click="dispatch('service::canceled', '{{ $requestService->id }}')"
                                            >
                                            </flux:button>
                                        </flux:tooltip>
                                    @endcan

                                    <flux:tooltip content="Editar">
                                        <flux:button 
                                            wire:key="edit-service-{{ $requestService->id }}"
                                            :disabled="$requestService->completed_at || $requestService->step_id"
                                            size="sm"
                                            icon="pencil"
                                            variant="ghost"
                                            wire:click="dispatch('service::update', '{{ $requestService->id }}')"
                                        />
                                    </flux:tooltip>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </flux:card>
            @endforeach
        </div>
    </div>

    <livewire:service.update />
    <livewire:service.requesting />
    <livewire:service.update-step />
    <livewire:service.complete />
</div>