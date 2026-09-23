<?php

use App\Actions\SendInvitation;
use App\Enums\Rules;
use App\Models\Partner;
use App\Models\Rule;
use App\Models\User;
use Flux\Flux;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\On;
use Livewire\Attributes\Validate;
use Livewire\Component;

new class extends Component
{
    public ?Partner $partner = null;

    #[Validate('required|string|min:3|max:255')]
    public ?string $name = null;

    #[Validate('required|string|min:11|max:15')]
    public ?string $phone = null;

    #[Validate('required|email|max:255|unique:users,email')]
    public ?string $email = null;

    #[On('partner::edit')]
    public function edit($id): void
    {
        $this->partner = Partner::find($id);
        $this->name = $this->partner->user->name;
        $this->email = $this->partner->user->email;
        $this->phone = $this->partner->phone;

        Flux::modal('store-partner-modal')->show();
    }

    public function save(): void
    {
        $this->validate();

        DB::transaction(function () {
            $rule = Rule::where('name', Rules::LabPartner->value)->first();
            $user = User::updateOrCreate(
                ['email' => $this->email],
                [
                'name' => $this->name,
                'rule_id' => $rule->id,
            ]);

            Partner::updateOrCreate([
                'id' => $this->partner?->id,
            ], [
                'user_id' => $user->id,
                'phone' => $this->phone,
            ]);

            if ($user->wasRecentlyCreated) {
                SendInvitation::handle($user);
            }
        });

        Flux::modals()->close();
        Flux::toast(variant: 'success', heading: 'Sucesso!', text: str('Parceiro :action com sucesso!')->replace(':action', $this->partner ? 'atualizado' : 'criado'));

        $this->reset();
        $this->dispatch('partners::refresh');
    }
};
?>

<div>
    <flux:modal name="store-partner-modal" flyout variant="floating" class="md:w-lg" >
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">Novo Parceiro</flux:heading>
                <flux:subheading>Adicione um novo parceiro em sua rede.</flux:subheading>
            </div>
            <form class="space-y-4" wire:submit="save">
                <flux:input
                    wire:model="name"
                    label="Nome"
                    name="name"
                    placeholder="Digite o nome"
                />
                <flux:input
                    wire:model="phone"
                    label="Telefone"
                    name="phone"
                    placeholder="Digite o telefone"
                    mask="(99) 99999-9999"
                />

                <flux:field>
                    <flux:label>
                        Email
                        <flux:tooltip content="Um email de confirmação será enviado para este email." position="top">
                            <flux:icon class="ml-2 size-4" name="information-circle" />
                        </flux:tooltip>
                    </flux:label>
                    <flux:input
                        wire:model="email"
                        name="email"
                        placeholder="Digite o email"
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