<?php

namespace App\Policies;

use App\Enums\Rules;
use App\Models\User;

class ServicePolicy
{
    public function create(User $user): bool
    {
        $user->loadMissing('rule');
        return $user->rule->name == Rules::Owner || $user->rule->name == Rules::Client;
    }

    public function delete(User $user): bool
    {
        $user->loadMissing('rule');
        return $user->rule->name == Rules::Owner || $user->rule->name == Rules::Client;
    }
}
