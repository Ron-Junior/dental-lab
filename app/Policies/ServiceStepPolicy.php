<?php

namespace App\Policies;

use App\Enums\Rules;
use App\Models\ServiceStep;
use App\Models\User;

class ServiceStepPolicy
{
    public function viewAny(User $user): bool
    {
        $user->loadMissing('rule');

        return in_array($user->rule?->name, [Rules::Owner, Rules::Lab, Rules::LabManager], true);
    }

    public function create(User $user): bool
    {
        $user->loadMissing('rule');

        return in_array($user->rule?->name, [Rules::Owner, Rules::Lab, Rules::LabManager], true);
    }

    public function update(User $user, ?ServiceStep $step = null): bool
    {
        $user->loadMissing('rule');

        return in_array($user->rule?->name, [Rules::Owner, Rules::Lab, Rules::LabManager], true);
    }

    public function delete(User $user, ?ServiceStep $step = null): bool
    {
        $user->loadMissing('rule');

        return in_array($user->rule?->name, [Rules::Owner, Rules::Lab, Rules::LabManager], true);
    }

    public function forceDelete(User $user, ?ServiceStep $step = null): bool
    {
        return false;
    }
}
