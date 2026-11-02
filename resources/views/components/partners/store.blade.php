<?php

use App\Actions\SendInvitation;
use App\Enums\PlanResource;
use App\Enums\Rules;
use App\Livewire\Concerns\EnforcesPlanLimits;
use App\Models\Partner;
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
    use EnforcesPlanLimits;

    #[Locked]
    public ?Partner $partner = null;

    public ?string $name = null;

    public ?string $phone = null;

    public ?string $email = null;

    protected function rules(): array
    {
        $editingUserId = $this->partner?->user_id;

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

    #[On('partner::edit')]
    public function edit($id): void
    {
        $partner = Partner::with('user')->findOrFail($id);
        $this->authorize('update', $partner);

        $this->partner = $partner;
        $this->name = $this->partner->user->name;
        $this->email = $this->partner->user->email;
        $this->phone = $this->partner->phone;

        Flux::modal('store-partner-modal')->show();
    }

    public function closeModal(): void
    {
        $this->reset();
        $this->resetValidation();
        Flux::modals()->close();
    }

    public function save(): void
    {
        if ($this->partner) {
            $this->authorize('update', $this->partner);
        } else {
            $this->authorize('create', Partner::class);
        }

        $this->validate();

        if (! $this->partner && ! $this->guardPlanLimit(PlanResource::Users)) {
            return;
        }

        $isEditing = $this->partner !== null;

        DB::transaction(function () {
            if ($this->partner) {
                $partner = Partner::with('user')->findOrFail($this->partner->id);
                $user = $partner->user;
                $previousEmail = $user->email;

                $user->update([
                    'name' => $this->name,
                    'email' => $this->email,
                ]);

                $partner->update([
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
                $rule = RuleModel::firstOrCreate(['name' => Rules::LabPartner->value]);
                $user = User::create([
                    'name' => $this->name,
                    'email' => $this->email,
                    'rule_id' => $rule->id,
                ]);

                Partner::create([
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
            text: str('Parceiro :action com sucesso!')->replace(':action', $isEditing ? 'atualizado' : 'criado')
        );

        $this->reset();
        $this->resetValidation();
        $this->dispatch('partners::refresh');
    }
};
?>

<div>
    <flux:modal class="backdrop:backdrop-blur-sm md:w-lg" name="store-partner-modal" flyout variant="floating" @close="closeModal">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ $partner ? 'Editar Parceiro' : 'Novo Parceiro' }}</flux:heading>
                <flux:subheading>{{ $partner ? 'Atualize as informações do parceiro.' : 'Adicione um novo parceiro em sua rede.' }}</flux:subheading>
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
