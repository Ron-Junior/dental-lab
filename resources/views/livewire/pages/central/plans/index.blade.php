<?php

use App\Actions\CreatePlan;
use App\Actions\DeactivatePlan;
use App\Actions\ReactivatePlan;
use App\Actions\UpdatePlan;
use App\Enums\PlanResource;
use App\Models\CentralUser;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\PlanLimits\PlanLimitImpactAnalyzer;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.central')] class extends Component
{
    public ?string $editingPlanId = null;

    public string $planName = '';

    public string $planPrice = '';

    public string $planBillingCycle = 'monthly';

    public bool $planIsActive = true;

    public ?string $usersLimit = '2';

    public bool $usersUnlimited = false;

    public ?string $storageLimit = '5';

    public bool $storageUnlimited = false;

    public ?string $monthlyItemsLimit = '100';

    public bool $monthlyItemsUnlimited = false;

    public ?string $deactivatingPlanId = null;

    public string $deactivatingPlanName = '';

    public int $deactivatingSubscribersCount = 0;

    public string $targetPlanId = '';

    /**
     * Resumo de reajuste pendente de confirmação na modal.
     *
     * @var array{
     *     subscribers_count: int,
     *     old_price_formatted: string,
     *     new_price_formatted: string,
     *     earliest_effective_date: string,
     *     latest_effective_date: string
     * }|null
     */
    public ?array $pendingPriceChangeSummary = null;

    /**
     * Impactos de redução de limites pendentes de confirmação.
     *
     * @var array<string, array{label: string, unit: string, old_limit: int|null, new_limit: int, count: int, tenant_names: list<string>}>
     */
    public array $pendingLimitImpacts = [];

    #[Computed]
    public function plans(): Collection
    {
        $user = Auth::guard('central')->user();
        abort_unless($user instanceof CentralUser, 403);

        return Plan::query()
            ->withCount(['billableSubscriptions as billable_subscriptions_count'])
            ->with([
                'billableSubscriptions' => fn ($query) => $query
                    ->with('scheduledPlan')
                    ->where(function ($q): void {
                        $q->whereNotNull('scheduled_price_in_cents')
                            ->orWhereNotNull('scheduled_plan_id');
                    })
                    ->whereNotNull('price_change_effective_at')
                    ->orderBy('price_change_effective_at'),
            ])
            ->orderBy('price_in_cents')
            ->get();
    }

    /**
     * Planos ativos elegíveis como destino de migração ao desativar um plano.
     */
    #[Computed]
    public function availableTargetPlans(): Collection
    {
        return $this->plans
            ->filter(fn (Plan $plan): bool => $plan->is_active && (string) $plan->id !== (string) $this->deactivatingPlanId)
            ->values();
    }

    public function openCreatePlanModal(): void
    {
        $this->resetPlanForm();
        Flux::modal('plan-form')->show();
    }

    public function openEditPlanModal(string $planId): void
    {
        $plan = Plan::query()->findOrFail($planId);

        $this->resetPlanForm();
        $this->editingPlanId = (string) $plan->id;
        $this->planName = (string) $plan->name;
        $this->planPrice = number_format($plan->price_in_cents / 100, 2, ',', '.');
        $this->planBillingCycle = (string) $plan->billing_cycle;
        $this->planIsActive = (bool) $plan->is_active;

        $limits = $plan->limits ?? [];

        $users = $limits[PlanResource::Users->value] ?? null;
        $this->usersUnlimited = $users === null;
        $this->usersLimit = $users !== null ? (string) (int) $users : '';

        $storage = $limits[PlanResource::Storage->value] ?? null;
        $this->storageUnlimited = $storage === null;
        $this->storageLimit = $storage !== null ? (string) (int) $storage : '';

        $monthly = $limits[PlanResource::MonthlyItems->value] ?? null;
        $this->monthlyItemsUnlimited = $monthly === null;
        $this->monthlyItemsLimit = $monthly !== null ? (string) (int) $monthly : '';

        Flux::modal('plan-form')->show();
    }

    public function closePlanModal(): void
    {
        Flux::modal('plan-form')->close();
        $this->resetPlanForm();
    }

    public function savePlan(
        CreatePlan $createPlan,
        UpdatePlan $updatePlan,
        PlanLimitImpactAnalyzer $impactAnalyzer,
    ): void {
        $payload = $this->validateAndBuildPayload();

        if ($this->editingPlanId === null) {
            $createPlan->handle($payload);
            unset($this->plans, $this->availableTargetPlans);
            Flux::modal('plan-form')->close();
            $this->resetPlanForm();

            Flux::toast(
                heading: 'Plano criado',
                text: 'O plano foi cadastrado e sincronizado com sucesso.',
                variant: 'success',
            );

            return;
        }

        $plan = Plan::query()->findOrFail($this->editingPlanId);

        if ($payload['billing_cycle'] !== $plan->billing_cycle && $plan->has_billable_subscriptions) {
            throw ValidationException::withMessages([
                'planBillingCycle' => 'Não é possível alterar o ciclo de cobrança de um plano que possui assinaturas vinculadas. Crie um novo plano.',
            ]);
        }

        $this->pendingPriceChangeSummary = $this->buildPriceChangeSummary($plan, $payload['price_in_cents']);
        $this->pendingLimitImpacts = $this->buildLimitImpactSummary($plan, $payload['limits'], $impactAnalyzer);

        if ($this->pendingPriceChangeSummary !== null || $this->pendingLimitImpacts !== []) {
            Flux::modal('plan-confirm')->show();

            return;
        }

        $this->performUpdate($plan, $payload, $updatePlan);
    }

    public function confirmSavePlan(UpdatePlan $updatePlan): void
    {
        if ($this->editingPlanId === null) {
            return;
        }

        $plan = Plan::query()->findOrFail($this->editingPlanId);
        $payload = $this->validateAndBuildPayload();

        Flux::modal('plan-confirm')->close();
        $this->performUpdate($plan, $payload, $updatePlan);
    }

    public function cancelConfirmSavePlan(): void
    {
        $this->pendingPriceChangeSummary = null;
        $this->pendingLimitImpacts = [];
        Flux::modal('plan-confirm')->close();
    }

    public function togglePlanStatus(
        string $planId,
        DeactivatePlan $deactivatePlan,
        ReactivatePlan $reactivatePlan,
    ): void {
        $user = Auth::guard('central')->user();
        abort_unless($user instanceof CentralUser, 403);

        $plan = Plan::query()
            ->withCount(['billableSubscriptions as billable_subscriptions_count'])
            ->findOrFail($planId);

        if (! $plan->is_active) {
            $reactivatePlan->handle($plan);
            unset($this->plans, $this->availableTargetPlans);

            Flux::toast(
                heading: 'Plano ativado',
                text: "O plano {$plan->name} voltou a ficar disponível e eventuais migrações pendentes foram canceladas.",
                variant: 'success',
            );

            return;
        }

        if ($plan->billable_subscriptions_count > 0) {
            $this->deactivatingPlanId = (string) $plan->id;
            $this->deactivatingPlanName = (string) $plan->name;
            $this->deactivatingSubscribersCount = (int) $plan->billable_subscriptions_count;
            unset($this->availableTargetPlans);
            $this->targetPlanId = (string) ($this->availableTargetPlans->first()?->id ?? '');
            $this->resetErrorBag();

            Flux::modal('deactivate-plan')->show();

            return;
        }

        $deactivatePlan->handle($plan);
        unset($this->plans, $this->availableTargetPlans);

        Flux::toast(
            heading: 'Plano desativado',
            text: "O plano {$plan->name} foi ocultado do checkout e de novos cadastros.",
            variant: 'success',
        );
    }

    public function confirmDeactivatePlan(DeactivatePlan $deactivatePlan): void
    {
        $user = Auth::guard('central')->user();
        abort_unless($user instanceof CentralUser, 403);

        if ($this->deactivatingPlanId === null) {
            return;
        }

        $plan = Plan::query()->findOrFail($this->deactivatingPlanId);
        $targetPlan = $this->targetPlanId !== '' ? Plan::query()->find($this->targetPlanId) : null;

        $deactivatePlan->handle($plan, $targetPlan);

        $targetName = $targetPlan?->name ?? '';
        $count = $this->deactivatingSubscribersCount;

        Flux::modal('deactivate-plan')->close();
        $this->closeDeactivatePlanModal();
        unset($this->plans, $this->availableTargetPlans);

        Flux::toast(
            heading: 'Plano desativado e migração agendada',
            text: "{$count} assinante(s) foram notificados de que migrarão para o plano {$targetName} em 2 meses.",
            variant: 'success',
        );
    }

    public function closeDeactivatePlanModal(): void
    {
        $this->deactivatingPlanId = null;
        $this->deactivatingPlanName = '';
        $this->deactivatingSubscribersCount = 0;
        $this->targetPlanId = '';
        $this->resetErrorBag();
        Flux::modal('deactivate-plan')->close();
    }

    /**
     * @param  array{
     *     name: string,
     *     price_in_cents: int,
     *     billing_cycle: string,
     *     limits: array<string, int|null>,
     *     is_active: bool
     * }  $payload
     */
    private function performUpdate(Plan $plan, array $payload, UpdatePlan $updatePlan): void
    {
        /** @var CentralUser|null $actor */
        $actor = Auth::guard('central')->user();

        try {
            $updatePlan->handle($plan, $payload, $actor);
        } catch (ValidationException $e) {
            if (isset($e->errors()['billing_cycle'])) {
                throw ValidationException::withMessages([
                    'planBillingCycle' => $e->errors()['billing_cycle'],
                ]);
            }

            throw $e;
        }

        unset($this->plans, $this->availableTargetPlans);
        Flux::modal('plan-form')->close();
        $this->resetPlanForm();

        Flux::toast(
            heading: 'Plano atualizado',
            text: 'As alterações do plano foram salvas com sucesso.',
            variant: 'success',
        );
    }

    /**
     * @return array{
     *     name: string,
     *     price_in_cents: int,
     *     billing_cycle: string,
     *     limits: array<string, int|null>,
     *     is_active: bool
     * }
     */
    private function validateAndBuildPayload(): array
    {
        $this->validate([
            'planName' => ['required', 'string', 'max:100'],
            'planPrice' => ['required', 'string'],
            'planBillingCycle' => ['required', 'in:monthly,annual'],
            'planIsActive' => ['boolean'],
            'usersUnlimited' => ['boolean'],
            'storageUnlimited' => ['boolean'],
            'monthlyItemsUnlimited' => ['boolean'],
        ], [
            'planName.required' => 'Informe o nome do plano.',
            'planPrice.required' => 'Informe o valor do plano.',
            'planBillingCycle.in' => 'Selecione um ciclo de cobrança válido.',
        ]);

        $priceInCents = currency_to_int($this->planPrice);
        if ($priceInCents <= 0) {
            throw ValidationException::withMessages([
                'planPrice' => 'O valor do plano deve ser maior que zero.',
            ]);
        }

        $usersLimit = $this->parseLimitField('usersLimit', $this->usersUnlimited, $this->usersLimit);
        $storageLimit = $this->parseLimitField('storageLimit', $this->storageUnlimited, $this->storageLimit);
        $monthlyLimit = $this->parseLimitField('monthlyItemsLimit', $this->monthlyItemsUnlimited, $this->monthlyItemsLimit);

        return [
            'name' => trim($this->planName),
            'price_in_cents' => $priceInCents,
            'billing_cycle' => $this->planBillingCycle,
            'limits' => [
                PlanResource::Users->value => $usersLimit,
                PlanResource::Storage->value => $storageLimit,
                PlanResource::MonthlyItems->value => $monthlyLimit,
            ],
            'is_active' => $this->planIsActive,
        ];
    }

    private function parseLimitField(string $fieldName, bool $isUnlimited, ?string $rawValue): ?int
    {
        if ($isUnlimited) {
            return null;
        }

        $trimmed = trim((string) $rawValue);
        if ($trimmed === '' || ! ctype_digit($trimmed) || (int) $trimmed <= 0) {
            throw ValidationException::withMessages([
                $fieldName => 'Informe um número inteiro maior que zero ou marque como ilimitado.',
            ]);
        }

        return (int) $trimmed;
    }

    /**
     * @return array{
     *     subscribers_count: int,
     *     old_price_formatted: string,
     *     new_price_formatted: string,
     *     earliest_effective_date: string,
     *     latest_effective_date: string
     * }|null
     */
    private function buildPriceChangeSummary(Plan $plan, int $newPriceInCents): ?array
    {
        if ($newPriceInCents === (int) $plan->price_in_cents) {
            return null;
        }

        $subscriptions = Subscription::query()
            ->where('plan_id', $plan->id)
            ->whereIn('status', Plan::BILLABLE_STATUSES)
            ->get();

        if ($subscriptions->isEmpty()) {
            return null;
        }

        $now = Carbon::now();
        $effectiveDates = $subscriptions->map(function (Subscription $sub) use ($plan, $now): Carbon {
            if ($sub->has_scheduled_price_change && $sub->price_change_effective_at !== null) {
                return $sub->price_change_effective_at->copy();
            }

            $base = $sub->current_period_end !== null
                ? Carbon::instance($sub->current_period_end)
                : $now;

            return $plan->addBillingCycles($base, 2);
        })->sort();

        /** @var Carbon $earliest */
        $earliest = $effectiveDates->first();
        /** @var Carbon $latest */
        $latest = $effectiveDates->last();

        return [
            'subscribers_count' => $subscriptions->count(),
            'old_price_formatted' => (string) currency_from_int((int) $plan->price_in_cents, true),
            'new_price_formatted' => (string) currency_from_int($newPriceInCents, true),
            'earliest_effective_date' => $earliest->format('d/m/Y'),
            'latest_effective_date' => $latest->format('d/m/Y'),
        ];
    }

    /**
     * @param  array<string, int|null>  $newLimits
     * @return array<string, array{label: string, unit: string, old_limit: int|null, new_limit: int, count: int, tenant_names: list<string>}>
     */
    private function buildLimitImpactSummary(
        Plan $plan,
        array $newLimits,
        PlanLimitImpactAnalyzer $impactAnalyzer,
    ): array {
        $rawImpacts = $impactAnalyzer->analyze($plan, $newLimits);
        $formatted = [];

        foreach ($rawImpacts as $key => $impact) {
            /** @var PlanResource $resource */
            $resource = $impact['resource'];
            $formatted[$key] = [
                'label' => $resource->label(),
                'unit' => $resource->unit(),
                'old_limit' => $impact['old_limit'],
                'new_limit' => $impact['new_limit'],
                'count' => $impact['count'],
                'tenant_names' => array_map(
                    static fn (array $t): string => $t['name'].' ('.(int) ceil($t['used']).' '.$resource->unit().')',
                    $impact['tenants']
                ),
            ];
        }

        return $formatted;
    }

    private function resetPlanForm(): void
    {
        $this->editingPlanId = null;
        $this->planName = '';
        $this->planPrice = '';
        $this->planBillingCycle = 'monthly';
        $this->planIsActive = true;
        $this->usersLimit = '2';
        $this->usersUnlimited = false;
        $this->storageLimit = '5';
        $this->storageUnlimited = false;
        $this->monthlyItemsLimit = '100';
        $this->monthlyItemsUnlimited = false;
        $this->pendingPriceChangeSummary = null;
        $this->pendingLimitImpacts = [];
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
                    <flux:heading size="xl">Gestão de Planos</flux:heading>
                </div>
            </div>

            <div class="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-center">
                <flux:button href="{{ route('central.laboratories.index') }}" variant="ghost" icon="arrow-left">
                    Laboratórios
                </flux:button>
                <flux:button href="{{ route('central.plans.profitability') }}" variant="subtle" icon="calculator">
                    Calculadora de Rentabilidade
                </flux:button>
                <flux:button wire:click="openCreatePlanModal" variant="primary" icon="plus">
                    Novo plano
                </flux:button>
            </div>
        </header>

        <section class="flex flex-col gap-2">
            <flux:heading size="lg">Planos comerciais do SaaS</flux:heading>
            <flux:text>
                Crie ou edite os planos disponíveis. Novos preços valem na hora para novas assinaturas e respeitam a carência de 2 ciclos para assinantes atuais.
            </flux:text>
        </section>

        <section aria-label="Lista de planos" class="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-3">
            @forelse ($this->plans as $plan)
                @php
                    $limits = $plan->limits ?? [];
                    $usersLimitVal = $limits[PlanResource::Users->value] ?? null;
                    $storageLimitVal = $limits[PlanResource::Storage->value] ?? null;
                    $monthlyLimitVal = $limits[PlanResource::MonthlyItems->value] ?? null;
                    $scheduledSubs = $plan->billableSubscriptions;
                    $firstScheduledSub = $scheduledSubs->first();
                    $nextEffective = $firstScheduledSub?->price_change_effective_at;
                    $scheduledTargetPlan = $firstScheduledSub?->scheduledPlan;
                @endphp

                <article
                    wire:key="plan-card-{{ $plan->id }}"
                    class="flex flex-col justify-between rounded-2xl border border-zinc-200 bg-white p-6 shadow-xs transition dark:border-zinc-800 dark:bg-zinc-900"
                >
                    <div class="space-y-5">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <flux:heading size="lg">{{ $plan->name }}</flux:heading>
                                <flux:text class="text-xs text-zinc-500 dark:text-zinc-400">
                                    Ciclo {{ $plan->billing_cycle_label }}
                                </flux:text>
                            </div>

                            <div class="flex flex-wrap items-center justify-end gap-1.5">
                                @if ($plan->is_active)
                                    <flux:badge color="emerald" size="sm">Ativo</flux:badge>
                                @else
                                    <flux:badge color="zinc" size="sm">Inativo</flux:badge>
                                @endif

                                @if ($scheduledSubs->isNotEmpty())
                                    @if ($scheduledTargetPlan)
                                        <flux:badge color="indigo" size="sm">Migração agendada</flux:badge>
                                    @else
                                        <flux:badge color="amber" size="sm">Reajuste agendado</flux:badge>
                                    @endif
                                @endif
                            </div>
                        </div>

                        <div class="flex items-baseline gap-1.5">
                            <span class="text-3xl font-extrabold tracking-tight text-zinc-900 dark:text-white">
                                {{ currency_from_int($plan->price_in_cents, true) }}
                            </span>
                            <span class="text-sm text-zinc-500 dark:text-zinc-400">
                                /{{ $plan->isAnnual() ? 'ano' : 'mês' }}
                            </span>
                        </div>

                        <dl class="space-y-2.5 border-y border-zinc-100 py-4 text-sm dark:border-zinc-800">
                            <div class="flex items-center justify-between">
                                <dt class="text-zinc-500 dark:text-zinc-400">Usuários da equipe</dt>
                                <dd class="font-medium text-zinc-900 dark:text-white">
                                    {{ $usersLimitVal !== null ? $usersLimitVal.' usuários' : 'Ilimitado' }}
                                </dd>
                            </div>
                            <div class="flex items-center justify-between">
                                <dt class="text-zinc-500 dark:text-zinc-400">Armazenamento</dt>
                                <dd class="font-medium text-zinc-900 dark:text-white">
                                    {{ $storageLimitVal !== null ? $storageLimitVal.' GB' : 'Ilimitado' }}
                                </dd>
                            </div>
                            <div class="flex items-center justify-between">
                                <dt class="text-zinc-500 dark:text-zinc-400">Serviços no mês</dt>
                                <dd class="font-medium text-zinc-900 dark:text-white">
                                    {{ $monthlyLimitVal !== null ? $monthlyLimitVal.' serviços' : 'Ilimitado' }}
                                </dd>
                            </div>
                            <div class="flex items-center justify-between pt-1">
                                <dt class="text-zinc-500 dark:text-zinc-400">Assinaturas vinculadas</dt>
                                <dd class="font-semibold text-zinc-900 dark:text-white">
                                    {{ $plan->billable_subscriptions_count }}
                                </dd>
                            </div>
                        </dl>

                        @if ($scheduledSubs->isNotEmpty() && $nextEffective)
                            @if ($scheduledTargetPlan)
                                <div class="rounded-xl border border-indigo-200 bg-indigo-50/70 p-3 text-xs text-indigo-900 dark:border-indigo-900/60 dark:bg-indigo-950/40 dark:text-indigo-200">
                                    {{ $scheduledSubs->count() }} {{ $scheduledSubs->count() === 1 ? 'assinatura migrará' : 'assinaturas migrarão' }}
                                    para o plano <strong>{{ $scheduledTargetPlan->name }}</strong> a partir de <strong>{{ $nextEffective->format('d/m/Y') }}</strong>.
                                </div>
                            @else
                                <div class="rounded-xl border border-amber-200 bg-amber-50/70 p-3 text-xs text-amber-900 dark:border-amber-900/60 dark:bg-amber-950/40 dark:text-amber-200">
                                    {{ $scheduledSubs->count() }} {{ $scheduledSubs->count() === 1 ? 'assinatura mudará' : 'assinaturas mudarão' }}
                                    para o novo preço a partir de <strong>{{ $nextEffective->format('d/m/Y') }}</strong>.
                                </div>
                            @endif
                        @endif
                    </div>

                    <div class="mt-6 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                        <flux:button
                            wire:click="openEditPlanModal('{{ $plan->id }}')"
                            variant="filled"
                            icon="pencil-square"
                            class="w-full sm:w-auto"
                        >
                            Editar plano
                        </flux:button>

                        <flux:button
                            wire:click="togglePlanStatus('{{ $plan->id }}')"
                            variant="ghost"
                            class="w-full sm:w-auto"
                        >
                            {{ $plan->is_active ? 'Desativar' : 'Ativar' }}
                        </flux:button>
                    </div>
                </article>
            @empty
                <div class="col-span-full rounded-2xl border border-dashed border-zinc-300 bg-white p-10 text-center dark:border-zinc-800 dark:bg-zinc-900">
                    <flux:heading size="md">Nenhum plano cadastrado</flux:heading>
                    <flux:text class="mt-1">Cadastre o primeiro plano comercial para habilitar novas assinaturas.</flux:text>
                    <div class="mt-4">
                        <flux:button wire:click="openCreatePlanModal" variant="primary" icon="plus">
                            Criar primeiro plano
                        </flux:button>
                    </div>
                </div>
            @endforelse
        </section>

        {{-- Modal de criação e edição de plano --}}
        <flux:modal name="plan-form" class="w-full max-w-lg backdrop:backdrop-blur-sm" @close="closePlanModal">
            <div class="space-y-6">
                <div>
                    <flux:heading size="lg">
                        {{ $editingPlanId ? 'Editar Plano Comercial' : 'Novo Plano Comercial' }}
                    </flux:heading>
                    <flux:subheading>
                        Configure o valor, o ciclo de cobrança e os limites operacionais do plano.
                    </flux:subheading>
                </div>

                <form wire:submit="savePlan" class="space-y-4">
                    <flux:field>
                        <flux:label>Nome do plano</flux:label>
                        <flux:input wire:model="planName" placeholder="Ex: Profissional" required />
                        <flux:error name="planName" />
                    </flux:field>

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <flux:field>
                            <flux:label>Valor (R$)</flux:label>
                            <flux:input wire:model="planPrice" placeholder="199,00" required />
                            <flux:error name="planPrice" />
                        </flux:field>

                        <flux:field>
                            <flux:label>Ciclo de cobrança</flux:label>
                            <flux:select wire:model="planBillingCycle">
                                <option value="monthly">Mensal</option>
                                <option value="annual">Anual</option>
                            </flux:select>
                            <flux:error name="planBillingCycle" />
                        </flux:field>
                    </div>

                    <div class="space-y-3 rounded-xl border border-zinc-200 p-4 dark:border-zinc-800">
                        <flux:heading size="sm">Limites de recursos</flux:heading>

                        <div class="grid grid-cols-1 gap-3 sm:grid-cols-3 sm:items-end">
                            <div class="sm:col-span-2">
                                <flux:field>
                                    <flux:label>Usuários da equipe</flux:label>
                                    <flux:input
                                        wire:model="usersLimit"
                                        type="number"
                                        min="1"
                                        :disabled="$usersUnlimited"
                                        placeholder="Ex: 6"
                                    />
                                    <flux:error name="usersLimit" />
                                </flux:field>
                            </div>
                            <div class="pb-1">
                                <flux:checkbox wire:model.live="usersUnlimited" label="Ilimitado" />
                            </div>
                        </div>

                        <div class="grid grid-cols-1 gap-3 sm:grid-cols-3 sm:items-end">
                            <div class="sm:col-span-2">
                                <flux:field>
                                    <flux:label>Armazenamento (GB)</flux:label>
                                    <flux:input
                                        wire:model="storageLimit"
                                        type="number"
                                        min="1"
                                        :disabled="$storageUnlimited"
                                        placeholder="Ex: 25"
                                    />
                                    <flux:error name="storageLimit" />
                                </flux:field>
                            </div>
                            <div class="pb-1">
                                <flux:checkbox wire:model.live="storageUnlimited" label="Ilimitado" />
                            </div>
                        </div>

                        <div class="grid grid-cols-1 gap-3 sm:grid-cols-3 sm:items-end">
                            <div class="sm:col-span-2">
                                <flux:field>
                                    <flux:label>Serviços solicitados/mês</flux:label>
                                    <flux:input
                                        wire:model="monthlyItemsLimit"
                                        type="number"
                                        min="1"
                                        :disabled="$monthlyItemsUnlimited"
                                        placeholder="Ex: 400"
                                    />
                                    <flux:error name="monthlyItemsLimit" />
                                </flux:field>
                            </div>
                            <div class="pb-1">
                                <flux:checkbox wire:model.live="monthlyItemsUnlimited" label="Ilimitado" />
                            </div>
                        </div>
                    </div>

                    @if (! $editingPlanId)
                        <flux:checkbox wire:model="planIsActive" label="Plano ativo e visível para novas contratações" />
                    @endif

                    <div class="flex flex-col-reverse justify-end gap-3 pt-2 sm:flex-row">
                        <flux:modal.close>
                            <flux:button type="button" variant="ghost" wire:click="closePlanModal" class="w-full sm:w-auto">
                                Cancelar
                            </flux:button>
                        </flux:modal.close>
                        <flux:button type="submit" variant="primary" class="w-full sm:w-auto">
                            {{ $editingPlanId ? 'Salvar alterações' : 'Criar plano' }}
                        </flux:button>
                    </div>
                </form>
            </div>
        </flux:modal>

        {{-- Modal de confirmação de impacto (reajuste com carência ou redução de limite) --}}
        <flux:modal name="plan-confirm" class="w-full max-w-lg backdrop:backdrop-blur-sm">
            <div class="space-y-6">
                <div>
                    <flux:heading size="lg">Confirmar alterações do plano</flux:heading>
                    <flux:subheading>
                        Revise o impacto nas assinaturas atuais antes de confirmar.
                    </flux:subheading>
                </div>

                @if ($pendingPriceChangeSummary)
                    <div class="space-y-2 rounded-xl border border-amber-200 bg-amber-50/80 p-4 text-sm text-amber-950 dark:border-amber-900/60 dark:bg-amber-950/40 dark:text-amber-100">
                        <div class="font-semibold">Reajuste de preço com carência de 2 ciclos</div>
                        <p>
                            <strong>{{ $pendingPriceChangeSummary['subscribers_count'] }}</strong>
                            {{ $pendingPriceChangeSummary['subscribers_count'] === 1 ? 'assinante manterá' : 'assinantes manterão' }}
                            o valor de <strong>{{ $pendingPriceChangeSummary['old_price_formatted'] }}</strong> pelas próximas 2 mensalidades e
                            {{ $pendingPriceChangeSummary['subscribers_count'] === 1 ? 'passará' : 'passarão' }} a pagar
                            <strong>{{ $pendingPriceChangeSummary['new_price_formatted'] }}</strong> a partir de
                            <strong>{{ $pendingPriceChangeSummary['earliest_effective_date'] }}</strong>.
                        </p>
                    </div>
                @endif

                @if ($pendingLimitImpacts !== [])
                    <div class="space-y-3 rounded-xl border border-red-200 bg-red-50/80 p-4 text-sm text-red-950 dark:border-red-900/60 dark:bg-red-950/40 dark:text-red-100">
                        <div class="font-semibold">Aviso de redução de limites</div>
                        <ul class="list-disc space-y-2 pl-5">
                            @foreach ($pendingLimitImpacts as $impact)
                                <li>
                                    <strong>{{ $impact['count'] }}</strong>
                                    {{ $impact['count'] === 1 ? 'laboratório ficará' : 'laboratórios ficarão' }}
                                    acima do novo limite de <strong>{{ $impact['label'] }}</strong>
                                    ({{ $impact['new_limit'] }} {{ $impact['unit'] }}):
                                    <span class="text-xs">{{ implode(', ', $impact['tenant_names']) }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <div class="flex flex-col-reverse justify-end gap-3 pt-2 sm:flex-row">
                    <flux:button type="button" variant="ghost" wire:click="cancelConfirmSavePlan" class="w-full sm:w-auto">
                        Voltar
                    </flux:button>
                    <flux:button type="button" variant="primary" wire:click="confirmSavePlan" class="w-full sm:w-auto">
                        Confirmar e salvar
                    </flux:button>
                </div>
            </div>
        </flux:modal>

        {{-- Modal de desativação de plano com agendamento de migração em 2 meses --}}
        <flux:modal name="deactivate-plan" class="w-full max-w-lg backdrop:backdrop-blur-sm" @close="closeDeactivatePlanModal">
            <div class="space-y-6">
                <div>
                    <flux:heading size="lg">Desativar plano {{ $deactivatingPlanName }}</flux:heading>
                    <flux:subheading>
                        Este plano possui assinaturas vigentes. Escolha para qual plano ativo os laboratórios migrarão após 2 meses.
                    </flux:subheading>
                </div>

                <div class="space-y-2 rounded-xl border border-indigo-200 bg-indigo-50/80 p-4 text-sm text-indigo-950 dark:border-indigo-900/60 dark:bg-indigo-950/40 dark:text-indigo-100">
                    <div class="font-semibold">Aviso automático aos assinantes</div>
                    <p>
                        <strong>{{ $deactivatingSubscribersCount }}</strong>
                        {{ $deactivatingSubscribersCount === 1 ? 'laboratório receberá' : 'laboratórios receberão' }}
                        um aviso por e-mail e no painel informando que o plano atual foi descontinuado e que a assinatura migrará automaticamente para o plano selecionado abaixo em <strong>2 meses</strong>.
                    </p>
                </div>

                <form wire:submit="confirmDeactivatePlan" class="space-y-4">
                    <flux:field>
                        <flux:label>Plano ativo de destino</flux:label>
                        <flux:select wire:model="targetPlanId" required>
                            <option value="">Selecione um plano...</option>
                            @foreach ($this->availableTargetPlans as $candidatePlan)
                                <option value="{{ $candidatePlan->id }}">
                                    {{ $candidatePlan->name }} ({{ currency_from_int($candidatePlan->price_in_cents, true) }}/{{ $candidatePlan->isAnnual() ? 'ano' : 'mês' }})
                                </option>
                            @endforeach
                        </flux:select>
                        <flux:error name="targetPlanId" />
                    </flux:field>

                    <div class="flex flex-col-reverse justify-end gap-3 pt-2 sm:flex-row">
                        <flux:button type="button" variant="ghost" wire:click="closeDeactivatePlanModal" class="w-full sm:w-auto">
                            Cancelar
                        </flux:button>
                        <flux:button type="submit" variant="primary" class="w-full sm:w-auto">
                            Desativar e notificar assinantes
                        </flux:button>
                    </div>
                </form>
            </div>
        </flux:modal>
    </main>
</div>
