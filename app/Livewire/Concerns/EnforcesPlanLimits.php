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
            if (app('livewire')->current()) {
                Flux::toast(
                    variant: 'danger',
                    heading: 'Limite do plano atingido',
                    text: $exception->messageFor(Auth::user()),
                );
            } elseif (method_exists($this, 'dispatch')) {
                $this->dispatch(
                    'toast-show',
                    duration: 5000,
                    slots: [
                        'text' => $exception->messageFor(Auth::user()),
                        'heading' => 'Limite do plano atingido',
                    ],
                    dataset: [
                        'variant' => 'danger',
                    ],
                );
            }

            return false;
        }
    }
}
