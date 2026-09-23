<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $profilePhotos = [
            "https://img.magnific.com/free-photo/close-up-portrait-curly-handsome-european-male_176532-8133.jpg",
            "https://img.magnific.com/free-photo/close-up-portrait-attractive-man-with-afro-hairstyle-stubble-wears-orange-anorak_273609-8595.jpg",
            "https://img.magnific.com/free-photo/a-smiling-young-man-with-a-clean-shaven-face-is-sitting-in-a-chair_273609-3443.jpg",
            'https://img.magnific.com/free-photo/portrait-cheerful-caucasian-man_53876-13438.jpg',
        ];

        return [
            'name' => $this->faker->name(),
            'email' => $this->faker->unique()->safeEmail(),
            'profile_photo_url' => $this->faker->randomElement($profilePhotos),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'rule_id' => null
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
