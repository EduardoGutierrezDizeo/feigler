<?php

namespace Database\Factories;

use App\Models\Color;
use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductImage>
 */
class ProductImageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * The color is a random one and not one of the product: the column is NOT NULL
     * because a picture always shows something, and a test that cares about which
     * color it is says so with `->for($color, 'color')`.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'color_id' => Color::factory(),
            'path' => 'products/'.fake()->unique()->slug(4).'.jpg',
            'is_primary' => false,
        ];
    }

    /**
     * Indicate that this image is the main one of its color.
     */
    public function primary(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_primary' => true,
        ]);
    }
}
