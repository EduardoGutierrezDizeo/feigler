<?php

namespace Database\Factories;

use App\Models\Address;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Address>
 */
class AddressFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'recipient_name' => fake()->name(),
            'line1' => fake()->streetAddress(),
            'line2' => fake()->secondaryAddress(),
            'department_code' => '11',
            'department' => 'BOGOTÁ, D.C.',
            'city_code' => '11001',
            'city' => 'BOGOTÁ, D.C.',
            'label' => fake()->randomElement(['Casa', 'Trabajo']),
            'instructions' => fake()->sentence(),
            'phone' => '3001234567',
        ];
    }

    /**
     * Indicate that this is the address the customer checks out with by default.
     */
    public function default(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_default' => true,
        ]);
    }
}
