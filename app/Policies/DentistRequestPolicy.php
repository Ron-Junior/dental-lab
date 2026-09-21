<?php

namespace App\Policies;

use App\Models\User;

class DentistRequestPolicy
{
    public function update(User $user): bool
    {
        return true;
    }
}
