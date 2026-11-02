<?php

use App\Actions\SendInvitation;
use App\Enums\Rules;
use App\Models\Dentist;
use App\Models\Rule as RuleModel;
use App\Models\User;
use Flux\Flux;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule as ValidationRule;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component
{
    #[Locked]
    public ?int $dentistId = null;

    public ?string $name = null;

    public ?string $phone = null;

    public ?string $email = null;

    protected function rules(): array
    {
        $editingUserId = null;
        if ($this->dentistId) {
            $dentist = Dentist::find($this->dentistId);
            $editingUserId = $dentist?->user_id;
        }

        return [
            'name' => ['required', 'string', 'min:3', 'max:255'],
            'phone' => ['required', 'string', 'min:11', 'max:15'],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                ValidationRule::unique('users', 'email')->whereNull('deleted_at')->ignore($editingUserId),
            ],
        ];
    }

    #[On('dentist::edit')]
    public function edit($id): void
    {
        $dentist = Dentist::with('user')->findOrFail((int) $id);
        $this->authorize('update', $dentist);

        $this->dentistId = (int) $id;
        $this->name = $dentist->user->name;
        $this->email = $dentist->user->email;
        $this->phone = $dentist->phone;

        Flux::modal('store-dentist-modal')->show();
    }

    public function closeModal(): void
    {
        $this->reset();
        $this->resetValidation();
        Flux::modals()->close();
    }

    public function save(): void
    {
        if ($this->dentistId) {
            $dentist = Dentist::with('user')->findOrFail($this->dentistId);
            $this->authorize('update', $dentist);
        } else {
            $this->authorize('create', Dentist::class);
        }

        $this->validate();

        $isEditing = $this->dentistId !== null;

        DB::transaction(function () {
            if ($this->dentistId) {
                $dentist = Dentist::with('user')->findOrFail($this->dentistId);
                $user = $dentist->user;
                $previousEmail = $user->email;

                $user->update([
                    'name' => $this->name,
                    'email' => $this->email,
                ]);

                $dentist->update([
                    'phone' => $this->phone,
                ]);

                if (
                    strcasecmp($previousEmail, $this->email) !== 0 &&
                    $user->email_verified_at === null &&
                    $user->password === null
                ) {
                    SendInvitation::handle($user->fresh());
                }
            } else {
                $rule = RuleModel::firstOrCreate(['name' => Rules::Dentist->value]);
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
            }
        });

        Flux::modals()->close();
        Flux::toast(
            variant: 'success',
            heading: 'Sucesso!',
            text: str('Dentista :action com sucesso!')->replace(':action', $isEditing ? 'atualizado' : 'criado')
        );

        $this->reset();
        $this->resetValidation();
        $this->dispatch('dentist::refresh');
    }
};
?>

<div>
    <flux:modal class="backdrop:backdrop-blur-sm md:w-lg" name="store-dentist-modal" flyout variant="floating" @close="closeModal">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ $dentistId ? 'Editar Dentista' : 'Novo Dentista' }}</flux:heading>
                <flux:subheading>{{ $dentistId ? 'Atualize as informações do dentista.' : 'Adicione um novo dentista em sua rede.' }}</flux:subheading>
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
