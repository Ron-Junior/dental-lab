<?php

namespace Database\Seeders;

use App\Enums\Rules;
use App\Models\Rule;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        User::factory()
            ->for(Rule::factory([
                'name' => Rules::Owner,
            ]))->create([
                'name' => 'Test User',
                'email' => 'test@example.com',
            ]);
    }
}
