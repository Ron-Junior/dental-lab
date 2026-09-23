<?php

namespace Database\Factories;

use App\Enums\Rules;
use App\Models\Partner;
use App\Models\Rule;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Partner>
 */
class PartnerFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $labPartnerRule = Rule::where('name', Rules::LabPartner->value)->first();
        return [
            'user_id' => User::factory([
                'rule_id' => $labPartnerRule->id,
            ]),
            'phone' => $this->faker->phoneNumber(),
            'is_active' => $this->faker->boolean(),
            'started_date' => $this->faker->date(),
        ];
    }
}
