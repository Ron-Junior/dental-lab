<?php

namespace App\Actions;

use App\Notifications\CompletedRequestNotification;
use App\Models\RequestService;

class CompleteService
{
    public static function handle(int $requestServiceId): void
    {
        $service = RequestService::with(['dentistRequest.requestServices', 'dentistRequest.dentist.user'])->find($requestServiceId);
        
        $service->update([
            'completed_at' => now(),
        ]);

        $isCompleted = $service->dentistRequest->requestServices->every(fn(RequestService $requestService) => $requestService->completed_at !== null);
        
        if (!$isCompleted) {
            return;
        }

        $service->dentistRequest->update([
            'completed_at' => now(),
        ]);

        $service->dentistRequest->dentist->user->notify(new CompletedRequestNotification($service->dentistRequest->id));        
    }
}