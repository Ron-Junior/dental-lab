<?php

use App\Models\Dentist;
use App\Models\DentistRequest;
use App\Models\RequestService;
use App\Models\Service;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Validate;
use Livewire\Component;

new class extends Component
{
    #[Validate(['required', 'exists:dentists,id'])]
    public ?int $dentistId = null;

    public array $services = [
        [
            'service_id' => '',
            'unit_price' => '',
            'quantity' => '',
        ],
    ];

    public string $searchDentist = '';

    public string $searchService = '';

    public function rules(): array
    {
        return [
            'services' => 'array|min:1',
            'services.*.service_id' => 'required|exists:services,id',
            'services.*.unit_price' => 'required',
            'services.*.quantity' => 'required|integer|min:1|max:1000000',
        ];
    }

    public function messages(): array
    {
        return [
            'services.*.service_id.required' => 'O serviço é obrigatório.',
            'services.*.service_id.exists' => 'O serviço selecionado é inválido.',
            'services.*.unit_price.required' => 'O preço unitário é obrigatório.',
            'services.*.quantity.required' => 'A quantidade é obrigatória.',
            'services.*.quantity.integer' => 'A quantidade deve ser um número inteiro.',
            'services.*.quantity.min' => 'A quantidade deve ser maior ou igual a 1.',
            'services.*.quantity.max' => 'A quantidade deve ser menor ou igual a 1000000.',
        ];
    }

    #[Computed(true)]
    public function dentists(): Collection
    {
        return Dentist::with('user')->whereRelation('user', 'name', 'like', '%' . $this->searchDentist . '%')->get();
    }

    #[Computed(true)]
    public function offerServices(): Collection
    {
        return Service::all();
    }

    public function updatedServices($value, $key): void
    {
        [$index, $field] = explode('.', $key);

        if ($field === 'unit_price' || $field === 'quantity') {
            return;
        }
        
        $selectedService = $this->offerServices->firstWhere('id', $value);
        $this->services[$index]['unit_price'] = $selectedService?->price ?? 0;
    }

    public function addService(): void
    {
        $this->services[] = [
            'service_id' => '',
            'unit_price' => '',
            'quantity' => '',
        ];
    }    

    public function removeService(int $index): void
    {
        unset($this->services[$index]);
    }

    public function cancel(): void
    {
        $this->reset();
        $this->resetValidation();
    }
    
    public function save(): void
    {
        $this->authorize('update', DentistRequest::class);
        $this->validate();

        $prices = array_map(fn($service) => floatval(str_replace(',', '.', $service['unit_price'])), $this->services);

        DB::transaction(function () use ($prices) {
            $dentistRequest = DentistRequest::create([
                'dentist_id' => $this->dentistId,
            ]);

            foreach ($this->services as $index => $service) {
                RequestService::create([
                    'dentist_request_id' => $dentistRequest->id,
                    'service_id' => $service['service_id'],
                    'unit_price' => $prices[$index],
                    'quantity' => $service['quantity'],
                ]);
            }
        });

        Flux::modal('store-service-modal')->close();
        Flux::toast(variant: "success", heading: 'Sucesso!', text: "Pedido criado com sucesso!");
        $this->reset();
        $this->resetValidation();
    }
};

?>
<div>
    <flux:modal flyout name="store-service-modal" class="md:w-lg" @cancel="cancel">
        <form wire:submit.prevent="save" class="space-y-6" wire:key="store-service-modal">
            <flux:heading size="lg">Nova Solicitação</flux:heading>
            <flux:subheading>Adicione uma nova solicitação de serviço.</flux:subheading>

            <flux:select label="Dentista" wire:model="dentistId">
                <flux:select.option>Selecione um Dentista</flux:select.option>
                @foreach ($this->dentists as $dentist)
                    <flux:select.option value="{{ $dentist->id }}">{{ $dentist->user->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:heading size="lg">Serviços</flux:heading>

            @foreach ($this->services as $index => $service)
                <flux:card>
                    <div class="space-y-4">

                        <flux:select label="Serviço" wire:model.live="services.{{ $index }}.service_id">
                            <flux:select.option>Selecione um Serviço</flux:select.option>
                            @foreach ($this->offerServices as $offerService)
                                <flux:select.option value="{{ $offerService->id }}">{{ $offerService->name }}</flux:select.option>
                            @endforeach
                        </flux:select>

                        <flux:input mask:dynamic="$money($input)" label="Preço Unitário" wire:model="services.{{ $index }}.unit_price" readonly />            
                        <flux:input label="Quantidade" type="number" wire:model="services.{{ $index }}.quantity" />

                        @if (count($services) > 1)
                            <flux:button wire:click="removeService('{{ $index }}')" variant="outline" icon="trash" class="w-full">
                                Remover Serviço
                            </flux:button>
                        @endif
                    </div>
                </flux:card>
            @endforeach

            <flux:button class="w-full" icon="plus" variant="outline" wire:click="addService">Adicionar Serviço</flux:button>

            <div class="flex items-center justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">Cancelar</flux:button>
                </flux:modal.close>
                <flux:button type="submit">Salvar</flux:button>
            </div>
        </form>
    </flux:modal>
</div>