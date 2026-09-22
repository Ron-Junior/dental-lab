<?php

namespace Database\Seeders;

use App\Enums\Rules;
use App\Models\Rule;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $owner = Rule::firstWhere('name', Rules::Owner->value);

        User::factory()
            ->for($owner)
            ->create([
                'name' => 'Laboratório Teste',
                'email' => 'lab@email.com',
            ]);
    }
}