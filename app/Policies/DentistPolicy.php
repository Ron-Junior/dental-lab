<?php

namespace App\Policies;

use App\Enums\Rules;
use App\Models\Dentist;
use App\Models\User;

class DentistPolicy
{
    public function viewAny(User $user): bool
    {
        $user->loadMissing('rule');

        return in_array($user->rule?->name, [Rules::Owner, Rules::Lab, Rules::LabManager], true);
    }

    public function delete(User $user, ?Dentist $dentist = null): bool
    {
        $user->loadMissing('rule');

        return in_array($user->rule?->name, [Rules::Owner, Rules::Lab, Rules::LabManager], true);
    }

    public function forceDelete(User $user, ?Dentist $dentist = null): bool
    {
        return false;
    }
}
