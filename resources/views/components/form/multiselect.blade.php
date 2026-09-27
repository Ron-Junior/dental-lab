@props([
    'label' => null,
    'placeholder' => 'Selecione...',
    'options' => [],
    'valueKey' => 'id',
    'labelKey' => 'name',
    'searchable' => true,
    'clearable' => true,
])

@php
    $normalizedOptions = [];

    if ($options instanceof \Illuminate\Support\Collection) {
        $options = $options->all();
    }

    if (is_array($options)) {
        foreach ($options as $key => $item) {
            if (is_array($item) || is_object($item)) {
                $val = is_array($item) ? ($item[$valueKey] ?? $key) : ($item->$valueKey ?? $key);
                $lbl = is_array($item) ? ($item[$labelKey] ?? $val) : ($item->$labelKey ?? $val);
                $normalizedOptions[] = ['value' => $val, 'label' => (string) $lbl];
            } else {
                if (is_numeric($key) && is_int($key) && array_is_list($options)) {
                    $normalizedOptions[] = ['value' => $item, 'label' => (string) $item];
                } else {
                    $normalizedOptions[] = ['value' => $key, 'label' => (string) $item];
                }
            }
        }
    }

    $wireModel = $attributes->wire('model');
    $hasWireModel = $wireModel->value() !== null;
@endphp

<div
    @if ($hasWireModel)
        x-data="{
            open: false,
            search: '',
            value: @entangle($wireModel),
            options: {{ json_encode($normalizedOptions) }},
            clearable: {{ json_encode((bool)$clearable) }},
            searchable: {{ json_encode((bool)$searchable) }},
            
            get selectedOptions() {
                if (!Array.isArray(this.value)) return [];
                return this.options.filter(opt => this.value.some(v => String(v) === String(opt.value)));
            },
            
            get filteredOptions() {
                if (!this.search.trim()) return this.options;
                const query = this.search.toLowerCase();
                return this.options.filter(opt => opt.label.toLowerCase().includes(query));
            },

            isSelected(val) {
                if (!Array.isArray(this.value)) return false;
                return this.value.some(v => String(v) === String(val));
            },

            toggle(val) {
                if (!Array.isArray(this.value)) {
                    this.value = [val];
                    return;
                }
                if (this.isSelected(val)) {
                    this.value = this.value.filter(v => String(v) !== String(val));
                } else {
                    this.value.push(val);
                }
            },

            remove(val) {
                if (!Array.isArray(this.value)) return;
                this.value = this.value.filter(v => String(v) !== String(val));
            },

            clear() {
                this.value = [];
            }
        }"
    @else
        x-data="{
            open: false,
            search: '',
            value: [],
            options: {{ json_encode($normalizedOptions) }},
            clearable: {{ json_encode((bool)$clearable) }},
            searchable: {{ json_encode((bool)$searchable) }},

            get selectedOptions() {
                if (!Array.isArray(this.value)) return [];
                return this.options.filter(opt => this.value.some(v => String(v) === String(opt.value)));
            },
            
            get filteredOptions() {
                if (!this.search.trim()) return this.options;
                const query = this.search.toLowerCase();
                return this.options.filter(opt => opt.label.toLowerCase().includes(query));
            },

            isSelected(val) {
                if (!Array.isArray(this.value)) return false;
                return this.value.some(v => String(v) === String(val));
            },

            toggle(val) {
                if (!Array.isArray(this.value)) {
                    this.value = [val];
                    return;
                }
                if (this.isSelected(val)) {
                    this.value = this.value.filter(v => String(v) !== String(val));
                } else {
                    this.value.push(val);
                }
            },

            remove(val) {
                if (!Array.isArray(this.value)) return;
                this.value = this.value.filter(v => String(v) !== String(val));
            },

            clear() {
                this.value = [];
            }
        }"
    @endif
    x-on:keydown.escape.window="open = false"
    x-on:click.outside="open = false"
    {{ $attributes->whereDoesntStartWith('wire:model')->class(['relative w-full']) }}
