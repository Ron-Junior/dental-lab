<?php

use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.guest')] class extends Component
{
    public string $laboratory_name = '';

    public string $admin_name = '';

    public string $email = '';

    public string $slug = '';

    public string $password = '';

    public string $password_confirmation = '';

    /**
     * @return array<string, array<int, mixed>>
     */
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
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'laboratory_name.required' => 'Informe o nome do laboratório.',
            'admin_name.required' => 'Informe o nome do administrador.',
            'email.required' => 'Informe um e-mail válido.',
            'slug.required' => 'Informe o endereço do laboratório.',
            'slug.regex' => 'O endereço deve conter apenas letras minúsculas, números e hífens.',
            'slug.not_in' => 'Este endereço está reservado e não pode ser utilizado.',
            'slug.unique' => 'Este endereço já está em uso.',
            'password.required' => 'Informe uma senha.',
            'password.min' => 'A senha deve ter pelo menos 8 caracteres.',
            'password.confirmed' => 'A confirmação da senha não corresponde.',
        ];
    }

    public function register(): void
    {
        $rateLimitKey = 'tenant-registration:'.(request()->ip() ?? 'unknown');

        if (RateLimiter::tooManyAttempts($rateLimitKey, 5)) {
            abort(429, 'Muitas tentativas de cadastro. Aguarde antes de tentar novamente.');
        }

        RateLimiter::hit($rateLimitKey, 60);

        $data = $this->validate();

        $tenant = app(\App\Actions\CreateLaboratory::class)->handle(
            data: $data,
            exemptionData: null,
            creator: null,
            ipAddress: request()->ip(),
            userAgent: request()->userAgent(),
        );

        $this->redirectRoute('onboarding.show', ['tenant' => $tenant->id], navigate: true);
    }

    /**
     * @return list<string>
     */
    private function reservedSlugs(): array
    {
        $centralDomainLabels = array_map(
            static fn (string $domain): string => explode('.', $domain)[0],
            config('tenancy.central_domains'),
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

<div>
    <div class="flex flex-col gap-6">
        <div class="text-center">
            <flux:heading size="xl">Crie o espaço do seu laboratório</flux:heading>
            <flux:text class="mt-2">Seu ambiente será preparado automaticamente.</flux:text>
        </div>

        <form class="flex flex-col gap-4" wire:submit="register">
            <flux:input
                autofocus
                label="Nome do laboratório"
                wire:model="laboratory_name"
                name="laboratory_name"
                required
            />

            <flux:input
                label="Nome do administrador"
                wire:model="admin_name"
                name="admin_name"
                required
            />

            <flux:input
                label="E-mail"
                wire:model="email"
                name="email"
                type="email"
                autocomplete="email"
                required
            />

            <flux:field>
                <flux:label for="slug">Endereço do laboratório</flux:label>
                <div class="flex items-center gap-2">
                    <flux:input
                        wire:model="slug"
                        name="slug"
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

            <flux:input
                label="Senha"
                wire:model="password"
                name="password"
                type="password"
                autocomplete="new-password"
                required
            />

            <flux:input
                label="Confirme a senha"
                wire:model="password_confirmation"
                name="password_confirmation"
                type="password"
                autocomplete="new-password"
                required
            />

            <flux:button class="mt-2 w-full" type="submit" wire:loading.attr="disabled" wire:target="register">
                Criar laboratório
            </flux:button>
        </form>
    </div>
</div>
