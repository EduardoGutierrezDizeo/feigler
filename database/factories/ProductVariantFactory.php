<?php

namespace Database\Factories;

use App\Models\Color;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Size;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductVariant>
 */
class ProductVariantFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * The size is not written here: it depends on the product, and a size only means
     * something inside a category. It is settled in `configure()` instead, once the
     * product behind the variant is known.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'color_id' => Color::factory(),
            'sku' => strtoupper(fake()->unique()->bothify('FG-####??')),
        ];
    }

    /**
     * Give every variant a size of the category of its product.
     *
     * A size is picked among the standard ones the store seeds every category with,
     * and it is created when the category does not carry it, which is what lets
     * `ProductVariant::factory()->create()` keep working with no arguments: the size a
     * variant points at always belongs to the category of its product, so no test can
     * build a variant the panel would refuse.
     */
    public function configure(): static
    {
        return $this->afterMaking(function (ProductVariant $variant): void {
            if ($variant->size_id !== null) {
                return;
            }

            $categoryId = Product::query()
                ->whereKey($variant->product_id)
                ->value('category_id');

            $variant->size_id = Size::query()->firstOrCreate([
                'category_id' => $categoryId,
                'name' => fake()->randomElement(Size::STANDARD_NAMES),
            ])->getKey();
        });
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

    /**
     * Indicate that the variant is sold in a size of its own.
     */
    public function inSize(Size $size): static
    {
        return $this->state(fn (array $attributes) => [
            'size_id' => $size->getKey(),
        ]);
    }
}
