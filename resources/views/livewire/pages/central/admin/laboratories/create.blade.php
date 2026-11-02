<?php

use App\Models\CentralUser;
use App\Models\Plan;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.central')] class extends Component
{
    public string $laboratory_name = '';
    public string $admin_name = '';
    public string $email = '';
    public string $slug = '';

    // Plan & Benefits (Trial / Exemption granted by partner)
    public string $plan_id = '';
    public string $benefit_type = 'none'; // 'none', 'trial', 'exemption'
    public int $trial_days = 14;
    public string $exemption_starts_at = '';
    public string $exemption_ends_at = '';
    public string $exemption_reason = '';

    public function mount(): void
    {
        $user = Auth::guard('central')->user();
        abort_unless($user instanceof CentralUser, 403);

        $defaultPlan = Plan::query()->where('is_active', true)->first();
        if ($defaultPlan) {
            $this->plan_id = (string) $defaultPlan->id;
        }

        $this->exemption_starts_at = Carbon::now()->format('Y-m');
        $this->exemption_ends_at = Carbon::now()->addMonths(3)->format('Y-m');
    }

    #[Computed]
    public function plans()
    {
        return Plan::query()->where('is_active', true)->get();
    }

    public function rules(): array
    {
        return [
            'laboratory_name' => ['required', 'string', 'max:150'],
            'admin_name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:255'],
            'slug' => [
                'required',
                'string',
                'max:63',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::notIn($this->reservedSlugs()),
                Rule::unique('tenants', 'id'),
                Rule::unique('domains', 'domain')
                    ->where('domain', $this->slug.'.'.config('tenancy.central_domain')),
            ],
            'plan_id' => ['required', 'exists:plans,id'],
            'benefit_type' => ['required', 'in:none,trial,exemption'],
            'trial_days' => [Rule::requiredIf($this->benefit_type === 'trial'), 'nullable', 'integer', 'min:1', 'max:365'],
            'exemption_starts_at' => [Rule::requiredIf($this->benefit_type === 'exemption'), 'nullable', 'date'],
            'exemption_ends_at' => [Rule::requiredIf($this->benefit_type === 'exemption'), 'nullable', 'date', 'after_or_equal:exemption_starts_at'],
            'exemption_reason' => [Rule::requiredIf($this->benefit_type === 'exemption'), 'nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'laboratory_name.required' => 'Informe o nome do laboratório.',
            'admin_name.required' => 'Informe o nome do administrador.',
            'email.required' => 'Informe um e-mail válido.',
            'slug.required' => 'Informe o endereço do laboratório.',
            'slug.regex' => 'O endereço deve conter apenas letras minúsculas, números e hífens.',
            'slug.unique' => 'Este endereço já está em uso.',
            'plan_id.required' => 'Selecione um plano.',
            'trial_days.required' => 'Informe a quantidade de dias do período de testes.',
            'trial_days.min' => 'O período de testes deve ser de pelo menos 1 dia.',
            'exemption_ends_at.after_or_equal' => 'O mês final da isenção deve ser igual ou posterior ao mês inicial.',
            'exemption_reason.required' => 'Informe o motivo da isenção.',
        ];
    }

    public function register(\App\Actions\CreateLaboratory $createLaboratory): void
    {
        $user = Auth::guard('central')->user();
        abort_unless($user instanceof CentralUser, 403);

        $data = $this->validate();

        $trialDays = $this->benefit_type === 'trial' ? (int) $this->trial_days : null;
        $exemptionData = null;
        if ($this->benefit_type === 'exemption') {
            $exemptionData = [
                'starts_at' => Carbon::parse($this->exemption_starts_at)->startOfMonth(),
                'ends_at' => Carbon::parse($this->exemption_ends_at)->endOfMonth(),
                'reason' => $this->exemption_reason,
            ];
        }

        $tenant = $createLaboratory->handle(
            data: $data,
            exemptionData: $exemptionData,
            trialDays: $trialDays,
            creator: $user,
            ipAddress: request()->ip(),
            userAgent: request()->userAgent(),
        );

        if ($this->benefit_type !== 'none') {
            $this->redirectRoute('onboarding.show', ['tenant' => $tenant->id], navigate: true);
        } else {
            $this->redirectRoute('onboarding.checkout', ['tenant' => $tenant->id], navigate: true);
        }
    }


    private function reservedSlugs(): array
    {
        $centralDomainLabels = array_map(
            static fn (string $domain): string => explode('.', $domain)[0],
            config('tenancy.central_domains') ?? [config('tenancy.central_domain')],
        );

        return array_values(array_unique([
            'admin',
            'api',
            'app',
            'mail',
            'www',
            ...$centralDomainLabels,
        ]));
    }
}; ?>

