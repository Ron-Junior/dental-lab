<?php

namespace App\Policies;

use App\Enums\Rules;
use App\Models\PartnerDemand;
use App\Models\User;

class PartnerDemandPolicy
{
    public function assign(User $user, ?PartnerDemand $partnerDemand = null): bool
    {
        $user->loadMissing('rule');

        if (in_array($user->rule?->name, [Rules::Owner, Rules::Lab, Rules::LabManager], true)) {
            return true;
        }

        if (! $partnerDemand) {
            return false;
        }

        $partnerDemand->loadMissing('serviceServiceStep.serviceStep.partners.user');

        return $partnerDemand->serviceServiceStep
            ?->serviceStep
            ?->partners
            ?->pluck('user.id')
            ->contains($user->id) ?? false;
    }

    public function start(User $user, ?PartnerDemand $partnerDemand = null): bool
    {
        if (! $partnerDemand || ! $partnerDemand->canStartNow) {
            return false;
        }

        $user->loadMissing('rule');

        if (in_array($user->rule?->name, [Rules::Owner, Rules::Lab, Rules::LabManager], true)) {
            return true;
        }

        return $partnerDemand->partner?->user_id === $user->id;
    }

    public function complete(User $user, ?PartnerDemand $partnerDemand = null): bool
    {
        if (! $partnerDemand || ! $partnerDemand->canCompleteNow) {
            return false;
        }

        $user->loadMissing('rule');

        if (in_array($user->rule?->name, [Rules::Owner, Rules::Lab, Rules::LabManager], true)) {
            return true;
        }

        return $partnerDemand->partner?->user_id === $user->id;
    }
}
