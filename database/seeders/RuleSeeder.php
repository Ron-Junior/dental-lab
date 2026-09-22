<?php

namespace Database\Seeders;

use App\Enums\Rules;
use App\Models\Rule;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class RuleSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        Rule::factory(['name' => Rules::Owner->value])->create();
        Rule::factory(['name' => Rules::Lab->value])->create();
        Rule::factory(['name' => Rules::LabManager->value])->create();
        Rule::factory(['name' => Rules::Dentist->value])->create();
    }
}