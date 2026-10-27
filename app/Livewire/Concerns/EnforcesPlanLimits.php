<?php

namespace App\Livewire\Concerns;

use App\Enums\PlanResource;
use App\Services\PlanLimits\PlanLimitExceededException;
use App\Services\PlanLimits\TenantLimitChecker;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;

trait EnforcesPlanLimits
{
    /**
     * Retorna `false` (e avisa o usuário) quando criar `$incoming` itens ultrapassa o limite do plano.
     */
    protected function guardPlanLimit(PlanResource $resource, int|float $incoming = 1): bool
    {
        try {
            app(TenantLimitChecker::class)->assertCanCreate($resource, $incoming);

            return true;
        } catch (PlanLimitExceededException $exception) {
            Flux::toast(
                variant: 'danger',
                heading: 'Limite do plano atingido',
                text: $exception->messageFor(Auth::user()),
            );

            return false;
        }
    }
}
