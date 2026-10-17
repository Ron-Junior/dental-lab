<?php

use App\Models\CentralUser;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
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

        $counts = [
            'total' => 0,
            'active' => 0,
            'provisioning' => 0,
            'failed' => 0,
        ];

        foreach ($this->tenants as $tenant) {
            $status = $tenant->status?->value ?? (string) $tenant->getAttribute('status');

            $counts['total']++;

            if ($status === 'active') {
                $counts['active']++;
            } elseif ($status === 'provisioning') {
                $counts['provisioning']++;
            } elseif ($status === 'failed') {
                $counts['failed']++;
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

        return Tenant::query()
            ->with('domains')
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($query) use ($search): void {
                    $query->where(function ($query) use ($search): void {
                        $tenantName = Str::lower($search);

                        $query->whereRaw("LOWER(CAST(data->>'name' AS TEXT)) LIKE ?", ['%'.$tenantName.'%'])
                            ->orWhere('id', 'like', '%'.$search.'%')
                            ->orWhereHas('domains', function ($query) use ($search): void {
                                $query->where('domain', 'like', '%'.$search.'%');
                            });
                    });
                });
            })
            ->latest()
            ->get();
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

            <div class="flex flex-col gap-3 sm:flex-row">
                <flux:button href="{{ route('onboarding.create') }}" variant="primary" icon="plus">
                    Cadastrar laboratório
                </flux:button>
                <flux:button wire:click="logout" variant="ghost" icon="arrow-right-start-on-rectangle">
                    Sair
                </flux:button>
            </div>
        </header>

        <section class="flex flex-col gap-2">
            <flux:heading size="lg">Gerencie seus espaços de trabalho</flux:heading>
            <flux:text>Consulte a configuração e acesse os laboratórios vinculados à sua conta.</flux:text>
        </section>

        @php
            $summary = $this->summaryCounts;
        @endphp

        <section aria-label="Resumo dos laboratórios" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <flux:card class="flex flex-col gap-1">
                <flux:text>Total de laboratórios</flux:text>
                <flux:heading size="xl">{{ $summary['total'] }}</flux:heading>
            </flux:card>
            <flux:card class="flex flex-col gap-1">
                <flux:text>Ativos</flux:text>
                <flux:heading size="xl">{{ $summary['active'] }}</flux:heading>
            </flux:card>
            <flux:card class="flex flex-col gap-1">
                <flux:text>Em configuração</flux:text>
                <flux:heading size="xl">{{ $summary['provisioning'] }}</flux:heading>
            </flux:card>
            <flux:card class="flex flex-col gap-1">
                <flux:text>Precisam de atenção</flux:text>
                <flux:heading size="xl">{{ $summary['failed'] }}</flux:heading>
            </flux:card>
        </section>

        <section class="flex flex-col gap-5">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <flux:heading size="lg">Laboratórios</flux:heading>
                    <flux:text class="mt-1">Encontre um laboratório pelo nome ou endereço.</flux:text>
                </div>
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

            @php
                $tenants = $this->tenants;
            @endphp

            @if ($tenants->isNotEmpty())
                <div class="grid gap-4 md:grid-cols-2">
                    @foreach ($tenants as $tenant)
                        @php
                            $domain = $tenant->domains->first()?->domain;
                        @endphp

                        <flux:card wire:key="laboratory-{{ $tenant->id }}" class="flex flex-col gap-5">
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

                                @if ($tenant->status->value === 'active')
                                    <flux:badge color="green" icon="check-circle">Ativo</flux:badge>
                                @elseif ($tenant->status->value === 'provisioning')
                                    <flux:badge color="blue" icon="clock">Configurando</flux:badge>
                                @else
                                    <flux:badge color="red" icon="exclamation-triangle">Falha</flux:badge>
                                @endif
                            </div>

                            <div class="flex flex-col gap-4 border-t border-zinc-200 pt-4 dark:border-zinc-700 sm:flex-row sm:items-center sm:justify-between">
                                <flux:text class="text-sm">
                                    Criado em {{ $tenant->created_at?->format('d/m/Y') ?? '—' }}
                                </flux:text>

                                @if ($tenant->status->value === 'active')
                                    <flux:button
                                        href="{{ route('login', ['tenant' => $tenant->id]) }}"
                                        variant="outline"
                                        icon="arrow-top-right-on-square"
                                    >
                                        Entrar no laboratório
                                    </flux:button>
                                @else
                                    <flux:button
                                        href="{{ route('onboarding.show', $tenant) }}"
                                        variant="outline"
                                        icon="arrow-right"
                                    >
                                        {{ $tenant->status->value === 'provisioning' ? 'Acompanhar configuração' : 'Ver detalhes' }}
                                    </flux:button>
                                @endif
                            </div>
                        </flux:card>
                    @endforeach
                </div>
            @elseif ($search !== '')
                <flux:card class="flex flex-col items-center gap-3 py-12 text-center">
                    <flux:icon name="magnifying-glass" class="size-9 text-zinc-400" />
                    <flux:heading size="base">Nenhum laboratório encontrado</flux:heading>
                    <flux:text>Tente buscar por outro nome ou endereço.</flux:text>
                    <flux:button wire:click="$set('search', '')" variant="ghost">Limpar busca</flux:button>
                </flux:card>
            @else
                <flux:card class="flex flex-col items-center gap-4 py-12 text-center">
                    <span class="flex size-14 items-center justify-center rounded-full bg-teal-50 text-teal-700 dark:bg-teal-950 dark:text-teal-300">
                        <flux:icon name="building-office-2" class="size-7" />
                    </span>
                    <div class="flex flex-col gap-1">
                        <flux:heading size="base">Sua conta ainda não tem laboratórios</flux:heading>
                        <flux:text>Cadastre seu primeiro laboratório para começar.</flux:text>
                    </div>
                    <flux:button href="{{ route('onboarding.create') }}" variant="primary" icon="plus">
                        Cadastrar laboratório
                    </flux:button>
                </flux:card>
            @endif
        </section>
    </main>
</div>
