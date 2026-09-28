<?php

use App\Models\Partner;
use App\Models\PartnerDemand;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    public string $search = '';

    public string $status = '';

    public ?int $editingServiceId = null;

    public array $filterPartnersIds = [];

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingStatus(): void
    {
        $this->resetPage();
    }

    public function updatingFilterPartnersIds(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function partners()
    {
        return Partner::with('user')->get()->map(fn ($partner) => [
            'id' => $partner->id,
            'name' => $partner->user->name ?? "Parceiro #{$partner->id}",
        ]);
    }

    #[Computed, On('demand::refresh')]
    public function demands(): LengthAwarePaginator
    {
        return PartnerDemand::with([
            'requestService.service',
            'requestService.dentistRequest.dentist.user',
            'serviceServiceStep.serviceStep.partners.user',
            'partner.user',
        ])
            ->search($this->search)
            ->assignedToPartner($this->filterPartnersIds)
            ->orderBy('order')
            ->paginate(10);
    }

    public function getStatusInfo(PartnerDemand $demand): array
    {
        if ($demand->ended_at) {
            return ['label' => 'Concluído', 'color' => 'green'];
        }

        if ($demand->started_at) {
            return ['label' => 'Em Andamento', 'color' => 'blue'];
        }

        if ($demand->partner_id) {
            return ['label' => 'Atribuído', 'color' => 'purple'];
        }

        return ['label' => 'Pendente', 'color' => 'zinc'];
    }
};
?>

