<?php

use App\Models\InvitationToken;
use App\Models\Partner;
use App\Models\PartnerDemand;
use Flux\Flux;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component
{
    public ?Partner $partner = null;

    #[On('partner::delete')]
    public function confirmDelete(int $partnerId): void
    {
        $partner = Partner::findOrFail($partnerId);
        $this->authorize('delete', $partner);

        $this->partner = $partner;

        Flux::modal('delete-partner-modal')->show();
    }

    public function delete(): void
    {
        abort_if(! $this->partner, 404);

        $this->authorize('delete', $this->partner);

        $hasActiveDemands = PartnerDemand::where('partner_id', $this->partner->id)
            ->whereNotNull('started_at')
            ->whereNull('ended_at')
            ->exists();

        if ($hasActiveDemands) {
            Flux::modals()->close();
            Flux::toast(variant: 'danger', heading: 'Não é possível excluir!', text: 'O parceiro possui demandas em andamento. Conclua ou reatribua as demandas antes de excluir.');

            return;
        }

        DB::transaction(function () {
            PartnerDemand::where('partner_id', $this->partner->id)
                ->whereNull('started_at')
                ->whereNull('ended_at')
                ->update(['partner_id' => null]);

            $this->partner->serviceSteps()->detach();

            $userId = $this->partner->user_id;

            $this->partner->delete();
            $this->partner->user?->delete();

            if ($userId) {
                DB::table('sessions')->where('user_id', $userId)->delete();
                InvitationToken::where('user_id', $userId)->delete();
            }
        });

        $this->dispatch('partners::refresh');
        Flux::toast(variant: 'success', heading: 'Sucesso!', text: 'Parceiro deletado com sucesso!');
        Flux::modals()->close();
    }
};
?>

<div>
    <flux:modal class="backdrop:backdrop-blur-sm" name="delete-partner-modal" title="Excluir Parceiro">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">Deletar Parceiro</flux:heading>
                <flux:subheading>Tem certeza de que deseja deletar este parceiro?</flux:subheading>
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