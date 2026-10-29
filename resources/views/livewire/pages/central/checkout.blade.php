<?php

use App\Actions\CreateLaboratory;
use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\TenantStatus;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Services\StripeService;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.guest', ['maxWidth' => 'max-w-5xl', 'noCard' => true])] class extends Component
{
    public Tenant $tenant;

    public ?Subscription $subscription = null;

    public string $selectedPlanId = '';

    public string $clientSecret = '';

    public string $stripeKey = '';

    public bool $isMockMode = false;

    public function mount(Tenant $tenant, StripeService $stripeService): void
    {
        $this->tenant = $tenant;

        // Se já está ativo, redirecionar
        if ($this->tenant->status === TenantStatus::Active) {
            $url = $this->tenant->first_access_url ?? $this->tenant->data['first_access_url'] ?? route('login', ['tenant' => $this->tenant->id]);
            $this->redirect($url, navigate: true);

            return;
        }

        // Se o laboratório for isento ou possuir período de teste concedido por sócio, bypass de checkout
        if ($this->tenant->isExempt() || $this->tenant->hasActiveTrial()) {
            if ($this->tenant->status !== TenantStatus::Provisioning) {
                CreateLaboratory::dispatchProvisioning($this->tenant);
            }

            $this->redirectRoute('onboarding.show', ['tenant' => $this->tenant->id], navigate: true);

            return;
        }

        $this->subscription = $this->tenant->subscriptions()->with('plan')->latest()->first();

        if ($this->subscription && $this->subscription->plan_id) {
            $this->selectedPlanId = (string) $this->subscription->plan_id;
        } else {
            $firstPlan = $this->plans->first();
            $this->selectedPlanId = (string) ($firstPlan?->id ?? '');
        }

        $this->stripeKey = (string) config('services.stripe.key');
        $this->isMockMode = empty($this->stripeKey) || str_contains($this->stripeKey, 'placeholder') || app()->environment('testing');

        $intent = $stripeService->createSetupIntent($this->tenant);
        $this->clientSecret = $intent['client_secret'];
    }

    /**
     * @return Collection<int, Plan>
     */
    #[Computed]
    public function plans(): Collection
    {
        return Plan::query()
            ->where('is_active', true)
            ->orderBy('price_in_cents', 'asc')
            ->get();
    }

    #[Computed]
    public function selectedPlan(): ?Plan
    {
        if (! $this->selectedPlanId) {
            return $this->subscription?->plan ?? $this->plans->first();
        }

        return $this->plans->firstWhere('id', $this->selectedPlanId)
            ?? Plan::query()->find($this->selectedPlanId);
    }

    public function selectPlan(string $planId): void
    {
        $plan = Plan::query()->where('is_active', true)->find($planId);
        if (! $plan) {
            return;
        }

        $this->selectedPlanId = (string) $plan->id;

        if ($this->subscription) {
            $now = now();
            $subEnd = $plan->billing_cycle === 'annual' ? $now->copy()->addYear() : $now->copy()->addMonth();
            $this->subscription->update([
                'plan_id' => $plan->id,
                'current_period_end' => $subEnd,
            ]);
            $this->subscription->refresh();
            $this->subscription->load('plan');
        }

        $this->tenant->payments()
            ->where('status', PaymentStatus::Pending)
            ->update([
                'amount_in_cents' => $plan->price_in_cents,
            ]);
    }

    public function confirmPaymentMethod(string $paymentMethodId, StripeService $stripeService): void
    {
        $stripeService->attachPaymentMethod($this->tenant, $paymentMethodId);

        $amountInCents = $plan?->price_in_cents ?? $this->subscription?->plan?->price_in_cents ?? 0;

        $pendingPayment = $this->tenant->payments()
            ->where('status', PaymentStatus::Pending)
            ->first();

        if ($pendingPayment) {
            $pendingPayment->update([
                'amount_in_cents' => $amountInCents,
                'status' => PaymentStatus::Paid,
                'paid_at' => now(),
            ]);
        } else {
            $this->tenant->payments()->create([
                'amount_in_cents' => $amountInCents,
                'status' => PaymentStatus::Paid,
                'paid_at' => now(),
                'due_date' => now(),
            ]);
        }

        // Inicia o provisionamento
        CreateLaboratory::dispatchProvisioning($this->tenant);

        $this->redirectRoute('onboarding.show', ['tenant' => $this->tenant->id], navigate: true);
    }
}; ?>

<div
    x-data="stripeCardSetup({
        clientSecret: @js($clientSecret),
        stripeKey: @js($stripeKey),
        wireAction: 'confirmPaymentMethod'
    })"
    class="w-full flex flex-col gap-6 sm:gap-8"
