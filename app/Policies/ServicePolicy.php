<?php

namespace App\Policies;

use App\Enums\Rules;
use App\Models\Service;
use App\Models\User;

use function Illuminate\Log\log;

class ServicePolicy
{
    public function delete(User $user): bool
    {
        $user->loadMissing('rule');
        return $user->rule->name == Rules::Owner;
    }
}
