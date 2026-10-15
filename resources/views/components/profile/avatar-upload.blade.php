@props([
    'model' => 'photo',
    'currentUrl' => null,
    'userName' => '',
])

<div
    x-data="avatarUpload({
        modelName: @js($model),
        currentUrl: @js($currentUrl),
        userName: @js($userName),
        width: 1200,
        height: 1200,
        quality: 0.85
    })"
    x-on:profile-updated.window="setCurrentUrl($event.detail.photoUrl)"
>
    <flux:field class="space-y-3">
        <flux:label>Foto do perfil</flux:label>

        <div class="flex flex-col gap-4 sm:flex-row sm:items-center">
            <div class="flex size-20 shrink-0 items-center justify-center overflow-hidden rounded-full bg-zinc-100 text-xl font-medium text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">
                <img
                    x-show="!previewUrl"
                    x-bind:src="currentUrl"
                    src="{{ $currentUrl }}"
                    alt="Foto do perfil"
                    class="size-full object-cover"
                >
                <img
                    x-cloak
                    x-show="previewUrl"
                    x-bind:src="previewUrl"
                    alt="Prévia da foto do perfil"
                    class="size-full object-cover"
                >
                <span x-cloak x-show="!currentUrl && !previewUrl" x-text="initials"></span>
            </div>

            <div class="flex min-w-0 flex-col items-start gap-2">
                <input
                    x-ref="fileInput"
                    type="file"
                    accept="image/jpeg,image/png,image/webp"
                    class="sr-only"
                    x-on:change="selectFile($event.target.files[0])"
                >
                <flux:button type="button" variant="outline" x-on:click="$refs.fileInput.click()">
                    Escolher foto
                </flux:button>
                <flux:text>JPG, PNG ou WebP até 10 MB. A imagem será convertida para WebP 1200 × 1200.</flux:text>
                <flux:text x-show="fileName" x-text="fileName"></flux:text>
                <flux:text x-show="isProcessing" x-text="statusText"></flux:text>
                <flux:text x-show="errorMessage" x-text="errorMessage" variant="error"></flux:text>
            </div>
        </div>

        <flux:error name="{{ $model }}" />
    </flux:field>
</div>
