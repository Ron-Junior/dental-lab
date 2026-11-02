@can('viewAny', \App\Models\Dentist::class)
    @php
        $alerts = collect(app(\App\Services\PlanLimits\TenantLimitChecker::class)->usage())
            ->filter(fn ($usage) => $usage->level !== \App\Enums\UsageLevel::Normal);
    @endphp

    @if ($alerts->isNotEmpty())
        <div class="space-y-3 mb-6" id="plan-usage-alerts">
            @foreach ($alerts as $usage)
                @php
                    $isStorage = $usage->resource === \App\Enums\PlanResource::Storage;
                    $format = fn (float $value) => number_format($value, $isStorage ? 2 : 0, ',', '.');
                    $variant = $usage->level === \App\Enums\UsageLevel::Warning ? 'warning' : 'danger';
                    $heading = match ($usage->level) {
                        \App\Enums\UsageLevel::Warning => 'Você está chegando ao limite do plano',
                        \App\Enums\UsageLevel::Critical => 'Atenção: limite do plano quase esgotado',
                        default => 'Limite do plano atingido',
                    };
                @endphp

                <flux:callout
                    :variant="$variant"
                    icon="{{ $usage->level === \App\Enums\UsageLevel::Warning ? 'exclamation-triangle' : 'x-circle' }}"
                    id="plan-alert-{{ $usage->resource->value }}"
                >
                    <flux:callout.heading>{{ $heading }}</flux:callout.heading>
                    <flux:callout.text>
                        {{ ucfirst($usage->resource->label()) }}:
                        {{ $format($usage->used) }} de {{ $format((float) $usage->limit) }} {{ $usage->resource->unit() }}
                        ({{ number_format($usage->percentage, 0) }}%).
                        @if ($usage->level->isBlocking())
                            Novos cadastros deste recurso estão bloqueados. Faça upgrade do plano para continuar.
                        @endif
                    </flux:callout.text>
                    <div class="mt-2 h-1.5 w-full rounded-full bg-zinc-200 dark:bg-zinc-700">
                        <div
                            class="h-1.5 rounded-full {{ $variant === 'warning' ? 'bg-amber-500' : 'bg-red-600' }}"
                            style="width: {{ min(100, $usage->percentage) }}%"
                        ></div>
                    </div>
                </flux:callout>
            @endforeach
        </div>
    @endif
@endcan
