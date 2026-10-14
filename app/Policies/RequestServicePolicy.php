<?php

namespace App\Policies;

use App\Enums\Rules;
use App\Models\RequestService;
use App\Models\User;

class RequestServicePolicy
{
    public function updateStatus(User $user): bool
    {
        return $this->canManageServices($user);
    }

    public function update(User $user, RequestService $requestService): bool
    {
        return $this->canManageServices($user);
    }

    private function canManageServices(User $user): bool
    {
        $user->loadMissing('rule');

        return in_array($user->rule->name, [
            Rules::Owner,
            Rules::Lab,
            Rules::LabManager,
        ], true);
    }
}
