<?php

namespace Database\Factories;

use App\Models\InventoryMovement;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InventoryMovement>
 */
class InventoryMovementFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_variant_id' => ProductVariant::factory(),
            'type' => 'ajuste_entrada',
            'quantity' => fake()->numberBetween(1, 20),
            'user_id' => User::factory(),
        ];
    }

    /**
     * Indicate that the movement takes stock out, e.g. a manual write-down.
     */
    public function salida(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'ajuste_salida',
            'quantity' => -fake()->numberBetween(1, 20),
        ]);
    }
}
