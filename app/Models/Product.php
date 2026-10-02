<?php

namespace App\Models;

use App\Enums\StoreSection;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'category_id',
        'name',
        'slug',
        'reference',
        'description',
        'brand',
        'material',
        'base_price',
        'status',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'base_price' => 'decimal:2',
        ];
    }

    /**
     * Give the product a reference when nobody provided one.
     *
     * It only happens on insert: a product that already has a reference keeps it
     * even if it is moved to another category, because its SKUs were built from it.
     */
    protected static function booted(): void
    {
        static::creating(function (self $product): void {
            if (filled($product->reference) || $product->category === null) {
                return;
            }

            $product->reference = static::nextReferenceFor($product->category);
        });
    }

    /**
     * The category the product belongs to.
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * The sellable variants (size/color combinations) of the product.
     *
     * @return HasMany<ProductVariant>
     */
    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    /**
     * The images attached to the product as a whole.
     *
     * @return HasMany<ProductImage>
     */
    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class);
    }

    /**
     * The store section the product belongs to, inherited from its category.
     *
     * It is never stored on the product: the catalog is divided into sections by
     * the categories, and a product is in a section for as long as it sits in a
     * category of that section.
     */
    protected function section(): Attribute
    {
        return Attribute::get(fn (): ?StoreSection => $this->category?->section);
    }

    /**
     * The next free reference for the products of this category: its SKU prefix
     * plus a counter of at least three digits, e.g. `PLH-001`, `PLH-002`.
     *
     * The prefix belongs to the category and to nobody else, which is what makes
     * the series independent: `PL-001` (a category of the old catalog) is left
     * out of the count of `PLH`, because the match demands the prefix followed by
     * a dash and nothing else.
     *
     * The counter is the highest number already used under that prefix plus one,
     * read and compared as a number instead of as text. Ordering references as text
     * breaks at the fourth digit, where `PLH-1000` sorts before `PLH-999` and the next
     * product would be numbered `PLH-001` all over again. References that are not
     * `PREFIX-` followed by digits are left out of the count.
     *
     * The category row is locked for the whole transaction. Locking only the
     * last product would not be enough: with no product yet there is no row to
     * lock, so two products created at the same time would both read "none" and
     * both claim `PLH-001`. The category row always exists, and every product that
     * wants a `PLH-` reference queues behind it.
     *
     * Callers creating a product must do it inside `DB::transaction()`. The lock
     * lives no longer than the outermost transaction, and the read of the counter
     * is only useful while it is held: without an outer transaction the lock is
     * released as soon as this method returns, and the INSERT that follows lands
     * outside of it, which is exactly the race the lock was there to prevent.
     */
    public static function nextReferenceFor(Category $category): string
    {
        $prefix = $category->sku_prefix;

        return DB::transaction(function () use ($category, $prefix) {
            $category->newQuery()->lockForUpdate()->findOrFail($category->getKey());

            $highest = 0;

            foreach (static::referencesInSeries($prefix) as $reference) {
                if (preg_match('/^'.preg_quote($prefix, '/').'-(\d+)$/', $reference, $matches) !== 1) {
                    continue;
                }

                $highest = max($highest, (int) $matches[1]);
            }

            return $prefix.'-'.str_pad((string) ($highest + 1), 3, '0', STR_PAD_LEFT);
        });
    }

    /**
     * The references already taken in this series, locked until the transaction
     * that asked for them ends.
     *
     * @return list<string>
     */
    private static function referencesInSeries(string $prefix): array
    {
        return static::query()
            ->where('reference', 'like', $prefix.'-%')
            ->lockForUpdate()
            ->pluck('reference')
            ->all();
    }

    /**
     * The units available for sale: the stock of the active variants only.
     *
     * An inactive variant is out of the catalog, so counting it would advertise
     * stock nobody can buy.
     */
    protected function stockTotal(): Attribute
    {
        return Attribute::get(fn (): int => (int) $this->variants
            ->where('is_active', true)
            ->sum('stock'));
    }

    /**
     * The status to show, which is not always the stored one.
     *
     * `out_of_stock` is never written to the `status` column: it is what happens
     * when the stock runs out, and a stored copy would go stale the moment a
     * movement is recorded. A stored `out_of_stock` is ignored on purpose, since
     * those are rows written before the status became computed.
     *
     * The variant count is read from the `variants` relation whenever it is already
     * loaded, and only falls back to a query when it is not. A listing eager-loads
     * the relation to read `stock_total`, so asking the database again here would
     * put one query per row back into a table that had none.
     */
    protected function displayStatus(): Attribute
    {
        return Attribute::get(function (): string {
            if ($this->status === 'inactive') {
                return 'inactive';
            }

            $hasVariants = $this->relationLoaded('variants')
                ? $this->variants->isNotEmpty()
                : $this->variants()->exists();

            if (! $hasVariants) {
                return 'no_variants';
            }

            return $this->stock_total <= 0 ? 'out_of_stock' : 'active';
        });
    }

    /**
     * The images taken in this color.
     *
     * @return HasMany<ProductImage>
     */
    public function imagesForColor(Color $color): HasMany
    {
        return $this->images()
            ->where('color_id', $color->getKey())
            ->orderBy('order');
    }

    /**
     * The colors this product is sold in, in the order they first show up.
     *
     * @return Collection<int, Color>
     */
    public function colors(): Collection
    {
        return Color::query()
            ->whereHas('variants', fn ($query) => $query->where('product_id', $this->getKey()))
            ->orderBy('colors.id')
            ->get();
    }
}
