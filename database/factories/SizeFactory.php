<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\Size;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Size>
 */
class SizeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'category_id' => Category::factory(),
            // Un size has a name of its own in every category and `name` is unique
            // together with `category_id`, so a repeated one is not an option here.
            'name' => fake()->unique()->randomElement(['XS', 'S', 'M', 'L', 'XL', 'XXL', 'XXXL']),
            // Said here and not left to the database so that a size that was just
            // created reads the same as it is stored, before it is read again.
            'order' => 0,
            'is_active' => true,
        ];
    }

    /**
     * Indicate that no new variant can be created in this size.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
