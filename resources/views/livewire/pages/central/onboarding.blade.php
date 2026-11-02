<?php

use App\Enums\TenantStatus;
use App\Models\Tenant;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Volt\Component;

new #[Layout('layouts.guest')] class extends Component
{
    #[Locked]
    public Tenant $tenant;

    public function mount(Tenant $tenant): void
    {
        abort_unless(Tenant::isOnboardingAuthorizedForCurrentRequest(request(), $tenant), 403);

        $this->tenant = $tenant;

        if ($this->tenant->status === TenantStatus::Active) {
            $this->redirect($this->loginUrl);
        }
    }

    public function booted(): void
    {
        if (isset($this->tenant)) {
            abort_unless(Tenant::isOnboardingAuthorizedForCurrentRequest(request(), $this->tenant), 403);
        }
    }

    #[Computed]
    public function loginUrl(): string
    {
        return route('login', ['tenant' => $this->tenant->id]);
    }

    public function refreshStatus(): void
    {
        $this->tenant->refresh();
    }
}; ?>

<div>
    <section
        @if ($tenant->status === TenantStatus::Provisioning) wire:poll.3s="refreshStatus" @endif
        class="flex flex-col gap-4 text-center"
    >
        <flux:heading size="xl">Preparando seu laboratório</flux:heading>
        <flux:text>
            Estamos configurando o espaço de {{ $tenant->name }}. Esta página seguirá o andamento automaticamente.
        </flux:text>

        <div aria-live="polite" class="mt-4 font-medium text-zinc-800 dark:text-zinc-200">
            @if ($tenant->status === TenantStatus::Active)
                <div class="flex flex-col items-center gap-4">
                    <flux:badge color="green" size="lg" icon="check-circle">Configuração Concluída!</flux:badge>
                    <flux:text class="text-sm">Seu ambiente está pronto. O link seguro para definição de senha foi enviado para <strong>{{ $tenant->owner_email }}</strong>.</flux:text>
                    <flux:button href="{{ $this->loginUrl }}" variant="primary" icon:trailing="arrow-right">
                        Acessar plataforma
                    </flux:button>
                </div>
            @elseif ($tenant->status === TenantStatus::Failed)
                <div class="flex flex-col items-center gap-3 text-rose-600 dark:text-rose-400">
                    <flux:badge color="red" size="lg" icon="exclamation-triangle">Falha na Configuração</flux:badge>
                    <flux:text>Não foi possível concluir a configuração automática. Entre em contato com o suporte para continuar.</flux:text>
                </div>
            @else
                <div class="flex flex-col items-center gap-3">
                    <flux:text>Configurando banco de dados, produtos iniciais e acesso do administrador...</flux:text>
                    <flux:icon.loading class="mt-2 justify-self-center"/>
                </div>
            @endif
        </div>
    </section>
</div>
