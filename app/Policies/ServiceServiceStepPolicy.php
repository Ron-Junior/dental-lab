<?php

namespace App\Policies;

use App\Enums\Rules;
use App\Models\User;

class ServiceServiceStepPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->rule->name != Rules::Dentist;
    }

    public function delete(User $user): bool
    {
        return $user->rule->name != Rules::Dentist;
    }
}
