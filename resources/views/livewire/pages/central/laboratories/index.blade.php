<?php

use App\Enums\DiscountType;
use App\Enums\PaymentStatus;
use App\Enums\PlanResource;
use App\Enums\SubscriptionStatus;
use App\Enums\TenantStatus;
use App\Enums\UsageLevel;
use App\Models\CentralUser;
use App\Models\Discount;
use App\Models\Exemption;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Services\ApplyDiscountService;
use App\Services\ExemptionService;
use App\Services\ImpersonateTenantService;
use App\Services\PlanLimits\ResourceUsage;
use App\Services\PlanLimits\TenantLimitChecker;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;

new #[Layout('layouts.central')] class extends Component
{
    #[Validate('string|max:100')]
    public string $search = '';

    // Quick filter: 'all', 'active', 'near_limit', 'overdue', 'provisioning'
    public string $statusFilter = 'all';

    /** @var array<string, array<string, ResourceUsage>> */
    protected array $cachedUsages = [];

    /**
     * @return array<string, ResourceUsage>
     */
    public function getTenantUsages(Tenant $tenant): array
    {
        $tenantId = (string) $tenant->id;
        if (! isset($this->cachedUsages[$tenantId])) {
            $this->cachedUsages[$tenantId] = app(TenantLimitChecker::class)->usage($tenant);
        }

        return $this->cachedUsages[$tenantId];
    }

    // Discount modal state
    public bool $showDiscountModal = false;

    public string $selectedTenantId = '';

    public ?string $editingDiscountId = null;

    public string $discountType = 'fixed_amount';

    public string $discountValue = '';

    public ?string $discountExpiresAt = null;

    // Remove discount modal state
    public bool $showRemoveDiscountModal = false;

    public ?string $removingDiscountId = null;

    // Exemption modal state
    public bool $showExemptionModal = false;

    public ?string $editingExemptionId = null;

    public string $exemptionStartsAt = '';

    public string $exemptionEndsAt = '';

    public string $exemptionReason = '';

    // Revoke exemption modal state
    public bool $showRevokeExemptionModal = false;

    public ?string $revokingExemptionId = null;

    public function logout(): void
    {
        Auth::guard('central')->logout();

        Session::invalidate();
        Session::regenerateToken();

        $this->redirect(route('central.login', absolute: false), navigate: true);
    }

    #[Computed]
    public function summaryCounts(): array
    {
        $user = Auth::guard('central')->user();

        abort_unless($user instanceof CentralUser, 403);

        $now = Carbon::now();
        $allTenants = Tenant::query()
            ->with(['subscriptions.plan', 'payments', 'exemptions'])
            ->get();

        $counts = [
            'total' => $allTenants->count(),
            'active' => 0,
            'overdue' => 0,
            'provisioning' => 0,
            'failed' => 0,
            'near_limit' => 0,
        ];

        $limitChecker = app(TenantLimitChecker::class);

        foreach ($allTenants as $tenant) {
            $status = $tenant->status?->value ?? (string) $tenant->getAttribute('status');

            if ($status === 'active') {
                $counts['active']++;

                $usages = $this->getTenantUsages($tenant);
                if ($limitChecker->isNearLimit($usages)) {
                    $counts['near_limit']++;
                }
            } elseif ($status === 'provisioning') {
                $counts['provisioning']++;
            } elseif ($status === 'failed') {
                $counts['failed']++;
            }

            if ($this->isTenantOverdue($tenant, $now)) {
                $counts['overdue']++;
            }
        }

        return $counts;
    }

    #[Computed]
    public function tenants(): Collection
    {
        $user = Auth::guard('central')->user();

        abort_unless($user instanceof CentralUser, 403);

        $search = trim($this->search);
        $now = Carbon::now();

        $query = Tenant::query()
            ->with(['domains', 'exemptions', 'subscriptions.plan', 'payments', 'discounts.appliedPayment'])
            ->when($search !== '', function ($query) use ($search): void {
                $tenantName = Str::lower($search);

                $query->where(function ($query) use ($search, $tenantName): void {
                    $query->whereRaw('LOWER(name) LIKE ?', ['%'.$tenantName.'%'])
                        ->orWhere('id', 'like', '%'.$search.'%')
                        ->orWhereHas('domains', function ($query) use ($search): void {
                            $query->where('domain', 'like', '%'.$search.'%');
                        });
                });
            })
            ->latest();

        $results = $query->get();

        if ($this->statusFilter === 'active') {
            return $results->filter(fn (Tenant $t) => $t->status === TenantStatus::Active)->values();
        }

        if ($this->statusFilter === 'near_limit') {
            $checker = app(TenantLimitChecker::class);

            return $results->filter(function (Tenant $t) use ($checker) {
                if ($t->status !== TenantStatus::Active) {
                    return false;
                }

                return $checker->isNearLimit($this->getTenantUsages($t));
            })->values();
        }

        if ($this->statusFilter === 'overdue') {
            return $results->filter(fn (Tenant $t) => $this->isTenantOverdue($t, $now))->values();
        }

        if ($this->statusFilter === 'provisioning') {
            return $results->filter(fn (Tenant $t) => $t->status === TenantStatus::Provisioning)->values();
        }

        return $results;
    }

    public function isTenantOverdue(Tenant $tenant, ?Carbon $now = null): bool
    {
        $now ??= Carbon::now();

        if ($tenant->isExempt($now)) {
            return false;
        }

        $hasOverdueSub = $tenant->subscriptions->contains(
            fn (Subscription $sub): bool => $sub->status === SubscriptionStatus::Overdue
        );

        $hasPastDuePayment = $tenant->payments->contains(
            fn (Payment $p): bool => $p->status === PaymentStatus::Pending
                && $p->due_date !== null
                && $p->due_date->lessThan($now)
        );

        return $hasOverdueSub || $hasPastDuePayment;
    }

    public function getOverdueAmount(Tenant $tenant, ?Carbon $now = null): int
    {
        $now ??= Carbon::now();

        return (int) $tenant->payments
            ->filter(
                fn (Payment $p): bool => $p->status === PaymentStatus::Pending
                    && $p->due_date !== null
                    && $p->due_date->lessThan($now)
            )
            ->sum('amount_in_cents');
    }

    public function impersonate(string $tenantId, ImpersonateTenantService $service): mixed
    {
        $tenant = Tenant::query()->findOrFail($tenantId);
        $user = Auth::guard('central')->user();

        abort_unless($user instanceof CentralUser, 403);
        Gate::authorize('impersonate', $tenant);

        $rateKey = 'impersonate-central:'.$user->id;
        if (RateLimiter::tooManyAttempts($rateKey, 10)) {
            Flux::toast(text: 'Muitas tentativas de impersonação. Aguarde um minuto.', variant: 'danger');

            return null;
        }
        RateLimiter::hit($rateKey, 60);

        try {
            $result = $service->impersonate(
                $tenant,
                $user,
                request()->ip(),
                request()->userAgent()
            );

            return redirect()->away($result['redirect_url']);
        } catch (\Throwable $e) {
            Flux::toast(text: $e->getMessage(), variant: 'danger');

            return null;
        }
    }

    public function openDiscountModal(string $tenantId): void
    {
        $this->resetDiscountForm();
        $this->selectedTenantId = $tenantId;
        $this->editingDiscountId = null;
        $this->showDiscountModal = true;
        Flux::modal('discount-modal')->show();
    }

    public function openEditDiscountModal(string $discountId): void
    {
        $this->resetDiscountForm();
        $discount = Discount::query()->findOrFail($discountId);
        $this->selectedTenantId = $discount->tenant_id;
        $this->editingDiscountId = $discount->id;
        $this->discountType = $discount->type->value;
        if ($discount->type === DiscountType::Percentage) {
            $this->discountValue = (string) $discount->value_in_cents;
        } else {
            $this->discountValue = (string) ($discount->value_in_cents / 100);
        }
        $this->discountExpiresAt = $discount->expires_at?->format('Y-m');
        $this->showDiscountModal = true;
        Flux::modal('discount-modal')->show();
    }

    public function closeDiscountModal(): void
    {
        $this->showDiscountModal = false;
        $this->resetDiscountForm();
        Flux::modal('discount-modal')->close();
    }

    public function confirmRemoveDiscount(string $discountId): void
    {
        $this->removingDiscountId = $discountId;
        $this->showRemoveDiscountModal = true;
        Flux::modal('remove-discount-modal')->show();
    }

    public function closeRemoveDiscountModal(): void
    {
        $this->showRemoveDiscountModal = false;
        $this->removingDiscountId = null;
        Flux::modal('remove-discount-modal')->close();
    }

    public function removeDiscount(ApplyDiscountService $service): void
    {
        $user = Auth::guard('central')->user();
        abort_unless($user instanceof CentralUser, 403);
        Gate::authorize('manageDiscounts', Tenant::class);

        if (! $this->removingDiscountId) {
            return;
        }

        try {
            $discount = Discount::query()->findOrFail($this->removingDiscountId);
            $service->remove(
                $discount,
                $user,
                request()->ip(),
                request()->userAgent()
            );

            $this->closeRemoveDiscountModal();

            Flux::toast(
                text: 'Desconto removido com sucesso e valor da fatura restaurado.',
                variant: 'success',
            );
        } catch (\Throwable $e) {
            Flux::toast(text: $e->getMessage(), variant: 'danger');
        }
    }

    public function saveDiscount(ApplyDiscountService $service): void
    {
        $user = Auth::guard('central')->user();
        abort_unless($user instanceof CentralUser, 403);
        Gate::authorize('manageDiscounts', Tenant::class);

        $this->validate([
            'selectedTenantId' => ['required', 'exists:tenants,id'],
            'discountType' => ['required', 'in:percentage,fixed_amount'],
            'discountValue' => ['required', 'numeric', 'min:1'],
            'discountExpiresAt' => ['nullable', 'date'],
        ], [
            'discountValue.required' => 'Informe o valor do desconto.',
            'discountValue.min' => 'O valor deve ser maior que zero.',
        ]);

        if ($this->discountExpiresAt) {
            $parsedExpiry = Carbon::parse($this->discountExpiresAt)->endOfMonth();
            if ($parsedExpiry->isPast()) {
                $this->addError('discountExpiresAt', 'O mês de expiração deve ser no presente ou futuro.');

                return;
            }
        }

        $tenant = Tenant::query()->findOrFail($this->selectedTenantId);

        if ($this->discountType === 'percentage') {
            $pct = (int) $this->discountValue;
            if ($pct > 100) {
                $this->addError('discountValue', 'O percentual não pode ser maior que 100%.');

                return;
            }
            $valueInCents = $pct;
        } else {
            $valueInCents = currency_to_int($this->discountValue);
        }

        $expiresAt = $this->discountExpiresAt ? Carbon::parse($this->discountExpiresAt)->endOfMonth() : null;

        try {
            if ($this->editingDiscountId) {
                $discount = Discount::query()->findOrFail($this->editingDiscountId);
                $service->update(
                    $discount,
                    DiscountType::from($this->discountType),
                    $valueInCents,
                    $expiresAt,
                    $user,
                    request()->ip(),
                    request()->userAgent()
                );

                Flux::toast(
                    text: "Desconto atualizado com sucesso para [{$tenant->name}].",
                    variant: 'success',
                );
            } else {
                $discount = Discount::query()->create([
                    'tenant_id' => $tenant->id,
                    'type' => DiscountType::from($this->discountType),
                    'value_in_cents' => $valueInCents,
                    'expires_at' => $expiresAt,
                ]);

                $service->apply($discount);

                Flux::toast(
                    text: "Desconto aplicado com sucesso ao próximo pagamento de [{$tenant->name}].",
                    variant: 'success',
                );
            }

            $this->closeDiscountModal();
        } catch (\Throwable $e) {
            Flux::toast(text: $e->getMessage(), variant: 'danger');
        }
    }

    public function openExemptionModal(string $tenantId): void
    {
        $tenant = Tenant::query()->findOrFail($tenantId);

        $hasPaidHistory = $tenant->payments()->where('status', PaymentStatus::Paid)->exists();
        $hasActiveStripeSub = $tenant->subscriptions()
            ->where('status', SubscriptionStatus::Active)
            ->whereNotNull('stripe_subscription_id')
            ->exists();

        if ($hasPaidHistory || $hasActiveStripeSub) {
            Flux::toast(
                text: 'Isenção de pagamento só pode ser concedida a novos clientes (em onboarding). Assinantes ativos não são elegíveis.',
                variant: 'danger',
            );

            return;
        }

        $this->resetExemptionForm();
        $this->selectedTenantId = $tenantId;
        $this->editingExemptionId = null;
        $this->exemptionStartsAt = Carbon::now()->format('Y-m');
        $this->exemptionEndsAt = Carbon::now()->addMonth()->format('Y-m');
        $this->showExemptionModal = true;
        Flux::modal('exemption-modal')->show();
    }

    public function openEditExemptionModal(string $exemptionId): void
    {
        $this->resetExemptionForm();
        $exemption = Exemption::query()->findOrFail($exemptionId);
        $this->selectedTenantId = $exemption->tenant_id;
        $this->editingExemptionId = $exemption->id;
        $this->exemptionStartsAt = $exemption->starts_at->format('Y-m');
        $this->exemptionEndsAt = $exemption->ends_at->format('Y-m');
        $this->exemptionReason = $exemption->reason;
        $this->showExemptionModal = true;
        Flux::modal('exemption-modal')->show();
    }

    public function closeExemptionModal(): void
    {
        $this->showExemptionModal = false;
        $this->resetExemptionForm();
        Flux::modal('exemption-modal')->close();
    }

    public function confirmRevokeExemption(string $exemptionId): void
    {
        $this->revokingExemptionId = $exemptionId;
        $this->showRevokeExemptionModal = true;
        Flux::modal('revoke-exemption-modal')->show();
    }

    public function closeRevokeExemptionModal(): void
    {
        $this->showRevokeExemptionModal = false;
        $this->revokingExemptionId = null;
        Flux::modal('revoke-exemption-modal')->close();
    }

    public function revokeExemption(ExemptionService $service): void
    {
        $user = Auth::guard('central')->user();
        abort_unless($user instanceof CentralUser, 403);

        if (! $this->revokingExemptionId) {
            return;
        }

        try {
            $exemption = Exemption::query()->findOrFail($this->revokingExemptionId);
            $service->revoke(
                $exemption,
                $user,
                request()->ip(),
                request()->userAgent()
            );

            $this->closeRevokeExemptionModal();

            Flux::toast(
                text: 'Isenção revogada com sucesso. O laboratório retornou ao ciclo de faturamento.',
                variant: 'success',
            );
        } catch (\Throwable $e) {
            Flux::toast(text: $e->getMessage(), variant: 'danger');
        }
    }

    public function saveExemption(ExemptionService $service): void
    {
        $user = Auth::guard('central')->user();
        abort_unless($user instanceof CentralUser, 403);

        $this->validate([
            'selectedTenantId' => ['required', 'exists:tenants,id'],
            'exemptionStartsAt' => ['required', 'date'],
            'exemptionEndsAt' => ['required', 'date', 'after_or_equal:exemptionStartsAt'],
            'exemptionReason' => ['required', 'string', 'max:255'],
        ], [
            'exemptionEndsAt.after_or_equal' => 'O mês final deve ser igual ou posterior ao mês inicial.',
            'exemptionReason.required' => 'Informe o motivo da isenção.',
        ]);

        $tenant = Tenant::query()->findOrFail($this->selectedTenantId);

        $startsAt = Carbon::parse($this->exemptionStartsAt)->startOfMonth();
        $endsAt = Carbon::parse($this->exemptionEndsAt)->endOfMonth();

        try {
            if ($this->editingExemptionId) {
                $exemption = Exemption::query()->findOrFail($this->editingExemptionId);
                $service->update(
                    $exemption,
                    $startsAt,
                    $endsAt,
                    $this->exemptionReason,
                    $user,
                    request()->ip(),
                    request()->userAgent()
                );

                Flux::toast(
                    text: "Período de isenção atualizado com sucesso para [{$tenant->name}].",
                    variant: 'success',
                );
            } else {
                $service->grant(
                    $tenant,
                    $startsAt,
                    $endsAt,
                    $this->exemptionReason,
                    $user,
                    request()->ip(),
                    request()->userAgent()
                );

                Flux::toast(
                    text: "Isenção concedida com sucesso para [{$tenant->name}].",
                    variant: 'success',
                );
            }

            $this->closeExemptionModal();
        } catch (\Throwable $e) {
            Flux::toast(text: $e->getMessage(), variant: 'danger');
        }
    }

    public function resendFirstAccess(string $tenantId, \App\Actions\ResendFirstAccessLink $action): void
    {
        $user = Auth::guard('central')->user();
        abort_unless($user instanceof CentralUser, 403);

        $tenant = Tenant::query()->findOrFail($tenantId);

        try {
            $action->handle($tenant);
            Flux::toast(
                text: "Novo link de primeiro acesso enviado para [{$tenant->owner_email}].",
                variant: 'success',
            );
        } catch (\Throwable $e) {
            Flux::toast(text: $e->getMessage(), variant: 'danger');
        }
    }

    private function resetDiscountForm(): void
    {
        $this->editingDiscountId = null;
        $this->discountType = 'fixed_amount';
        $this->discountValue = '';
        $this->discountExpiresAt = null;
        $this->resetErrorBag();
    }

    private function resetExemptionForm(): void
    {
        $this->editingExemptionId = null;
        $this->exemptionStartsAt = '';
        $this->exemptionEndsAt = '';
        $this->exemptionReason = '';
        $this->resetErrorBag();
    }
}; ?>

