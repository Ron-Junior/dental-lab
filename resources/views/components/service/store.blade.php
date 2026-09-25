<?php

use App\Models\Service;
use App\Models\ServiceStep;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Validate;
use Livewire\Component;

new class extends Component
{
    public ?int $serviceId = null;

    public ?string $name = null;

    #[Validate('required|string|min:3|max:255', 'descrição')]
    public ?string $description = null;
    
    #[Validate('required', 'preço', onUpdate: false)]
    public ?string $price = null;

    #[Validate('required|array|min:1', 'Etapas')]
    public array $steps = [
        [
            'service_step_id' => null,
            'order' => 1,
        ]
    ];

    protected $validationAttributes = [
        'steps.*.service_step_id' => 'Etapa',
        'steps.*.order' => 'Ordem',
    ];

    protected function rules()
    {
        return [
            'name' => [
                'required',
                'string',
                'min:3',
                'max:255',
                Rule::unique('services', 'name')->ignore($this->serviceId),
            ],
            'steps.*.service_step_id' => [
                'required',
                'exists:service_steps,id',
            ],
            'steps.*.order' => [
                'required',
                'integer',
                'min:1',
            ],
        ];
    }

    #[On('service::edit')]
    public function edit($id): void
    {
        $this->serviceId = $id;
        $service = Service::with(['serviceSteps' => fn($q) => $q->orderBy('service_service_step.order')])->find($id);

        $this->name = $service->name;
        $this->description = $service->description;
        $this->price = number_format($service->price, 2, ',', '.');

        $this->steps = $service->serviceSteps->map(function ($step, $index) {
            return [
                'service_step_id' => $step->id,
                'order' => $step->pivot->order ?? ($index + 1),
            ];
        })->toArray();

        Flux::modal('store-service-modal')->show();
    }

    #[Computed]
    public function serviceSteps(): Collection
    {
        return ServiceStep::all();
    }

    public function store(): void
    {
        $this->authorize('create', Service::class);
        $this->validate();

        $actionText = $this->serviceId ? 'atualizado' : 'criado';

        DB::transaction(function () {
            $service = Service::updateOrCreate(
                ['id' => $this->serviceId],
                [
                    'name' => $this->name,
                    'description' => $this->description,
                    'price' => str($this->price)->replaceFirst('.', '')->replaceLast(',', '.')->toFloat(),
                ]
            );

            $syncData = collect($this->steps)
                ->pluck('service_step_id')
                ->filter()
                ->values()
                ->mapWithKeys(fn($id, $index) => [
                    $id => ['order' => $index + 1]
                ])
                ->toArray();

            $service->serviceSteps()->sync($syncData);
        });

        $this->dispatch('service::refresh');

        Flux::toast(
            variant: 'success', 
            heading: 'Sucesso!', 
            text: __("Serviço {$actionText} com sucesso!")
        );

        $this->closeModal();
    }

    public function closeModal(): void
    {
        $this->reset();
        $this->resetErrorBag();
        Flux::modals()->close();
    }

    public function addStep(): void
    {
        $this->steps[] = [
            'service_step_id' => '',
            'order' => count($this->steps) + 1,
        ];
    }

    public function removeStep(int $index): void
    {
        unset($this->steps[$index]);

        $this->steps = collect($this->steps)
            ->values()
            ->map(function ($step, $idx) {
                $step['order'] = $idx + 1;
                return $step;
            })
            ->toArray();
    }

    public function reorderSteps(int $fromIndex, int $toIndex): void
    {
        if (!isset($this->steps[$fromIndex])) {
            return;
        }

        $movedItem = array_splice($this->steps, $fromIndex, 1)[0];

        array_splice($this->steps, $toIndex, 0, [$movedItem]);

        $this->steps = collect($this->steps)
            ->map(function ($step, $index) {
                $step['order'] = $index + 1;
                return $step;
            })
            ->toArray();
    }
}
?>

<div>
    <flux:modal name="store-service-modal" flyout variant="floating" class="md:w-lg" >
        <div class="space-y-6" wire:key="store-service-modal">
            <flux:heading size="lg">Novo Serviço</flux:heading>
            <flux:subheading>Adicione um novo serviço ao laboratório.</flux:subheading>
            <flux:input wire:model="name" label="Nome" placeholder="Nome do serviço" />
            <flux:textarea wire:model="description" label="Descrição" placeholder="Descrição do serviço" />
            <flux:field>
                <flux:label>Preço</flux:label>
                <flux:input.group>
                    <flux:input.group.prefix>R$</flux:input.group.prefix>
                    <flux:input
                        mask:dynamic="$money($input, ',', '.')"
                        wire:model="price"  
                    />
                </flux:input.group>
                <flux:error name="price" />
            </flux:field>

            <flux:separator />

            <flux:heading size="md">Etapas do Serviço</flux:heading>
            <div class="relative space-y-4" wire:sort.defer="reorderSteps">
                @foreach ($steps as $index => $step)
                    <div wire:key="step-wrapper-{{ $index }}" wire:sort:item="{{ $index }}" class="relative pl-9 group">
                        @unless ($loop->last)
                            <span class="absolute left-3.25 top-7 -bottom-4 w-0.5 bg-zinc-200 dark:bg-zinc-700" aria-hidden="true"></span>
                        @endunless

                        <div class="absolute left-0 top-3 flex h-7 w-7 items-center justify-center rounded-full bg-zinc-900 text-white dark:bg-zinc-100 dark:text-zinc-900 text-xs font-semibold ring-4 ring-white dark:ring-zinc-900 shadow-sm z-10 transition-transform group-hover:scale-110">
                            {{ $index + 1 }}
                        </div>

                        <flux:card class="flex gap-4">
                            <div class="flex-1">
                                <flux:select wire:model.live="steps.{{ $index }}.service_step_id" label="Nome" placeholder="Selecione uma etapa">
                                    @foreach ($this->serviceSteps as $serviceStep)
                                        <flux:select.option :value="$serviceStep->id">{{ $serviceStep->name }}</flux:select.option>
                                    @endforeach
                                </flux:select>
                            </div>
                            <flux:button class="mt-2" icon="trash" variant="ghost" wire:click="removeStep({{ $index }})"></flux:button>
                        </flux:card>
                    </div>
                @endforeach
            </div>

            @error('steps')
                <flux:error name="steps" />
            @enderror
                

            <flux:button class="w-full" variant="outline" wire:click="addStep">Adicionar Etapa</flux:button>

            <div class="flex items-center justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost" wire:click="closeModal">Cancelar</flux:button>
                </flux:modal.close>
                <flux:button wire:click="store">Salvar</flux:button>
            </div>
        </div>
    </flux:modal>
</div>