>
    @if ($label)
        <flux:label class="mb-1.5">{{ $label }}</flux:label>
    @endif

    {{-- Select Trigger Box --}}
    <div
        x-on:click="open = !open"
        class="relative flex min-h-10 w-full items-center justify-between rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 px-3 py-1.5 text-sm text-zinc-900 dark:text-zinc-100 shadow-xs hover:border-zinc-300 dark:hover:border-zinc-600 focus:outline-none focus:ring-2 focus:ring-zinc-900 dark:focus:ring-zinc-100 cursor-pointer transition-colors"
    >
        <div class="flex flex-wrap items-center gap-1.5 pr-6 overflow-hidden">
            <template x-if="selectedOptions.length === 0">
                <span class="text-zinc-400 dark:text-zinc-500 select-none">{{ $placeholder }}</span>
            </template>
            <template x-for="option in selectedOptions" :key="option.value">
                <span class="inline-flex items-center gap-1 rounded-md bg-zinc-100 dark:bg-zinc-700/80 px-2 py-0.5 text-xs font-medium text-zinc-800 dark:text-zinc-200">
                    <span x-text="option.label"></span>
                    <button
                        type="button"
                        x-on:click.stop="remove(option.value)"
                        class="text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-200 focus:outline-none"
                    >
                        <flux:icon name="x-mark" class="size-3" />
                    </button>
                </span>
            </template>
        </div>

        <div class="absolute right-2.5 flex items-center gap-1">
            <template x-if="clearable && selectedOptions.length > 0">
                <button
                    type="button"
                    x-on:click.stop="clear()"
                    class="text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-200 p-0.5 rounded"
                    title="Limpar seleção"
                >
                    <flux:icon name="x-circle" class="size-4" />
                </button>
            </template>

            <flux:icon
                name="chevron-down"
                class="size-4 text-zinc-400 transition-transform duration-200"
                x-bind:class="open ? 'rotate-180' : ''"
            />
        </div>
    </div>

    {{-- Dropdown Menu --}}
    <div
        x-show="open"
        x-transition:enter="transition ease-out duration-100"
        x-transition:enter-start="opacity-0 scale-95"
        x-transition:enter-end="opacity-100 scale-100"
        x-transition:leave="transition ease-in duration-75"
        x-transition:leave-start="opacity-100 scale-100"
        x-transition:leave-end="opacity-0 scale-95"
        style="display: none;"
        class="absolute left-0 right-0 top-full z-50 mt-1.5 max-h-64 w-full overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 p-1 shadow-lg"
    >
        <template x-if="searchable">
            <div class="p-1.5 border-b border-zinc-100 dark:border-zinc-700/70">
                <flux:input
                    x-model="search"
                    size="sm"
                    placeholder="Buscar..."
                    icon="magnifying-glass"
                    x-on:click.stop
                />
            </div>
        </template>

        <div class="max-h-48 overflow-y-auto p-1 space-y-0.5">
            <template x-for="option in filteredOptions" :key="option.value">
                <div
                    x-on:click="toggle(option.value)"
                    class="flex items-center justify-between rounded-md px-2.5 py-1.5 text-sm cursor-pointer select-none transition-colors"
                    :class="isSelected(option.value) 
                        ? 'bg-zinc-100 dark:bg-zinc-700/60 font-medium text-zinc-900 dark:text-zinc-100' 
                        : 'text-zinc-700 dark:text-zinc-300 hover:bg-zinc-50 dark:hover:bg-zinc-700/40'"
                >
                    <span x-text="option.label" class="truncate"></span>
                    <flux:icon
                        name="check"
                        class="size-4 text-zinc-800 dark:text-zinc-200"
                        x-show="isSelected(option.value)"
                    />
                </div>
            </template>

            <template x-if="filteredOptions.length === 0">
                <div class="px-3 py-2 text-xs text-zinc-400 text-center">
                    Nenhuma opção encontrada
                </div>
            </template>
        </div>
    </div>
</div>
