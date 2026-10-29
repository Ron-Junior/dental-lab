<?php

use App\Enums\SubscriptionStatus;
use App\Models\Tenant;
use App\Services\StripeService;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;

new class extends Component
{
    public string $clientSecret = '';

    public string $stripeKey = '';

    public bool $isMockMode = false;

    public function mount(StripeService $stripeService): void
    {
        $this->stripeKey = (string) config('services.stripe.key');
        $this->isMockMode = empty($this->stripeKey) || str_contains($this->stripeKey, 'placeholder') || app()->environment('testing');

        $tenant = $this->centralTenant();
        if ($tenant) {
            $intent = $stripeService->createSetupIntent($tenant);
            $this->clientSecret = $intent['client_secret'];
        }
    }

    #[Computed]
    public function centralTenant(): ?Tenant
    {
        $centralConn = config('tenancy.database.central_connection', 'pgsql');
        $tenantId = tenant('id');

        if (! $tenantId) {
            return null;
        }

        return Tenant::on($centralConn)
            ->with(['subscriptions.plan', 'exemptions'])
            ->find($tenantId);
    }

    #[Computed]
    public function subscription()
    {
        return $this->centralTenant?->subscriptions->sortByDesc('created_at')->first();
    }

    #[Computed]
    public function isExempt(): bool
    {
        return (bool) $this->centralTenant?->isExempt();
    }

    #[Computed]
    public function activeExemption()
    {
        return $this->centralTenant?->exemptions->first(fn ($ex) => $ex->isActive());
    }

    #[Computed]
    public function trialDaysRemaining(): int
    {
        $sub = $this->subscription;
        if (! $sub || $sub->status !== SubscriptionStatus::Trial || ! $sub->current_period_end) {
            return 0;
        }

        $now = now();
        if ($now->greaterThanOrEqualTo($sub->current_period_end)) {
            return 0;
        }

        return max(0, (int) $now->diffInDays($sub->current_period_end, false));
    }

    public function openUpdateCardModal(): void
    {
        Flux::modal('update-card-modal')->show();
    }

    public function updateCard(string $paymentMethodId, StripeService $stripeService): void
    {
        $tenant = $this->centralTenant;
        if (! $tenant) {
            return;
        }

        $stripeService->attachPaymentMethod($tenant, $paymentMethodId);

        Flux::modal('update-card-modal')->close();
        Flux::toast(variant: 'success', text: 'Cartão de crédito atualizado com sucesso!');
    }
}; ?>

