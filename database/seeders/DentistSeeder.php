<?php

namespace Database\Seeders;

use App\Enums\Rules;
use App\Models\Dentist;
use App\Models\Rule;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DentistSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $dentistRule = Rule::firstWhere('name', Rules::Dentist->value);

        $user = User::factory()
            ->for($dentistRule)
            ->create([
                'name' => 'Dentista Junior',
                'email' => 'dentista@email.com',
            ]);

        Dentist::factory()
            ->for($user)
            ->create();
    }
}