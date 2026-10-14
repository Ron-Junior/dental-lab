<?php

namespace App\Policies;

use App\Enums\Rules;
use App\Models\User;

class ServiceServiceStepPolicy
{
    public function viewAny(User $user): bool
    {
        $user->loadMissing('rule');

        return in_array($user->rule->name, [
            Rules::Owner,
            Rules::Lab,
            Rules::LabManager,
            Rules::LabPartner,
        ], true);
    }

    public function delete(User $user): bool
    {
        $user->loadMissing('rule');

        return in_array($user->rule->name, [
            Rules::Owner,
            Rules::Lab,
            Rules::LabManager,
        ], true);
    }
}
