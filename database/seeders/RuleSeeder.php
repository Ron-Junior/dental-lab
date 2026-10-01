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
        foreach (Rules::cases() as $rule) {
            Rule::firstOrCreate(['name' => $rule->value]);
        }
    }
}
