<?php

use App\Enums\TenantStatus;
use App\Models\Tenant;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.guest')] class extends Component
{
    public Tenant $tenant;

    public function mount(Tenant $tenant): void
    {
        $this->tenant = $tenant;

        if ($this->tenant->status === TenantStatus::Active) {
            $this->redirectRoute('login', ['tenant' => $this->tenant->id], navigate: true);
        }
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

        <p aria-live="polite" class="font-medium text-zinc-800 dark:text-zinc-200">
            @if ($tenant->status === TenantStatus::Active)
                Seu laboratório está pronto.
                <a class="underline" href="{{ route('login', ['tenant' => $tenant->id]) }}">Acessar agora</a>
            @elseif ($tenant->status === TenantStatus::Failed)
                Não foi possível concluir a configuração. Entre em contato com o suporte para continuar.
            @else
                Configurando banco de dados, produtos iniciais e acesso do administrador...
            @endif
        </p>
    </section>
</div>
