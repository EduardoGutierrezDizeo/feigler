<?php

namespace Database\Factories;

use App\Models\Material;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Material>
 */
class MaterialFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // Spanish, because the name of a material is shown in the panel and next
            // to the garments it describes, and `name` is unique in the whole store.
            'name' => Str::title(fake()->unique()->words(2, true)),
            // Said here and not left to the database so that a material that was just
            // created reads the same as it is stored, before it is read again.
            'order' => 0,
            'is_active' => true,
        ];
    }

    /**
     * Indicate that the material is not offered anymore.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
