<?php

use App\Enums\DiscountType;
use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\TenantStatus;
use App\Models\CentralUser;
use App\Models\Discount;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Services\ApplyDiscountService;
use App\Services\ExemptionService;
use App\Services\ImpersonateTenantService;
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

    // Quick filter: 'all', 'active', 'overdue', 'provisioning'
    public string $statusFilter = 'all';

    // Discount modal state
    public bool $showDiscountModal = false;
    public string $selectedTenantId = '';
    public string $discountType = 'fixed_amount';
    public string $discountValue = '';
    public ?string $discountExpiresAt = null;

    // Exemption modal state
    public bool $showExemptionModal = false;
    public string $exemptionStartsAt = '';
    public string $exemptionEndsAt = '';
    public string $exemptionReason = '';

    // Feedback messages
    public ?string $feedbackMessage = null;
    public ?string $errorMessage = null;

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
            ->with(['subscriptions', 'payments', 'exemptions'])
            ->get();

        $counts = [
            'total' => $allTenants->count(),
            'active' => 0,
            'overdue' => 0,
            'provisioning' => 0,
            'failed' => 0,
        ];

        foreach ($allTenants as $tenant) {
            $status = $tenant->status?->value ?? (string) $tenant->getAttribute('status');

            if ($status === 'active') {
                $counts['active']++;
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
            ->with(['domains', 'exemptions', 'subscriptions', 'payments'])
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
            $this->errorMessage = 'Muitas tentativas de impersonação. Aguarde um minuto.';
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
            $this->errorMessage = $e->getMessage();
            return null;
        }
    }

    public function openDiscountModal(string $tenantId): void
    {
        $this->resetDiscountForm();
        $this->selectedTenantId = $tenantId;
        $this->showDiscountModal = true;
    }

    public function closeDiscountModal(): void
    {
        $this->showDiscountModal = false;
        $this->resetDiscountForm();
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
            'discountExpiresAt' => ['nullable', 'date', 'after:now'],
        ], [
            'discountValue.required' => 'Informe o valor do desconto.',
            'discountValue.min' => 'O valor deve ser maior que zero.',
            'discountExpiresAt.after' => 'A data de expiração deve ser no futuro.',
        ]);

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

        try {
            $discount = Discount::query()->create([
                'tenant_id' => $tenant->id,
                'type' => DiscountType::from($this->discountType),
                'value_in_cents' => $valueInCents,
                'expires_at' => $this->discountExpiresAt ? Carbon::parse($this->discountExpiresAt) : null,
            ]);

            $service->apply($discount);

            $this->feedbackMessage = "Desconto aplicado com sucesso ao próximo pagamento de [{$tenant->name}].";
            $this->closeDiscountModal();
        } catch (\Throwable $e) {
            $this->errorMessage = $e->getMessage();
        }
    }

    public function openExemptionModal(string $tenantId): void
    {
        $this->resetExemptionForm();
        $this->selectedTenantId = $tenantId;
        $this->exemptionStartsAt = Carbon::now()->format('Y-m-d\TH:i');
        $this->exemptionEndsAt = Carbon::now()->addMonth()->format('Y-m-d\TH:i');
        $this->showExemptionModal = true;
    }

    public function closeExemptionModal(): void
    {
        $this->showExemptionModal = false;
        $this->resetExemptionForm();
    }

    public function saveExemption(ExemptionService $service): void
    {
        $user = Auth::guard('central')->user();
        abort_unless($user instanceof CentralUser, 403);

        $this->validate([
            'selectedTenantId' => ['required', 'exists:tenants,id'],
            'exemptionStartsAt' => ['required', 'date'],
            'exemptionEndsAt' => ['required', 'date', 'after:exemptionStartsAt'],
            'exemptionReason' => ['required', 'string', 'max:255'],
        ], [
            'exemptionEndsAt.after' => 'A data final deve ser posterior à data inicial.',
            'exemptionReason.required' => 'Informe o motivo da isenção.',
        ]);

        $tenant = Tenant::query()->findOrFail($this->selectedTenantId);

        try {
            $service->grant(
                $tenant,
                Carbon::parse($this->exemptionStartsAt),
                Carbon::parse($this->exemptionEndsAt),
                $this->exemptionReason,
                $user,
                request()->ip(),
                request()->userAgent()
            );

            $this->feedbackMessage = "Isenção concedida com sucesso para [{$tenant->name}].";
            $this->closeExemptionModal();
        } catch (\Throwable $e) {
            $this->errorMessage = $e->getMessage();
        }
    }

    private function resetDiscountForm(): void
    {
        $this->discountType = 'fixed_amount';
        $this->discountValue = '';
        $this->discountExpiresAt = null;
        $this->resetErrorBag();
    }

    private function resetExemptionForm(): void
    {
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

        @if ($feedbackMessage)
            <div class="rounded-xl border border-emerald-300 bg-emerald-50 p-4 text-emerald-900 dark:border-emerald-800 dark:bg-emerald-950 dark:text-emerald-200">
                <div class="flex items-center justify-between">
                    <span>{{ $feedbackMessage }}</span>
                    <button wire:click="$set('feedbackMessage', null)" class="text-emerald-700 hover:text-emerald-900 dark:text-emerald-300">✕</button>
                </div>
            </div>
        @endif

        @if ($errorMessage)
            <div class="rounded-xl border border-rose-300 bg-rose-50 p-4 text-rose-900 dark:border-rose-800 dark:bg-rose-950 dark:text-rose-200">
                <div class="flex items-center justify-between">
                    <span>{{ $errorMessage }}</span>
                    <button wire:click="$set('errorMessage', null)" class="text-rose-700 hover:text-rose-900 dark:text-rose-300">✕</button>
                </div>
            </div>
        @endif

        <section class="flex flex-col gap-2">
            <flux:heading size="lg">Gerencie seus espaços de trabalho</flux:heading>
            <flux:text>Consulte a configuração, acompanhe faturas e acesse os laboratórios vinculados com auditoria.</flux:text>
        </section>

        @php
            $summary = $this->summaryCounts;
        @endphp

        <section aria-label="Resumo dos laboratórios" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <button
                type="button"
                wire:click="$set('statusFilter', 'all')"
                class="text-left transition"
            >
                <flux:card class="flex flex-col gap-1 {{ $statusFilter === 'all' ? 'ring-2 ring-zinc-900 dark:ring-white' : '' }}">
                    <flux:text>Total de laboratórios</flux:text>
                    <flux:heading size="xl">{{ $summary['total'] }}</flux:heading>
                </flux:card>
            </button>

            <button
                type="button"
                wire:click="$set('statusFilter', 'active')"
                class="text-left transition"
            >
                <flux:card class="flex flex-col gap-1 {{ $statusFilter === 'active' ? 'ring-2 ring-emerald-500' : '' }}">
                    <flux:text>Ativos</flux:text>
                    <flux:heading size="xl">{{ $summary['active'] }}</flux:heading>
                </flux:card>
            </button>

            <button
                type="button"
                wire:click="$set('statusFilter', 'overdue')"
                class="text-left transition"
            >
                <flux:card class="flex flex-col gap-1 {{ $statusFilter === 'overdue' ? 'ring-2 ring-rose-500' : '' }}">
                    <flux:text class="text-rose-600 dark:text-rose-400">Inadimplentes</flux:text>
                    <flux:heading size="xl" class="text-rose-600 dark:text-rose-400">{{ $summary['overdue'] }}</flux:heading>
                </flux:card>
            </button>

            <button
                type="button"
                wire:click="$set('statusFilter', 'provisioning')"
                class="text-left transition"
            >
                <flux:card class="flex flex-col gap-1 {{ $statusFilter === 'provisioning' ? 'ring-2 ring-blue-500' : '' }}">
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
                                (Filtro: {{ match($statusFilter) { 'active' => 'Ativos', 'overdue' => 'Inadimplentes', 'provisioning' => 'Em configuração', default => $statusFilter } }})
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
                <div class="grid gap-4 md:grid-cols-2">
                    @foreach ($tenants as $tenant)
                        @php
                            $domain = $tenant->domains->first()?->domain;
                            $isOverdue = $this->isTenantOverdue($tenant);
                            $overdueAmount = $isOverdue ? $this->getOverdueAmount($tenant) : 0;
                            $activeExemption = $tenant->exemptions->first(fn ($ex) => $ex->isActive());
                        @endphp

                        <flux:card wire:key="laboratory-{{ $tenant->id }}" class="flex flex-col gap-5 {{ $isOverdue ? 'border-rose-300 dark:border-rose-900/60' : '' }}">
                            <div class="flex items-start justify-between gap-4">
                                <div class="flex min-w-0 items-center gap-3">
                                    <span class="flex size-12 shrink-0 items-center justify-center rounded-xl bg-teal-50 text-teal-700 dark:bg-teal-950 dark:text-teal-300">
                                        <flux:icon name="building-office-2" class="size-6" />
                                    </span>
                                    <div class="min-w-0">
                                        <flux:heading size="base" class="truncate">{{ $tenant->name }}</flux:heading>
                                        <flux:text class="truncate text-sm">
                                            {{ $domain ?? 'Domínio ainda não configurado' }}
                                        </flux:text>
                                    </div>
                                </div>

                                <div class="flex flex-wrap items-center gap-2">
                                    @if ($activeExemption)
                                        <flux:badge color="amber" icon="gift">
                                            Isento até {{ $activeExemption->ends_at->format('d/m/Y') }}
                                        </flux:badge>
                                    @endif

                                    @if ($isOverdue)
                                        <flux:badge color="red" icon="exclamation-circle">
                                            Inadimplente (R$ {{ number_format($overdueAmount / 100, 2, ',', '.') }})
                                        </flux:badge>
                                    @endif

                                    @if ($tenant->status->value === 'active')
                                        <flux:badge color="green" icon="check-circle">Ativo</flux:badge>
                                    @elseif ($tenant->status->value === 'provisioning')
                                        <flux:badge color="blue" icon="clock">Configurando</flux:badge>
                                    @else
                                        <flux:badge color="red" icon="exclamation-triangle">Falha</flux:badge>
                                    @endif
                                </div>
                            </div>

                            <div class="flex flex-col gap-4 border-t border-zinc-200 pt-4 dark:border-zinc-700 sm:flex-row sm:items-center sm:justify-between">
                                <flux:text class="text-sm">
                                    Criado em {{ $tenant->created_at?->format('d/m/Y') ?? '—' }}
                                </flux:text>

                                <div class="flex flex-wrap items-center gap-2">
                                    <flux:button
                                        wire:click="openDiscountModal('{{ $tenant->id }}')"
                                        size="sm"
                                        variant="outline"
                                        icon="receipt-percent"
                                        title="Conceder desconto no próximo pagamento"
                                    >
                                        Desconto
                                    </flux:button>

                                    <flux:button
                                        wire:click="openExemptionModal('{{ $tenant->id }}')"
                                        size="sm"
                                        variant="outline"
                                        icon="gift"
                                        title="Conceder período de isenção"
                                    >
                                        Isenção
                                    </flux:button>

                                    @if ($tenant->status->value === 'active')
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
        @if ($showDiscountModal)
            <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
                <div class="w-full max-w-md rounded-2xl bg-white p-6 shadow-xl dark:bg-zinc-900">
                    <div class="mb-4">
                        <flux:heading size="lg">Conceder Desconto Comercial</flux:heading>
                        <flux:text class="text-sm">O abatimento será aplicado ao próximo pagamento pendente do tenant.</flux:text>
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
                            <flux:label>Expira em (Opcional)</flux:label>
                            <flux:input wire:model="discountExpiresAt" type="datetime-local" />
                            <flux:error name="discountExpiresAt" />
                        </flux:field>

                        <div class="mt-6 flex justify-end gap-3">
                            <flux:button wire:click="closeDiscountModal" type="button" variant="ghost">Cancelar</flux:button>
                            <flux:button type="submit" variant="primary">Aplicar Desconto</flux:button>
                        </div>
                    </form>
                </div>
            </div>
        @endif

        {{-- Modal de Isenção --}}
        @if ($showExemptionModal)
            <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
                <div class="w-full max-w-md rounded-2xl bg-white p-6 shadow-xl dark:bg-zinc-900">
                    <div class="mb-4">
                        <flux:heading size="lg">Conceder Período de Isenção</flux:heading>
                        <flux:text class="text-sm">O laboratório ficará isento de cobranças durante o período especificado.</flux:text>
                    </div>

                    <form wire:submit="saveExemption" class="space-y-4">
                        <flux:field>
                            <flux:label>Data de Início</flux:label>
                            <flux:input wire:model="exemptionStartsAt" type="datetime-local" required />
                            <flux:error name="exemptionStartsAt" />
                        </flux:field>

                        <flux:field>
                            <flux:label>Data de Término</flux:label>
                            <flux:input wire:model="exemptionEndsAt" type="datetime-local" required />
                            <flux:error name="exemptionEndsAt" />
                        </flux:field>

                        <flux:field>
                            <flux:label>Motivo da Isenção</flux:label>
                            <flux:input wire:model="exemptionReason" placeholder="Parceria comercial / teste estendido" required />
                            <flux:error name="exemptionReason" />
                        </flux:field>

                        <div class="mt-6 flex justify-end gap-3">
                            <flux:button wire:click="closeExemptionModal" type="button" variant="ghost">Cancelar</flux:button>
                            <flux:button type="submit" variant="primary">Conceder Isenção</flux:button>
                        </div>
                    </form>
                </div>
            </div>
        @endif
    </main>
</div>
