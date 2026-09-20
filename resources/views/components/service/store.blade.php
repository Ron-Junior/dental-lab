<?php

use App\Models\Service;
use Flux\Flux;
use Illuminate\Validation\Rule;
use Livewire\Attributes\On;
use Livewire\Attributes\Validate;
use Livewire\Component;

new class extends Component
{
    public ?int $serviceId = null;

    public ?string $name = null;

    #[Validate('required|string|min:3|max:255')]
    public ?string $description = null;
    
    #[Validate('required|numeric|min:0|max:20000')]
    public ?float $price = null;

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
        ];
    }

    #[On('service::edit')]
    public function edit($id): void
    {
        $this->serviceId = $id;
        $service = Service::find($id);

        $this->name = $service->name;
        $this->description = $service->description;
        $this->price = $service->price;

        Flux::modal('store-service-modal')->show();
    }

    public function store(): void
    {
        $this->validate();

        Service::updateOrCreate([
            'id' => $this->serviceId,
        ],[
            'name' => $this->name,
            'description' => $this->description,
            'price' => $this->price,
        ]);
        
        $this->reset();
        $this->dispatch('service::refresh');

        Flux::toast(variant: 'success', heading: 'Sucesso!', text: str(__('Serviço :action com sucesso!'))->replace(':action', $this->serviceId ? 'atualizado' : 'criado'));
        Flux::modals()->close();
    }

    public function clearModal(): void
    {
        $this->reset();
        $this->resetValidation();
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
                        type="number" 
                        wire:model.number="price"  
                    />
                </flux:input.group>
                <flux:error name="price" />
            </flux:field>
            <div class="flex items-center justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost" wire:click="clearModal">Cancelar</flux:button>
                </flux:modal.close>
                <flux:button wire:click="store">Salvar</flux:button>
            </div>
        </div>
    </flux:modal>
</div>