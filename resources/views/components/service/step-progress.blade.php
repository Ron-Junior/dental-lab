<?php

use App\Models\RequestService;
use Flux\Flux;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component
{
    public ?int $requestServiceId = null;

    public ?RequestService $requestService = null;

    #[On('service::step::open')]
    public function open(int|string $requestServiceId): void
    {
        $this->requestServiceId = (int) $requestServiceId;

        $this->requestService = RequestService::with([
            'service',
            'dentistRequest.dentist.user',
            'partnerDemands' => fn ($query) => $query->orderBy('order'),
            'partnerDemands.serviceServiceStep.serviceStep',
            'partnerDemands.partner.user',
        ])->find($this->requestServiceId);

        Flux::modal('service-step-progress-modal')->show();
    }

    public function getStatusInfo($demand): array
    {
        if ($demand->ended_at) {
            return [
                'label' => 'Concluído',
                'color' => 'green',
                'icon' => 'check-circle',
            ];
        }

        if ($demand->started_at) {
            return [
                'label' => 'Em Andamento',
                'color' => 'blue',
                'icon' => 'clock',
            ];
        }

        if ($demand->partner_id) {
            return [
                'label' => 'Atribuído',
                'color' => 'purple',
                'icon' => 'user-check',
            ];
        }

        return [
            'label' => 'Pendente',
            'color' => 'zinc',
            'icon' => 'ellipsis-horizontal-circle',
        ];
    }
};

?>

