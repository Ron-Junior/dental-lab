<?php

namespace App\Policies;

use App\Enums\Rules;
use App\Models\User;

class DentistRequestPolicy
{
    public function viewAny(User $user): bool
    {
        $user->loadMissing('rule');

        return in_array($user->rule->name, [
            Rules::Owner,
            Rules::Lab,
            Rules::LabManager,
            Rules::Dentist,
        ], true);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }
}
