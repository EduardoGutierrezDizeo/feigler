<?php

namespace Database\Factories;

use App\Enums\StoreSection;
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
            'section' => StoreSection::Hombre,
            // Three random letters: `unique()` keeps them apart, and the column is
            // unique in the whole store, not per section.
            'sku_prefix' => Str::upper(fake()->unique()->lexify('???')),
        ];
    }

    /**
     * Indicate that the category lives in another section of the store.
     */
    public function section(StoreSection $section): static
    {
        return $this->state(fn (array $attributes) => [
            'section' => $section,
        ]);
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
