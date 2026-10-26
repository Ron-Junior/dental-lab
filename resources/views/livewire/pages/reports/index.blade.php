<?php

use App\Enums\CommissionTypes;
use App\Enums\StockMovementsReasons;
use App\Enums\StockMovementsTypes;
use App\Models\Dentist;
use App\Models\DentistRequest;
use App\Models\PartnerDemand;
use App\Models\RequestService;
use App\Models\Service;
use App\Models\StockMovement;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    public string $dateFrom = '';

    public string $dateTo = '';

    #[Locked]
    public string $appliedDateFrom = '';

    #[Locked]
    public string $appliedDateTo = '';

    public ?int $dentistId = null;

    public ?int $serviceId = null;

    public string $activeReport = 'profitability';

    public string $revenueGroup = 'period';

    public function mount(): void
    {
        $this->dateFrom = now()->startOfMonth()->toDateString();
        $this->dateTo = now()->toDateString();
        $this->appliedDateFrom = $this->dateFrom;
        $this->appliedDateTo = $this->dateTo;
    }

    public function rules(): array
    {
        return [
            'dateFrom' => ['required', 'date'],
            'dateTo' => ['required', 'date', 'after_or_equal:dateFrom'],
            'dentistId' => ['nullable', 'integer', 'exists:dentists,id'],
            'serviceId' => ['nullable', 'integer', 'exists:services,id'],
            'activeReport' => ['required', 'in:profitability,revenue,partners'],
            'revenueGroup' => ['required', 'in:period,dentist,service'],
        ];
    }

    public function applyFilters(): void
    {
        $this->validate();
        $this->appliedDateFrom = $this->dateFrom;
        $this->appliedDateTo = $this->dateTo;
        $this->resetPage();
        $this->resetPage('partnerPage');
    }

    #[Computed]
    public function dentists(): Collection
    {
        return Dentist::query()
            ->with('user:id,name')
            ->join('users', 'dentists.user_id', '=', 'users.id')
            ->orderBy('users.name')
            ->get(['dentists.id', 'dentists.user_id']);
    }

    #[Computed]
    public function services(): Collection
    {
        return Service::query()->orderBy('name')->get(['id', 'name']);
    }

    #[Computed]
    public function orders(): LengthAwarePaginator
    {
        return $this->ordersQuery()
            ->with('dentist.user')
            ->withSum('requestServices as revenue_cents', DB::raw('unit_price * quantity'))
            ->withSum(
                ['stockMovements as cmv_cents' => fn (Builder $query) => $this->serviceConsumptionMovements($query)],
                DB::raw('quantity * unit_cost')
            )
            ->withSum(
                ['partnerDemands as partner_commissions_cents' => fn (Builder $query) => $this->completedPartnerDemands($query)],
                DB::raw($this->partnerCommissionExpression())
            )
            ->withCount([
                'requestServices as revenue_lines_count',
                'requestServices as incomplete_revenue_lines_count' => fn (Builder $query) => $query
                    ->where(fn (Builder $query) => $query->whereNull('unit_price')->orWhereNull('quantity')),
                'stockMovements as material_movements_count' => fn (Builder $query) => $this->serviceConsumptionMovements($query),
                'stockMovements as unpriced_material_movements_count' => fn (Builder $query) => $this->serviceConsumptionMovements($query)
                    ->whereNull('unit_cost'),
                'partnerDemands as completed_partner_demands_count' => fn (Builder $query) => $this->completedPartnerDemands($query),
                'partnerDemands as pending_partner_demands_count' => fn (Builder $query) => $this->pendingPartnerDemands($query),
                'partnerDemands as unpriced_partner_demands_count' => fn (Builder $query) => $this->unpricedPartnerDemands($this->completedPartnerDemands($query)),
            ])
            ->latest('created_at')
            ->latest('id')
            ->paginate(15);
    }

    #[Computed]
    public function profitabilitySummary(): array
    {
        $requestIds = fn (): Builder => $this->ordersQuery()->select('dentist_requests.id');
        $consumptions = fn (): Builder => StockMovement::query()
            ->whereIn('dentist_request_id', $requestIds())
            ->where('type', StockMovementsTypes::Out->value)
            ->where('reason', StockMovementsReasons::ServiceConsumption->value);
        $completedPartnerDemands = fn (): Builder => $this->completedPartnerDemandsQuery();

        return [
            'orders_count' => $this->ordersQuery()->count(),
            'revenue_cents' => (int) RequestService::query()
                ->whereIn('dentist_request_id', $requestIds())
                ->sum(DB::raw('unit_price * quantity')),
            'cmv_cents' => (int) $consumptions()->sum(DB::raw('quantity * unit_cost')),
            'partner_commissions_cents' => (int) $completedPartnerDemands()->sum(DB::raw($this->partnerCommissionExpression())),
            'orders_without_consumption' => $this->ordersQuery()
                ->whereDoesntHave('stockMovements', fn (Builder $query) => $this->serviceConsumptionMovements($query))
                ->count(),
            'unpriced_movements' => $consumptions()->whereNull('unit_cost')->count(),
            'pending_partner_demands' => $this->pendingPartnerDemands($this->partnerDemandsQuery())->count(),
            'unpriced_partner_demands' => $this->unpricedPartnerDemands($completedPartnerDemands())->count(),
            'incomplete_revenue_lines' => RequestService::query()
                ->whereIn('dentist_request_id', $requestIds())
                ->where(fn (Builder $query) => $query->whereNull('unit_price')->orWhereNull('quantity'))
                ->count(),
        ];
    }

    #[Computed]
    public function revenueSummary(): array
    {
        $totals = (clone $this->revenueLines())
            ->selectRaw('COALESCE(SUM(request_services.unit_price * request_services.quantity), 0) AS revenue_cents')
            ->selectRaw('COUNT(DISTINCT dentist_requests.id) AS orders_count')
            ->selectRaw('COUNT(CASE WHEN request_services.unit_price IS NULL OR request_services.quantity IS NULL THEN 1 END) AS incomplete_lines_count')
            ->first();

        return [
            'revenue_cents' => (int) $totals->revenue_cents,
            'orders_count' => (int) $totals->orders_count,
            'incomplete_lines_count' => (int) $totals->incomplete_lines_count,
        ];
    }

    #[Computed]
    public function partnerCommissionSummary(): array
    {
        $completedDemands = fn (): Builder => $this->completedPartnerDemandsQuery();

        return [
            'commission_cents' => (int) $completedDemands()->sum(DB::raw($this->partnerCommissionExpression())),
            'completed_demands_count' => $completedDemands()->count(),
            'partners_count' => $completedDemands()->distinct('partner_demands.partner_id')->count('partner_demands.partner_id'),
            'pending_demands_count' => $this->pendingPartnerDemands($this->partnerDemandsQuery())->count(),
            'unpriced_demands_count' => $this->unpricedPartnerDemands($completedDemands())->count(),
        ];
    }

    #[Computed]
    public function partnerCommissionsByPartner(): Collection
    {
        return $this->completedPartnerDemandsQuery()
            ->join('partners', 'partner_demands.partner_id', '=', 'partners.id')
            ->join('users', 'partners.user_id', '=', 'users.id')
            ->select('partners.id as partner_id', 'users.name as partner_name')
            ->selectRaw('COUNT(*) AS completed_demands_count')
            ->selectRaw('SUM('.$this->partnerCommissionExpression().') AS commission_cents')
            ->groupBy('partners.id', 'users.name')
            ->orderBy('users.name')
            ->get();
    }

    #[Computed]
    public function partnerCommissionDetails(): LengthAwarePaginator
    {
        return $this->completedPartnerDemandsQuery()
            ->select('partner_demands.*')
            ->selectRaw($this->partnerCommissionExpression().' AS commission_cents')
            ->with([
                'partner.user',
                'requestService.dentistRequest.dentist.user',
                'requestService.service',
                'serviceServiceStep.serviceStep',
            ])
            ->orderByDesc('partner_demands.ended_at')
            ->orderByDesc('partner_demands.id')
            ->paginate(15, ['partner_demands.*'], 'partnerPage');
    }

    #[Computed]
    public function revenuesByPeriod(): Collection
    {
        return $this->revenueLines()
            ->selectRaw('DATE(dentist_requests.created_at) AS period')
            ->selectRaw('COUNT(DISTINCT dentist_requests.id) AS orders_count')
            ->selectRaw('COALESCE(SUM(request_services.unit_price * request_services.quantity), 0) AS revenue_cents')
            ->selectRaw('COUNT(CASE WHEN request_services.unit_price IS NULL OR request_services.quantity IS NULL THEN 1 END) AS incomplete_lines_count')
            ->groupByRaw('DATE(dentist_requests.created_at)')
            ->orderByDesc('period')
            ->get();
    }

    #[Computed]
    public function revenuesByDentist(): Collection
    {
        return $this->revenueLines()
            ->join('dentists', 'dentist_requests.dentist_id', '=', 'dentists.id')
            ->join('users', 'dentists.user_id', '=', 'users.id')
            ->select('dentists.id as dentist_id', 'users.name as dentist_name')
            ->selectRaw('COUNT(DISTINCT dentist_requests.id) AS orders_count')
            ->selectRaw('COALESCE(SUM(request_services.unit_price * request_services.quantity), 0) AS revenue_cents')
            ->selectRaw('COUNT(CASE WHEN request_services.unit_price IS NULL OR request_services.quantity IS NULL THEN 1 END) AS incomplete_lines_count')
            ->groupBy('dentists.id', 'users.name')
            ->orderBy('users.name')
            ->get();
    }

    #[Computed]
    public function revenuesByService(): Collection
    {
        return $this->revenueLines()
            ->join('services', 'request_services.service_id', '=', 'services.id')
            ->select('services.id as service_id', 'services.name as service_name')
            ->selectRaw('COUNT(DISTINCT dentist_requests.id) AS orders_count')
            ->selectRaw('COALESCE(SUM(request_services.unit_price * request_services.quantity), 0) AS revenue_cents')
            ->selectRaw('COUNT(CASE WHEN request_services.unit_price IS NULL OR request_services.quantity IS NULL THEN 1 END) AS incomplete_lines_count')
            ->groupBy('services.id', 'services.name')
            ->orderBy('services.name')
            ->get();
    }

    public function canCalculateProfit(DentistRequest $order): bool
    {
        return (int) $order->revenue_lines_count > 0
            && (int) $order->incomplete_revenue_lines_count === 0
            && (int) $order->material_movements_count > 0
            && (int) $order->unpriced_material_movements_count === 0
            && (int) $order->pending_partner_demands_count === 0
            && (int) $order->unpriced_partner_demands_count === 0;
    }

    public function grossProfitCents(DentistRequest $order): ?int
    {
        if (! $this->canCalculateProfit($order)) {
            return null;
        }

        $profit = (int) $order->revenue_cents - (int) $order->cmv_cents;
        $profit -= (int) $order->partner_commissions_cents;

        return $profit;
    }

    public function grossMarginPercentage(DentistRequest $order): ?float
    {
        if (! $this->canCalculateProfit($order) || (int) $order->revenue_cents <= 0) {
            return null;
        }

        return $this->grossProfitCents($order) / (int) $order->revenue_cents * 100;
    }

    public function orderCostStatus(DentistRequest $order): string
    {
        return match (true) {
            (int) $order->incomplete_revenue_lines_count > 0 || (int) $order->revenue_lines_count === 0 => 'Receita incompleta',
            (int) $order->material_movements_count === 0 => 'Sem consumo vinculado',
            (int) $order->unpriced_material_movements_count > 0 => 'Custo parcial',
            (int) $order->pending_partner_demands_count > 0 => 'Comissões pendentes',
            (int) $order->unpriced_partner_demands_count > 0 => 'Comissão incompleta',
            default => 'Completo',
        };
    }

    public function activeRevenueRows(): Collection
    {
        return match ($this->revenueGroup) {
            'dentist' => $this->revenuesByDentist,
            'service' => $this->revenuesByService,
            default => $this->revenuesByPeriod,
        };
    }

    public function revenueRowName(object $row): string
    {
        return match ($this->revenueGroup) {
            'dentist' => $row->dentist_name,
            'service' => $row->service_name,
            default => Carbon::parse($row->period)->format('d/m/Y'),
        };
    }

    private function ordersQuery(): Builder
    {
        return DentistRequest::query()
            ->whereBetween('created_at', [
                Carbon::parse($this->appliedDateFrom)->startOfDay(),
                Carbon::parse($this->appliedDateTo)->endOfDay(),
            ])
            ->when($this->dentistId, fn (Builder $query) => $query->where('dentist_id', $this->dentistId));
    }

    private function revenueLines(): Builder
    {
        return RequestService::query()
            ->join('dentist_requests', 'request_services.dentist_request_id', '=', 'dentist_requests.id')
            ->whereBetween('dentist_requests.created_at', [
                Carbon::parse($this->appliedDateFrom)->startOfDay(),
                Carbon::parse($this->appliedDateTo)->endOfDay(),
            ])
            ->when($this->dentistId, fn (Builder $query) => $query->where('dentist_requests.dentist_id', $this->dentistId))
            ->when($this->serviceId, fn (Builder $query) => $query->where('request_services.service_id', $this->serviceId));
    }

    private function serviceConsumptionMovements(Builder $query): Builder
    {
        return $query
            ->where('type', StockMovementsTypes::Out->value)
            ->where('reason', StockMovementsReasons::ServiceConsumption->value);
    }

    private function partnerDemandsQuery(): Builder
    {
        return PartnerDemand::query()
            ->join('request_services', 'partner_demands.request_service_id', '=', 'request_services.id')
            ->join('dentist_requests', 'request_services.dentist_request_id', '=', 'dentist_requests.id')
            ->whereBetween('dentist_requests.created_at', [
                Carbon::parse($this->appliedDateFrom)->startOfDay(),
                Carbon::parse($this->appliedDateTo)->endOfDay(),
            ])
            ->when($this->dentistId, fn (Builder $query) => $query->where('dentist_requests.dentist_id', $this->dentistId));
    }

    private function completedPartnerDemandsQuery(): Builder
    {
        return $this->partnerDemandsQuery()
            ->whereNotNull('partner_demands.partner_id')
            ->whereNotNull('partner_demands.ended_at');
    }

    private function completedPartnerDemands(Builder $query): Builder
    {
        return $query
            ->whereNotNull('partner_demands.partner_id')
            ->whereNotNull('partner_demands.ended_at');
    }

    private function pendingPartnerDemands(Builder $query): Builder
    {
        return $query->where(function (Builder $query) {
            $query->where(function (Builder $query) {
                $query->whereNotNull('partner_demands.partner_id')
                    ->whereNull('partner_demands.ended_at');
            })
                ->orWhere(function (Builder $query) {
                    $query->whereNull('partner_demands.partner_id')
                        ->whereHas('serviceServiceStep.serviceStep.partners');
                });
        });
    }

    private function unpricedPartnerDemands(Builder $query): Builder
    {
        return $query->where(function (Builder $query) {
            $query->whereNull('partner_demands.partner_commission')
                ->orWhereNull('partner_demands.partner_commission_type')
                ->orWhereNotIn('partner_demands.partner_commission_type', [
                    CommissionTypes::Percentage->value,
                    CommissionTypes::Fixed->value,
                ])
                ->orWhere(function (Builder $query) {
                    $query->where('partner_demands.partner_commission_type', CommissionTypes::Percentage->value)
                        ->where(function (Builder $query) {
                            $query->whereNull('request_services.unit_price')
                                ->orWhereNull('request_services.quantity');
                        });
                });
        });
    }

    private function partnerCommissionExpression(): string
    {
        return "CASE
            WHEN partner_demands.partner_commission_type = '".CommissionTypes::Percentage->value."'
                THEN ROUND(partner_demands.partner_commission * request_services.unit_price * request_services.quantity / 100.0)
            WHEN partner_demands.partner_commission_type = '".CommissionTypes::Fixed->value."'
                THEN partner_demands.partner_commission * 100
            ELSE NULL
        END";
    }
};
?>

