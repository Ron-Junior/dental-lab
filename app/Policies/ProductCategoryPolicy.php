<?php

namespace App\Policies;

use App\Enums\Rules;
use App\Models\ProductCategory;
use App\Models\User;

class ProductCategoryPolicy
{
    public function viewAny(User $user): bool
    {
        $user->loadMissing('rule');

        return in_array($user->rule?->name, [Rules::Owner, Rules::Lab, Rules::LabManager], true);
    }

    public function view(User $user, ?ProductCategory $category = null): bool
    {
        $user->loadMissing('rule');

        return in_array($user->rule?->name, [Rules::Owner, Rules::Lab, Rules::LabManager], true);
    }

    public function create(User $user): bool
    {
        $user->loadMissing('rule');

        return in_array($user->rule?->name, [Rules::Owner, Rules::Lab, Rules::LabManager], true);
    }

    public function update(User $user, ?ProductCategory $category = null): bool
    {
        $user->loadMissing('rule');

        return in_array($user->rule?->name, [Rules::Owner, Rules::Lab, Rules::LabManager], true);
    }

    public function delete(User $user, ?ProductCategory $category = null): bool
    {
        $user->loadMissing('rule');

        return in_array($user->rule?->name, [Rules::Owner, Rules::Lab, Rules::LabManager], true);
    }

    public function forceDelete(User $user, ?ProductCategory $category = null): bool
    {
        return false;
    }
}
