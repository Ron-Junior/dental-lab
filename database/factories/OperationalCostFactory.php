<?php

namespace Database\Factories;

use App\Enums\OperationalCostType;
use App\Models\OperationalCost;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OperationalCost>
 */
class OperationalCostFactory extends Factory
{
    protected $model = OperationalCost::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Servidor '.fake()->word(),
            'type' => OperationalCostType::Fixed,
            'amount' => 30000,
            'is_active' => true,
            'notes' => null,
        ];
    }

    public function ofType(OperationalCostType $type, int $amount): static
    {
        return $this->state(fn () => [
            'type' => $type,
            'amount' => $amount,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => [
            'is_active' => false,
        ]);
    }
}