<div class="space-y-6">
    <div>
        <flux:heading size="xl">Relatórios gerenciais</flux:heading>
        <flux:subheading class="mt-1">Faturamento, custos e comissões dos pedidos do laboratório.</flux:subheading>
    </div>

    <flux:card class="space-y-4">
        <form wire:submit="applyFilters" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
            <flux:field>
                <flux:label>Data inicial</flux:label>
                <flux:input type="date" wire:model="dateFrom" />
                <flux:error name="dateFrom" />
            </flux:field>
            <flux:field>
                <flux:label>Data final</flux:label>
                <flux:input type="date" wire:model="dateTo" />
                <flux:error name="dateTo" />
            </flux:field>
            <flux:field>
                <flux:label>Dentista</flux:label>
                <flux:select wire:model="dentistId">
                    <flux:select.option value="">Todos</flux:select.option>
                    @foreach ($this->dentists as $dentist)
                        <flux:select.option :value="$dentist->id">{{ $dentist->user->name }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:error name="dentistId" />
            </flux:field>
            @if ($activeReport === 'revenue')
                <flux:field>
                    <flux:label>Serviço</flux:label>
                    <flux:select wire:model="serviceId">
                        <flux:select.option value="">Todos</flux:select.option>
                        @foreach ($this->services as $service)
                            <flux:select.option :value="$service->id">{{ $service->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:error name="serviceId" />
                </flux:field>
            @endif
            <div class="flex items-end">
                <flux:button type="submit" icon="funnel" class="w-full">Aplicar filtros</flux:button>
            </div>
        </form>
    </flux:card>

    <div class="flex flex-wrap gap-2">
        <flux:button
            variant="{{ $activeReport === 'profitability' ? 'primary' : 'ghost' }}"
            wire:click="$set('activeReport', 'profitability')"
        >
            Lucratividade por pedido (CMV)
        </flux:button>
        <flux:button
            variant="{{ $activeReport === 'revenue' ? 'primary' : 'ghost' }}"
            wire:click="$set('activeReport', 'revenue')"
        >
            Faturamento
        </flux:button>
        <flux:button
            variant="{{ $activeReport === 'partners' ? 'primary' : 'ghost' }}"
            wire:click="$set('activeReport', 'partners')"
        >
            Comissões de parceiros
        </flux:button>
    </div>

    @if ($activeReport === 'profitability')
        @php($summary = $this->profitabilitySummary)
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
            <flux:card>
                <flux:text>Pedidos no período</flux:text>
                <flux:heading size="xl" class="mt-2">{{ number_format($summary['orders_count'], 0, ',', '.') }}</flux:heading>
            </flux:card>
            <flux:card>
                <flux:text>Faturamento registrado</flux:text>
                <flux:heading size="xl" class="mt-2">{{ currency_from_int($summary['revenue_cents'], true) }}</flux:heading>
            </flux:card>
            <flux:card>
                <flux:text>CMV registrado</flux:text>
                <flux:heading size="xl" class="mt-2">{{ currency_from_int($summary['cmv_cents'], true) }}</flux:heading>
            </flux:card>
            <flux:card>
                <flux:text>Comissões registradas</flux:text>
                <flux:heading size="xl" class="mt-2">{{ currency_from_int($summary['partner_commissions_cents'], true) }}</flux:heading>
            </flux:card>
            <flux:card>
                <flux:text>Custos sem valor</flux:text>
                <flux:heading size="xl" class="mt-2 {{ $summary['unpriced_movements'] + $summary['unpriced_partner_demands'] > 0 ? 'text-amber-600 dark:text-amber-400' : '' }}">
                    {{ number_format($summary['unpriced_movements'] + $summary['unpriced_partner_demands'], 0, ',', '.') }}
                </flux:heading>
            </flux:card>
        </div>

        <flux:callout icon="information-circle" variant="warning">
            <flux:callout.heading>Como ler este relatório</flux:callout.heading>
            <flux:callout.text>
                O CMV considera saídas de estoque vinculadas ao pedido com custo unitário informado. As comissões concluídas entram no cálculo do lucro; percentuais incidem sobre o valor total do serviço (preço unitário × quantidade).
                O lucro bruto por pedido só aparece quando receita e custos considerados estão completos. Não inclui impostos, retrabalho, perdas ou despesas fixas.
            </flux:callout.text>
        </flux:callout>

        @if ($summary['orders_without_consumption'] > 0 || $summary['incomplete_revenue_lines'] > 0 || $summary['pending_partner_demands'] > 0 || $summary['unpriced_partner_demands'] > 0)
            <flux:callout icon="exclamation-triangle" variant="warning">
                {{ $summary['orders_without_consumption'] }} pedido(s) sem consumo de material vinculado e
                {{ $summary['incomplete_revenue_lines'] }} item(ns) com receita incompleta.
                Há {{ $summary['pending_partner_demands'] }} demanda(s) de parceiro pendente(s) e
                {{ $summary['unpriced_partner_demands'] }} comissão(ões) concluída(s) sem valor calculável.
                Os custos registrados podem estar parciais.
            </flux:callout>
        @endif

        <flux:card class="!py-2 !px-4 overflow-hidden">
            <flux:table :paginate="$this->orders">
                <flux:table.columns>
                    <flux:table.column>Pedido / Dentista</flux:table.column>
                    <flux:table.column>Data</flux:table.column>
                    <flux:table.column>Faturamento</flux:table.column>
                    <flux:table.column>CMV</flux:table.column>
                    <flux:table.column>Comissões</flux:table.column>
                    <flux:table.column>Lucro bruto*</flux:table.column>
                    <flux:table.column>Margem</flux:table.column>
                    <flux:table.column>Situação dos custos</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @if ($this->orders->isEmpty())
                        <flux:table.row>
                            <flux:table.cell colspan="8">
                                <x-empty-state
                                    inset
                                    icon="document-chart-bar"
                                    title="Nenhum pedido encontrado"
                                    description="Nenhum pedido encontrado para os filtros selecionados."
                                />
                            </flux:table.cell>
                        </flux:table.row>
                    @else
                        @foreach ($this->orders as $order)
                            <flux:table.row :key="$order->id">
                                <flux:table.cell>
                                    <div class="font-medium">{{ $order->code }}</div>
                                    <div class="text-xs text-zinc-500">{{ $order->dentist->user->name }}</div>
                                </flux:table.cell>
                                <flux:table.cell>{{ $order->created_at->format('d/m/Y') }}</flux:table.cell>
                                <flux:table.cell>
                                    {{ $order->revenue_cents === null ? '—' : currency_from_int((int) $order->revenue_cents, true) }}
                                </flux:table.cell>
                                <flux:table.cell>
                                    {{ (int) $order->material_movements_count === 0 ? '—' : currency_from_int((int) $order->cmv_cents, true) }}
                                </flux:table.cell>
                                <flux:table.cell>
                                    {{ $order->partner_commissions_cents === null ? '—' : currency_from_int((int) $order->partner_commissions_cents, true) }}
                                </flux:table.cell>
                                <flux:table.cell>
                                    {{ $this->grossProfitCents($order) === null ? '—' : currency_from_int($this->grossProfitCents($order), true) }}
                                </flux:table.cell>
                                <flux:table.cell>
                                    {{ $this->grossMarginPercentage($order) === null ? '—' : number_format($this->grossMarginPercentage($order), 1, ',', '.') . '%' }}
                                </flux:table.cell>
                                <flux:table.cell>
                                    <flux:badge color="{{ $this->orderCostStatus($order) === 'Completo' ? 'green' : 'amber' }}">
                                        {{ $this->orderCostStatus($order) }}
                                    </flux:badge>
                                </flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    @endif
                </flux:table.rows>
            </flux:table>
        </flux:card>
        <flux:text size="sm">
            * Lucro bruto = faturamento − CMV dos materiais − comissões concluídas dos parceiros.
        </flux:text>
    @elseif ($activeReport === 'revenue')
        @php($summary = $this->revenueSummary)
        <div class="grid gap-4 sm:grid-cols-2">
            <flux:card>
                <flux:text>Faturamento registrado</flux:text>
                <flux:heading size="xl" class="mt-2">{{ currency_from_int($summary['revenue_cents'], true) }}</flux:heading>
            </flux:card>
            <flux:card>
                <flux:text>Pedidos no período</flux:text>
                <flux:heading size="xl" class="mt-2">{{ number_format($summary['orders_count'], 0, ',', '.') }}</flux:heading>
            </flux:card>
        </div>

        @if ($summary['incomplete_lines_count'] > 0)
            <flux:callout icon="exclamation-triangle" variant="warning">
                {{ $summary['incomplete_lines_count'] }} item(ns) sem preço ou quantidade; o total de faturamento está parcial.
            </flux:callout>
        @endif

        <div class="flex flex-wrap gap-2">
            <flux:button variant="{{ $revenueGroup === 'period' ? 'primary' : 'ghost' }}" wire:click="$set('revenueGroup', 'period')">
                Por período
            </flux:button>
            <flux:button variant="{{ $revenueGroup === 'dentist' ? 'primary' : 'ghost' }}" wire:click="$set('revenueGroup', 'dentist')">
                Por dentista
            </flux:button>
            <flux:button variant="{{ $revenueGroup === 'service' ? 'primary' : 'ghost' }}" wire:click="$set('revenueGroup', 'service')">
                Por serviço
            </flux:button>
        </div>

        <flux:card class="!p-0 overflow-hidden">
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ $revenueGroup === 'period' ? 'Período' : ($revenueGroup === 'dentist' ? 'Dentista' : 'Serviço') }}</flux:table.column>
                    <flux:table.column>Pedidos</flux:table.column>
                    <flux:table.column>Faturamento</flux:table.column>
                    <flux:table.column>Itens incompletos</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @if ($this->activeRevenueRows()->isEmpty())
                        <flux:table.row>
                            <flux:table.cell colspan="4">
                                <x-empty-state
                                    inset
                                    icon="banknotes"
                                    title="Nenhum faturamento encontrado"
                                    description="Nenhum faturamento encontrado para os filtros selecionados."
                                />
                            </flux:table.cell>
                        </flux:table.row>
                    @else
                        @foreach ($this->activeRevenueRows() as $row)
                            <flux:table.row :key="$revenueGroup.'-'.$loop->index">
                                <flux:table.cell>{{ $this->revenueRowName($row) }}</flux:table.cell>
                                <flux:table.cell>{{ number_format((int) $row->orders_count, 0, ',', '.') }}</flux:table.cell>
                                <flux:table.cell>{{ currency_from_int((int) $row->revenue_cents, true) }}</flux:table.cell>
                                <flux:table.cell>{{ number_format((int) $row->incomplete_lines_count, 0, ',', '.') }}</flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    @endif
                </flux:table.rows>
            </flux:table>
        </flux:card>
    @else
        @php($summary = $this->partnerCommissionSummary)
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <flux:card>
                <flux:text>Comissões registradas</flux:text>
                <flux:heading size="xl" class="mt-2">{{ currency_from_int($summary['commission_cents'], true) }}</flux:heading>
            </flux:card>
            <flux:card>
                <flux:text>Parceiros com demandas concluídas</flux:text>
                <flux:heading size="xl" class="mt-2">{{ number_format($summary['partners_count'], 0, ',', '.') }}</flux:heading>
            </flux:card>
            <flux:card>
                <flux:text>Demandas concluídas</flux:text>
                <flux:heading size="xl" class="mt-2">{{ number_format($summary['completed_demands_count'], 0, ',', '.') }}</flux:heading>
            </flux:card>
            <flux:card>
                <flux:text>Pendentes ou sem valor</flux:text>
                <flux:heading size="xl" class="mt-2 {{ $summary['pending_demands_count'] + $summary['unpriced_demands_count'] > 0 ? 'text-amber-600 dark:text-amber-400' : '' }}">
                    {{ number_format($summary['pending_demands_count'] + $summary['unpriced_demands_count'], 0, ',', '.') }}
                </flux:heading>
            </flux:card>
        </div>

        <flux:callout icon="information-circle" variant="warning">
            <flux:callout.heading>Como ler este relatório</flux:callout.heading>
            <flux:callout.text>
                São contabilizadas apenas demandas concluídas. A comissão percentual é aplicada ao faturamento total da linha do serviço (preço unitário × quantidade); o valor fixo é contabilizado uma vez por demanda. Demandas pendentes e comissões sem dados suficientes não entram nos totais.
            </flux:callout.text>
        </flux:callout>

        @if ($summary['pending_demands_count'] > 0 || $summary['unpriced_demands_count'] > 0)
            <flux:callout icon="exclamation-triangle" variant="warning">
                {{ $summary['pending_demands_count'] }} demanda(s) de parceiro ainda não concluída(s) e
                {{ $summary['unpriced_demands_count'] }} demanda(s) concluída(s) sem comissão calculável.
            </flux:callout>
        @endif

        <flux:card class="!py-2 !px-4 overflow-hidden">
            <flux:heading class="pt-2">Comissões por parceiro</flux:heading>
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>Parceiro</flux:table.column>
                    <flux:table.column>Demandas concluídas</flux:table.column>
                    <flux:table.column>Comissões registradas</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @if ($this->partnerCommissionsByPartner->isEmpty())
                        <flux:table.row>
                            <flux:table.cell colspan="3">
                                <x-empty-state
                                    inset
                                    icon="user-group"
                                    title="Nenhuma comissão de parceiro encontrada"
                                    description="Nenhuma comissão de parceiro encontrada para os filtros selecionados."
                                />
                            </flux:table.cell>
                        </flux:table.row>
                    @else
                        @foreach ($this->partnerCommissionsByPartner as $partner)
                            <flux:table.row :key="$partner->partner_id">
                                <flux:table.cell>{{ $partner->partner_name }}</flux:table.cell>
                                <flux:table.cell>{{ number_format((int) $partner->completed_demands_count, 0, ',', '.') }}</flux:table.cell>
                                <flux:table.cell>
                                    {{ $partner->commission_cents === null ? '—' : currency_from_int((int) $partner->commission_cents, true) }}
                                </flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    @endif
                </flux:table.rows>
            </flux:table>
        </flux:card>

        <flux:card class="!py-2 !px-4 overflow-hidden">
            <flux:heading class="pt-2">Demandas concluídas</flux:heading>
            <flux:table :paginate="$this->partnerCommissionDetails">
                <flux:table.columns>
                    <flux:table.column>Parceiro</flux:table.column>
                    <flux:table.column>Pedido / Dentista</flux:table.column>
                    <flux:table.column>Serviço / Etapa</flux:table.column>
                    <flux:table.column>Conclusão</flux:table.column>
                    <flux:table.column>Comissão</flux:table.column>
                    <flux:table.column>Custo calculado</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @if ($this->partnerCommissionDetails->isEmpty())
                        <flux:table.row>
                            <flux:table.cell colspan="6">
                                <x-empty-state
                                    inset
                                    icon="clipboard-document-check"
                                    title="Nenhuma demanda concluída encontrada"
                                    description="Nenhuma demanda concluída encontrada para os filtros selecionados."
                                />
                            </flux:table.cell>
                        </flux:table.row>
                    @else
                        @foreach ($this->partnerCommissionDetails as $demand)
                            <flux:table.row :key="$demand->id">
                                <flux:table.cell>{{ $demand->partner->user->name }}</flux:table.cell>
                                <flux:table.cell>
                                    <div class="font-medium">{{ $demand->requestService->dentistRequest->code }}</div>
                                    <div class="text-xs text-zinc-500">{{ $demand->requestService->dentistRequest->dentist->user->name }}</div>
                                </flux:table.cell>
                                <flux:table.cell>
                                    <div>{{ $demand->requestService->service->name }}</div>
                                    <div class="text-xs text-zinc-500">{{ $demand->serviceServiceStep->serviceStep->name }}</div>
                                </flux:table.cell>
                                <flux:table.cell>{{ $demand->ended_at->format('d/m/Y') }}</flux:table.cell>
                                <flux:table.cell>
                                    @if ($demand->partner_commission_type === CommissionTypes::Percentage->value && $demand->partner_commission !== null)
                                        {{ number_format((float) $demand->partner_commission, 2, ',', '.') }}%
                                    @elseif ($demand->partner_commission_type === CommissionTypes::Fixed->value && $demand->partner_commission !== null)
                                        {{ currency_from_int((int) $demand->partner_commission * 100, true) }}
                                    @else
                                        —
                                    @endif
                                </flux:table.cell>
                                <flux:table.cell>
                                    {{ $demand->commission_cents === null ? '—' : currency_from_int((int) $demand->commission_cents, true) }}
                                </flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    @endif
                </flux:table.rows>
            </flux:table>
        </flux:card>
    @endif
</div>
