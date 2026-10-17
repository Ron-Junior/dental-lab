<?php

namespace Database\Seeders;

use App\Models\CentralUser;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class CentralUserSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        CentralUser::query()->firstOrCreate(
            ['email' => 'owner@example.test'],
            [
                'name' => 'Owner de Teste',
                'password' => Hash::make('password'),
            ],
        );
    }
}