<div class="min-h-screen bg-zinc-50 dark:bg-zinc-950">
    <main class="mx-auto flex w-full max-w-6xl flex-col gap-8 px-4 py-8 sm:px-6 lg:px-8">
        <header class="flex flex-col gap-5 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex items-center gap-3">
                <x-icons.logo class="size-11 text-zinc-900 dark:text-white" />
                <div>
                    <flux:text class="text-sm font-medium text-zinc-500 dark:text-zinc-400">PROTHO</flux:text>
                    <flux:heading size="xl">Meus laboratórios</flux:heading>
                </div>
            </div>

            <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
                <flux:button href="{{ route('central.admin.laboratories.create') }}" variant="primary" icon="plus">
                    Cadastrar laboratório
                </flux:button>
                <flux:button wire:click="logout" variant="ghost" icon="arrow-right-start-on-rectangle">
                    Sair
                </flux:button>
            </div>
        </header>

        <section class="flex flex-col gap-2">
            <flux:heading size="lg">Gerencie seus espaços de trabalho</flux:heading>
            <flux:text>Consulte a configuração, acompanhe faturas e acesse os laboratórios vinculados com auditoria.</flux:text>
        </section>

        @php
            $summary = $this->summaryCounts;
        @endphp

        <section aria-label="Resumo dos laboratórios" class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-5">
            <button
                type="button"
                wire:click="$set('statusFilter', 'all')"
                class="min-w-0 text-left transition"
            >
                <flux:card class="flex h-full flex-col gap-1 {{ $statusFilter === 'all' ? 'ring-2 ring-zinc-900 dark:ring-white' : '' }}">
                    <flux:text>Total de laboratórios</flux:text>
                    <flux:heading size="xl">{{ $summary['total'] }}</flux:heading>
                </flux:card>
            </button>

            <button
                type="button"
                wire:click="$set('statusFilter', 'active')"
                class="min-w-0 text-left transition"
            >
                <flux:card class="flex h-full flex-col gap-1 {{ $statusFilter === 'active' ? 'ring-2 ring-emerald-500' : '' }}">
                    <flux:text>Ativos</flux:text>
                    <flux:heading size="xl">{{ $summary['active'] }}</flux:heading>
                </flux:card>
            </button>

            <button
                type="button"
                wire:click="$set('statusFilter', 'near_limit')"
                class="min-w-0 text-left transition"
            >
                <flux:card class="flex h-full flex-col gap-1 {{ $statusFilter === 'near_limit' ? 'ring-2 ring-amber-500' : '' }}">
                    <flux:text class="{{ $summary['near_limit'] > 0 ? 'text-amber-600 dark:text-amber-400 font-medium' : '' }}">Perto do limite</flux:text>
                    <flux:heading size="xl" class="{{ $summary['near_limit'] > 0 ? 'text-amber-600 dark:text-amber-400' : '' }}">{{ $summary['near_limit'] }}</flux:heading>
                </flux:card>
            </button>

            <button
                type="button"
                wire:click="$set('statusFilter', 'overdue')"
                class="min-w-0 text-left transition"
            >
                <flux:card class="flex h-full flex-col gap-1 {{ $statusFilter === 'overdue' ? 'ring-2 ring-rose-500' : '' }}">
                    <flux:text class="text-rose-600 dark:text-rose-400">Inadimplentes</flux:text>
                    <flux:heading size="xl" class="text-rose-600 dark:text-rose-400">{{ $summary['overdue'] }}</flux:heading>
                </flux:card>
            </button>

            <button
                type="button"
                wire:click="$set('statusFilter', 'provisioning')"
                class="min-w-0 text-left transition"
            >
                <flux:card class="flex h-full flex-col gap-1 {{ $statusFilter === 'provisioning' ? 'ring-2 ring-blue-500' : '' }}">
                    <flux:text>Em configuração</flux:text>
                    <flux:heading size="xl">{{ $summary['provisioning'] }}</flux:heading>
                </flux:card>
            </button>
        </section>

        <section class="flex flex-col gap-5">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <flux:heading size="lg">
                        Laboratórios
                        @if ($statusFilter !== 'all')
                            <span class="text-sm font-normal text-zinc-500">
                                (Filtro: {{ match($statusFilter) {
                                    'active' => 'Ativos',
                                    'near_limit' => 'Perto do limite',
                                    'overdue' => 'Inadimplentes',
                                    'provisioning' => 'Em configuração',
                                    default => $statusFilter
                                } }})
                            </span>
                        @endif
                    </flux:heading>
                    <flux:text class="mt-1">Encontre um laboratório pelo nome ou endereço.</flux:text>
                </div>
                <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
                    @if ($statusFilter !== 'all')
                        <flux:button wire:click="$set('statusFilter', 'all')" size="sm" variant="ghost">
                            Ver todos
                        </flux:button>
                    @endif
                    <div class="w-full sm:max-w-sm">
                        <flux:input
                            wire:model.live.debounce.300ms="search"
                            label="Buscar laboratórios"
                            placeholder="Nome ou endereço do laboratório"
                            icon="magnifying-glass"
                        />
                        <flux:error name="search" />
                    </div>
                </div>
            </div>

            @php
                $tenants = $this->tenants;
            @endphp

            @if ($tenants->isNotEmpty())
                <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                    @foreach ($tenants as $tenant)
                        @php
                            $domain = $tenant->domains->first()?->domain;
                            $isOverdue = $this->isTenantOverdue($tenant);
                            $overdueAmount = $isOverdue ? $this->getOverdueAmount($tenant) : 0;
                            $activeExemption = $tenant->exemptions->first(fn ($ex) => $ex->isActive());
                            $activeDiscount = $tenant->discounts->first(
                                fn ($d) => $d->appliedPayment && $d->appliedPayment->status === \App\Enums\PaymentStatus::Pending
                            );
                        @endphp

                        <flux:card wire:key="laboratory-{{ $tenant->id }}" class="flex min-w-0 flex-col justify-between gap-5 overflow-hidden {{ $isOverdue ? 'border-rose-300 dark:border-rose-900/60' : '' }}">
                            <div class="flex min-w-0 flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                <div class="flex min-w-0 flex-1 items-center gap-3">
                                    <span class="flex size-12 shrink-0 items-center justify-center rounded-xl bg-teal-50 text-teal-700 dark:bg-teal-950 dark:text-teal-300">
                                        <flux:icon name="building-office-2" class="size-6" />
                                    </span>
                                    <div class="min-w-0 flex-1">
                                        <flux:heading size="base" class="truncate">{{ $tenant->name }}</flux:heading>
                                        <flux:text class="truncate text-sm">
                                            {{ $domain ?? 'Domínio ainda não configurado' }}
                                        </flux:text>
                                    </div>
                                </div>

                                <div class="flex flex-wrap items-center gap-1.5 sm:max-w-[50%] sm:justify-end">
                                    @if ($activeExemption)
                                        <flux:badge color="amber" icon="gift">
                                            Isento até {{ $activeExemption->ends_at->format('d/m/Y') }}
                                        </flux:badge>
                                    @endif

                                    @if ($activeDiscount)
                                        <flux:badge color="indigo" icon="receipt-percent">
                                            Desconto: {{ $activeDiscount->type === \App\Enums\DiscountType::Percentage ? $activeDiscount->value_in_cents . '%' : 'R$ ' . number_format($activeDiscount->value_in_cents / 100, 2, ',', '.') }}
                                        </flux:badge>
                                    @endif

                                    @if ($isOverdue)
                                        <flux:badge color="red" icon="exclamation-circle">
                                            Inadimplente (R$ {{ number_format($overdueAmount / 100, 2, ',', '.') }})
                                        </flux:badge>
                                    @endif

                                    @if ($tenant->status->value === 'active')
                                        <flux:badge color="green" icon="check-circle">Ativo</flux:badge>
                                        @if (! $tenant->isVerified())
                                            <flux:badge color="amber" icon="envelope">1º Acesso Pendente</flux:badge>
                                        @endif
                                    @elseif ($tenant->status->value === 'provisioning')
                                        <flux:badge color="blue" icon="clock">Configurando</flux:badge>
                                    @else
                                        <flux:badge color="red" icon="exclamation-triangle">Falha</flux:badge>
                                    @endif
                                </div>
                            </div>

                            {{-- Seção de Plano e Consumo de Recursos --}}
                            @php
                                $subscription = $tenant->currentSubscription();
                                $plan = $subscription?->plan;
                                $usages = $tenant->status === \App\Enums\TenantStatus::Active ? $this->getTenantUsages($tenant) : [];
                                $limitChecker = app(\App\Services\PlanLimits\TenantLimitChecker::class);
                                $overallLevel = ! empty($usages) ? $limitChecker->overallLevel($usages) : null;
                                $isAllUnlimited = ! empty($usages) ? $limitChecker->isAllUnlimited($usages) : false;
                                $maxPct = ! empty($usages) ? $limitChecker->maxPercentage($usages) : 0;
                            @endphp

                            <div class="min-w-0 rounded-xl border border-zinc-200/90 bg-zinc-50/70 p-3.5 sm:p-4 dark:border-zinc-800 dark:bg-zinc-900/40 space-y-3.5" data-testid="laboratory-plan-section-{{ $tenant->id }}">
                                <div class="flex min-w-0 flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                    <div class="flex min-w-0 flex-1 items-center gap-2">
                                        <span class="flex size-7 shrink-0 items-center justify-center rounded-lg bg-teal-100/70 text-teal-700 dark:bg-teal-950/80 dark:text-teal-300">
                                            <flux:icon name="sparkles" class="size-4" />
                                        </span>
                                        <div class="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1">
                                            <span class="truncate text-sm font-semibold text-zinc-900 dark:text-zinc-100">
                                                {{ $plan ? 'Plano ' . $plan->name : 'Sem plano contratado' }}
                                            </span>
                                            @if ($subscription && $subscription->status === \App\Enums\SubscriptionStatus::Trial)
                                                <flux:badge color="amber" size="sm" icon="clock">
                                                    Teste ({{ $subscription->daysRemainingInTrial() }}d restantes)
                                                </flux:badge>
                                            @elseif ($plan)
                                                <span class="text-xs text-zinc-500 dark:text-zinc-400">
                                                    • R$ {{ number_format($plan->price_in_cents / 100, 2, ',', '.') }}/{{ $plan->billing_cycle === 'annual' ? 'ano' : 'mês' }}
                                                </span>
                                            @endif
                                        </div>
                                    </div>

                                    <div class="shrink-0">
                                        @if ($tenant->status !== \App\Enums\TenantStatus::Active)
                                            <flux:badge color="zinc" size="sm">
                                                Aguardando ativação
                                            </flux:badge>
                                        @elseif ($isAllUnlimited)
                                            <flux:badge color="zinc" size="sm" icon="check">
                                                Consumo livre (Sem limites)
                                            </flux:badge>
                                        @elseif ($overallLevel === \App\Enums\UsageLevel::Exceeded)
                                            <flux:badge color="red" size="sm" icon="no-symbol">
                                                Limite atingido ({{ number_format($maxPct, 0) }}%)
                                            </flux:badge>
                                        @elseif ($overallLevel === \App\Enums\UsageLevel::Critical)
                                            <flux:badge color="red" size="sm" icon="exclamation-circle">
                                                Limite crítico ({{ number_format($maxPct, 0) }}%)
                                            </flux:badge>
                                        @elseif ($overallLevel === \App\Enums\UsageLevel::Warning)
                                            <flux:badge color="amber" size="sm" icon="exclamation-triangle">
                                                Perto do limite ({{ number_format($maxPct, 0) }}%)
                                            </flux:badge>
                                        @else
                                            <flux:badge color="green" size="sm" icon="check-circle">
                                                Longe dos limites ({{ number_format($maxPct, 0) }}%)
                                            </flux:badge>
                                        @endif
                                    </div>
                                </div>

                                @if ($tenant->status === \App\Enums\TenantStatus::Active && ! empty($usages))
                                    <div class="grid grid-cols-1 gap-2.5 pt-0.5 sm:grid-cols-3 md:grid-cols-1 lg:grid-cols-3">
                                        @foreach ($usages as $usage)
                                            @php
                                                $isStorage = $usage->resource === \App\Enums\PlanResource::Storage;
                                                $format = fn (float $value) => number_format($value, $isStorage ? 1 : 0, ',', '.');
                                                $isUnlimited = $usage->isUnlimited();

                                                $progressBarColor = match ($usage->level) {
                                                    \App\Enums\UsageLevel::Normal => 'bg-emerald-500',
                                                    \App\Enums\UsageLevel::Warning => 'bg-amber-500',
                                                    \App\Enums\UsageLevel::Critical => 'bg-rose-500',
                                                    \App\Enums\UsageLevel::Exceeded => 'bg-red-600',
                                                };

                                                $icon = match ($usage->resource) {
                                                    \App\Enums\PlanResource::Users => 'users',
                                                    \App\Enums\PlanResource::Storage => 'archive-box',
                                                    \App\Enums\PlanResource::MonthlyItems => 'document-text',
                                                };

                                                $resourceShortName = match ($usage->resource) {
                                                    \App\Enums\PlanResource::Users => 'Usuários',
                                                    \App\Enums\PlanResource::Storage => 'Armazenamento',
                                                    \App\Enums\PlanResource::MonthlyItems => 'Serviços/mês',
                                                };
                                            @endphp

                                            <div class="flex min-w-0 flex-col justify-between rounded-lg border border-zinc-200/70 bg-white/80 p-2.5 dark:border-zinc-800 dark:bg-zinc-800/60 shadow-xs">
                                                <div class="min-w-0 space-y-1.5">
                                                    <div class="flex min-w-0 items-center gap-1.5 text-xs font-medium text-zinc-700 dark:text-zinc-300">
                                                        <flux:icon :icon="$icon" class="size-3.5 shrink-0 text-zinc-400" />
                                                        <span class="truncate" title="{{ $resourceShortName }}">{{ $resourceShortName }}</span>
                                                    </div>

                                                    <div class="flex min-w-0 items-baseline justify-between gap-1 text-xs">
                                                        <span class="truncate font-semibold text-zinc-900 dark:text-zinc-100">
                                                            @if ($isUnlimited)
                                                                <span class="text-zinc-500 font-normal">Livre</span>
                                                            @else
                                                                {{ $format($usage->used) }}<span class="text-zinc-400 font-normal">/{{ $format((float) $usage->limit) }}</span>
                                                            @endif
                                                        </span>

                                                        @if ($isUnlimited)
                                                            <span class="shrink-0 text-[11px] text-zinc-500 dark:text-zinc-400">Sem limite</span>
                                                        @else
                                                            <span class="shrink-0 text-[11px] {{ $usage->level !== \App\Enums\UsageLevel::Normal ? 'font-semibold text-amber-600 dark:text-amber-400' : 'text-zinc-500 dark:text-zinc-400' }}">
                                                                {{ number_format($usage->percentage, 0) }}% usado
                                                            </span>
                                                        @endif
                                                    </div>

                                                    {{-- Barra de progresso visual --}}
                                                    <div class="h-1.5 w-full rounded-full bg-zinc-200/80 dark:bg-zinc-700/60 overflow-hidden">
                                                        <div
                                                            class="h-1.5 rounded-full transition-all duration-300 {{ $isUnlimited ? 'bg-zinc-300 dark:bg-zinc-600' : $progressBarColor }}"
                                                            style="width: {{ $isUnlimited ? 0 : min(100, $usage->percentage) }}%"
                                                        ></div>
                                                    </div>
                                                </div>

                                                <div class="mt-2 flex min-w-0 items-center justify-between text-[11px] text-zinc-500 dark:text-zinc-400">
                                                    @if ($isUnlimited)
                                                        <span class="truncate">Ilimitado</span>
                                                    @elseif ($usage->level->isBlocking())
                                                        <span class="truncate text-rose-600 dark:text-rose-400 font-medium">Esgotado</span>
                                                    @else
                                                        <span class="truncate">{{ $format($usage->remaining() ?? 0) }} {{ $usage->resource->unit() }} livres</span>
                                                    @endif
                                                </div>
                                            </div>
                                        @endforeach
                                    </div>
                                @elseif ($tenant->status === \App\Enums\TenantStatus::Provisioning)
                                    <div class="text-xs text-zinc-500 dark:text-zinc-400 italic">
                                        O consumo de recursos estará disponível assim que a configuração for concluída.
                                    </div>
                                @elseif ($tenant->status === \App\Enums\TenantStatus::Failed)
                                    <div class="text-xs text-rose-500 dark:text-rose-400">
                                        Laboratório com falha de provisionamento. Não foi possível verificar recursos.
                                    </div>
                                @else
                                    <div class="text-xs text-zinc-500 dark:text-zinc-400">
                                        Aguardando ativação do plano e faturamento.
                                    </div>
                                @endif
                            </div>

                            <div class="flex min-w-0 flex-col gap-4 border-t border-zinc-200 pt-4 dark:border-zinc-700 sm:flex-row sm:items-center sm:justify-between">
                                <flux:text class="shrink-0 text-sm">
                                    Criado em {{ $tenant->created_at?->format('d/m/Y') ?? '—' }}
                                </flux:text>

                                <div class="flex flex-wrap items-center gap-2 sm:justify-end">
                                    @if ($activeDiscount)
                                        <flux:dropdown position="bottom" align="end">
                                            <flux:button
                                                size="sm"
                                                variant="outline"
                                                icon="receipt-percent"
                                                icon:trailing="chevron-down"
                                                title="Opções de desconto do laboratório"
                                            >
                                                Desconto
                                            </flux:button>

                                            <flux:menu>
                                                <flux:menu.item
                                                    icon="pencil-square"
                                                    wire:click="openEditDiscountModal('{{ $activeDiscount->id }}')"
                                                >
                                                    Editar desconto
                                                </flux:menu.item>
                                                <flux:menu.item
                                                    icon="trash"
                                                    variant="danger"
                                                    wire:click="confirmRemoveDiscount('{{ $activeDiscount->id }}')"
                                                >
                                                    Remover desconto
                                                </flux:menu.item>
                                            </flux:menu>
                                        </flux:dropdown>
                                    @else
                                        <flux:button
                                            wire:click="openDiscountModal('{{ $tenant->id }}')"
                                            size="sm"
                                            variant="outline"
                                            icon="receipt-percent"
                                            title="Conceder desconto no próximo pagamento"
                                        >
                                            Desconto
                                        </flux:button>
                                    @endif

                                    @if ($activeExemption)
                                        <flux:dropdown position="bottom" align="end">
                                            <flux:button
                                                size="sm"
                                                variant="outline"
                                                icon="gift"
                                                icon:trailing="chevron-down"
                                                title="Opções de isenção do laboratório"
                                            >
                                                Isenção
                                            </flux:button>

                                            <flux:menu>
                                                <flux:menu.item
                                                    icon="pencil-square"
                                                    wire:click="openEditExemptionModal('{{ $activeExemption->id }}')"
                                                >
                                                    Editar período
                                                </flux:menu.item>
                                                <flux:menu.item
                                                    icon="no-symbol"
                                                    variant="danger"
                                                    wire:click="confirmRevokeExemption('{{ $activeExemption->id }}')"
                                                >
                                                    Revogar isenção
                                                </flux:menu.item>
                                            </flux:menu>
                                        </flux:dropdown>
                                    @else
                                        @php
                                            $isExistingClient = $tenant->payments->contains(fn ($p) => $p->status === \App\Enums\PaymentStatus::Paid)
                                                || $tenant->subscriptions->contains(fn ($s) => $s->status === \App\Enums\SubscriptionStatus::Active && ! empty($s->stripe_subscription_id));
                                        @endphp
                                        @if (! $isExistingClient)
                                            <flux:button
                                                wire:click="openExemptionModal('{{ $tenant->id }}')"
                                                size="sm"
                                                variant="outline"
                                                icon="gift"
                                                title="Conceder período de isenção"
                                            >
                                                Isenção
                                            </flux:button>
                                        @endif
                                    @endif

                                    @if ($tenant->status->value === 'active')
                                        @if (! $tenant->isVerified())
                                            <flux:button
                                                wire:click="resendFirstAccess('{{ $tenant->id }}')"
                                                size="sm"
                                                variant="outline"
                                                icon="paper-airplane"
                                                title="Reenviar link de primeiro acesso por e-mail"
                                            >
                                                Reenviar Acesso
                                            </flux:button>
                                        @endif

                                        <flux:button
                                            wire:click="impersonate('{{ $tenant->id }}')"
                                            size="sm"
                                            variant="subtle"
                                            icon="arrow-top-right-on-square"
                                            title="Inspecionar laboratório (Auditado LGPD)"
                                        >
                                            Inspecionar
                                        </flux:button>
                                    @else
                                        <flux:button
                                            href="{{ route('onboarding.show', $tenant) }}"
                                            size="sm"
                                            variant="outline"
                                            icon="arrow-right"
                                        >
                                            Detalhes
                                        </flux:button>
                                    @endif
                                </div>
                            </div>
                        </flux:card>
                    @endforeach
                </div>
            @elseif ($search !== '')
                <x-empty-state
                    icon="magnifying-glass"
                    title="Nenhum laboratório encontrado"
                    description="Tente buscar por outro nome ou endereço."
                >
                    <flux:button wire:click="$set('search', '')" variant="ghost">Limpar busca</flux:button>
                </x-empty-state>
            @elseif ($statusFilter === 'near_limit')
                <x-empty-state
                    icon="check-circle"
                    title="Nenhum laboratório próximo dos limites"
                    description="Todos os laboratórios ativos estão operando com boa margem de recursos em seus planos."
                >
                    <flux:button wire:click="$set('statusFilter', 'all')" variant="subtle">
                        Ver todos os laboratórios
                    </flux:button>
                </x-empty-state>
            @elseif ($statusFilter !== 'all')
                <x-empty-state
                    icon="check-circle"
                    title="Nenhum laboratório com o filtro selecionado"
                    description="Não há laboratórios na categoria selecionada no momento."
                >
                    <flux:button wire:click="$set('statusFilter', 'all')" variant="subtle">
                        Ver todos os laboratórios
                    </flux:button>
                </x-empty-state>
            @else
                <x-empty-state
                    icon="building-office-2"
                    title="Sua conta ainda não tem laboratórios"
                    description="Cadastre seu primeiro laboratório para começar."
                >
                    <flux:button href="{{ route('central.admin.laboratories.create') }}" variant="primary" icon="plus">
                        Cadastrar laboratório
                    </flux:button>
                </x-empty-state>
            @endif
        </section>

        {{-- Modal de Desconto --}}
        <flux:modal name="discount-modal" class="backdrop:backdrop-blur-sm md:w-md" @close="closeDiscountModal">
            <div class="space-y-6">
                <div>
                    <flux:heading size="lg">{{ $editingDiscountId ? 'Editar Desconto Comercial' : 'Conceder Desconto Comercial' }}</flux:heading>
                    <flux:subheading>{{ $editingDiscountId ? 'Altere os parâmetros do desconto aplicado à fatura pendente.' : 'O abatimento será aplicado ao próximo pagamento pendente do tenant.' }}</flux:subheading>
                </div>

                <form wire:submit="saveDiscount" class="space-y-4">
                    <flux:field>
                        <flux:label>Tipo de Desconto</flux:label>
                        <flux:select wire:model.live="discountType">
                            <option value="fixed_amount">Valor Fixo (R$)</option>
                            <option value="percentage">Percentual (%)</option>
                        </flux:select>
                    </flux:field>

                    <flux:field>
                        <flux:label>{{ $discountType === 'percentage' ? 'Percentual (1 a 100)' : 'Valor (Ex: 50,00)' }}</flux:label>
                        <flux:input
                            wire:model="discountValue"
                            placeholder="{{ $discountType === 'percentage' ? '10' : '50,00' }}"
                            required
                        />
                        <flux:error name="discountValue" />
                    </flux:field>

                    <flux:field>
                        <flux:label>Expira em (Mês/Ano - Opcional)</flux:label>
                        <flux:input wire:model="discountExpiresAt" type="month" />
                        <flux:error name="discountExpiresAt" />
                    </flux:field>

                    <div class="flex justify-end gap-3 pt-2">
                        <flux:modal.close>
                            <flux:button type="button" variant="ghost" wire:click="closeDiscountModal">Cancelar</flux:button>
                        </flux:modal.close>
                        <flux:button type="submit" variant="primary">{{ $editingDiscountId ? 'Salvar Alterações' : 'Aplicar Desconto' }}</flux:button>
                    </div>
                </form>
            </div>
        </flux:modal>

        {{-- Modal de Confirmação para Remover Desconto --}}
        <flux:modal name="remove-discount-modal" class="backdrop:backdrop-blur-sm md:w-md" @close="closeRemoveDiscountModal">
            <div class="space-y-6">
                <div>
                    <flux:heading size="lg">Remover Desconto Comercial</flux:heading>
                    <flux:subheading>
                        Tem certeza que deseja remover este desconto? O valor original da fatura pendente será restaurado.
                    </flux:subheading>
                </div>

                <div class="flex justify-end gap-3 pt-2">
                    <flux:modal.close>
                        <flux:button type="button" variant="ghost" wire:click="closeRemoveDiscountModal">Cancelar</flux:button>
                    </flux:modal.close>
                    <flux:button type="button" variant="danger" wire:click="removeDiscount">Remover Desconto</flux:button>
                </div>
            </div>
        </flux:modal>

        {{-- Modal de Isenção --}}
        <flux:modal name="exemption-modal" class="backdrop:backdrop-blur-sm md:w-md" @close="closeExemptionModal">
            <div class="space-y-6">
                <div>
                    <flux:heading size="lg">{{ $editingExemptionId ? 'Editar Período de Isenção' : 'Conceder Período de Isenção' }}</flux:heading>
                    <flux:subheading>{{ $editingExemptionId ? 'Ajuste os meses do período ou o motivo da isenção.' : 'O laboratório ficará isento de cobranças durante o período especificado.' }}</flux:subheading>
                </div>

                <form wire:submit="saveExemption" class="space-y-4">
                    <div class="grid grid-cols-2 gap-4">
                        <flux:field>
                            <flux:label>Mês Inicial</flux:label>
                            <flux:input wire:model="exemptionStartsAt" type="month" required />
                            <flux:error name="exemptionStartsAt" />
                        </flux:field>

                        <flux:field>
                            <flux:label>Mês Final</flux:label>
                            <flux:input wire:model="exemptionEndsAt" type="month" required />
                            <flux:error name="exemptionEndsAt" />
                        </flux:field>
                    </div>

                    <flux:field>
                        <flux:label>Motivo da Isenção</flux:label>
                        <flux:input wire:model="exemptionReason" placeholder="Parceria comercial / teste estendido" required />
                        <flux:error name="exemptionReason" />
                    </flux:field>

                    <div class="flex justify-end gap-3 pt-2">
                        <flux:modal.close>
                            <flux:button type="button" variant="ghost" wire:click="closeExemptionModal">Cancelar</flux:button>
                        </flux:modal.close>
                        <flux:button type="submit" variant="primary">{{ $editingExemptionId ? 'Salvar Alterações' : 'Conceder Isenção' }}</flux:button>
                    </div>
                </form>
            </div>
        </flux:modal>

        {{-- Modal de Confirmação para Revogar Isenção --}}
        <flux:modal name="revoke-exemption-modal" class="backdrop:backdrop-blur-sm md:w-md" @close="closeRevokeExemptionModal">
            <div class="space-y-6">
                <div>
                    <flux:heading size="lg">Revogar Isenção de Pagamento</flux:heading>
                    <flux:subheading>
                        Tem certeza que deseja revogar o período de isenção deste laboratório? O tenant retornará ao ciclo padrão de faturamento.
                    </flux:subheading>
                </div>

                <div class="flex justify-end gap-3 pt-2">
                    <flux:modal.close>
                        <flux:button type="button" variant="ghost" wire:click="closeRevokeExemptionModal">Cancelar</flux:button>
                    </flux:modal.close>
                    <flux:button type="button" variant="danger" wire:click="revokeExemption">Revogar Isenção</flux:button>
                </div>
            </div>
        </flux:modal>
    </main>
</div>
