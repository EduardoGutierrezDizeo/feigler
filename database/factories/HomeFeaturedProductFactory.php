<?php

namespace Database\Factories;

use App\Models\HomeFeaturedProduct;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HomeFeaturedProduct>
 */
class HomeFeaturedProductFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * A row created by hand starts at the end of the list, which is what the
     * panel does too: `order` 0 is the first position.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'order' => 0,
        ];
    }
}
