<?php

namespace App\Policies;

use App\Enums\Rules;
use App\Models\DentistRequest;
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

    public function view(User $user, DentistRequest $dentistRequest): bool
    {
        $user->loadMissing('rule');

        if (in_array($user->rule?->name, [
            Rules::Owner,
            Rules::Lab,
            Rules::LabManager,
        ], true)) {
            return true;
        }

        if ($user->rule?->name === Rules::Dentist) {
            $dentistRequest->loadMissing('dentist');

            return $dentistRequest->dentist !== null
                && $dentistRequest->dentist->user_id === $user->id;
        }

        return false;
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, DentistRequest $dentistRequest): bool
    {
        return $this->view($user, $dentistRequest);
    }

    public function deleteFile(User $user, DentistRequest $dentistRequest): bool
    {
        return $this->update($user, $dentistRequest);
    }
}
