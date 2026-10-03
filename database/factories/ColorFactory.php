<?php

namespace Database\Factories;

use App\Models\Color;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Color>
 */
class ColorFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => Str::title(fake()->unique()->words(2, true)),
            'hex' => Str::upper(fake()->hexColor()),
            'code' => Str::upper(fake()->unique()->lexify('???')),
            // Said here and not left to the database so that a color that was just
            // created reads the same as it is stored, before it is read again: the
            // variant actions ask `is_active` of the object they are given, and an
            // object without the attribute would look like a color that is off.
            'order' => 0,
            'is_active' => true,
        ];
    }

    /**
     * Indicate that no new variant can be created in this color.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
