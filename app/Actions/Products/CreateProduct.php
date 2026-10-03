<?php

namespace App\Actions\Products;

use App\Actions\ProductDetails\SyncProductMaterials;
use App\Exceptions\InvalidProductNameException;
use App\Exceptions\InvalidProductStatusException;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreateProduct
{
    /**
     * The blank the brand is allowed to be left in: the column is not nullable
     * and the catalog is Feigler's own, so a product with no brand of its own is
     * a Feigler one.
     */
    public const DEFAULT_BRAND = 'Feigler';

    /**
     * The state a product is born in, which is also the default of the column.
     */
    public const INITIAL_STATUS = 'active';

    /**
     * The states the `status` column may hold.
     *
     * `out_of_stock` is deliberately absent: it is what `Product::display_status`
     * computes when the stock runs out, and a stored copy would go stale on the
     * next movement.
     *
     * @var list<string>
     */
    public const WRITABLE_STATUSES = ['active', 'inactive'];

    /**
     * Add a product to a category.
     *
     * The data arrives already validated, under the column names: the caller
     * decides how it is checked — the panel asks Livewire for it, an import
     * checks a spreadsheet — and this action only enforces what a product is:
     * a name that can be turned into a slug, a slug nobody else is using, the
     * brand of the store when the one typed is blank, a status the column can
     * hold, and a reference of its own.
     *
     * The composition is not checked here either: it is passed straight to
     * `SyncProductMaterials`, which is the one that knows what a garment made of
     * several materials is.
     *
     * The reference is reserved inside the transaction that writes the row, which
     * is what keeps the lock `nextReferenceFor` takes on the category alive until
     * the product exists. Outside of it, two products created at the same time
     * would both read the same counter and collide on the unique reference. When
     * the caller has already opened a transaction this nests into it as a
     * savepoint, and the lock then lasts as long as that outer one: that is what
     * lets an import number several products of the same category in a row
     * without either of them repeating a reference.
     *
     * The product comes back active when no status is given, and with no variants
     * and no images: a product is born as a row of general data, and everything
     * hanging off it is added afterwards.
     *
     * The composition is written inside the transaction that creates the row, so a
     * product is never left in the catalog describing materials it refused. The empty
     * list is the default and is a composition too: a garment nobody has described
     * yet is a garment with no materials, not a garment with a wrong one.
     *
     * @param  array{
     *     name: string,
     *     description?: string|null,
     *     brand?: string|null,
     *     base_price: string|int|float,
     *     status?: string|null
     * }  $data
     * @param  list<array{id: int, percentage: int|string|float}>  $composition
     */
    public function __invoke(Category $category, array $data, array $composition = []): Product
    {
        $slug = $this->uniqueSlugFor($data['name']);

        $status = $data['status'] ?? self::INITIAL_STATUS;

        $this->guardStatusIsWritable($status);

        return DB::transaction(function () use ($category, $data, $slug, $status, $composition): Product {
            $product = Product::create([
                'category_id' => $category->getKey(),
                'name' => trim($data['name']),
                'slug' => $slug,
                'reference' => Product::nextReferenceFor($category),
                'description' => $this->textOrNull($data['description'] ?? null),
                'brand' => $this->brandOrDefault($data['brand'] ?? null),
                'base_price' => $data['base_price'],
                'status' => $status,
            ]);

            // El sync abre su propia transacción, que aquí anida como savepoint: la
            // fila y su composición se escriben juntas o no se escribe ninguna.
            (new SyncProductMaterials)($product, $composition);

            return $product;
        });
    }

    /**
     * The slug of a product name, with a numeric suffix when the name is already
     * taken. Two products may well share a name, and `products.slug` is unique, so
     * the second «Polo básico» becomes `polo-basico-2` instead of failing to save.
     */
    private function uniqueSlugFor(string $name): string
    {
        $base = Str::slug($name);

        if ($base === '') {
            throw InvalidProductNameException::withoutLettersOrNumbers();
        }

        $slug = $base;
        $suffix = 2;

        while (Product::query()->where('slug', $slug)->exists()) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }

    /**
     * The name of the store when the brand is left blank.
     *
     * The blank is measured after trimming, so a brand of spaces is as blank as a
     * missing one instead of being stored as whitespace.
     */
    private function brandOrDefault(?string $brand): string
    {
        $brand = $this->textOrNull($brand);

        return $brand !== null && $brand !== '' ? $brand : self::DEFAULT_BRAND;
    }

    /**
     * A sentence as the columns hold it, with the spaces around it taken off and
     * a value that is not there left as none.
     */
    private function textOrNull(?string $text): ?string
    {
        return $text !== null ? trim($text) : null;
    }

    /**
     * Refuse a status the column cannot be trusted to hold.
     *
     * `out_of_stock` is written by nobody: it is what the stock of the variants
     * computes, and a stored copy would still say "sold out" after the next
     * delivery.
     */
    private function guardStatusIsWritable(string $status): void
    {
        if (! in_array($status, self::WRITABLE_STATUSES, true)) {
            throw InvalidProductStatusException::notWritable($status);
        }
    }
}
