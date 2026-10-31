<?php

namespace App\Actions;

use App\Models\Plan;
use App\Models\Subscription;
use Illuminate\Support\Facades\DB;

class ReactivatePlan
{
    /**
     * Reativa um plano comercial e cancela eventuais migrações de desativação pendentes.
     */
    public function handle(Plan $plan): Plan
    {
        return DB::transaction(function () use ($plan): Plan {
            $plan->update(['is_active' => true]);

            Subscription::query()
                ->where('plan_id', $plan->id)
                ->whereNotNull('scheduled_plan_id')
                ->update([
                    'scheduled_plan_id' => null,
                    'scheduled_price_in_cents' => null,
                    'scheduled_stripe_price_id' => null,
                    'price_change_effective_at' => null,
                ]);

            return $plan->fresh() ?? $plan;
        });
    }
}

