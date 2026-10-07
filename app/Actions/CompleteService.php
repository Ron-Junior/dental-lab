<?php

namespace App\Actions;

use App\Models\RequestService;
use App\Notifications\CompletedRequestNotification;

class CompleteService
{
    public static function handle(int $requestServiceId): void
    {
        $service = RequestService::with(['dentistRequest.requestServices', 'dentistRequest.dentist.user'])->find($requestServiceId);

        if (! $service->partnerDemands->every(fn ($demand) => $demand->ended_at !== null)) {
            return;
        }

        $service->update([
            'completed_at' => now(),
        ]);

        $isCompleted = $service->dentistRequest->requestServices->every(fn (RequestService $requestService) => $requestService->completed_at !== null);

        if (! $isCompleted) {
            return;
        }

        $service->dentistRequest->update([
            'completed_at' => now(),
        ]);

        $service->dentistRequest->dentist->user->notify(new CompletedRequestNotification($service->dentistRequest->id));
    }
}
