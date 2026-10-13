<?php

namespace App\Services;

use App\Enums\StoreSection;
use App\Models\Category;
use App\Models\Color;
use App\Models\Material;
use App\Models\Product;
use App\Models\Size;
use App\Support\Concerns\NormalizesNames;
use Illuminate\Database\Eloquent\Collection;

/**
 * Everything the catalog holds, read once and kept in memory.
 *
 * `ProductDetailsCatalog` answers a name by looking it up in the database, which
 * is the right thing to do for the panel: one name arrives at a time, typed into
 * a field, and a query per name is a query per form. A file is not one name: it
 * is hundreds of rows asking the same small catalog the same questions, and a
 * query per row would turn a file of five hundred rows into a thousand queries
 * for data that never changes while the file is being read.
 *
 * This is that catalog, read once. The `ProductDetailsCatalog` is handed one and
 * resolves against it instead of the database, so a row costs no query at all.
 * The product names come along because the import has to tell a name that is
 * already taken from one that is free, and the materials by id so the messages
 * of the composition rules can name the material they are about.
 *
 * Nothing here writes, and nothing here is kept beyond the file it was read for:
 * it is built when a file is read and thrown away with it.
 */
final class CatalogSnapshot
{
    use NormalizesNames;

    /** @var Collection<int, Color> */
    private Collection $colors;

    /** @var Collection<int, Material> */
    private Collection $materials;

    /** @var Collection<int, Size> */
    private Collection $sizes;

    /**
     * The categories of the store, under the section and the name they are
     * written with, so a `(género, categoría)` pair is one lookup.
     *
     * @var array<string, Category>
     */
    private array $categories;

    /**
     * The names already taken, under `category id` and the name they are written
     * with. It holds every product, turned on or not: a name that is in the
     * catalog is a name the import cannot use again.
     *
     * @var array<string, true>
     */
    private array $productNames = [];

    /**
     * @param  Collection<int, Category>  $categories
     * @param  Collection<int, Color>  $colors
     * @param  Collection<int, Material>  $materials
     * @param  Collection<int, Size>  $sizes
     * @param  Collection<int, Product>  $products
     */
    public function __construct(
        Collection $categories,
        Collection $colors,
        Collection $materials,
        Collection $sizes,
        Collection $products,
    ) {
        $this->colors = $colors;
        $this->materials = $materials;
        $this->sizes = $sizes;

        $this->categories = [];

        foreach ($categories as $category) {
            $this->categories[$this->categoryKey($category->section, $category->name)] = $category;
        }

        foreach ($products as $product) {
            $this->productNames[$this->productKey((int) $product->category_id, $product->name)] = true;
        }
    }

    /**
     * Read the whole catalog once.
     */
    public static function load(): self
    {
        return new self(
            Category::query()->orderBy('order')->orderBy('id')->get(),
            Color::listed(),
            Material::listed(),
            Size::query()->ordered()->get(),
            Product::query()->get(['id', 'category_id', 'name']),
        );
    }

    /**
     * Every color of the store, in catalog order.
     *
     * @return Collection<int, Color>
     */
    public function colors(): Collection
    {
        return $this->colors;
    }

    /**
     * Every material of the store, in catalog order.
     *
     * @return Collection<int, Material>
     */
    public function materials(): Collection
    {
        return $this->materials;
    }

    /**
     * The sizes of one category, in catalog order, whether they are on or off.
     *
     * The one is turned off is kept in: `ProductDetailsCatalog` tells a size the
     * category does not carry from one it carries and has stopped offering, and
     * the second answer needs the size to be here.
     *
     * @return Collection<int, Size>
     */
    public function sizesForCategory(int $categoryId): Collection
    {
        return $this->sizes
            ->filter(fn (Size $size): bool => (int) $size->category_id === $categoryId)
            ->values();
    }

    /**
     * The category a `(género, categoría)` pair is written for, if the store has it.
     */
    public function categoryFor(StoreSection $section, string $writtenName): ?Category
    {
        return $this->categories[$this->categoryKey($section, $writtenName)] ?? null;
    }

    /**
     * A category by its id, if the store has it.
     *
     * The bulk load needs this after it has already resolved an article: the
     * article kept its category id, and telling whether that category is on
     * requires reading it back without another query.
     */
    public function categoryById(int $id): ?Category
    {
        foreach ($this->categories as $category) {
            if ((int) $category->getKey() === $id) {
                return $category;
            }
        }

        return null;
    }

    /**
     * Whether a name is already used by a product of this category.
     */
    public function productNameExists(int $categoryId, string $writtenName): bool
    {
        return isset($this->productNames[$this->productKey($categoryId, $writtenName)]);
    }

    public function colorById(int $id): ?Color
    {
        return $this->colors->firstWhere('id', $id);
    }

    public function materialById(int $id): ?Material
    {
        return $this->materials->firstWhere('id', $id);
    }

    public function sizeById(int $id): ?Size
    {
        return $this->sizes->firstWhere('id', $id);
    }

    private function categoryKey(StoreSection $section, string $writtenName): string
    {
        return $section->value.'|'.$this->nameKey($this->cleanName($writtenName));
    }

    private function productKey(int $categoryId, string $writtenName): string
    {
        return $categoryId.'|'.$this->nameKey($this->cleanName($writtenName));
    }
}
