<x-guest-layout>
    <div class="flex flex-col gap-6">
        <div class="text-center">
            <h1 class="text-2xl font-semibold text-zinc-900 dark:text-white">Crie o espaço do seu laboratório</h1>
            <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-400">Seu ambiente será preparado automaticamente.</p>
        </div>

        <form method="POST" action="{{ route('onboarding.store') }}" class="flex flex-col gap-4">
            @csrf

            <div>
                <x-input-label for="laboratory_name" value="Nome do laboratório" />
                <x-text-input id="laboratory_name" name="laboratory_name" type="text" class="mt-1 block w-full" :value="old('laboratory_name')" required autofocus />
                <x-input-error :messages="$errors->get('laboratory_name')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="admin_name" value="Nome do administrador" />
                <x-text-input id="admin_name" name="admin_name" type="text" class="mt-1 block w-full" :value="old('admin_name')" required />
                <x-input-error :messages="$errors->get('admin_name')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="email" value="E-mail" />
                <x-text-input id="email" name="email" type="email" class="mt-1 block w-full" :value="old('email')" required autocomplete="email" />
                <x-input-error :messages="$errors->get('email')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="slug" value="Endereço do laboratório" />
                <div class="mt-1 flex items-center gap-2">
                    <x-text-input id="slug" name="slug" type="text" class="block w-full" :value="old('slug')" required pattern="[a-z0-9]+(?:-[a-z0-9]+)*" maxlength="63" />
                    <span class="whitespace-nowrap text-sm text-zinc-600 dark:text-zinc-400">.{{ config('tenancy.central_domain') }}</span>
                </div>
                <x-input-error :messages="$errors->get('slug')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="password" value="Senha" />
                <x-text-input id="password" name="password" type="password" class="mt-1 block w-full" required autocomplete="new-password" />
                <x-input-error :messages="$errors->get('password')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="password_confirmation" value="Confirme a senha" />
                <x-text-input id="password_confirmation" name="password_confirmation" type="password" class="mt-1 block w-full" required autocomplete="new-password" />
            </div>

            <x-primary-button class="mt-2 w-full justify-center">Criar laboratório</x-primary-button>
        </form>
    </div>
</x-guest-layout>
