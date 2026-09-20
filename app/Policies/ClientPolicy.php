<?php

namespace App\Policies;

use App\Enums\Rules;
use App\Models\User;

class ClientPolicy
{
    /**
     * Create a new policy instance.
     */
    public function __construct()
    {
        //
    }

    public function delete(User $user): bool
    {
        return $user->rule->name === Rules::Owner;
    }
}
