<?php

use App\Actions\SendInvitation;
use App\Enums\Rules;
use App\Models\Dentist;
use App\Models\Rule;
use App\Models\User;
use Flux\Flux;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\On;
use Livewire\Attributes\Validate;
use Livewire\Component;

new class extends Component
{
    public ?int $dentistId = null;

    #[Validate('required|string|min:3|max:255')]
    public ?string $name = null;

    #[Validate('required|string|min:11|max:15')]
    public ?string $phone = null;

    #[Validate('required|email|max:255|unique:users,email')]
    public ?string $email = null;

    #[On('dentist::edit')]
    public function edit($id): void
    {
        $this->dentistId = $id;
        $dentist = Dentist::find($id);

        $this->name = $dentist->user->name;
        $this->email = $dentist->user->email;
        $this->phone = $dentist->phone;

        Flux::modal('store-dentist-modal')->show();
    }

    public function save(): void
    {
        $this->validate();

        DB::transaction(function () {
            $rule = Rule::where('name', Rules::Dentist->value)->first();
            $user = User::create([
                'name' => $this->name,
                'email' => $this->email,
                'rule_id' => $rule->id,
            ]);

            Dentist::create([
                'user_id' => $user->id,
                'phone' => $this->phone,
            ]);

            SendInvitation::handle($user);
        });

        Flux::modals()->close();
        Flux::toast(variant: 'success', heading: 'Sucesso!', text: str('Dentista :action com sucesso!')->replace(':action', $this->dentistId ? 'atualizado' : 'criado'));

        $this->reset();
        $this->dispatch('dentist::refresh');
    }
};
?>

<div>
    <flux:modal name="store-dentist-modal" flyout variant="floating" class="md:w-lg" >
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">Novo Dentista</flux:heading>
                <flux:subheading>Adicione um novo dentista em sua rede.</flux:subheading>
            </div>
            <form class="space-y-4" wire:submit="save">
                <flux:input
                    wire:model="name"
                    label="Nome"
                    name="name"
                    placeholder="Digite o nome do dentista"
                />
                <flux:input
                    wire:model="phone"
                    label="Telefone"
                    name="phone"
                    placeholder="Digite o telefone do dentista"
                    mask="(99) 99999-9999"
                />

                <flux:field>
                    <flux:label>
                        Email
                        <flux:tooltip content="Um email de confirmação será enviado para este endereço." position="top">
                            <flux:icon class="ml-2 size-4" name="information-circle" />
                        </flux:tooltip>
                    </flux:label>
                    <flux:input
                        wire:model="email"
                        name="email"
                        placeholder="Digite o email do dentista"
                    />
                </flux:field>
                
                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button variant="ghost">Cancelar</flux:button>
                    </flux:modal.close>
                    <flux:button type="submit">Salvar</flux:button>
                </div>
            </form>
        </div>
    </flux:modal>
</div>