<div>
    {{-- Header --}}
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-5">
        <div>
            <flux:heading size="xl">Demandas</flux:heading>
            <flux:text class="mt-1 sm:mt-2">As demandas dos serviços solicitados pelos dentistas.</flux:text>
        </div>
    </div>

    {{-- Search and Filters --}}
    <div class="flex flex-col sm:flex-row gap-3 mb-5">
        <div class="flex-1">
            <flux:input 
                wire:model.live.debounce.300ms="search" 
                placeholder="Buscar por serviço, etapa, dentista ou parceiro..." 
                icon="magnifying-glass"
            />
        </div>
        <div class="w-full sm:w-64">
            <x-form.multiselect 
                wire:model.live="filterPartnersIds" 
                :options="$this->partners" 
                placeholder="Filtrar por parceiros..." 
            />
        </div>
    </div>

    {{-- Desktop Table View --}}
    <div class="hidden md:block">
        <flux:table :paginate="$this->demands">
            <flux:table.columns>
                <flux:table.column>Serviço / Etapa</flux:table.column>
                <flux:table.column>Dentista / Solicitação</flux:table.column>
                <flux:table.column>Parceiro Responsável</flux:table.column>
                <flux:table.column>Status</flux:table.column>
                <flux:table.column align="end">Ações</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($this->demands as $demand)
                    @php
                        $status = $this->getStatusInfo($demand);
                        $serviceName = $demand->requestService->service->name ?? 'N/A';
                        $stepName = $demand->serviceServiceStep->serviceStep->name ?? 'N/A';
                        $dentistName = $demand->requestService->dentistRequest->dentist->user->name ?? 'N/A';
                        $requestCode = $demand->requestService->dentistRequest->code ?? null;
                        $partnerName = $demand->partner->user->name ?? null;
                    @endphp
                    <flux:table.row :key="$demand->id">
                        <flux:table.cell>
                            <div class="font-medium text-zinc-900 dark:text-zinc-100">{{ $serviceName }}</div>
                            <div class="text-xs text-zinc-500">{{ $stepName }}</div>
                        </flux:table.cell>
                        <flux:table.cell>
                            <div class="text-sm font-medium">{{ $dentistName }}</div>
                            @if ($requestCode)
                                <div class="text-xs text-zinc-400">#{{ Str::limit($requestCode, 8, '') }}</div>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell>
                            @if ($partnerName)
                                <div class="flex items-center gap-1.5 text-sm text-zinc-700 dark:text-zinc-300">
                                    <flux:icon name="user" class="size-4 text-zinc-400" />
                                    <span>{{ $partnerName }}</span>
                                </div>
                            @else
                                <flux:button 
                                    wire:click="dispatch('assign-demand::open', '{{ $demand->id }}')" 
                                    icon="user" 
                                    variant="filled" 
                                    size="sm"
                                    aria-label="Atribuir demanda"
                                    :disabled="!Auth::user()->can('assign', $demand)"
                                >
                                    Atribuir
                                </flux:button>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell>
                            <flux:badge :color="$status['color']" size="sm">
                                {{ $status['label'] }}
                            </flux:badge>
                        </flux:table.cell>
                        <flux:table.cell class="py-0 text-right">
                            <div class="flex items-center justify-end gap-1">
                                <flux:button 
                                    wire:click="dispatch('demand::edit', '{{ $demand->id }}')" 
                                    icon="pencil" 
                                    variant="ghost" 
                                    size="sm"
                                    aria-label="Editar demanda"
                                />
                                <flux:button 
                                    wire:click="dispatch('demand::delete', '{{ $demand->id }}')" 
                                    icon="trash" 
                                    variant="ghost" 
                                    size="sm"
                                    aria-label="Excluir demanda"
                                />
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="6" class="text-center py-8">
                            <flux:text class="text-zinc-500">Nenhuma demanda encontrada.</flux:text>
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </div>

    {{-- Mobile Cards View --}}
    <div class="md:hidden space-y-3">
        @forelse ($this->demands as $demand)
            @php
                $status = $this->getStatusInfo($demand);
                $serviceName = $demand->requestService->service->name ?? 'N/A';
                $stepName = $demand->serviceServiceStep->serviceStep->name ?? 'N/A';
                $dentistName = $demand->requestService->dentistRequest->dentist->user->name ?? 'N/A';
                $requestCode = $demand->requestService->dentistRequest->code ?? null;
                $partnerName = $demand->partner->user->name ?? null;
            @endphp
            <flux:card class="!p-4 border border-zinc-200 dark:border-zinc-800 space-y-3">
                <div class="flex items-center justify-between gap-2">
                    <flux:badge size="sm" variant="solid" color="zinc">Etapa #{{ $demand->order }}</flux:badge>
                    <flux:badge :color="$status['color']" size="sm">
                        {{ $status['label'] }}
                    </flux:badge>
                </div>

                <div>
                    <flux:heading size="base" class="font-semibold text-zinc-900 dark:text-zinc-100">
                        {{ $serviceName }}
                    </flux:heading>
                    <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400 mt-0.5">
                        {{ $stepName }}
                    </flux:text>
                </div>

                <div class="grid grid-cols-2 gap-2 text-xs bg-zinc-50 dark:bg-zinc-800/50 p-2.5 rounded-lg">
                    <div>
                        <span class="text-zinc-400 uppercase tracking-wider block font-medium">Dentista</span>
                        <span class="font-medium text-zinc-700 dark:text-zinc-300 block truncate mt-0.5">
                            {{ $dentistName }}
                        </span>
                    </div>
                    <div>
                        <span class="text-zinc-400 uppercase tracking-wider block font-medium">Parceiro</span>
                        <span class="font-medium text-zinc-700 dark:text-zinc-300 block truncate mt-0.5">
                            {{ $partnerName ?? 'Não atribuído' }}
                        </span>
                    </div>
                </div>

                <div class="flex items-center justify-between pt-2 border-t border-zinc-100 dark:border-zinc-800">
                    @if ($requestCode)
                        <span class="text-xs text-zinc-400">Cod: #{{ Str::limit($requestCode, 8, '') }}</span>
                    @else
                        <span></span>
                    @endif
                    <div class="flex items-center gap-1">
                        @if (!$demand->partner_id)
                            <flux:button 
                                wire:click="dispatch('assign-demand::open', '{{ $demand->id }}')" 
                                icon="user-plus" 
                                variant="ghost" 
                                size="sm"
                                aria-label="Atribuir demanda"
                                :disabled="!Auth::user()->can('assign', $demand)"
                            >
                                Atribuir
                            </flux:button>
                        @endif

                        @if (Auth::user()->can('start', $demand))
                            <flux:button 
                                wire:click="dispatch('assign-demand::open', '{{ $demand->id }}')"
                                icon="play-circle" 
                                icon:variant="outline"
                                variant="ghost"
                                size="sm"
                                :disabled="!Auth::user()->can('assign', $demand)"
                            >
                                Iniciar
                            </flux:button>
                        @endif
                        
                        @if (Auth::user()->can('complete', $demand))
                            <flux:button 
                                wire:click="dispatch('assign-demand::open', '{{ $demand->id }}')"  
                                icon="stop-circle"
                                icon:variant="outline"
                                variant="ghost"
                                size="sm"
                                :disabled="!Auth::user()->can('assign', $demand)"
                            >
                                Complete
                            </flux:button>
                        @endif
                    </div>
                </div>
            </flux:card>
        @empty
            <flux:card class="text-center py-10">
                <flux:heading size="lg">Nenhuma demanda encontrada</flux:heading>
                <flux:text class="mt-2 text-zinc-500">Não há demandas cadastradas ou que correspondam aos filtros.</flux:text>
            </flux:card>
        @endforelse

        @if ($this->demands->hasPages())
            <div class="mt-4">
                {{ $this->demands->links() }}
            </div>
        @endif
    </div>

    <livewire:partners.assign-demand/>
{{-- 
    <livewire:demands.store/>
    <livewire:demands.delete/> --}}
</div>