<div>
    <flux:card class="space-y-6" id="profile-subscription-billing-card">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div class="space-y-1">
                <div class="flex items-center gap-2">
                    <flux:heading size="lg">Assinatura & Faturamento</flux:heading>
                    @if ($this->isExempt)
                        <flux:badge color="amber" size="sm" icon="gift">Isenção Ativa</flux:badge>
                    @elseif ($this->subscription)
                        @if ($this->subscription->status === \App\Enums\SubscriptionStatus::Trial)
                            <flux:badge color="indigo" size="sm" icon="clock">Período de Testes</flux:badge>
                        @elseif ($this->subscription->status === \App\Enums\SubscriptionStatus::Active)
                            <flux:badge color="green" size="sm" icon="check-circle">Ativo</flux:badge>
                        @elseif ($this->subscription->status === \App\Enums\SubscriptionStatus::Overdue)
                            <flux:badge color="red" size="sm" icon="exclamation-circle">Inadimplente</flux:badge>
                        @else
                            <flux:badge color="zinc" size="sm">{{ $this->subscription->status->label() }}</flux:badge>
                        @endif
                    @endif
                </div>
                <flux:subheading>Gerencie seu plano, forma de pagamento cadastrada e prazos de cobrança.</flux:subheading>
            </div>

            @if (! $this->isExempt && $this->subscription && $this->subscription->status === \App\Enums\SubscriptionStatus::Trial)
                <div class="flex items-center gap-3 self-start rounded-xl border border-indigo-200/80 bg-indigo-50/50 px-3.5 py-2.5 dark:border-indigo-900/60 dark:bg-indigo-950/40 sm:self-auto">
                    <flux:icon icon="clock" class="size-5 text-indigo-600 dark:text-indigo-400" />
                    <div class="text-xs">
                        <div class="font-semibold text-indigo-900 dark:text-indigo-200">
                            @if ($this->trialDaysRemaining === 0)
                                Último dia do Trial!
                            @elseif ($this->trialDaysRemaining === 1)
                                1 dia restante de teste
                            @else
                                {{ $this->trialDaysRemaining }} dias restantes de teste
                            @endif
                        </div>
                        <div class="text-indigo-600/80 dark:text-indigo-400/80">
                            Término em {{ $this->subscription->current_period_end?->format('d/m/Y') }}
                        </div>
                    </div>
                </div>
            @elseif ($this->isExempt && $this->activeExemption)
                <div class="flex items-center gap-3 self-start rounded-xl border border-amber-200/80 bg-amber-50/50 px-3.5 py-2.5 dark:border-amber-900/60 dark:bg-amber-950/40 sm:self-auto">
                    <flux:icon icon="gift" class="size-5 text-amber-600 dark:text-amber-400" />
                    <div class="text-xs">
                        <div class="font-semibold text-amber-900 dark:text-amber-200">Isenção Promocional</div>
                        <div class="text-amber-600/80 dark:text-amber-400/80">
                            Válida até {{ $this->activeExemption->ends_at->format('d/m/Y') }}
                        </div>
                    </div>
                </div>
            @endif
        </div>

        <div class="grid gap-4 md:grid-cols-2">
            <!-- Plano Contratado -->
            <div class="flex flex-col justify-between rounded-xl border border-zinc-200 bg-zinc-50/50 p-4 dark:border-zinc-700/80 dark:bg-zinc-800/40">
                <div class="space-y-1">
                    <flux:text class="text-xs text-zinc-500">Plano Contratado</flux:text>
                    <div class="text-lg font-bold text-zinc-900 dark:text-zinc-100">
                        {{ $this->subscription?->plan?->name ?? 'Plano Inicial' }}
                    </div>
                    <div class="text-sm text-zinc-600 dark:text-zinc-400">
                        R$ {{ number_format(($this->subscription?->plan?->price_in_cents ?? 0) / 100, 2, ',', '.') }}
                        / {{ $this->subscription?->plan?->billing_cycle === 'annual' ? 'ano' : 'mês' }}
                    </div>
                </div>
            </div>

            <!-- Forma de Pagamento -->
            <div class="flex flex-col justify-between rounded-xl border border-zinc-200 bg-zinc-50/50 p-4 dark:border-zinc-700/80 dark:bg-zinc-800/40">
                <div class="space-y-1">
                    <flux:text class="text-xs text-zinc-500">Forma de Pagamento (Stripe)</flux:text>
                    <div class="flex items-center gap-2">
                        <flux:icon icon="credit-card" class="size-5 text-zinc-500" />
                        <span class="font-semibold text-zinc-900 dark:text-zinc-100">
                            @if ($this->centralTenant?->card_last_four)
                                {{ ucfirst($this->centralTenant->card_brand ?? 'Cartão') }} final {{ $this->centralTenant->card_last_four }}
                            @else
                                Nenhum cartão cadastrado
                            @endif
                        </span>
                    </div>
                </div>

                <div class="mt-3 pt-2 border-t border-zinc-200/60 dark:border-zinc-700/60">
                    <flux:button wire:click="openUpdateCardModal" size="sm" variant="outline" icon="pencil-square">
                        Alterar Cartão de Crédito
                    </flux:button>
                </div>
            </div>
        </div>

        <!-- Modal para atualizar cartão com Flux Modal -->
        <flux:modal name="update-card-modal" class="md:w-120">
            <div
                x-data="stripeCardSetup({
                    clientSecret: @js($clientSecret),
                    stripeKey: @js($stripeKey),
                    wireAction: 'updateCard'
                })"
                class="space-y-6"
            >
                <div>
                    <flux:heading size="lg">Atualizar Cartão de Crédito</flux:heading>
                    <flux:subheading>Insira os dados do novo cartão. Ele será salvo com segurança via Stripe.</flux:subheading>
                </div>

                @if ($isMockMode)
                    <div class="rounded-lg border border-amber-300 bg-amber-50 p-3 text-xs text-amber-900 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-200">
                        Ambiente local de testes: Você pode simular a alteração imediata clicando no botão abaixo.
                    </div>

                    <div class="flex justify-end gap-2">
                        <flux:modal.close>
                            <flux:button variant="ghost">Cancelar</flux:button>
                        </flux:modal.close>
                        <flux:button wire:click="updateCard('pm_mock_updated_card')" variant="primary">
                            Salvar Cartão Simulado
                        </flux:button>
                    </div>
                @else
                    <form @submit.prevent="handleSubmit" class="space-y-4">
                        <!-- Nome no Cartão -->
                        <div>
                            <label for="update-cardholder-name" class="block text-xs font-medium text-zinc-700 dark:text-zinc-300 mb-1.5">
                                Nome impresso no cartão
                            </label>
                            <input
                                id="update-cardholder-name"
                                type="text"
                                x-model="cardholderName"
                                placeholder="Como impresso no cartão"
                                class="w-full rounded-xl border border-zinc-300 p-3 text-sm text-zinc-900 bg-white placeholder-zinc-400 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100 dark:placeholder-zinc-500 min-h-[46px]"
                                required
                            />
                        </div>

                        <!-- Número do Cartão -->
                        <div>
                            <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300 mb-1.5">
                                Número do cartão
                            </label>
                            <div
                                wire:ignore
                                x-ref="cardNumber"
                                class="rounded-xl border border-zinc-300 p-3 bg-white dark:border-zinc-700 dark:bg-zinc-900 min-h-[46px] transition focus-within:ring-2 focus-within:ring-indigo-500 focus-within:border-indigo-500"
                            >
                                <!-- Stripe Card Number Element montado via Alpine -->
                            </div>
                        </div>

                        <!-- Validade e CVC -->
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300 mb-1.5">
                                    Validade (MM/AA)
                                </label>
                                <div
                                    wire:ignore
                                    x-ref="cardExpiry"
                                    class="rounded-xl border border-zinc-300 p-3 bg-white dark:border-zinc-700 dark:bg-zinc-900 min-h-[46px] transition focus-within:ring-2 focus-within:ring-indigo-500 focus-within:border-indigo-500"
                                >
                                    <!-- Stripe Card Expiry Element montado via Alpine -->
                                </div>
                            </div>

                            <div>
                                <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300 mb-1.5">
                                    CVC / CVV
                                </label>
                                <div
                                    wire:ignore
                                    x-ref="cardCvc"
                                    class="rounded-xl border border-zinc-300 p-3 bg-white dark:border-zinc-700 dark:bg-zinc-900 min-h-[46px] transition focus-within:ring-2 focus-within:ring-indigo-500 focus-within:border-indigo-500"
                                >
                                    <!-- Stripe Card CVC Element montado via Alpine -->
                                </div>
                            </div>
                        </div>

                        <div x-show="errorMessage" x-text="errorMessage" role="alert" class="text-xs text-rose-600 font-medium"></div>

                        <div class="flex justify-end gap-2 pt-2">
                            <flux:modal.close>
                                <flux:button variant="ghost" x-bind:disabled="isProcessing">Cancelar</flux:button>
                            </flux:modal.close>
                            <flux:button type="submit" variant="primary" x-bind:disabled="isProcessing">
                                <span x-show="!isProcessing">Salvar Novo Cartão</span>
                                <span x-show="isProcessing" class="inline-flex items-center gap-2">
                                    <flux:icon.loading class="size-4" />
                                    Processando...
                                </span>
                            </flux:button>
                        </div>
                    </form>
                @endif
            </div>
        </flux:modal>
    </flux:card>
</div>

