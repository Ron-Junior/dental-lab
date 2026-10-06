<?php

namespace Database\Seeders;

use App\Enums\CommissionTypes;
use App\Enums\Rules;
use App\Models\Partner;
use App\Models\Rule;
use App\Models\ServiceStep;
use App\Models\User;
use Illuminate\Database\Seeder;

class PartnerSeeder extends Seeder
{
    public function run(): void
    {
        $rule = Rule::updateOrCreate(
            ['name' => Rules::LabPartner->value]
        );

        $allowedSteps = ServiceStep::all();
        $pivotValues = $allowedSteps->map(fn () => [
            'commission' => rand(10, 90),
            'commission_type' => CommissionTypes::Percentage->value,
        ])->toArray();

        Partner::factory(10)
            ->has(User::factory()->forRule($rule))
            ->hasAttached($allowedSteps, $pivotValues)
            ->create();
    }
}
