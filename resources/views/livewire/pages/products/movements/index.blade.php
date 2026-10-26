<?php

use App\Enums\StockMovementsReasons;
use App\Enums\StockMovementsTypes;
use App\Models\Product;
use App\Models\StockBatch;
use App\Models\StockMovement;
use Carbon\Carbon;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    public string $activeTab = 'movements'; // 'movements' | 'batches'

    public string $search = '';

    public string $filterType = '';

    public string $filterReason = '';

    public string $filterPeriod = 'all'; // '7_days', '30_days', 'this_month', 'all'

    public string $batchSearch = '';

    public string $batchFilterStatus = 'all'; // 'all', 'available', 'expiring_soon', 'expired'

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedFilterType(): void
    {
        $this->filterReason = '';
        $this->resetPage();
    }

    public function updatedFilterReason(): void
    {
        $this->resetPage();
    }

    public function updatedFilterPeriod(): void
    {
        $this->resetPage();
    }

    public function updatedBatchSearch(): void
    {
        $this->resetPage('batchesPage');
    }

    public function updatedBatchFilterStatus(): void
    {
        $this->resetPage('batchesPage');
    }

    #[Computed, On('movements::refresh')]
    public function stockMovements(): LengthAwarePaginator
    {
        return StockMovement::query()
            ->with(['product.category', 'stockBatch', 'user'])
            ->search($this->search)
            ->filterType($this->filterType)
            ->when($this->filterReason, fn ($q) => $q->where('reason', $this->filterReason))
            ->when($this->filterPeriod === '7_days', fn ($q) => $q->where('created_at', '>=', now()->subDays(7)))
            ->when($this->filterPeriod === '30_days', fn ($q) => $q->where('created_at', '>=', now()->subDays(30)))
            ->when($this->filterPeriod === 'this_month', fn ($q) => $q->where('created_at', '>=', now()->startOfMonth()))
            ->latest()
            ->paginate(15);
    }

    #[Computed, On('movements::refresh')]
    public function stockBatches(): LengthAwarePaginator
    {
        return StockBatch::query()
            ->with(['product.category'])
            ->when($this->batchSearch, function ($q) {
                $q->where('batch_number', 'like', "%{$this->batchSearch}%")
                    ->orWhereHas('product', fn ($p) => $p->where('name', 'like', "%{$this->batchSearch}%"));
            })
            ->when($this->batchFilterStatus === 'available', fn ($q) => $q->where('current_quantity', '>', 0))
            ->when($this->batchFilterStatus === 'expiring_soon', fn ($q) => $q->expiringSoon(30))
            ->when($this->batchFilterStatus === 'expired', fn ($q) => $q->expired())
            ->orderBy('expiration_date', 'asc')
            ->paginate(15, ['*'], 'batchesPage');
    }

    #[Computed, On('movements::refresh')]
    public function metrics(): array
    {
        $startOfMonth = now()->startOfMonth();

        $incomingCount = StockMovement::where('type', StockMovementsTypes::In)
            ->where('created_at', '>=', $startOfMonth)
            ->count();

        $outgoingCount = StockMovement::where('type', StockMovementsTypes::Out)
            ->where('created_at', '>=', $startOfMonth)
            ->count();

        $lowStockCount = Product::whereColumn('current_stock', '<=', 'min_stock')->count();

        $expiringBatchesCount = StockBatch::where('current_quantity', '>', 0)
            ->whereBetween('expiration_date', [now(), now()->addDays(30)])
            ->count();

        $expiredBatchesCount = StockBatch::where('current_quantity', '>', 0)
            ->where('expiration_date', '<', now())
            ->count();

        return [
            'incoming_month' => $incomingCount,
            'outgoing_month' => $outgoingCount,
            'low_stock_products' => $lowStockCount,
            'expiring_batches' => $expiringBatchesCount,
            'expired_batches' => $expiredBatchesCount,
        ];
    }
};
?>