<div class="min-h-screen bg-zinc-50 dark:bg-zinc-950">
    <main class="mx-auto flex w-full max-w-4xl flex-col gap-8 px-4 py-8 sm:px-6 lg:px-8">
        <header class="flex flex-col gap-5 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex items-center gap-3">
                <x-icons.logo class="size-11 text-zinc-900 dark:text-white" />
                <div>
                    <flux:text class="text-sm font-medium text-zinc-500 dark:text-zinc-400">PAINEL DO SÓCIO</flux:text>
                    <flux:heading size="xl">Cadastrar Laboratório</flux:heading>
                </div>
            </div>

            <div class="flex gap-3">
                <flux:button href="{{ route('central.laboratories.index') }}" variant="ghost" icon="arrow-left">
                    Voltar
                </flux:button>
            </div>
        </header>

        <flux:card class="flex flex-col gap-6">
            <div>
                <flux:heading size="lg">Dados do Novo Laboratório</flux:heading>
                <flux:text class="mt-1">Cadastre o laboratório escolhendo o plano contratado. A senha será criada pelo administrador no primeiro acesso ao ambiente.</flux:text>
            </div>

            <form wire:submit="register" class="flex flex-col gap-5">
                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:input
                        label="Nome do laboratório"
                        wire:model="laboratory_name"
                        required
                    />

                    <flux:field>
                        <flux:label>Endereço do laboratório (Slug)</flux:label>
                        <div class="flex items-center gap-2">
                            <flux:input
                                wire:model="slug"
                                pattern="[a-z0-9]+(?:-[a-z0-9]+)*"
                                maxlength="63"
                                required
                            />
                            <span class="whitespace-nowrap text-sm text-zinc-600 dark:text-zinc-400">
                                .{{ config('tenancy.central_domain') }}
                            </span>
                        </div>
                        <flux:error name="slug" />
                    </flux:field>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:input
                        label="Nome do administrador inicial"
                        wire:model="admin_name"
                        required
                    />

                    <flux:input
                        label="E-mail do administrador"
                        wire:model="email"
                        type="email"
                        required
                    />
                </div>

                <div class="border-t border-zinc-200 pt-5 dark:border-zinc-800">
                    <flux:heading size="base" class="mb-3">Plano de Assinatura</flux:heading>
                    <flux:field>
                        <flux:label>Selecione o Plano</flux:label>
                        <flux:select wire:model="plan_id" required>
                            @foreach ($this->plans as $plan)
                                <option value="{{ $plan->id }}">
                                    {{ $plan->name }} — R$ {{ number_format($plan->price_in_cents / 100, 2, ',', '.') }} / {{ $plan->billing_cycle === 'annual' ? 'ano' : 'mês' }}
                                </option>
                            @endforeach
                        </flux:select>
                        <flux:error name="plan_id" />
                    </flux:field>
                </div>

                <div class="rounded-xl border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-800 dark:bg-zinc-900/50">
                    <flux:heading size="sm" class="mb-1">Condição de Acesso Concedida pelo Sócio</flux:heading>
                    <flux:text class="text-xs mb-3">Defina se este laboratório terá acesso com período de testes ou isenção concedida por você, ou se deverá realizar o checkout normal.</flux:text>

                    <div class="grid gap-3 sm:grid-cols-3">
                        <label class="flex items-center gap-2 rounded-lg border p-3 cursor-pointer {{ $benefit_type === 'none' ? 'border-zinc-900 bg-white ring-1 ring-zinc-900 dark:border-white dark:bg-zinc-800 dark:ring-white' : 'border-zinc-200 dark:border-zinc-700' }}">
                            <input type="radio" wire:model.live="benefit_type" value="none" class="text-zinc-900" />
                            <span class="text-sm font-medium">Sem Benefício (Checkout)</span>
                        </label>
                        <label class="flex items-center gap-2 rounded-lg border p-3 cursor-pointer {{ $benefit_type === 'trial' ? 'border-zinc-900 bg-white ring-1 ring-zinc-900 dark:border-white dark:bg-zinc-800 dark:ring-white' : 'border-zinc-200 dark:border-zinc-700' }}">
                            <input type="radio" wire:model.live="benefit_type" value="trial" class="text-zinc-900" />
                            <span class="text-sm font-medium">Período de Testes (Trial)</span>
                        </label>
                        <label class="flex items-center gap-2 rounded-lg border p-3 cursor-pointer {{ $benefit_type === 'exemption' ? 'border-zinc-900 bg-white ring-1 ring-zinc-900 dark:border-white dark:bg-zinc-800 dark:ring-white' : 'border-zinc-200 dark:border-zinc-700' }}">
                            <input type="radio" wire:model.live="benefit_type" value="exemption" class="text-zinc-900" />
                            <span class="text-sm font-medium">Isenção de Pagamento</span>
                        </label>
                    </div>

                    @if ($benefit_type === 'trial')
                        <div class="mt-4 grid gap-4 sm:grid-cols-2">
                            <flux:field>
                                <flux:label>Duração do Período de Testes (dias)</flux:label>
                                <flux:input wire:model="trial_days" type="number" min="1" max="365" required />
                                <flux:error name="trial_days" />
                            </flux:field>
                        </div>
                    @elseif ($benefit_type === 'exemption')
                        <div class="mt-4 grid gap-4 sm:grid-cols-2">
                            <flux:field>
                                <flux:label>Mês inicial da isenção</flux:label>
                                <flux:input wire:model="exemption_starts_at" type="month" required />
                                <flux:error name="exemption_starts_at" />
                            </flux:field>

                            <flux:field>
                                <flux:label>Mês final da isenção</flux:label>
                                <flux:input wire:model="exemption_ends_at" type="month" required />
                                <flux:error name="exemption_ends_at" />
                            </flux:field>

                            <div class="sm:col-span-2">
                                <flux:field>
                                    <flux:label>Motivo da Isenção</flux:label>
                                    <flux:input wire:model="exemption_reason" placeholder="Ex: Período de cortesia comercial" required />
                                    <flux:error name="exemption_reason" />
                                </flux:field>
                            </div>
                        </div>
                    @endif
                </div>

                <div class="flex justify-end gap-3 pt-4">
                    <flux:button href="{{ route('central.laboratories.index') }}" variant="ghost">
                        Cancelar
                    </flux:button>
                    <flux:button type="submit" variant="primary" wire:loading.attr="disabled">
                        Cadastrar Laboratório
                    </flux:button>
                </div>
            </form>
        </flux:card>
    </main>
</div>
