<?php

namespace Database\Factories;

use App\Models\Color;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductVariant>
 */
class ProductVariantFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'size' => fake()->randomElement(['XS', 'S', 'M', 'L', 'XL']),
            'color_id' => Color::factory(),
            'sku' => strtoupper(fake()->unique()->bothify('FG-####??')),
        ];
    }

    /**
     * Indicate that the variant cannot be sold.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
