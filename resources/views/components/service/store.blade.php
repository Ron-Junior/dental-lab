<?php

use App\Models\Service;
use App\Models\ServiceStep;
use Flux\Flux;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
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
    public ?array $steps = [
        [
            'name' => '',
            'description' => '',
        ]
    ];

    protected $validationAttributes = [
        'steps.*.name' => 'Nome da etapa',
        'steps.*.description' => 'Descrição da etapa',
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
            'steps.*.name' => [
                'required',
                'string',
                'min:3',
                'max:255',
            ],
            'steps.*.description' => [
                'required',
                'string',
                'min:3',
                'max:255',
            ],
        ];
    }

    #[On('service::edit')]
    public function edit($id): void
    {
        $this->serviceId = $id;
        $service = Service::find($id);

        $this->name = $service->name;
        $this->description = $service->description;
        $this->price = number_format($service->price, 2, ',', '.');
        $this->steps = $service->steps->toArray();

        Flux::modal('store-service-modal')->show();
    }

    public function store(): void
    {
        $this->authorize('create', Service::class);
        $this->validate();

        DB::transaction(function () {
           $service = Service::updateOrCreate(
                ['id' => $this->serviceId],
                [
                    'name' => $this->name,
                    'description' => $this->description,
                    'price' => str($this->price)->replaceFirst('.', '')->replaceLast(',', '.')->toFloat(),
                ]
           );

            ServiceStep::where('service_id', $service->id)->delete();

            foreach ($this->steps as $step) {
                ServiceStep::create([
                    'service_id' => $service->id,
                    'name' => $step['name'],
                    'description' => $step['description'],
                ]);
            }
        });
        
        $this->reset();
        $this->dispatch('service::refresh');

        Flux::toast(variant: 'success', heading: 'Sucesso!', text: str(__('Serviço :action com sucesso!'))->replace(':action', $this->serviceId ? 'atualizado' : 'criado'));
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
            'name' => '',
            'description' => '',
        ];
    }

    public function removeStep(int $index): void
    {
        unset($this->steps[$index]);
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
            @foreach ($steps as $index => $step)
                <flux:card wire:key="step-{{ $index }}" class="space-y-5">
                    <div class="space-y-4">
                        <flux:input wire:model="steps.{{ $index }}.name" label="Nome" placeholder="Nome do serviço" />
                        <flux:textarea wire:model="steps.{{ $index }}.description" label="Descrição" placeholder="Descrição do serviço" />
                    </div>
                    <flux:button class="w-full" icon="trash" variant="ghost" wire:click="removeStep({{ $index }})">Remover</flux:button>
                </flux:card>
            @endforeach

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