<x-guest-layout>
    <section
        class="flex flex-col gap-4 text-center"
        data-status-url="{{ $statusUrl }}"
        data-login-url="{{ $loginUrl }}"
        data-status="{{ $tenant->status->value }}"
        id="tenant-onboarding"
    >
        <h1 class="text-2xl font-semibold text-zinc-900 dark:text-white">Preparando seu laboratório</h1>
        <p class="text-zinc-600 dark:text-zinc-400">
            Estamos configurando o espaço de {{ $tenant->name }}. Esta página seguirá o andamento automaticamente.
        </p>
        <p aria-live="polite" class="font-medium text-zinc-800 dark:text-zinc-200" id="tenant-onboarding-message">
            @if ($tenant->status->value === 'active')
                Seu laboratório está pronto.
                <a class="underline" href="{{ $loginUrl }}">Acessar agora</a>
            @elseif ($tenant->status->value === 'failed')
                Não foi possível concluir a configuração. Entre em contato com o suporte para continuar.
            @else
                Configurando banco de dados, produtos iniciais e acesso do administrador...
            @endif
        </p>
    </section>

    <script>
        (() => {
            const container = document.getElementById('tenant-onboarding');
            const message = document.getElementById('tenant-onboarding-message');

            if (container.dataset.status === 'active' || container.dataset.status === 'failed') {
                return;
            }

            const checkStatus = async () => {
                try {
                    const response = await fetch(container.dataset.statusUrl, {
                        headers: { Accept: 'application/json' },
                        cache: 'no-store',
                    });

                    if (!response.ok) {
                        throw new Error('Status request failed.');
                    }

                    const result = await response.json();

                    if (result.status === 'active') {
                        window.location.assign(result.login_url);
                        return;
                    }

                    if (result.status === 'failed') {
                        message.textContent = 'Não foi possível concluir a configuração. Entre em contato com o suporte para continuar.';
                        return;
                    }

                    window.setTimeout(checkStatus, 3000);
                } catch {
                    message.textContent = 'Não foi possível consultar o andamento. A página tentará novamente em instantes.';
                    window.setTimeout(checkStatus, 5000);
                }
            };

            window.setTimeout(checkStatus, 3000);
        })();
    </script>
</x-guest-layout>
