<?php

use App\Actions\SendInvitation;
use App\Enums\Rules;
use App\Models\Dentist;
use App\Models\Rule;
use App\Models\User;
use Flux\Flux;
use Illuminate\Support\Facades\DB;
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
                <flux:input
                    wire:model="email"
                    label="Email"
                    name="email"
                    placeholder="Digite o email do dentista"
                />
                <flux:button type="submit">Salvar</flux:button>
            </form>
        </div>
    </flux:modal>
</div>