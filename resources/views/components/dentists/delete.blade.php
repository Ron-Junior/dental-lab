<?php

use App\Models\Dentist;
use App\Models\InvitationToken;
use Flux\Flux;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component
{
    public ?int $dentistId = null;

    #[On('dentist::delete')]
    public function confirmDelete(int $dentistId): void
    {
        $this->dentistId = $dentistId;

        Flux::modal('delete-dentist-modal')->show();
    }

    public function delete(): void
    {
        $dentist = Dentist::find($this->dentistId);
        if (! $dentist) {
            return;
        }

        $this->authorize('delete', $dentist);

        DB::transaction(function () use ($dentist) {
            $userId = $dentist->user_id;
            $dentist->delete();
            $dentist->user?->delete();

            if ($userId) {
                DB::table('sessions')->where('user_id', $userId)->delete();
                InvitationToken::where('user_id', $userId)->delete();
            }
        });

        $this->dentistId = null;
        $this->dispatch('dentist::refresh');

        Flux::toast(variant: 'success', heading: 'Sucesso!', text: 'Dentista deletado com sucesso!');
        Flux::modals()->close();
    }
};
?>

<div>
    <flux:modal class="backdrop:backdrop-blur-sm" name="delete-dentist-modal" title="Excluir Dentista">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">Deletar Dentista</flux:heading>
                <flux:subheading>Tem certeza de que deseja deletar este dentista?</flux:subheading>
            </div>

            <div class="flex items-center justify-end gap-2">
                <flux:spacer />
                <flux:modal.close>
                    <flux:button variant="ghost">Cancelar</flux:button>
                </flux:modal.close>
                <flux:button variant="danger" wire:click="delete">Deletar</flux:button>
            </div>
        </div>    
    </flux:modal>
</div>