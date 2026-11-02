<?php

namespace App\Actions;

use App\Enums\StockMovementsReasons;
use App\Enums\StockMovementsTypes;
use App\Models\DentistRequest;
use App\Models\RequestService;
use App\Notifications\CompletedRequestNotification;
use DomainException;
use Illuminate\Support\Facades\DB;

class CancelService
{
    public static function handle(int|RequestService $requestService): void
    {
        if (! $requestService instanceof RequestService) {
            $requestService = RequestService::with([
                'service',
                'partnerDemands',
                'consumptions',
                'dentistRequest.requestServices',
            ])->findOrFail($requestService);
        } else {
            $requestService->loadMissing([
                'service',
                'partnerDemands',
                'consumptions',
                'dentistRequest.requestServices',
            ]);
        }

        if ($requestService->completed_at !== null) {
            throw new DomainException('Não é possível cancelar um serviço já concluído.');
        }

        $hasStartedOrEnded = $requestService->partnerDemands->contains(function ($demand) {
            return $demand->started_at !== null || $demand->ended_at !== null;
        });

        if ($hasStartedOrEnded) {
            throw new DomainException('Não é possível cancelar um serviço cujas etapas já foram iniciadas ou concluídas.');
        }

        DB::transaction(function () use ($requestService) {
            /** @var DentistRequest|null $dentistRequest */
            $dentistRequest = $requestService->dentistRequest;

            // Reverte insumos consumidos em estoque se houver
            foreach ($requestService->consumptions as $consumption) {
                StockMovement::handle($consumption->product_id, [
                    'dentist_request_id' => $dentistRequest?->id,
                    'movementType' => StockMovementsTypes::In,
                    'reason' => StockMovementsReasons::Devolution,
                    'amount' => $consumption->quantity_used,
                    'stockBatchId' => $consumption->stock_batch_id,
                    'notes' => 'Estorno por cancelamento do serviço '.($requestService->service?->name ?? ''),
                ]);
            }

            // Exclui demandas e o próprio RequestService
            $requestService->partnerDemands()->delete();
            $requestService->consumptions()->delete();
            $requestService->delete();

            if ($dentistRequest) {
                $remainingServices = $dentistRequest->requestServices()->get();

                if ($remainingServices->isEmpty()) {
                    $dentistRequest->delete();
                } elseif ($remainingServices->every(fn (RequestService $s) => $s->completed_at !== null)) {
                    $dentistRequest->update([
                        'completed_at' => now(),
                    ]);

                    $dentistRequest->dentist?->user?->notify(new CompletedRequestNotification($dentistRequest->id));
                }
            }
        });
    }
}
