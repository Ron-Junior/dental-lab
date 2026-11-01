<?php

use App\Enums\Rules;
use App\Livewire\Actions\Logout;
use App\Models\InvitationToken;
use App\Models\PartnerDemand;
use App\Models\User;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Component;

new class extends Component
{
    public string $password = '';

    /**
     * Delete the currently authenticated user.
     */
    public function deleteUser(Logout $logout): void
    {
        $this->validate([
            'password' => ['required', 'string', 'current_password'],
        ]);

        /** @var User $user */
        $user = Auth::user();
        $user->loadMissing(['rule', 'partner', 'dentist']);

        if (in_array($user->rule?->name, [Rules::Owner, Rules::Lab], true)) {
            $otherAdminsCount = User::query()
                ->where('id', '!=', $user->id)
                ->whereHas('rule', fn ($q) => $q->whereIn('name', [Rules::Owner->value, Rules::Lab->value]))
                ->count();

            $remainingLabAdminsCount = User::query()
                ->where('id', '!=', $user->id)
                ->whereHas('rule', fn ($q) => $q->where('name', Rules::Lab->value))
                ->count();

            if ($otherAdminsCount === 0 || $remainingLabAdminsCount === 0) {
                Flux::toast(variant: 'danger', heading: 'Erro!', text: 'Não é possível excluir a conta do único administrador do laboratório.');
                $this->addError('password', 'Não é possível excluir a conta do único administrador do laboratório.');

                return;
            }
        }

        if ($user->partner) {
            $hasActiveDemands = PartnerDemand::where('partner_id', $user->partner->id)
                ->whereNotNull('started_at')
                ->whereNull('ended_at')
                ->exists();

            if ($hasActiveDemands) {
                Flux::toast(variant: 'danger', heading: 'Erro!', text: 'Não é possível excluir a conta enquanto houver demandas em andamento.');
                $this->addError('password', 'Não é possível excluir a conta enquanto houver demandas em andamento.');

                return;
            }
        }

        DB::transaction(function () use ($user) {
            if ($user->dentist) {
                $user->dentist->delete();
            }

            if ($user->partner) {
                PartnerDemand::where('partner_id', $user->partner->id)
                    ->whereNull('started_at')
                    ->whereNull('ended_at')
                    ->update(['partner_id' => null]);

                $user->partner->serviceSteps()->detach();
                $user->partner->delete();
            }

            $userId = $user->id;
            $user->delete();

            DB::table('sessions')->where('user_id', $userId)->delete();
            InvitationToken::where('user_id', $userId)->delete();
        });

        $logout();

        $this->redirect('/', navigate: true);
    }
}; ?>

<section class="space-y-6">
    <header class="space-y-1">
        <flux:heading size="lg">
            {{ __('Delete Account') }}
        </flux:heading>

        <flux:text>
            {{ __('Once your account is deleted, all of its resources and data will be permanently deleted. Before deleting your account, please download any data or information that you wish to retain.') }}
        </flux:text>
    </header>

    <flux:modal.trigger name="confirm-user-deletion">
        <flux:button type="button" variant="danger">{{ __('Delete Account') }}</flux:button>
    </flux:modal.trigger>

    <flux:modal name="confirm-user-deletion" class="backdrop:backdrop-blur-sm sm:max-w-lg">
        <form wire:submit="deleteUser" class="space-y-6">
            <div class="space-y-2">
                <flux:heading size="lg">
                    {{ __('Are you sure you want to delete your account?') }}
                </flux:heading>

                <flux:text>
                    {{ __('Once your account is deleted, all of its resources and data will be permanently deleted. Please enter your password to confirm you would like to permanently delete your account.') }}
                </flux:text>
            </div>

            <flux:field>
                <flux:label for="password">{{ __('Password') }}</flux:label>
                <flux:input
                    wire:model="password"
                    id="password"
                    name="password"
                    type="password"
                    autocomplete="current-password"
                    required
                />
                <flux:error name="password" />
            </flux:field>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button type="button" variant="ghost">
                        {{ __('Cancel') }}
                    </flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="danger">
                    {{ __('Delete Account') }}
                </flux:button>
            </div>
        </form>
    </flux:modal>
</section>