<div x-data="{ previewPhotoUrl: null }">
    <flux:modal name="service-step-progress-modal" class="md:w-2xl space-y-6" flyout variant="floating">
        @if ($requestService)
            @php
                $completedCount = $requestService->partnerDemands->whereNotNull('ended_at')->count();
                $totalCount = $requestService->partnerDemands->count();
                $percentage = $totalCount > 0 ? round(($completedCount / $totalCount) * 100) : 0;
            @endphp

            <div>
                <div class="flex items-center justify-between gap-4">
                    <div>
                        <flux:heading size="xl">Progresso das Etapas</flux:heading>
                        <flux:text class="mt-1">
                            {{ $requestService->service->name ?? 'Serviço' }} — Solicitante: <span class="font-medium text-zinc-900 dark:text-zinc-100">{{ $requestService->dentistRequest->dentist->user->name ?? 'Dentista' }}</span>
                        </flux:text>
                    </div>

                    @if ($requestService->completed_at)
                        <flux:badge color="green" icon="check-circle">Concluído</flux:badge>
                    @else
                        <flux:badge color="blue" icon="clock">Em Andamento</flux:badge>
                    @endif
                </div>

                <div class="flex items-center gap-3 text-xs text-zinc-500 mt-2">
                    <span>Requisição: <strong class="text-zinc-700 dark:text-zinc-300">#{{ $requestService->dentistRequest->code }}</strong></span>
                    <span>•</span>
                    <span>Qtd: <strong class="text-zinc-700 dark:text-zinc-300">{{ $requestService->quantity }}</strong></span>
                    <span>•</span>
                    <span>Valor Total: <strong class="text-zinc-700 dark:text-zinc-300">R$ {{ number_format($requestService->unit_price * $requestService->quantity, 2, ',', '.') }}</strong></span>
                </div>
            </div>

            {{-- Barras de progresso geral --}}
            <flux:card class="p-4! bg-zinc-50 dark:bg-zinc-900/50">
                <div class="space-y-2">
                    <div class="flex justify-between items-center text-xs font-medium">
                        <flux:text class="text-zinc-500">Progresso de Etapas</flux:text>
                        <flux:text class="font-semibold">{{ $completedCount }} de {{ $totalCount }} ({{ $percentage }}%)</flux:text>
                    </div>
                    <flux:progress 
                        color="{{ $percentage === 100 ? 'green' : 'blue' }}" 
                        value="{{ $completedCount }}" 
                        max="{{ $totalCount > 0 ? $totalCount : 1 }}" 
                    />
                </div>
            </flux:card>

            {{-- Timeline / Lista de Etapas --}}
            <div class="space-y-4">
                <flux:heading size="lg">Etapas do Serviço</flux:heading>

                @forelse ($requestService->partnerDemands as $demand)
                    @php
                        $statusInfo = $this->getStatusInfo($demand);
                        $stepName = $demand->serviceServiceStep->serviceStep->name ?? "Etapa #{$demand->order}";
                        $stepDescription = $demand->serviceServiceStep->serviceStep->description ?? null;
                        $partnerName = $demand->partner->user->name ?? null;
                        $partnerPhoto = $demand->partner->user->profile_photo_url ?? null;
                    @endphp

                    <flux:card wire:key="step-demand-{{ $demand->id }}" class="p-4! relative">
                        <div class="flex flex-col sm:flex-row sm:items-start justify-between gap-3">
                            <div class="flex items-start gap-3">
                                {{-- Número/Ícone da Etapa --}}
                                <div class="shrink-0 w-8 h-8 rounded-full flex items-center justify-center font-bold text-xs bg-zinc-100 dark:bg-zinc-800 text-zinc-700 dark:text-zinc-300 border border-zinc-200 dark:border-zinc-700">
                                    {{ $demand->order }}
                                </div>

                                <div class="space-y-1">
                                    <div class="flex items-center gap-2 flex-wrap">
                                        <flux:heading size="base" class="font-semibold">{{ $stepName }}</flux:heading>
                                        <flux:badge size="sm" color="{{ $statusInfo['color'] }}" icon="{{ $statusInfo['icon'] }}">
                                            {{ $statusInfo['label'] }}
                                        </flux:badge>
                                    </div>

                                    @if ($stepDescription)
                                        <flux:text size="xs" class="text-zinc-500">{{ $stepDescription }}</flux:text>
                                    @endif

                                    {{-- Responsável (Parceiro) --}}
                                    <div class="pt-2 flex items-center gap-2 text-xs">
                                        <flux:text size="xs" class="text-zinc-400">Responsável:</flux:text>
                                        @if ($partnerName)
                                            <div class="flex items-center gap-1.5 font-medium text-zinc-700 dark:text-zinc-300">
                                                <flux:avatar src="{{ $partnerPhoto }}" size="xs" circle />
                                                <span>{{ $partnerName }}</span>
                                            </div>
                                        @else
                                            <flux:text size="xs" class="italic text-zinc-400">Pendente de atribuição</flux:text>
                                        @endif
                                    </div>
                                </div>
                            </div>

                            {{-- Datas de Início e Término --}}
                            <div class="flex flex-col sm:items-end text-xs text-zinc-500 space-y-1 self-end sm:self-auto border-t sm:border-t-0 pt-2 sm:pt-0 border-zinc-100 dark:border-zinc-800">
                                @if ($demand->started_at)
                                    <div>
                                        <span class="text-zinc-400">Iniciado em:</span> 
                                        <span class="font-medium text-zinc-700 dark:text-zinc-300">{{ $demand->started_at->format('d/m/Y H:i') }}</span>
                                    </div>
                                @endif

                                @if ($demand->ended_at)
                                    <div>
                                        <span class="text-zinc-400">Concluído em:</span> 
                                        <span class="font-medium text-emerald-600 dark:text-emerald-400">{{ $demand->ended_at->format('d/m/Y H:i') }}</span>
                                    </div>
                                @endif

                                @if (!$demand->started_at && !$demand->ended_at)
                                    <span class="italic text-zinc-400">Aguardando início</span>
                                @endif
                            </div>
                        </div>

                        {{-- Foto do Resultado (se houver) --}}
                        @if ($demand->result_photo_url)
                            <div class="mt-3 pt-3 border-t border-zinc-100 dark:border-zinc-800">
                                <flux:text size="xs" class="font-medium text-zinc-500 mb-1.5 block">Foto do Comprovante/Resultado:</flux:text>
                                <flux:modal.trigger name="image-preview-modal">
                                    <button 
                                        type="button" 
                                        x-on:click="previewPhotoUrl = '{{ $demand->result_photo_url }}'"
                                        class="inline-block group cursor-pointer text-left focus:outline-none rounded-lg"
                                        title="Clique para ampliar"
                                    >
                                        <div class="relative overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-700 w-24 h-24 bg-zinc-100 dark:bg-zinc-800">
                                            <img src="{{ $demand->result_photo_url }}" alt="Foto do resultado da etapa" class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-200" />
                                            <div class="absolute inset-0 bg-black/40 opacity-0 group-hover:opacity-100 transition-opacity duration-200 flex items-center justify-center">
                                                <flux:icon icon="magnifying-glass-plus" class="w-6 h-6 text-white" />
                                            </div>
                                        </div>
                                    </button>
                                </flux:modal.trigger>
                            </div>
                        @endif
                    </flux:card>
                @empty
                    <div class="text-center py-6 bg-zinc-50 dark:bg-zinc-900/50 rounded-lg">
                        <flux:text class="text-zinc-500">Nenhuma etapa registrada para este serviço.</flux:text>
                    </div>
                @endforelse
            </div>

            <div class="flex items-center justify-end">
                <flux:modal.close>
                    <flux:button variant="ghost">Fechar</flux:button>
                </flux:modal.close>
            </div>
        @endif
    </flux:modal>

    {{-- Modal para visualização da imagem em tamanho grande --}}
    <flux:modal name="image-preview-modal" class="max-w-4xl space-y-4">
        <div class="flex items-center justify-between">
            <flux:heading size="lg">Comprovante da Etapa</flux:heading>
        </div>

        <div class="flex items-center justify-center bg-zinc-950/95 rounded-lg p-3 min-h-64 max-h-[75vh] overflow-hidden">
            <template x-if="previewPhotoUrl">
                <img :src="previewPhotoUrl" alt="Foto do comprovante ampliada" class="max-w-full max-h-[70vh] object-contain rounded-md shadow-2xl" />
            </template>
        </div>

        <div class="flex items-center justify-end">
            <flux:modal.close>
                <flux:button variant="ghost">Fechar</flux:button>
            </flux:modal.close>
        </div>
    </flux:modal>
</div>
