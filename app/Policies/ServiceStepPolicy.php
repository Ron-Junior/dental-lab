<?php

namespace App\Policies;

use App\Enums\Rules;
use App\Models\User;

class ServiceStepPolicy
{
    public function update(User $user): bool
    {
        return $user->rule->name === Rules::Owner || $user->rule->name === Rules::Lab || $user->rule->name === Rules::LabManager;
    }
}
