<?php

namespace App\Policies;

use App\Enums\Rules;
use App\Models\User;
use App\Models\PartnerDemand;

use function Illuminate\Log\log;

class PartnerDemandPolicy
{
    public function assign(User $user, PartnerDemand $partnerDemand): bool
    {
        $isLab = $user->rule->name == Rules::Lab || $user->rule->name == Rules::LabManager;
        if ($isLab) {
            return true;
        }

        $isOwner = $user->rule->name == Rules::Owner;
        if ($isOwner) {
            return true;
        }

        return $partnerDemand->serviceServiceStep->serviceStep->partners->pluck('user.id')->contains($user->id);
    }

    public function start(User $user, PartnerDemand $partnerDemand): bool
    {
        if (!$partnerDemand->canStartNow) {
            return false;
        }

        $isLab = $user->rule->name == Rules::Lab || $user->rule->name == Rules::LabManager;
        if ($isLab) {
            return true;
        }

        $isOwner = $user->rule->name == Rules::Owner;
        if ($isOwner) {
            return true;
        }

        return $partnerDemand->partner_id === $user->id;
    }

    public function complete(User $user, PartnerDemand $partnerDemand): bool
    {
        if (!$partnerDemand->canCompleteNow) {
            return false;
        }

        $isLab = $user->rule->name == Rules::Lab || $user->rule->name == Rules::LabManager;
        if ($isLab) {
            return true;
        }

        $isOwner = $user->rule->name == Rules::Owner;
        if ($isOwner) {
            return true;
        }

        return $partnerDemand->partner_id === $user->id;
    }
}
