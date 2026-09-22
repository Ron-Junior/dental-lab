<?php

namespace Database\Seeders;

use App\Enums\Rules;
use App\Models\Rule;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DentistSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $owner = Rule::firstWhere('name', Rules::Dentist->value);

        User::factory()
            ->for($owner)
            ->create([
                'name' => 'Dentista Junior',
                'email' => 'dentista@email.com',
            ]);
    }
}