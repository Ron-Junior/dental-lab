@props([
    'icon' => 'inbox',
    'title',
    'description' => null,
    'inset' => false,
])

@php
    $wrapperClasses = $inset
        ? 'flex flex-col items-center justify-center gap-2 py-8 text-center'
        : 'flex flex-col items-center justify-center gap-2 rounded-xl border border-zinc-200 bg-white p-8 text-center dark:border-zinc-800 dark:bg-zinc-900';
@endphp

<div {{ $attributes->merge(['class' => $wrapperClasses]) }}>
    <flux:icon :name="$icon" class="size-8 text-zinc-300 dark:text-zinc-600" />
    <p class="font-medium text-zinc-700 dark:text-zinc-300">{{ $title }}</p>
    @if (filled($description))
        <p class="text-xs text-zinc-400">{{ $description }}</p>
    @endif
    @if (! $slot->isEmpty())
        <div class="mt-2 flex flex-wrap items-center justify-center gap-2">
            {{ $slot }}
        </div>
    @endif
</div>
