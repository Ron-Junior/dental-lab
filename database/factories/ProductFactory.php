<?php

namespace Database\Factories;

use App\Enums\UnitMeasure;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->words(3, true),
            'unit_of_measure' => UnitMeasure::Gram,
            'current_stock' => 10.00,
            'min_stock' => 2.00,
            'has_batches' => false,
            'is_active' => true,
        ];
    }

    public function withBatches(): static
    {
        return $this->state(fn () => [
            'has_batches' => true,
        ]);
    }
}