>
    <div class="text-center space-y-2">
        <flux:badge color="indigo" size="sm" class="inline-flex">ETAPA 2 DE 3 • CHECKOUT</flux:badge>
        <flux:heading size="xl" class="text-2xl sm:text-3xl font-extrabold tracking-tight">Finalizar Assinatura</flux:heading>
        <flux:text class="text-sm sm:text-base text-zinc-600 dark:text-zinc-400 max-w-2xl mx-auto">
            Escolha o plano ideal e confirme seus dados de pagamento para ativar seu laboratório.
        </flux:text>
    </div>

    <!-- Lista de todos os planos disponíveis -->
    <div class="space-y-4">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-1">
            <flux:heading size="base" class="font-semibold text-zinc-900 dark:text-zinc-100">
                Opções de Planos Disponíveis
            </flux:heading>
            <flux:text class="text-xs text-zinc-500">
                Clique em um plano para selecioná-lo
            </flux:text>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 sm:gap-5">
            @foreach ($this->plans as $plan)
                @php
                    $isSelected = ($this->selectedPlanId === (string) $plan->id);
                    $isPopular = str_contains(strtolower($plan->name), 'profissional');
                    $usersLimit = $plan->limits[App\Enums\PlanResource::Users->value] ?? null;
                    $storageLimit = $plan->limits[App\Enums\PlanResource::Storage->value] ?? null;
                    $monthlyItemsLimit = $plan->limits[App\Enums\PlanResource::MonthlyItems->value] ?? null;
                @endphp
                <button
                    type="button"
                    wire:click="selectPlan('{{ $plan->id }}')"
                    class="group relative flex flex-col justify-between rounded-2xl p-5 text-left transition-all duration-200 outline-none
                        {{ $isSelected
                            ? 'border-2 border-indigo-600 bg-indigo-50/40 shadow-md ring-2 ring-indigo-500/20 dark:border-indigo-500 dark:bg-indigo-950/20 dark:ring-indigo-400/20'
                            : 'border border-zinc-200 bg-white hover:border-zinc-300 hover:shadow-sm dark:border-zinc-800 dark:bg-zinc-900 dark:hover:border-zinc-700' }}"
                >
                    @if ($isPopular)
                        <div class="absolute -top-3 right-4">
                            <span class="inline-flex items-center rounded-full bg-gradient-to-r from-indigo-500 to-purple-600 px-2.5 py-0.5 text-[10px] font-bold uppercase tracking-wider text-white shadow-sm">
                                Mais Escolhido
                            </span>
                        </div>
                    @endif

                    <div class="space-y-4 w-full">
                        <div class="flex items-start justify-between gap-2">
                            <div>
                                <h3 class="font-bold text-lg text-zinc-900 dark:text-white">{{ $plan->name }}</h3>
                                <p class="text-xs text-zinc-500 dark:text-zinc-400">Ciclo {{ $plan->billing_cycle === 'annual' ? 'anual' : 'mensal' }}</p>
                            </div>
                            <span class="flex size-5 shrink-0 items-center justify-center rounded-full transition-colors {{ $isSelected ? 'bg-indigo-600 text-white' : 'border-2 border-zinc-300 group-hover:border-zinc-400 dark:border-zinc-600' }}">
                                @if ($isSelected)
                                    <flux:icon name="check" class="size-3 stroke-[3]" />
                                @endif
                            </span>
                        </div>

                        <div class="flex items-baseline gap-1">
                            <span class="text-3xl font-extrabold tracking-tight text-zinc-900 dark:text-white">
                                R$ {{ number_format($plan->price_in_cents / 100, 2, ',', '.') }}
                            </span>
                            <span class="text-xs text-zinc-500 dark:text-zinc-400">/ {{ $plan->billing_cycle === 'annual' ? 'ano' : 'mês' }}</span>
                        </div>

                        <div class="border-t border-zinc-200/80 dark:border-zinc-800 pt-3 space-y-2 text-xs text-zinc-600 dark:text-zinc-300">
                            <div class="flex items-center gap-2">
                                <flux:icon name="users" class="size-4 shrink-0 {{ $isSelected ? 'text-indigo-600 dark:text-indigo-400' : 'text-zinc-400' }}" />
                                <span>{{ $usersLimit ? $usersLimit . ' usuários' : 'Usuários ilimitados' }}</span>
                            </div>
                            <div class="flex items-center gap-2">
                                <flux:icon name="archive-box" class="size-4 shrink-0 {{ $isSelected ? 'text-indigo-600 dark:text-indigo-400' : 'text-zinc-400' }}" />
                                <span>{{ $storageLimit ? $storageLimit . ' GB armazenamento' : 'Armazenamento ilimitado' }}</span>
                            </div>
                            <div class="flex items-center gap-2">
                                <flux:icon name="document-text" class="size-4 shrink-0 {{ $isSelected ? 'text-indigo-600 dark:text-indigo-400' : 'text-zinc-400' }}" />
                                <span>{{ $monthlyItemsLimit ? $monthlyItemsLimit . ' ordens/mês' : 'Ordens ilimitadas' }}</span>
                            </div>
                        </div>
                    </div>

                    <div class="mt-5 w-full pt-2">
                        @if ($isSelected)
                            <div class="w-full rounded-xl bg-indigo-600 py-2 text-center text-xs font-semibold text-white shadow-sm dark:bg-indigo-500">
                                Plano Selecionado
                            </div>
                        @else
                            <div class="w-full rounded-xl border border-zinc-200 bg-zinc-50 py-2 text-center text-xs font-medium text-zinc-700 transition group-hover:bg-zinc-100 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-300 dark:group-hover:bg-zinc-700">
                                Selecionar Plano
                            </div>
                        @endif
                    </div>
                </button>
            @endforeach
        </div>
    </div>

    <!-- Grid inferior: Resumo e Pagamento -->
    <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
        <!-- Resumo do Pedido -->
        <div class="lg:col-span-5 flex flex-col gap-4">
            <flux:card class="flex flex-col gap-4 border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900 h-full justify-between">
                <div class="space-y-4">
                    <div class="flex items-center justify-between pb-3 border-b border-zinc-100 dark:border-zinc-800">
                        <flux:heading size="base">Resumo da Assinatura</flux:heading>
                        <flux:badge color="zinc" size="sm">RECORRENTE</flux:badge>
                    </div>

                    @if ($this->selectedPlan)
                        <div class="space-y-2">
                            <div class="flex items-center justify-between">
                                <span class="text-sm font-medium text-zinc-900 dark:text-white">
                                    Plano {{ $this->selectedPlan->name }}
                                </span>
                                <span class="text-sm font-semibold text-zinc-900 dark:text-white">
                                    R$ {{ number_format($this->selectedPlan->price_in_cents / 100, 2, ',', '.') }}
                                </span>
                            </div>
                            <div class="flex items-center justify-between text-xs text-zinc-500">
                                <span>Frequência de cobrança</span>
                                <span class="capitalize">{{ $this->selectedPlan->billing_cycle === 'annual' ? 'Anual' : 'Mensal' }}</span>
                            </div>
                        </div>

                        <div class="rounded-xl bg-zinc-50 dark:bg-zinc-800/60 p-3 space-y-1.5 border border-zinc-200/60 dark:border-zinc-700/60">
                            <div class="flex justify-between items-baseline font-bold">
                                <span class="text-zinc-900 dark:text-white text-sm">Total a pagar agora:</span>
                                <span class="text-lg text-indigo-600 dark:text-indigo-400">
                                    R$ {{ number_format($this->selectedPlan->price_in_cents / 100, 2, ',', '.') }}
                                </span>
                            </div>
                            <p class="text-[11px] text-zinc-500 dark:text-zinc-400">
                                Cobrança processada com segurança via Stripe. Sem taxa de adesão ou taxas ocultas.
                            </p>
                        </div>
                    @endif
                </div>

                <div class="text-xs text-zinc-500 space-y-2 pt-2">
                    <div class="flex items-center gap-2">
                        <flux:icon name="check-circle" class="size-4 text-emerald-600 dark:text-emerald-400 shrink-0" />
                        <span>Acesso imediato após confirmação</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <flux:icon name="check-circle" class="size-4 text-emerald-600 dark:text-emerald-400 shrink-0" />
                        <span>Cancele ou mude de plano a qualquer momento</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <flux:icon name="check-circle" class="size-4 text-emerald-600 dark:text-emerald-400 shrink-0" />
                        <span>Dados protegidos com criptografia TLS 256 bits</span>
                    </div>
                </div>
            </flux:card>
        </div>

        <!-- Formulário de Pagamento Stripe -->
        <div class="lg:col-span-7">
            <flux:card class="flex flex-col gap-5 border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900">
                <div>
                    <flux:heading size="base">Cartão de Crédito</flux:heading>
                    <flux:subheading>Insira os dados do cartão para concluir a contratação.</flux:subheading>
                </div>

                @if ($isMockMode)
                    <div class="rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-200">
                        <p class="font-medium">Modo de Simulação / Testes</p>
                        <p class="mt-1 text-xs">As credenciais do Stripe estão configuradas em modo local. Você pode simular a confirmação imediata do cartão abaixo.</p>
                    </div>

                    <flux:button
                        wire:click="confirmPaymentMethod('pm_mock_test_card')"
                        variant="primary"
                        class="w-full"
                        wire:loading.attr="disabled"
                    >
                        Confirmar Pagamento e Ativar {{ $this->selectedPlan?->name ?? 'Laboratório' }}
                    </flux:button>
                @else
                    <form @submit.prevent="handleSubmit" class="flex flex-col gap-4">
                        <div wire:ignore x-ref="cardElement" class="rounded-lg border border-zinc-300 p-3 bg-white dark:border-zinc-700 dark:bg-zinc-900 min-h-[46px]">
                            <!-- Stripe Card Element montado via Alpine -->
                        </div>
                        <div x-show="errorMessage" x-text="errorMessage" role="alert" class="text-xs text-rose-600"></div>

                        <flux:button type="submit" variant="primary" class="w-full" x-bind:disabled="isProcessing">
                            <span x-show="!isProcessing">
                                Confirmar Pagamento e Ativar {{ $this->selectedPlan?->name ?? 'Laboratório' }}
                            </span>
                            <span x-show="isProcessing" class="inline-flex items-center gap-2">
                                <flux:icon.loading class="size-4" />
                                Processando Pagamento...
                            </span>
                        </flux:button>
                    </form>
                @endif
            </flux:card>
        </div>
    </div>
</div>
