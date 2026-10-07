<?php

use App\Actions\CompleteService;
use App\Models\RequestService;
use Flux\Flux;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Computed;
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

    #[Computed()]
    public function canComplete(): bool
    {
        return $this->requestService->partnerDemands->every(fn ($demand) => $demand->ended_at !== null);
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
                'icon' => 'user',
            ];
        }

        return [
            'label' => 'Pendente',
            'color' => 'zinc',
            'icon' => 'ellipsis-horizontal-circle',
        ];
    }

    public function complete(): void
    {
        if (!$this->canComplete()) {
            Flux::toast(text: 'Existem etapas que não foram concluídas.');
            return ;
        }

        CompleteService::handle($this->requestServiceId);
    }
};

?>

<div x-data="{ previewPhotoUrl: null }">
    <flux:modal name="service-step-progress-modal" class="w-full sm:max-w-xl md:max-w-2xl space-y-6 p-4 sm:p-6 max-h-[90vh] overflow-y-auto" flyout variant="floating">
        @if ($requestService)
            @php
                $completedCount = $requestService->partnerDemands->whereNotNull('ended_at')->count();
                $totalCount = $requestService->partnerDemands->count();
                $percentage = $totalCount > 0 ? round(($completedCount / $totalCount) * 100) : 0;
            @endphp

            <div class="space-y-3">
                <div class="flex flex-col-reverse sm:flex-row sm:items-start justify-between gap-3">
                    <div>
                        <flux:heading size="xl" class="text-lg sm:text-xl">Progresso das Etapas</flux:heading>
                        <flux:text class="mt-1 text-xs sm:text-sm">
                            {{ $requestService->service->name ?? 'Serviço' }} — Solicitante: <span class="font-medium text-zinc-900 dark:text-zinc-100">{{ $requestService->dentistRequest->dentist->user->name ?? 'Dentista' }}</span>
                        </flux:text>
                    </div>

                    <div class="self-start sm:self-auto shrink-0">
                        @if ($requestService->completed_at)
                            <flux:badge color="green" icon="check-circle" size="sm">Concluído</flux:badge>
                        @else
                            <flux:badge color="blue" icon="clock" size="sm">Em Andamento</flux:badge>
                        @endif
                    </div>
                </div>

                {{-- Informações do Serviço --}}
                <div class="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-zinc-500 pt-1 border-t border-zinc-100 dark:border-zinc-800">
                    <span>Requisição: <strong class="text-zinc-700 dark:text-zinc-300">#{{ $requestService->dentistRequest->code }}</strong></span>
                    <span class="hidden sm:inline text-zinc-300 dark:text-zinc-700">•</span>
                    <span>Qtd: <strong class="text-zinc-700 dark:text-zinc-300">{{ $requestService->quantity }}</strong></span>
                    <span class="hidden sm:inline text-zinc-300 dark:text-zinc-700">•</span>
                    <span>Valor Total: <strong class="text-zinc-700 dark:text-zinc-300">R$ {{ number_format($requestService->unit_price * $requestService->quantity, 2, ',', '.') }}</strong></span>
                </div>
            </div>

            {{-- Barras de progresso geral --}}
            <flux:card class="p-3 sm:p-4! bg-zinc-50 dark:bg-zinc-900/50">
                <div class="space-y-2">
                    <div class="flex justify-between items-center text-xs font-medium gap-2">
                        <flux:text class="text-zinc-500 truncate">Progresso de Etapas</flux:text>
                        <flux:text class="font-semibold shrink-0">{{ $completedCount }} de {{ $totalCount }} ({{ $percentage }}%)</flux:text>
                    </div>
                    <flux:progress 
                        color="{{ $percentage === 100 ? 'green' : 'blue' }}" 
                        value="{{ $completedCount }}" 
                        max="{{ $totalCount > 0 ? $totalCount : 1 }}" 
                    />
                </div>
            </flux:card>

            {{-- Timeline / Lista de Etapas --}}
            <div class="space-y-3 sm:space-y-4">
                <flux:heading size="lg" class="text-base sm:text-lg">Etapas do Serviço</flux:heading>

                @forelse ($requestService->partnerDemands as $demand)
                    @php
                        $statusInfo = $this->getStatusInfo($demand);
                        $stepName = $demand->serviceServiceStep->serviceStep->name ?? "Etapa #{$demand->order}";
                        $stepDescription = $demand->serviceServiceStep->serviceStep->description ?? null;
                        $partnerName = $demand->partner->user->name ?? null;
                        $partnerPhoto = $demand->partner->user->profile_photo_url ?? null;
                    @endphp

                    <flux:card wire:key="step-demand-{{ $demand->id }}" class="p-3 sm:p-4! relative">
                        <div class="flex flex-col sm:flex-row sm:items-start justify-between gap-3">
                            <div class="flex items-start gap-2.5 sm:gap-3 min-w-0">
                                {{-- Número da Etapa --}}
                                <div class="shrink-0 w-7 h-7 sm:w-8 sm:h-8 rounded-full flex items-center justify-center font-bold text-xs bg-zinc-100 dark:bg-zinc-800 text-zinc-700 dark:text-zinc-300 border border-zinc-200 dark:border-zinc-700">
                                    {{ $demand->order }}
                                </div>

                                <div class="space-y-1 min-w-0">
                                    <div class="flex items-center gap-2 flex-wrap">
                                        <flux:heading size="base" class="font-semibold text-sm sm:text-base leading-snug">{{ $stepName }}</flux:heading>
                                        <flux:badge size="sm" color="{{ $statusInfo['color'] }}" icon="{{ $statusInfo['icon'] }}" class="shrink-0">
                                            {{ $statusInfo['label'] }}
                                        </flux:badge>
                                    </div>

                                    @if ($stepDescription)
                                        <flux:text size="xs" class="text-zinc-500 break-words">{{ $stepDescription }}</flux:text>
                                    @endif

                                    {{-- Responsável (Parceiro) --}}
                                    <div class="pt-1.5 flex flex-wrap items-center gap-1.5 text-xs">
                                        <flux:text size="xs" class="text-zinc-400">Responsável:</flux:text>
                                        @if ($partnerName)
                                            <div class="flex items-center gap-1.5 font-medium text-zinc-700 dark:text-zinc-300">
                                                <flux:avatar src="{{ $partnerPhoto }}" size="xs" circle />
                                                <span class="truncate max-w-[150px] sm:max-w-none">{{ $partnerName }}</span>
                                            </div>
                                        @else
                                            <flux:text size="xs" class="italic text-zinc-400">Pendente de atribuição</flux:text>
                                        @endif
                                    </div>
                                </div>
                            </div>

                            {{-- Datas de Início e Término --}}
                            <div class="flex flex-row sm:flex-col justify-between sm:justify-start items-center sm:items-end text-xs text-zinc-500 gap-2 border-t sm:border-t-0 pt-2 sm:pt-0 border-zinc-100 dark:border-zinc-800 shrink-0">
                                @if ($demand->started_at)
                                    <div>
                                        <span class="text-zinc-400">Iniciado:</span> 
                                        <span class="font-medium text-zinc-700 dark:text-zinc-300">{{ $demand->started_at->format('d/m/Y H:i') }}</span>
                                    </div>
                                @endif

                                @if ($demand->ended_at)
                                    <div>
                                        <span class="text-zinc-400">Concluído:</span> 
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
                            <div class="mt-3 pt-2.5 border-t border-zinc-100 dark:border-zinc-800">
                                <flux:text size="xs" class="font-medium text-zinc-500 mb-1.5 block">Foto do Comprovante/Resultado:</flux:text>
                                <flux:modal.trigger name="image-preview-modal">
                                    <button 
                                        type="button" 
                                        x-on:click="previewPhotoUrl = '{{ $demand->result_photo_url }}'"
                                        class="inline-block group cursor-pointer text-left focus:outline-none rounded-lg"
                                        title="Clique para ampliar"
                                    >
                                        <div class="relative overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-700 w-20 h-20 sm:w-24 sm:h-24 bg-zinc-100 dark:bg-zinc-800">
                                            <img src="{{ $demand->result_photo_url }}" alt="Foto do resultado da etapa" class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-200" />
                                            <div class="absolute inset-0 bg-black/40 opacity-0 group-hover:opacity-100 transition-opacity duration-200 flex items-center justify-center">
                                                <flux:icon icon="magnifying-glass-plus" class="w-5 h-5 sm:w-6 sm:h-6 text-white" />
                                            </div>
                                        </div>
                                    </button>
                                </flux:modal.trigger>
                            </div>
                        @endif
                    </flux:card>
                @empty
                    <div class="text-center py-6 bg-zinc-50 dark:bg-zinc-900/50 rounded-lg">
                        <flux:text class="text-zinc-500 text-sm">Nenhuma etapa registrada para este serviço.</flux:text>
                    </div>
                @endforelse
            </div>

            <div class="flex items-center justify-end pt-2">
                <flux:button wire:click="complete" :disabled="!$this->canComplete" variant="ghost" size="sm">Completar</flux:button>
            </div>
        @endif
    </flux:modal>

    {{-- Modal para visualização da imagem em tamanho grande --}}
    <flux:modal name="image-preview-modal" class="w-full sm:max-w-2xl md:max-w-4xl space-y-4 p-4 sm:p-6">
        <div class="flex items-center justify-between">
            <flux:heading size="lg" class="text-base sm:text-lg">Comprovante da Etapa</flux:heading>
        </div>

        <template x-if="previewPhotoUrl">
            <div  x-zoom="previewPhotoUrl" class="flex items-center justify-center bg-zinc-950/95 rounded-lg p-2 sm:p-4 min-h-[200px] max-h-[70vh] overflow-hidden">
                <img alt="Foto do comprovante ampliada" class="max-w-full max-h-[65vh] object-contain rounded-md shadow-2xl" />
            </div>
        </template>

        <div class="flex items-center justify-end">
            <flux:modal.close>
                <flux:button variant="ghost" size="sm">Fechar</flux:button>
            </flux:modal.close>
        </div>
    </flux:modal>
</div>