<?php

namespace App\Policies;

use App\Enums\Rules;
use App\Models\User;

class DentistPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->rule->name == Rules::Owner || $user->rule->name == Rules::Lab || $user->rule->name == Rules::LabManager;
    }

    public function delete(User $user): bool
    {
        return $user->rule->name == Rules::Owner || $user->rule->name == Rules::Lab || $user->rule->name == Rules::LabManager;
    }
}