<div class="space-y-6">
    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <flux:heading size="xl">Gestão de Estoque</flux:heading>
            <flux:subheading class="mt-1">
                Controle de movimentações, entradas, saídas e rastreabilidade de lotes e validades.
            </flux:subheading>
        </div>
        <div class="flex items-center gap-2 w-full sm:w-auto">
            <flux:modal.trigger name="store-stock-movement-modal">
                <flux:button icon="plus" class="w-full sm:w-auto">Nova Movimentação</flux:button>
            </flux:modal.trigger>
        </div>
    </div>

    <!-- Cards de Métricas / KPIs -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <!-- Entradas no Mês -->
        <div class="rounded-xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-4 shadow-xs">
            <div class="flex items-center justify-between">
                <span class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Entradas este Mês</span>
                <div class="rounded-lg bg-emerald-50 dark:bg-emerald-950/50 p-2 text-emerald-600 dark:text-emerald-400">
                    <flux:icon class="size-4" name="arrow-down-tray" />
                </div>
            </div>
            <div class="mt-2 flex items-baseline gap-2">
                <span class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">{{ $this->metrics['incoming_month'] }}</span>
                <span class="text-xs text-zinc-500">registros</span>
            </div>
        </div>

        <!-- Saídas no Mês -->
        <div class="rounded-xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-4 shadow-xs">
            <div class="flex items-center justify-between">
                <span class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Saídas este Mês</span>
                <div class="rounded-lg bg-rose-50 dark:bg-rose-950/50 p-2 text-rose-600 dark:text-rose-400">
                    <flux:icon class="size-4" name="arrow-up-tray" />
                </div>
            </div>
            <div class="mt-2 flex items-baseline gap-2">
                <span class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">{{ $this->metrics['outgoing_month'] }}</span>
                <span class="text-xs text-zinc-500">consumos / perdas</span>
            </div>
        </div>

        <!-- Estoque Mínimo -->
        <div class="rounded-xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-4 shadow-xs">
            <div class="flex items-center justify-between">
                <span class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Itens em Estoque Mínimo</span>
                <div class="rounded-lg bg-amber-50 dark:bg-amber-950/50 p-2 text-amber-600 dark:text-amber-400">
                    <flux:icon class="size-4" name="exclamation-triangle" />
                </div>
            </div>
            <div class="mt-2 flex items-baseline gap-2">
                <span class="text-2xl font-bold {{ $this->metrics['low_stock_products'] > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-zinc-900 dark:text-zinc-100' }}">
                    {{ $this->metrics['low_stock_products'] }}
                </span>
                <span class="text-xs text-zinc-500">precisam de reposição</span>
            </div>
        </div>

        <!-- Alertas de Lote (Validade ANVISA) -->
        <div class="rounded-xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-4 shadow-xs">
            <div class="flex items-center justify-between">
                <span class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Lotes a Vencer / Vencidos</span>
                <div class="rounded-lg bg-sky-50 dark:bg-sky-950/50 p-2 text-sky-600 dark:text-sky-400">
                    <flux:icon class="size-4" name="clock" />
                </div>
            </div>
            <div class="mt-2 flex items-baseline gap-2">
                <span class="text-2xl font-bold {{ ($this->metrics['expired_batches'] + $this->metrics['expiring_batches']) > 0 ? 'text-rose-600 dark:text-rose-400' : 'text-zinc-900 dark:text-zinc-100' }}">
                    {{ $this->metrics['expiring_batches'] }}
                    @if ($this->metrics['expired_batches'] > 0)
                        <span class="text-xs text-rose-500 font-normal">({{ $this->metrics['expired_batches'] }} vencidos)</span>
                    @endif
                </span>
                <span class="text-xs text-zinc-500">nos próx. 30 dias</span>
            </div>
        </div>
    </div>

    <!-- Navegação de Abas -->
    <div class="border-b border-zinc-200 dark:border-zinc-800 flex gap-4 sm:gap-6 overflow-x-auto whitespace-nowrap">
        <button 
            type="button"
            wire:click="$set('activeTab', 'movements')"
            class="pb-3 text-sm font-medium transition-colors border-b-2 shrink-0 flex items-center gap-2 {{ $activeTab === 'movements' ? 'border-primary-500 text-primary-600 dark:text-primary-400 font-semibold' : 'border-transparent text-zinc-500 hover:text-zinc-800 dark:hover:text-zinc-200' }}"
        >
            <flux:icon class="size-4" name="arrows-up-down" />
            Movimentações do Estoque
        </button>
        <button 
            type="button"
            wire:click="$set('activeTab', 'batches')"
            class="pb-3 text-sm font-medium transition-colors border-b-2 shrink-0 flex items-center gap-2 {{ $activeTab === 'batches' ? 'border-primary-500 text-primary-600 dark:text-primary-400 font-semibold' : 'border-transparent text-zinc-500 hover:text-zinc-800 dark:hover:text-zinc-200' }}"
        >
            <flux:icon class="size-4" name="archive-box" />
            Lotes & Rastreabilidade (ANVISA)
            @if ($this->metrics['expiring_batches'] + $this->metrics['expired_batches'] > 0)
                <span class="rounded-full bg-rose-500/10 text-rose-600 dark:text-rose-400 text-xs px-2 py-0.5 font-semibold">
                    {{ $this->metrics['expiring_batches'] + $this->metrics['expired_batches'] }}
                </span>
            @endif
        </button>
    </div>

    @if ($activeTab === 'movements')
        <!-- Filtros de Movimentações -->
        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-3">
            <div class="md:col-span-1">
                <flux:input 
                    placeholder="Buscar produto, lote, NF..." 
                    wire:model.live.debounce.400ms="search" 
                    icon="magnifying-glass"
                />
            </div>

            <div>
                <flux:select wire:model.live="filterType" placeholder="Tipo">
                    <option value="">Todos os tipos</option>
                    @foreach (StockMovementsTypes::cases() as $type)
                        <option value="{{ $type->value }}">{{ $type->label() }}</option>
                    @endforeach
                </flux:select>
            </div>

            <div>
                <flux:select wire:model.live="filterReason" placeholder="Motivo">
                    <option value="">Todos os motivos</option>
                    @foreach (StockMovementsReasons::cases() as $reason)
                        <option value="{{ $reason->value }}">{{ $reason->label() }}</option>
                    @endforeach
                </flux:select>
            </div>

            <div>
                <flux:select wire:model.live="filterPeriod" placeholder="Período">
                    <option value="all">Todo o período</option>
                    <option value="7_days">Últimos 7 dias</option>
                    <option value="30_days">Últimos 30 dias</option>
                    <option value="this_month">Este mês</option>
                </flux:select>
            </div>
        </div>

        <!-- Desktop Table View -->
        <div class="hidden md:block bg-white dark:bg-zinc-900 rounded-xl border border-zinc-200 dark:border-zinc-800 overflow-hidden shadow-xs">
            <flux:table :paginate="$this->stockMovements" class="px-6">
                <flux:table.columns>
                    <flux:table.column>Data / Hora</flux:table.column>
                    <flux:table.column>Produto</flux:table.column>
                    <flux:table.column>Tipo</flux:table.column>
                    <flux:table.column>Motivo</flux:table.column>
                    <flux:table.column>Lote</flux:table.column>
                    <flux:table.column align="end">Quantidade</flux:table.column>
                    <flux:table.column align="end">Valor Unit. / Total</flux:table.column>
                    <flux:table.column>Identificador / NF</flux:table.column>
                    <flux:table.column>Usuário</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @forelse ($this->stockMovements as $movement)
                        <flux:table.row :key="'mov-'.$movement->id">
                            <!-- Data -->
                            <flux:table.cell class="whitespace-nowrap text-xs text-zinc-500 dark:text-zinc-400">
                                {{ $movement->created_at?->format('d/m/Y H:i') }}
                            </flux:table.cell>

                            <!-- Produto -->
                            <flux:table.cell>
                                <div class="font-medium text-zinc-900 dark:text-zinc-100">
                                    {{ $movement->product?->name }}
                                </div>
                                @if ($movement->product?->category)
                                    <div class="text-xs text-zinc-400 dark:text-zinc-500">
                                        {{ $movement->product->category->name }}
                                    </div>
                                @endif
                            </flux:table.cell>

                            <!-- Tipo -->
                            <flux:table.cell>
                                @php
                                    $typeEnum = $movement->type;
                                @endphp
                                @if ($typeEnum === StockMovementsTypes::In)
                                    <flux:badge color="green" size="sm" icon="arrow-down-tray">
                                        Entrada
                                    </flux:badge>
                                @elseif ($typeEnum === StockMovementsTypes::Out)
                                    <flux:badge color="red" size="sm" icon="arrow-up-tray">
                                        Saída
                                    </flux:badge>
                                @else
                                    <flux:badge color="blue" size="sm" icon="scale">
                                        Ajuste
                                    </flux:badge>
                                @endif
                            </flux:table.cell>

                            <!-- Motivo -->
                            <flux:table.cell class="text-sm text-zinc-700 dark:text-zinc-300">
                                {{ $movement->reason?->label() ?? '-' }}
                            </flux:table.cell>

                            <!-- Lote -->
                            <flux:table.cell>
                                @if ($movement->stockBatch)
                                    <div class="flex flex-col">
                                        <span class="font-mono text-xs font-medium text-zinc-800 dark:text-zinc-200">
                                            {{ $movement->stockBatch->batch_number }}
                                        </span>
                                        @if ($movement->stockBatch->expiration_date)
                                            <span class="text-[11px] {{ $movement->stockBatch->isExpired() ? 'text-rose-500 font-semibold' : ($movement->stockBatch->isExpiringSoon() ? 'text-amber-500' : 'text-zinc-400') }}">
                                                Val: {{ $movement->stockBatch->expiration_date->format('d/m/Y') }}
                                            </span>
                                        @endif
                                    </div>
                                @else
                                    <span class="text-zinc-400 text-xs">-</span>
                                @endif
                            </flux:table.cell>

                            <!-- Quantidade -->
                            <flux:table.cell align="end" class="whitespace-nowrap font-medium text-sm">
                                @if ($movement->type === StockMovementsTypes::In)
                                    <span class="text-emerald-600 dark:text-emerald-400 font-semibold">
                                        + {{ number_format($movement->quantity, 2, ',', '.') }} {{ $movement->product?->unit_of_measure?->value }}
                                    </span>
                                @elseif ($movement->type === StockMovementsTypes::Out)
                                    <span class="text-rose-600 dark:text-rose-400 font-semibold">
                                        - {{ number_format($movement->quantity, 2, ',', '.') }} {{ $movement->product?->unit_of_measure?->value }}
                                    </span>
                                @else
                                    <span class="text-zinc-800 dark:text-zinc-200">
                                        {{ number_format($movement->quantity, 2, ',', '.') }} {{ $movement->product?->unit_of_measure?->value }}
                                    </span>
                                @endif
                            </flux:table.cell>

                            <!-- Valor Unit / Total -->
                            <flux:table.cell align="end" class="whitespace-nowrap text-xs text-zinc-600 dark:text-zinc-400">
                                @if ($movement->unit_cost)
                                    <div>R$ {{ number_format($movement->unit_cost, 2, ',', '.') }} / {{ $movement->product?->unit_of_measure?->value }}</div>
                                    <div class="font-semibold text-zinc-900 dark:text-zinc-200">
                                        Total: R$ {{ number_format($movement->unit_cost * (float)$movement->quantity, 2, ',', '.') }}
                                    </div>
                                @else
                                    <span>-</span>
                                @endif
                            </flux:table.cell>

                            <!-- Identificador Externo / NF -->
                            <flux:table.cell class="text-xs text-zinc-600 dark:text-zinc-400 max-w-xs truncate">
                                {{ $movement->external_identifier ?: '-' }}
                            </flux:table.cell>

                            <!-- Usuário -->
                            <flux:table.cell class="whitespace-nowrap text-xs text-zinc-500 dark:text-zinc-400">
                                {{ $movement->user?->name ?? 'Sistema' }}
                            </flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row>
                            <flux:table.cell colspan="9">
                                <x-empty-state
                                    inset
                                    icon="arrows-up-down"
                                    title="Nenhuma movimentação de estoque encontrada"
                                    description="Registre novas entradas, saídas ou ajustes para começar a rastrear o estoque."
                                />
                            </flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>
        </div>

        <!-- Mobile Cards View for Movements -->
        <div class="md:hidden space-y-3">
            @forelse ($this->stockMovements as $movement)
                <div class="rounded-xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-4 space-y-3 shadow-xs">
                    <div class="flex items-center justify-between gap-2">
                        <span class="text-xs text-zinc-500 dark:text-zinc-400">
                            {{ $movement->created_at?->format('d/m/Y H:i') }}
                        </span>
                        @if ($movement->type === StockMovementsTypes::In)
                            <flux:badge color="green" size="sm" icon="arrow-down-tray">Entrada</flux:badge>
                        @elseif ($movement->type === StockMovementsTypes::Out)
                            <flux:badge color="red" size="sm" icon="arrow-up-tray">Saída</flux:badge>
                        @else
                            <flux:badge color="blue" size="sm" icon="scale">Ajuste</flux:badge>
                        @endif
                    </div>

                    <div>
                        <div class="font-semibold text-zinc-900 dark:text-zinc-100 text-base">
                            {{ $movement->product?->name }}
                        </div>
                        <div class="flex items-center gap-2 mt-0.5">
                            @if ($movement->product?->category)
                                <span class="text-xs text-zinc-400">{{ $movement->product->category->name }}</span>
                                <span class="text-xs text-zinc-300">•</span>
                            @endif
                            <span class="text-xs font-medium text-zinc-500 dark:text-zinc-400">{{ $movement->reason?->label() ?? '-' }}</span>
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-2 text-xs bg-zinc-50 dark:bg-zinc-800/50 p-2.5 rounded-lg">
                        <div>
                            <span class="text-zinc-400 uppercase tracking-wider block font-medium">Quantidade</span>
                            <span class="font-bold text-sm block mt-0.5 {{ $movement->type === StockMovementsTypes::In ? 'text-emerald-600 dark:text-emerald-400' : ($movement->type === StockMovementsTypes::Out ? 'text-rose-600 dark:text-rose-400' : 'text-zinc-800 dark:text-zinc-200') }}">
                                {{ $movement->type === StockMovementsTypes::In ? '+' : ($movement->type === StockMovementsTypes::Out ? '-' : '') }} {{ number_format($movement->quantity, 2, ',', '.') }} {{ $movement->product?->unit_of_measure?->value }}
                            </span>
                        </div>
                        <div>
                            <span class="text-zinc-400 uppercase tracking-wider block font-medium">Valor Total</span>
                            <span class="font-semibold text-sm text-zinc-800 dark:text-zinc-200 block mt-0.5">
                                {{ $movement->unit_cost ? 'R$ ' . number_format($movement->unit_cost * (float)$movement->quantity, 2, ',', '.') : '-' }}
                            </span>
                        </div>
                        @if ($movement->stockBatch)
                            <div>
                                <span class="text-zinc-400 uppercase tracking-wider block font-medium">Lote</span>
                                <span class="font-mono text-xs font-semibold text-zinc-700 dark:text-zinc-300 block mt-0.5">
                                    {{ $movement->stockBatch->batch_number }}
                                </span>
                            </div>
                            <div>
                                <span class="text-zinc-400 uppercase tracking-wider block font-medium">Validade</span>
                                <span class="text-xs block mt-0.5 {{ $movement->stockBatch->isExpired() ? 'text-rose-500 font-semibold' : ($movement->stockBatch->isExpiringSoon() ? 'text-amber-500' : 'text-zinc-600 dark:text-zinc-300') }}">
                                    {{ $movement->stockBatch->expiration_date?->format('d/m/Y') ?? '-' }}
                                </span>
                            </div>
                        @endif
                    </div>

                    <div class="flex items-center justify-between text-xs text-zinc-500 dark:text-zinc-400 pt-1 border-t border-zinc-100 dark:border-zinc-800">
                        <span class="truncate max-w-45">{{ $movement->external_identifier ?: 'Sem doc.' }}</span>
                        <span>{{ $movement->user?->name ?? 'Sistema' }}</span>
                    </div>
                </div>
            @empty
                <x-empty-state
                    icon="arrows-up-down"
                    title="Nenhuma movimentação encontrada"
                    description="Registre novas entradas, saídas ou ajustes no estoque."
                />
            @endforelse

            @if ($this->stockMovements->hasPages())
                <div class="pt-2">
                    {{ $this->stockMovements->links() }}
                </div>
            @endif
        </div>

    @else
        <!-- Aba: Lotes & Rastreabilidade ANVISA -->
        <div class="space-y-4">
            <div class="flex flex-col sm:flex-row gap-3">
                <div class="flex-1">
                    <flux:input 
                        placeholder="Buscar por número do lote ou nome do produto..." 
                        wire:model.live.debounce.400ms="batchSearch" 
                        icon="magnifying-glass"
                    />
                </div>
                <div class="w-full sm:w-64">
                    <flux:select wire:model.live="batchFilterStatus" placeholder="Status do Lote">
                        <option value="all">Todos os lotes</option>
                        <option value="available">Com saldo disponível</option>
                        <option value="expiring_soon">Vencendo em até 30 dias</option>
                        <option value="expired">Vencidos</option>
                    </flux:select>
                </div>
            </div>

            <!-- Desktop Table View for Batches -->
            <div class="hidden px-6 md:block bg-white dark:bg-zinc-900 rounded-xl border border-zinc-200 dark:border-zinc-800 overflow-hidden shadow-xs">
                <flux:table :paginate="$this->stockBatches">
                    <flux:table.columns>
                        <flux:table.column>Produto</flux:table.column>
                        <flux:table.column>Número do Lote</flux:table.column>
                        <flux:table.column>Qtd. Inicial</flux:table.column>
                        <flux:table.column>Saldo Atual</flux:table.column>
                        <flux:table.column>Data de Validade</flux:table.column>
                        <flux:table.column>Status / Alerta</flux:table.column>
                        <flux:table.column align="end">Ações</flux:table.column>
                    </flux:table.columns>

                    <flux:table.rows>
                        @forelse ($this->stockBatches as $batch)
                            <flux:table.row :key="'batch-'.$batch->id">
                                <!-- Produto -->
                                <flux:table.cell>
                                    <div class="font-medium text-zinc-900 dark:text-zinc-100">
                                        {{ $batch->product?->name }}
                                    </div>
                                    <div class="text-xs text-zinc-400">
                                        {{ $batch->product?->category?->name ?? 'Material Dentário' }}
                                    </div>
                                </flux:table.cell>

                                <!-- Número do Lote -->
                                <flux:table.cell class="font-mono font-semibold text-zinc-800 dark:text-zinc-200">
                                    {{ $batch->batch_number }}
                                </flux:table.cell>

                                <!-- Qtd Inicial -->
                                <flux:table.cell class="text-xs text-zinc-500">
                                    {{ number_format($batch->initial_quantity, 2, ',', '.') }} {{ $batch->product?->unit_of_measure?->value }}
                                </flux:table.cell>

                                <!-- Saldo Atual -->
                                <flux:table.cell class="font-semibold text-sm {{ $batch->current_quantity <= 0 ? 'text-zinc-400 line-through' : 'text-zinc-900 dark:text-zinc-100' }}">
                                    {{ number_format($batch->current_quantity, 2, ',', '.') }} {{ $batch->product?->unit_of_measure?->value }}
                                </flux:table.cell>

                                <!-- Validade -->
                                <flux:table.cell class="text-sm">
                                    {{ $batch->expiration_date?->format('d/m/Y') }}
                                </flux:table.cell>

                                <!-- Status / Alerta -->
                                <flux:table.cell>
                                    @if ($batch->current_quantity <= 0)
                                        <flux:badge color="zinc" size="sm">Esgotado</flux:badge>
                                    @elseif ($batch->isExpired())
                                        <flux:badge color="red" size="sm" icon="exclamation-circle">Vencido</flux:badge>
                                    @elseif ($batch->isExpiringSoon(30))
                                        <flux:badge color="amber" size="sm" icon="clock">Vence em {{ $batch->expiration_date->diffInDays(now()) }} dias</flux:badge>
                                    @else
                                        <flux:badge color="green" size="sm" icon="check-circle">Regular</flux:badge>
                                    @endif
                                </flux:table.cell>

                                <!-- Ações -->
                                <flux:table.cell align="end">
                                    <flux:button 
                                        size="xs" 
                                        variant="ghost" 
                                        icon="arrows-up-down"
                                        wire:click="dispatch('stock-movement::create', { productId: {{ $batch->product_id }} })"
                                    >
                                        Movimentar
                                    </flux:button>
                                </flux:table.cell>
                            </flux:table.row>
                        @empty
                            <flux:table.row>
                                <flux:table.cell colspan="7">
                                    <x-empty-state
                                        inset
                                        icon="archive-box"
                                        title="Nenhum lote cadastrado"
                                        description="Lotes são gerados automaticamente nas movimentações de entrada para produtos com controle de lote."
                                    />
                                </flux:table.cell>
                            </flux:table.row>
                        @endforelse
                    </flux:table.rows>
                </flux:table>
            </div>

            <!-- Mobile Cards View for Batches -->
            <div class="md:hidden space-y-3">
                @forelse ($this->stockBatches as $batch)
                    <div class="rounded-xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-4 space-y-3 shadow-xs">
                        <div class="flex items-center justify-between gap-2">
                            <span class="font-mono font-bold text-sm text-zinc-800 dark:text-zinc-200">
                                Lote: {{ $batch->batch_number }}
                            </span>
                            @if ($batch->current_quantity <= 0)
                                <flux:badge color="zinc" size="sm">Esgotado</flux:badge>
                            @elseif ($batch->isExpired())
                                <flux:badge color="red" size="sm" icon="exclamation-circle">Vencido</flux:badge>
                            @elseif ($batch->isExpiringSoon(30))
                                <flux:badge color="amber" size="sm" icon="clock">Vence em {{ $batch->expiration_date->diffInDays(now()) }}d</flux:badge>
                            @else
                                <flux:badge color="green" size="sm" icon="check-circle">Regular</flux:badge>
                            @endif
                        </div>

                        <div>
                            <div class="font-semibold text-zinc-900 dark:text-zinc-100 text-base">
                                {{ $batch->product?->name }}
                            </div>
                            <div class="text-xs text-zinc-400 mt-0.5">
                                {{ $batch->product?->category?->name ?? 'Material Dentário' }}
                            </div>
                        </div>

                        <div class="grid grid-cols-3 gap-2 text-xs bg-zinc-50 dark:bg-zinc-800/50 p-2.5 rounded-lg">
                            <div>
                                <span class="text-zinc-400 uppercase tracking-wider block font-medium">Saldo Atual</span>
                                <span class="font-bold text-sm block mt-0.5 {{ $batch->current_quantity <= 0 ? 'text-zinc-400 line-through' : 'text-zinc-900 dark:text-zinc-100' }}">
                                    {{ number_format($batch->current_quantity, 2, ',', '.') }} {{ $batch->product?->unit_of_measure?->value }}
                                </span>
                            </div>
                            <div>
                                <span class="text-zinc-400 uppercase tracking-wider block font-medium">Inicial</span>
                                <span class="font-medium text-xs text-zinc-600 dark:text-zinc-300 block mt-0.5">
                                    {{ number_format($batch->initial_quantity, 2, ',', '.') }} {{ $batch->product?->unit_of_measure?->value }}
                                </span>
                            </div>
                            <div>
                                <span class="text-zinc-400 uppercase tracking-wider block font-medium">Validade</span>
                                <span class="font-medium text-xs text-zinc-700 dark:text-zinc-300 block mt-0.5">
                                    {{ $batch->expiration_date?->format('d/m/Y') }}
                                </span>
                            </div>
                        </div>

                        <div class="pt-1">
                            <flux:button 
                                class="w-full"
                                size="sm" 
                                variant="outline" 
                                icon="arrows-up-down"
                                wire:click="dispatch('stock-movement::create', { productId: {{ $batch->product_id }} })"
                            >
                                Movimentar este lote
                            </flux:button>
                        </div>
                    </div>
                @empty
                    <x-empty-state
                        icon="archive-box"
                        title="Nenhum lote cadastrado"
                        description="Lotes são criados automaticamente nas entradas com rastreamento."
                    />
                @endforelse

                @if ($this->stockBatches->hasPages())
                    <div class="pt-2">
                        {{ $this->stockBatches->links() }}
                    </div>
                @endif
            </div>
        </div>
    @endif

    <!-- Componente Modal de Nova Movimentação -->
    <livewire:products.stock.store />
</div>