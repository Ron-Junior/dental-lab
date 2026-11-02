<?php

use App\Enums\OperationalCostType;
use App\Models\CentralUser;
use App\Models\OperationalCost;
use App\Services\Billing\PlanMargin;
use App\Services\Billing\PlanProfitabilityCalculator;
use App\Services\Billing\ProfitabilityOverview;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.central')] class extends Component
{
    public ?string $editingCostId = null;

    public string $costName = '';

    public string $costType = 'fixed';

    public string $costAmount = '';

    public ?string $costNotes = null;

    public bool $costIsActive = true;

    public ?string $deletingCostId = null;

    #[Computed]
    public function costs(): Collection
    {
        $user = Auth::guard('central')->user();
        abort_unless($user instanceof CentralUser, 403);

        return OperationalCost::query()
            ->orderBy('type')
            ->orderBy('name')
            ->get();
    }

    /**
     * @return array<string, int>
     */
    #[Computed]
    public function costTotals(): array
    {
        return app(PlanProfitabilityCalculator::class)->activeCostTotals();
    }

    #[Computed]
    public function overview(): ProfitabilityOverview
    {
        return app(PlanProfitabilityCalculator::class)->overview();
    }

    /**
     * @return list<PlanMargin>
     */
    #[Computed]
    public function planMargins(): array
    {
        return app(PlanProfitabilityCalculator::class)->marginsForActivePlans();
    }

    public function openCreateCostModal(): void
    {
        $this->resetCostForm();
        Flux::modal('cost-form')->show();
    }

    public function openEditCostModal(string $costId): void
    {
        $cost = OperationalCost::query()->findOrFail($costId);

        $this->resetCostForm();
        $this->editingCostId = (string) $cost->id;
        $this->costName = (string) $cost->name;
        $this->costType = $cost->type->value;
        $this->costAmount = number_format($cost->amount / 100, 2, ',', '.');
        $this->costNotes = $cost->notes;
        $this->costIsActive = (bool) $cost->is_active;

        Flux::modal('cost-form')->show();
    }

    public function closeCostModal(): void
    {
        Flux::modal('cost-form')->close();
        $this->resetCostForm();
    }

    public function saveCost(): void
    {
        $user = Auth::guard('central')->user();
        abort_unless($user instanceof CentralUser, 403);

        $this->validate([
            'costName' => ['required', 'string', 'max:120'],
            'costType' => ['required', 'in:fixed,per_tenant,per_storage_gb,revenue_percentage'],
            'costAmount' => ['required', 'string'],
            'costNotes' => ['nullable', 'string', 'max:500'],
            'costIsActive' => ['boolean'],
        ], [
            'costName.required' => 'Informe o nome do custo operacional.',
            'costAmount.required' => 'Informe o valor ou percentual do custo.',
        ]);

        $type = OperationalCostType::from($this->costType);
        $rawAmount = $this->parseCostAmount($type, $this->costAmount);

        $attributes = [
            'name' => trim($this->costName),
            'type' => $type,
            'amount' => $rawAmount,
            'notes' => $this->costNotes ? trim($this->costNotes) : null,
            'is_active' => $this->costIsActive,
        ];

        if ($this->editingCostId !== null) {
            $cost = OperationalCost::query()->findOrFail($this->editingCostId);
            $cost->update($attributes);
            $heading = 'Custo atualizado';
        } else {
            OperationalCost::query()->create($attributes);
            $heading = 'Custo cadastrado';
        }

        $this->invalidateComputed();
        Flux::modal('cost-form')->close();
        $this->resetCostForm();

        Flux::toast(
            heading: $heading,
            text: 'A calculadora foi atualizada com o novo custo operacional.',
            variant: 'success',
        );
    }

    public function toggleCostStatus(string $costId): void
    {
        $user = Auth::guard('central')->user();
        abort_unless($user instanceof CentralUser, 403);

        $cost = OperationalCost::query()->findOrFail($costId);
        $cost->update(['is_active' => ! $cost->is_active]);

        $this->invalidateComputed();

        Flux::toast(
            heading: $cost->is_active ? 'Custo ativado' : 'Custo desativado',
            text: 'Os indicadores de rentabilidade foram recalculados.',
            variant: 'success',
        );
    }

    public function confirmDeleteCost(string $costId): void
    {
        $this->deletingCostId = $costId;
        Flux::modal('delete-cost')->show();
    }

    public function deleteCost(): void
    {
        $user = Auth::guard('central')->user();
        abort_unless($user instanceof CentralUser, 403);

        if ($this->deletingCostId === null) {
            return;
        }

        OperationalCost::query()->whereKey($this->deletingCostId)->delete();
        $this->deletingCostId = null;

        $this->invalidateComputed();
        Flux::modal('delete-cost')->close();

        Flux::toast(
            heading: 'Custo removido',
            text: 'O custo operacional foi removido com sucesso.',
            variant: 'success',
        );
    }

    public function recalculateStorage(PlanProfitabilityCalculator $calculator): void
    {
        $user = Auth::guard('central')->user();
        abort_unless($user instanceof CentralUser, 403);

        $calculator->forgetStorageCache();
        $this->invalidateComputed();

        Flux::toast(
            heading: 'Armazenamento recalculado',
            text: 'O consumo real de armazenamento dos laboratórios foi atualizado.',
            variant: 'success',
        );
    }

    private function parseCostAmount(OperationalCostType $type, string $raw): int
    {
        if ($type->isPercentage()) {
            $normalized = str_replace(['%', ' ', '.'], '', $raw);
            $normalized = str_replace(',', '.', $normalized);

            if (! is_numeric($normalized)) {
                throw ValidationException::withMessages([
                    'costAmount' => 'Informe um percentual válido (ex: 3,99).',
                ]);
            }

            $basisPoints = (int) round((float) $normalized * 100);
            if ($basisPoints <= 0 || $basisPoints > 10000) {
                throw ValidationException::withMessages([
                    'costAmount' => 'O percentual deve estar entre 0,01% e 100%.',
                ]);
            }

            return $basisPoints;
        }

        $cents = currency_to_int($raw);
        if ($cents <= 0) {
            throw ValidationException::withMessages([
                'costAmount' => 'Informe um valor maior que zero.',
            ]);
        }

        return $cents;
    }

    private function invalidateComputed(): void
    {
        unset($this->costs, $this->costTotals, $this->overview, $this->planMargins);
    }

    private function resetCostForm(): void
    {
        $this->editingCostId = null;
        $this->costName = '';
        $this->costType = OperationalCostType::Fixed->value;
        $this->costAmount = '';
        $this->costNotes = null;
        $this->costIsActive = true;
        $this->resetErrorBag();
    }
}; ?>

