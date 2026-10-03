<?php

namespace App\Actions\ProductDetails;

use App\Exceptions\DuplicateNormalizedSizeException;
use App\Models\Category;
use App\Models\Color;
use App\Models\Material;
use App\Models\ProductVariant;
use App\Models\Size;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Copy the sizes, the color order and the materials of the catalog that today live
 * in a constant, in a bare column and nowhere at all, into the structures that
 * administer them.
 *
 * Every method is idempotent on purpose: running it twice writes nothing the
 * second time, so a failed copy can be retried without cleaning up first.
 */
class BackfillProductDetails
{
    /**
     * The percentage stored for a product whose material was a single free text
     * value: all of it, because there is nothing to divide.
     */
    private const WHOLE_PRODUCT_PERCENTAGE = 100;

    /**
     * Give every category the sizes the store sells, and point every variant at
     * the size it is already written as.
     *
     * Each category starts with the eight sizes of `Size::STANDARD_NAMES`, in that
     * order and with `order` 1 to 8, so the new tables reproduce the order the panel
     * shows today. A size that appears in a variant but is not one of those eight is
     * added to the category of the product that carries it, at the end of its list,
     * instead of being refused: the variant exists, so the size it is sold in does
     * too.
     *
     * A size is read with its whitespace collapsed and uppercased before it is
     * looked up, so `' xl '` and `'XL'` are one size. That work is done in PHP on
     * purpose: SQLite compares text byte by byte and would see them as two rows,
     * while MySQL sees them as one, and normalizing in a single language is what
     * keeps both drivers pointing at the same `sizes` row.
     *
     * Two variants of the same product and color whose sizes normalize to the same
     * value cannot both be copied, because they would share one `sizes` row. The
     * copy is refused before anything is written, and the whole method runs inside a
     * transaction so a failure anywhere leaves the catalog untouched.
     *
     * @return array{created: int, assigned: int, skipped: int} `created` counts the
     *                                                          `sizes` rows written,
     *                                                          `assigned` the
     *                                                          variants that got a
     *                                                          `size_id` and
     *                                                          `skipped` the variants
     *                                                          whose size is blank
     */
    public function sizes(): array
    {
        return DB::transaction(function (): array {
            $created = $this->createStandardSizes();

            $written = $this->sizeTextIsStillReadable()
                ? $this->assignSizes()
                : ['assigned' => 0, 'skipped' => 0];

            return ['created' => $created] + $written;
        });
    }

    /**
     * Point every variant that is not pointing at a size yet at the size its text
     * says, creating the size in the category of its product when the category does
     * not carry it.
     *
     * @return array{assigned: int, skipped: int}
     */
    private function assignSizes(): array
    {
        $pending = ProductVariant::query()
            ->whereNull('size_id')
            ->with(['product.category', 'color'])
            ->orderBy('id')
            ->get();

        $this->guardNoSizeCollisions($pending);

        $assigned = 0;
        $skipped = 0;

        foreach ($pending as $variant) {
            $name = $this->normalizeSizeText((string) $variant->size);

            if ($name === '') {
                $skipped++;

                continue;
            }

            $variant->update([
                'size_id' => $this->resolveSizeFor($variant, $name)->getKey(),
            ]);

            $assigned++;
        }

        return ['assigned' => $assigned, 'skipped' => $skipped];
    }

    /**
     * Whether the free text of `product_variants.size` is still there to be read.
     *
     * The column is dropped once every variant points at a size, and this copy has to
     * stay runnable after that: it is called again by the migration that drops it, and
     * a `migrate:fresh` replays it from the top. Reading a column that is gone would
     * turn a no-op into a driver error, so the copy asks first and only creates the
     * standard sizes when there is nothing left to attribute.
     */
    private function sizeTextIsStillReadable(): bool
    {
        return Schema::hasColumn('product_variants', 'size');
    }

    /**
     * Give the colors a consecutive order, so the panel can list them in an order
     * the store chooses instead of in alphabetical order.
     *
     * The order follows the way the panel reads colors today, name and id, so the
     * sequence that comes out is the one the store was already looking at. Only the
     * colors still sitting at zero are numbered, which is what makes a second run a
     * no-op. `is_active` is left alone: the column arrives with every color
     * enabled and nothing here turns one off.
     *
     * @return array{assigned: int} the colors that received a position
     */
    public function colors(): array
    {
        return DB::transaction(function (): array {
            $pending = Color::query()
                ->where('order', 0)
                ->orderBy('name')
                ->orderBy('id')
                ->get();

            $position = 0;

            foreach ($pending as $color) {
                $position++;

                $color->update(['order' => $position]);
            }

            return ['assigned' => $position];
        });
    }

    /**
     * Turn the free text of `products.material` into materials of their own and
     * attach each product to the one it was naming.
     *
     * Two values that differ only in uppercase, surrounding spaces or accents are one
     * material, and the first of them in the order the products were written is the
     * one that is kept. Everything a product carries goes in as a single material
     * with the whole product in its percentage: a value that reads like
     * `80% algodón, 20% poliéster` is copied verbatim rather than guessed at, and it
     * is reported in `listed` so it can be split by hand afterwards.
     *
     * @return array{created: int, assigned: int, listed: list<string>} `listed`
     *                                                                  holds the copied
     *                                                                  values that look like
     *                                                                  they name more than one
     *                                                                  material
     */
    public function materials(): array
    {
        return DB::transaction(function (): array {
            $created = 0;
            $assigned = 0;
            $listed = [];

            foreach ($this->materialGroups() as $group) {
                $material = Material::query()->where('name', $group['name'])->first();

                if ($material === null) {
                    $material = Material::query()->create([
                        'name' => $group['name'],
                        'order' => $this->nextMaterialOrder(),
                    ]);

                    $created++;
                }

                $assigned += $this->attachToProducts($material, $group['productIds']);

                if ($this->namesMoreThanOneMaterial($group['name'])) {
                    $listed[] = $group['name'];
                }
            }

            $listed = array_values(array_unique($listed));
            sort($listed);

            return ['created' => $created, 'assigned' => $assigned, 'listed' => $listed];
        });
    }

