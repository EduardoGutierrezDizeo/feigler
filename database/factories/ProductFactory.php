<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\Material;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->words(3, true);

        return [
            'category_id' => Category::factory(),
            'name' => Str::title($name),
            'slug' => Str::slug($name),
            'description' => fake()->sentence(),
            'base_price' => fake()->randomFloat(2, 10, 200),
        ];
    }

    /**
     * Indicate that the garment is made of these materials, in these shares.
     *
     * It takes the same shape the panel and `SyncProductMaterials` use, so a test
     * that is describing a composition writes it the way the application writes it:
     *
     *     Product::factory()->withMaterials([
     *         ['id' => $algodon, 'percentage' => 80],
     *         ['id' => $poliester, 'percentage' => 20],
     *     ])->create();
     *
     * A product with no materials is a garment nobody has described yet, so the
     * default state of the factory does not describe one.
     *
     * @param  list<array{id: Material|int, percentage: int|string}>  $composition
     */
    public function withMaterials(array $composition): static
    {
        return $this->afterCreating(function (Product $product) use ($composition): void {
            foreach ($composition as $line) {
                $product->materials()->attach(
                    $line['id'] instanceof Material ? $line['id']->getKey() : $line['id'],
                    ['percentage' => (int) $line['percentage']],
                );
            }
        });
    }

    /**
     * Indicate that the product is not published in the catalog.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'inactive',
        ]);
    }

    /**
     * Indicate that the product has no stock left.
     */
    public function outOfStock(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'out_of_stock',
        ]);
    }
}