<div class="min-h-screen bg-zinc-50 dark:bg-zinc-950">
    <main class="mx-auto flex w-full max-w-6xl flex-col gap-8 px-4 py-8 sm:px-6 lg:px-8">
        <header class="flex flex-col gap-5 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex items-center gap-3">
                <x-icons.logo class="size-11 text-zinc-900 dark:text-white" />
                <div>
                    <flux:text class="text-sm font-medium text-zinc-500 dark:text-zinc-400">PROTHO • PAINEL DOS SÓCIOS</flux:text>
                    <flux:heading size="xl">Rentabilidade e Custos Operacionais</flux:heading>
                </div>
            </div>

            <div class="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-center">
                <flux:button href="{{ route('central.laboratories.index') }}" variant="ghost" icon="arrow-left">
                    Laboratórios
                </flux:button>
                <flux:button href="{{ route('central.plans.index') }}" variant="subtle" icon="squares-2x2">
                    Planos
                </flux:button>
                <flux:button wire:click="recalculateStorage" variant="ghost" icon="arrow-path">
                    Recalcular
                </flux:button>
                <flux:button wire:click="openCreateCostModal" variant="primary" icon="plus">
                    Novo custo
                </flux:button>
            </div>
        </header>

        @php
            $overview = $this->overview;
            $totals = $this->costTotals;
        @endphp

        {{-- Seção 1: Visão real do mês corrente --}}
        <section aria-label="Visão real da operação" class="space-y-4">
            <div class="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <flux:heading size="lg">Visão real (mês atual)</flux:heading>
                    <flux:text class="text-sm">
                        Receita recorrente das assinaturas ativas (excluindo isentos) comparada aos custos totais e armazenamento real consumido.
                    </flux:text>
                </div>

                <div>
                    @if ($overview->status === 'loss')
                        <flux:badge color="red">Prejuízo</flux:badge>
                    @elseif ($overview->status === 'tight')
                        <flux:badge color="amber">Apertado (&lt; 20%)</flux:badge>
                    @elseif ($overview->status === 'healthy')
                        <flux:badge color="emerald">Saudável</flux:badge>
                    @else
                        <flux:badge color="zinc">Sem operação</flux:badge>
                    @endif
                </div>
            </div>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                    <flux:text class="text-xs font-medium uppercase tracking-wider text-zinc-500">Receita Mensal (MRR)</flux:text>
                    <div class="mt-2 text-2xl font-extrabold text-zinc-900 dark:text-white">
                        {{ currency_from_int($overview->mrrInCents, true) }}
                    </div>
                    <flux:text class="mt-1 text-xs text-zinc-500">
                        {{ $overview->payingTenantsCount }} {{ $overview->payingTenantsCount === 1 ? 'laboratório pagante' : 'laboratórios pagantes' }}
                    </flux:text>
                </div>

                <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                    <flux:text class="text-xs font-medium uppercase tracking-wider text-zinc-500">Custos Operacionais</flux:text>
                    <div class="mt-2 text-2xl font-extrabold text-zinc-900 dark:text-white">
                        {{ currency_from_int($overview->totalCostsInCents, true) }}
                    </div>
                    <flux:text class="mt-1 text-xs text-zinc-500">
                        {{ $overview->consumingTenantsCount }} em operação ({{ $overview->trialTenantsCount }} trial, {{ $overview->exemptTenantsCount }} isentos)
                    </flux:text>
                </div>

                <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                    <flux:text class="text-xs font-medium uppercase tracking-wider text-zinc-500">Resultado Líquido</flux:text>
                    <div @class([
                        'mt-2 text-2xl font-extrabold',
                        'text-red-600 dark:text-red-400' => $overview->netResultInCents < 0,
                        'text-emerald-600 dark:text-emerald-400' => $overview->netResultInCents > 0,
                        'text-zinc-900 dark:text-white' => $overview->netResultInCents === 0,
                    ])>
                        {{ currency_from_int($overview->netResultInCents, true) }}
                    </div>
                    <flux:text class="mt-1 text-xs text-zinc-500">
                        Armazenamento real: {{ number_format($overview->totalStorageGb, 2, ',', '.') }} GB
                    </flux:text>
                </div>

                <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                    <flux:text class="text-xs font-medium uppercase tracking-wider text-zinc-500">Margem Operacional</flux:text>
                    <div class="mt-2 text-2xl font-extrabold text-zinc-900 dark:text-white">
                        {{ number_format($overview->marginPercentage, 2, ',', '.') }}%
                    </div>
                    <flux:text class="mt-1 text-xs text-zinc-500">
                        Status: {{ $overview->statusLabel() }}
                    </flux:text>
                </div>
            </div>

            {{-- Decomposição dos custos reais --}}
            <div class="grid grid-cols-1 gap-3 rounded-2xl border border-zinc-200 bg-white p-4 text-sm sm:grid-cols-2 lg:grid-cols-4 dark:border-zinc-800 dark:bg-zinc-900">
                <div class="flex items-center justify-between sm:flex-col sm:items-start sm:gap-1">
                    <span class="text-zinc-500 dark:text-zinc-400">Custos fixos</span>
                    <span class="font-semibold text-zinc-900 dark:text-white">{{ currency_from_int($overview->fixedCostsInCents, true) }}</span>
                </div>
                <div class="flex items-center justify-between sm:flex-col sm:items-start sm:gap-1">
                    <span class="text-zinc-500 dark:text-zinc-400">Por laboratório ({{ $overview->consumingTenantsCount }}x)</span>
                    <span class="font-semibold text-zinc-900 dark:text-white">{{ currency_from_int($overview->perTenantCostsInCents, true) }}</span>
                </div>
                <div class="flex items-center justify-between sm:flex-col sm:items-start sm:gap-1">
                    <span class="text-zinc-500 dark:text-zinc-400">Armazenamento ({{ number_format($overview->totalStorageGb, 2, ',', '.') }} GB)</span>
                    <span class="font-semibold text-zinc-900 dark:text-white">{{ currency_from_int($overview->storageCostsInCents, true) }}</span>
                </div>
                <div class="flex items-center justify-between sm:flex-col sm:items-start sm:gap-1">
                    <span class="text-zinc-500 dark:text-zinc-400">Taxas sobre receita</span>
                    <span class="font-semibold text-zinc-900 dark:text-white">{{ currency_from_int($overview->percentageCostsInCents, true) }}</span>
                </div>
            </div>
        </section>

        {{-- Seção 2: Margem por plano --}}
        <section aria-label="Margem por plano" class="space-y-4">
            <div>
                <flux:heading size="lg">Margem por plano</flux:heading>
                <flux:text class="text-sm">
                    Compara o valor mensal de cada plano ativo com as taxas, o custo por laboratório, o armazenamento (médio real e pior caso pelo limite) e o rateio do custo fixo.
                </flux:text>
            </div>

            {{-- Cards Mobile --}}
            <div class="grid grid-cols-1 gap-4 lg:hidden">
                @forelse ($this->planMargins as $item)
                    <article
                        wire:key="plan-margin-mobile-{{ $item->plan->id }}"
                        class="space-y-3 rounded-2xl border border-zinc-200 bg-white p-5 shadow-xs dark:border-zinc-800 dark:bg-zinc-900"
                    >
                        <div class="flex items-center justify-between">
                            <div>
                                <flux:heading size="md">{{ $item->plan->name }}</flux:heading>
                                <flux:text class="text-xs text-zinc-500">
                                    {{ currency_from_int($item->monthlyPriceInCents, true) }}/mês • {{ $item->subscribersCount }} assinantes
                                </flux:text>
                            </div>
                            <flux:badge :color="$item->badgeColor()" size="sm">{{ $item->statusLabel() }}</flux:badge>
                        </div>

                        <dl class="space-y-1.5 border-t border-zinc-100 pt-3 text-xs dark:border-zinc-800">
                            <div class="flex justify-between">
                                <dt class="text-zinc-500">Taxa % + Custo/lab</dt>
                                <dd class="font-medium text-zinc-900 dark:text-white">
                                    {{ currency_from_int($item->percentageCostInCents + $item->perTenantCostInCents, true) }}
                                </dd>
                            </div>
                            <div class="flex justify-between">
                                <dt class="text-zinc-500">Rateio do custo fixo</dt>
                                <dd class="font-medium text-zinc-900 dark:text-white">
                                    {{ $item->hasFixedCostAllocation() ? currency_from_int($item->fixedCostShareInCents, true) : 'Rateio indisponível' }}
                                </dd>
                            </div>
                            <div class="flex justify-between">
                                <dt class="text-zinc-500">Margem (uso médio: {{ number_format($item->averageStorageGb, 2, ',', '.') }} GB)</dt>
                                <dd class="font-semibold text-zinc-900 dark:text-white">
                                    {{ currency_from_int($item->averageMarginInCents, true) }} ({{ number_format($item->averageMarginPercentage, 1, ',', '.') }}%)
                                </dd>
                            </div>
                            <div class="flex justify-between">
                                <dt class="text-zinc-500">Margem (pior caso: {{ $item->storageLimitGb !== null ? $item->storageLimitGb.' GB' : 'ilimitado' }})</dt>
                                <dd class="font-semibold text-zinc-900 dark:text-white">
                                    @if ($item->worstCaseMarginInCents !== null && $item->worstCaseMarginPercentage !== null)
                                        {{ currency_from_int($item->worstCaseMarginInCents, true) }} ({{ number_format($item->worstCaseMarginPercentage, 1, ',', '.') }}%)
                                    @else
                                        —
                                    @endif
                                </dd>
                            </div>
                        </dl>
                    </article>
                @empty
                    <div class="rounded-2xl border border-dashed border-zinc-300 bg-white p-6 text-center text-sm text-zinc-500 dark:border-zinc-800 dark:bg-zinc-900">
                        Nenhum plano ativo disponível para cálculo.
                    </div>
                @endforelse
            </div>

            {{-- Tabela Desktop --}}
            <div class="hidden overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-xs lg:block dark:border-zinc-800 dark:bg-zinc-900">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-zinc-200 bg-zinc-50 text-xs uppercase text-zinc-500 dark:border-zinc-800 dark:bg-zinc-950/60 dark:text-zinc-400">
                        <tr>
                            <th class="px-5 py-3.5">Plano</th>
                            <th class="px-5 py-3.5">Preço mensal</th>
                            <th class="px-5 py-3.5">Taxa % + Lab</th>
                            <th class="px-5 py-3.5">Rateio fixo</th>
                            <th class="px-5 py-3.5">Margem (uso médio)</th>
                            <th class="px-5 py-3.5">Margem (pior caso)</th>
                            <th class="px-5 py-3.5 text-right">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                        @forelse ($this->planMargins as $item)
                            <tr wire:key="plan-margin-row-{{ $item->plan->id }}">
                                <td class="px-5 py-4">
                                    <div class="font-semibold text-zinc-900 dark:text-white">{{ $item->plan->name }}</div>
                                    <div class="text-xs text-zinc-500">{{ $item->subscribersCount }} assinantes</div>
                                </td>
                                <td class="px-5 py-4 font-medium text-zinc-900 dark:text-white">
                                    {{ currency_from_int($item->monthlyPriceInCents, true) }}
                                </td>
                                <td class="px-5 py-4 text-zinc-700 dark:text-zinc-300">
                                    {{ currency_from_int($item->percentageCostInCents + $item->perTenantCostInCents, true) }}
                                </td>
                                <td class="px-5 py-4 text-zinc-700 dark:text-zinc-300">
                                    @if ($item->hasFixedCostAllocation())
                                        {{ currency_from_int($item->fixedCostShareInCents, true) }}
                                    @else
                                        <span class="text-xs text-zinc-400">Rateio indisponível</span>
                                    @endif
                                </td>
                                <td class="px-5 py-4">
                                    <div class="font-semibold text-zinc-900 dark:text-white">
                                        {{ currency_from_int($item->averageMarginInCents, true) }}
                                        <span class="text-xs font-normal text-zinc-500">({{ number_format($item->averageMarginPercentage, 1, ',', '.') }}%)</span>
                                    </div>
                                    <div class="text-xs text-zinc-500">
                                        Média {{ number_format($item->averageStorageGb, 2, ',', '.') }} GB
                                    </div>
                                </td>
                                <td class="px-5 py-4">
                                    @if ($item->worstCaseMarginInCents !== null && $item->worstCaseMarginPercentage !== null)
                                        <div class="font-semibold text-zinc-900 dark:text-white">
                                            {{ currency_from_int($item->worstCaseMarginInCents, true) }}
                                            <span class="text-xs font-normal text-zinc-500">({{ number_format($item->worstCaseMarginPercentage, 1, ',', '.') }}%)</span>
                                        </div>
                                        <div class="text-xs text-zinc-500">Limite {{ $item->storageLimitGb }} GB</div>
                                    @else
                                        <span class="text-zinc-400">—</span>
                                        <div class="text-xs text-zinc-500">Storage ilimitado</div>
                                    @endif
                                </td>
                                <td class="px-5 py-4 text-right">
                                    <flux:badge :color="$item->badgeColor()" size="sm">{{ $item->statusLabel() }}</flux:badge>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-5 py-8 text-center text-zinc-500">
                                    Nenhum plano ativo disponível para cálculo.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        {{-- Seção 3: Cadastro de custos operacionais --}}
        <section aria-label="Custos operacionais" class="space-y-4">
            <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <flux:heading size="lg">Custos operacionais cadastrados</flux:heading>
                    <flux:text class="text-sm">
                        Cadastre servidores, ferramentas, taxas do Stripe e custos de armazenamento por GB. Apenas custos ativos entram nos cálculos.
                    </flux:text>
                </div>

                <flux:button wire:click="openCreateCostModal" variant="filled" icon="plus" class="w-full sm:w-auto">
                    Adicionar custo
                </flux:button>
            </div>

            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                @foreach (OperationalCostType::cases() as $costTypeCase)
                    <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-900">
                        <flux:text class="text-xs text-zinc-500">{{ $costTypeCase->label() }} (ativos)</flux:text>
                        <div class="mt-1 text-lg font-bold text-zinc-900 dark:text-white">
                            {{ $costTypeCase->format($totals[$costTypeCase->value] ?? 0) }}
                        </div>
                    </div>
                @endforeach
            </div>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @forelse ($this->costs as $cost)
                    <article
                        wire:key="cost-card-{{ $cost->id }}"
                        class="flex flex-col justify-between rounded-2xl border border-zinc-200 bg-white p-5 shadow-xs dark:border-zinc-800 dark:bg-zinc-900"
                    >
                        <div class="space-y-3">
                            <div class="flex items-start justify-between gap-2">
                                <div>
                                    <flux:heading size="md">{{ $cost->name }}</flux:heading>
                                    <flux:text class="text-xs text-zinc-500">{{ $cost->type->label() }}</flux:text>
                                </div>
                                @if ($cost->is_active)
                                    <flux:badge color="emerald" size="sm">Ativo</flux:badge>
                                @else
                                    <flux:badge color="zinc" size="sm">Inativo</flux:badge>
                                @endif
                            </div>

                            <div class="text-2xl font-extrabold text-zinc-900 dark:text-white">
                                {{ $cost->formatted_amount }}
                            </div>

                            @if ($cost->notes)
                                <flux:text class="text-xs text-zinc-500">{{ $cost->notes }}</flux:text>
                            @endif
                        </div>

                        <div class="mt-4 flex flex-wrap items-center justify-between gap-2 border-t border-zinc-100 pt-3 dark:border-zinc-800">
                            <div class="flex items-center gap-1">
                                <flux:button
                                    wire:click="openEditCostModal('{{ $cost->id }}')"
                                    variant="ghost"
                                    size="sm"
                                    icon="pencil-square"
                                >
                                    Editar
                                </flux:button>
                                <flux:button
                                    wire:click="toggleCostStatus('{{ $cost->id }}')"
                                    variant="ghost"
                                    size="sm"
                                >
                                    {{ $cost->is_active ? 'Desativar' : 'Ativar' }}
                                </flux:button>
                            </div>

                            <flux:button
                                wire:click="confirmDeleteCost('{{ $cost->id }}')"
                                variant="ghost"
                                size="sm"
                                icon="trash"
                            >
                                Excluir
                            </flux:button>
                        </div>
                    </article>
                @empty
                    <div class="col-span-full rounded-2xl border border-dashed border-zinc-300 bg-white p-8 text-center dark:border-zinc-800 dark:bg-zinc-900">
                        <flux:heading size="md">Nenhum custo operacional cadastrado</flux:heading>
                        <flux:text class="mt-1">
                            Adicione seus custos fixos (servidor, domínio), taxas do Stripe (ex: 3,99% e R$ 0,39/lab) e custo por GB para avaliar sua rentabilidade.
                        </flux:text>
                    </div>
                @endforelse
            </div>
        </section>

        {{-- Modal de criação e edição de custo operacional --}}
        <flux:modal name="cost-form" class="w-full max-w-md backdrop:backdrop-blur-sm" @close="closeCostModal">
            <div class="space-y-6">
                <div>
                    <flux:heading size="lg">
                        {{ $editingCostId ? 'Editar Custo Operacional' : 'Novo Custo Operacional' }}
                    </flux:heading>
                    <flux:subheading>
                        Defina o tipo e o valor mensal ou percentual do custo.
                    </flux:subheading>
                </div>

                <form wire:submit="saveCost" class="space-y-4">
                    <flux:field>
                        <flux:label>Nome do custo</flux:label>
                        <flux:input wire:model="costName" placeholder="Ex: Servidor VPS / Taxa Stripe" required />
                        <flux:error name="costName" />
                    </flux:field>

                    <flux:field>
                        <flux:label>Tipo de custo</flux:label>
                        <flux:select wire:model.live="costType">
                            @foreach (OperationalCostType::cases() as $typeOption)
                                <option value="{{ $typeOption->value }}">{{ $typeOption->label() }}</option>
                            @endforeach
                        </flux:select>
                        <flux:error name="costType" />
                    </flux:field>

                    <flux:field>
                        <flux:label>
                            {{ $costType === OperationalCostType::RevenuePercentage->value ? 'Percentual sobre a receita (%)' : 'Valor (R$)' }}
                        </flux:label>
                        <flux:input
                            wire:model="costAmount"
                            placeholder="{{ $costType === OperationalCostType::RevenuePercentage->value ? '3,99' : '300,00' }}"
                            required
                        />
                        <flux:error name="costAmount" />
                    </flux:field>

                    <flux:field>
                        <flux:label>Observações (opcional)</flux:label>
                        <flux:input wire:model="costNotes" placeholder="Ex: Cobrado mensalmente em dólar" />
                        <flux:error name="costNotes" />
                    </flux:field>

                    <flux:checkbox wire:model="costIsActive" label="Considerar este custo nos cálculos" />

                    <div class="flex flex-col-reverse justify-end gap-3 pt-2 sm:flex-row">
                        <flux:modal.close>
                            <flux:button type="button" variant="ghost" wire:click="closeCostModal" class="w-full sm:w-auto">
                                Cancelar
                            </flux:button>
                        </flux:modal.close>
                        <flux:button type="submit" variant="primary" class="w-full sm:w-auto">
                            {{ $editingCostId ? 'Salvar alterações' : 'Adicionar custo' }}
                        </flux:button>
                    </div>
                </form>
            </div>
        </flux:modal>

        {{-- Modal de confirmação de exclusão de custo --}}
        <flux:modal name="delete-cost" class="w-full max-w-sm backdrop:backdrop-blur-sm">
            <div class="space-y-6">
                <div>
                    <flux:heading size="lg">Remover custo operacional?</flux:heading>
                    <flux:subheading>
                        Esta ação removerá o custo da calculadora de rentabilidade.
                    </flux:subheading>
                </div>

                <div class="flex flex-col-reverse justify-end gap-3 sm:flex-row">
                    <flux:modal.close>
                        <flux:button type="button" variant="ghost" class="w-full sm:w-auto">Cancelar</flux:button>
                    </flux:modal.close>
                    <flux:button type="button" variant="danger" wire:click="deleteCost" class="w-full sm:w-auto">
                        Confirmar exclusão
                    </flux:button>
                </div>
            </div>
        </flux:modal>
    </main>
</div>

