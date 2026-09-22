<?php

namespace App\Policies;

use App\Enums\Rules;
use App\Models\User;

class ServicePolicy
{
    public function viewAny(User $user): bool
    {
        $user->loadMissing('rule');
        return $user->rule->name == Rules::Owner || $user->rule->name == Rules::Lab || $user->rule->name == Rules::LabManager;
    }

    public function create(User $user): bool
    {
        $user->loadMissing('rule');
        return $user->rule->name == Rules::Owner || $user->rule->name == Rules::Lab || $user->rule->name == Rules::LabManager;
    }

    public function delete(User $user): bool
    {
        $user->loadMissing('rule');
        return $user->rule->name == Rules::Owner || $user->rule->name == Rules::Lab || $user->rule->name == Rules::LabManager;
    }
}