    /**
     * The number of `sizes` rows written for the standard sizes.
     *
     * The list itself is not repeated here: every category is asked for the eight
     * sizes it starts with through `Size::seedStandardSizesFor()`, which is also
     * what a category created from the panel goes through. An existing row is left
     * completely alone, position included.
     */
    private function createStandardSizes(): int
    {
        $created = 0;

        foreach (Category::query()->orderBy('id')->get() as $category) {
            $created += Size::seedStandardSizesFor($category);
        }

        return $created;
    }

    /**
     * The size this variant is sold in, created in the category of its product when
     * the category does not carry it yet.
     */
    private function resolveSizeFor(ProductVariant $variant, string $name): Size
    {
        $categoryId = (int) $variant->product->category_id;

        $existing = Size::query()
            ->where('category_id', $categoryId)
            ->where('name', $name)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return Size::query()->create([
            'category_id' => $categoryId,
            'name' => $name,
            'order' => $this->nextSizeOrder($categoryId),
        ]);
    }

    /**
     * Refuse the copy when two variants of the same product and color normalize to
     * the same size.
     *
     * The check reads the whole pending set before the first write, so a collision
     * leaves the catalog exactly as it was and the caller is told which rows are the
     * ones to look at.
     *
     * @param  Collection<int, ProductVariant>  $pending
     */
    private function guardNoSizeCollisions(Collection $pending): void
    {
        $collisions = [];

        $groups = $pending->groupBy(
            fn (ProductVariant $variant): string => $variant->product_id.'|'.$variant->color_id
        );

        foreach ($groups as $variants) {
            $bySize = $variants->groupBy(
                fn (ProductVariant $variant): string => $this->normalizeSizeText((string) $variant->size)
            );

            foreach ($bySize as $sameSize) {
                if ($sameSize->count() < 2) {
                    continue;
                }

                $first = $sameSize->first();

                $collisions[] = [
                    'product' => $first->product->name,
                    'category' => $first->product->category->name,
                    'reference' => $first->product->reference,
                    'color' => $first->color->name,
                    'sizes' => $sameSize
                        ->map(fn (ProductVariant $variant): string => (string) $variant->size)
                        ->unique()
                        ->values()
                        ->all(),
                ];
            }
        }

        if ($collisions !== []) {
            throw DuplicateNormalizedSizeException::forCollisions($collisions);
        }
    }

    /**
     * The size in the shape it is stored as: trimmed, with the whitespace inside it
     * collapsed into one space and uppercased with multibyte rules, so `ÚNICA` and
     * `única` are one size.
     */
    private function normalizeSizeText(string $size): string
    {
        return Str::upper(Str::squish($size));
    }

    /**
     * The `order` a size added at the end of a category gets.
     */
    private function nextSizeOrder(int $categoryId): int
    {
        $highest = Size::query()->where('category_id', $categoryId)->max('order');

        return $highest === null ? 1 : ((int) $highest) + 1;
    }

    /**
     * The `order` a material added at the end of the list gets.
     */
    private function nextMaterialOrder(): int
    {
        $highest = Material::query()->max('order');

        return $highest === null ? 1 : ((int) $highest) + 1;
    }

    /**
     * The distinct materials the products carry, with the products that carry each
     * one, in the order the products were written.
     *
     * The first spelling that shows up is the one kept, and every other spelling of
     * it joins its list of products.
     *
     * @return array<string, array{name: string, productIds: list<int>}>
     */
    private function materialGroups(): array
    {
        $groups = [];

        $rows = DB::table('products')
            ->whereNotNull('material')
            ->orderBy('id')
            ->get(['id', 'material']);

        foreach ($rows as $row) {
            $name = Str::squish((string) $row->material);

            if ($name === '') {
                continue;
            }

            $key = $this->materialKey($name);

            if (! isset($groups[$key])) {
                $groups[$key] = ['name' => $name, 'productIds' => []];
            }

            $groups[$key]['productIds'][] = (int) $row->id;
        }

        return $groups;
    }

    /**
     * What tells two material spellings apart: neither the case, nor the accents,
     * nor the spaces around them.
     */
    private function materialKey(string $name): string
    {
        return Str::lower(Str::ascii(Str::squish($name)));
    }

    /**
     * Whether a copied value reads like it names more than one material or carries
     * its own percentages.
     *
     * Nothing is interpreted here: such a value is copied verbatim as a single
     * material and only reported, because deciding what the numbers mean is a
     * question about the garment, not about the text.
     */
    private function namesMoreThanOneMaterial(string $name): bool
    {
        return preg_match('/[%,\/]/u', $name) === 1;
    }

    /**
     * Attach a material to the products that name it, skipping the pairs that are
     * already there.
     *
     * @param  list<int>  $productIds
     * @return int the pairs written
     */
    private function attachToProducts(Material $material, array $productIds): int
    {
        $written = 0;

        foreach ($productIds as $productId) {
            $alreadyThere = DB::table('material_product')
                ->where('product_id', $productId)
                ->where('material_id', $material->getKey())
                ->exists();

            if ($alreadyThere) {
                continue;
            }

            DB::table('material_product')->insert([
                'product_id' => $productId,
                'material_id' => $material->getKey(),
                'percentage' => self::WHOLE_PRODUCT_PERCENTAGE,
            ]);

            $written++;
        }

        return $written;
    }
}
