<?php

namespace App\Actions;

use App\Enums\StockMovementsReasons;
use App\Enums\StockMovementsTypes;
use App\Models\DentistRequest;
use App\Models\User;
use App\Notifications\OrderCancelledNotification;
use App\Notifications\PartnerDemandCancelledNotification;
use DomainException;
use Illuminate\Support\Facades\DB;

class CancelDentistRequest
{
    /**
     * @param array{
     *     reason: string,
     *     category: string,
     *     material_actions?: array<int, string>
     * } $data
     */
    public static function handle(DentistRequest $dentistRequest, array $data, User $cancelledBy): void
    {
        if ($dentistRequest->isCompleted()) {
            throw new DomainException('Não é possível cancelar uma ordem de serviço já concluída.');
        }

        if ($dentistRequest->isCancelled()) {
            throw new DomainException('Esta ordem de serviço já se encontra cancelada.');
        }

        DB::transaction(function () use ($dentistRequest, $data, $cancelledBy) {
            $now = now();

            // 1. Atualizar a DentistRequest
            $dentistRequest->update([
                'cancelled_at' => $now,
                'cancelled_by_user_id' => $cancelledBy->id,
                'cancellation_category' => $data['category'],
                'cancellation_reason' => $data['reason'],
            ]);

            // 2. Tratar Insumos de Estoque
            $materialActions = $data['material_actions'] ?? [];
            $consumptions = $dentistRequest->requestServices()
                ->with(['consumptions.product', 'consumptions.stockBatch'])
                ->get()
                ->flatMap->consumptions;

            foreach ($consumptions as $consumption) {
                $action = $materialActions[$consumption->id] ?? 'devolution';

                if ($action === 'devolution') {
                    StockMovement::handle($consumption->product_id, [
                        'dentist_request_id' => $dentistRequest->id,
                        'movementType' => StockMovementsTypes::In,
                        'reason' => StockMovementsReasons::Devolution,
                        'amount' => $consumption->quantity_used,
                        'stockBatchId' => $consumption->stock_batch_id,
                        'notes' => "Estorno por cancelamento da OS #{$dentistRequest->code}",
                    ]);
                }
            }

            // 3. Atualizar Demandas de Parceiros e Notificar
            $demandsToCancel = $dentistRequest->partnerDemands()
                ->whereNull('ended_at')
                ->whereNull('cancelled_at')
                ->with(['partner.user', 'serviceServiceStep.serviceStep', 'requestService.dentistRequest'])
                ->get();

            foreach ($demandsToCancel as $demand) {
                $demand->update(['cancelled_at' => $now]);

                if ($demand->partner?->user && $demand->started_at !== null) {
                    $demand->partner->user->notify(new PartnerDemandCancelledNotification($demand));
                }
            }

            // 4. Notificar o Dentista (caso cancelado pela equipe do laboratório)
            $dentistRequest->loadMissing('dentist.user');
            if ($dentistRequest->dentist?->user && $cancelledBy->id !== $dentistRequest->dentist->user_id) {
                $dentistRequest->dentist->user->notify(new OrderCancelledNotification($dentistRequest));
            }
        });
    }
}

