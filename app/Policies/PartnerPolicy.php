<?php

namespace App\Policies;

use App\Enums\Rules;
use App\Models\Partner;
use App\Models\User;

class PartnerPolicy
{
    public function viewAny(User $user): bool
    {
        $user->loadMissing('rule');

        return in_array($user->rule?->name, [Rules::Owner, Rules::Lab, Rules::LabManager], true);
    }

    public function view(User $user, ?Partner $partner = null): bool
    {
        $user->loadMissing('rule');

        return in_array($user->rule?->name, [Rules::Owner, Rules::Lab, Rules::LabManager], true);
    }

    public function create(User $user): bool
    {
        $user->loadMissing('rule');

        return in_array($user->rule?->name, [Rules::Owner, Rules::Lab, Rules::LabManager], true);
    }

    public function update(User $user, ?Partner $partner = null): bool
    {
        $user->loadMissing('rule');

        return in_array($user->rule?->name, [Rules::Owner, Rules::Lab, Rules::LabManager], true);
    }

    public function delete(User $user, ?Partner $partner = null): bool
    {
        $user->loadMissing('rule');

        return in_array($user->rule?->name, [Rules::Owner, Rules::Lab, Rules::LabManager], true);
    }

    public function forceDelete(User $user, ?Partner $partner = null): bool
    {
        return false;
    }
}
