<?php

namespace Database\Factories;

use App\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Category>
 */
class CategoryFactory extends Factory
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
            'name' => Str::title($name),
            'slug' => Str::slug($name),
            'sku_prefix' => Str::upper(fake()->unique()->lexify('???')),
        ];
    }

    /**
     * A category created without a parent is a root, and a root is the one that
     * carries the SKU prefix its products are numbered with. `->for($raiz, 'parent')`
     * sets the parent after the definition has run, so the prefix is blanked here,
     * once the model is built and the parent is known: a subcategory inherits the
     * prefix of its root and must never take one of its own.
     */
    public function configure(): static
    {
        return $this->afterMaking(function (Category $category) {
            if ($category->parent_id !== null) {
                $category->sku_prefix = null;
            }
        });
    }

    /**
     * Indicate that the category is hidden from the storefront.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
