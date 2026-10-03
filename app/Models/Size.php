<?php

namespace App\Models;

use Database\Factories\SizeFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A size a category sells, such as `M` or `42`.
 *
 * The sizes belong to a category instead of to the store, because what a garment
 * is sold in depends on what it is: trousers go by number where shirts go by
 * letter, and a size the store adds for one category says nothing about another.
 */
class Size extends Model
{
    /** @use HasFactory<SizeFactory> */
    use HasFactory;

    /**
     * The sizes every category of the store is given when it starts.
     *
     * They are a starting point and not a closed list: a category of trousers adds
     * `42` to its own, and the panel offers whatever the category carries. What this
     * list is for is the seeding: the sizes that already existed when the store moved
     * its sizes into the database are these eight, in this order, so every category
     * reproduces the sequence the panel was showing before.
     *
     * @var list<string>
     */
    public const STANDARD_NAMES = ['XS', 'S', 'M', 'L', 'XL', 'XXL', 'XXXL', 'ÚNICA'];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'category_id',
        'name',
        'order',
        'is_active',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * The category whose garments are sold in this size.
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * The variants sold in this size.
     *
     * @return HasMany<ProductVariant>
     */
    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    /**
     * Only the sizes a variant can still be created in.
     *
     * A size that is turned off stops being offered without losing the variants
     * that are already in it: they keep pointing at it, which is what the
     * restricting foreign key is there for.
     *
     * @param  Builder<Size>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * In the order the catalog reads sizes, with the id breaking the tie between
     * two sizes that share a position.
     *
     * It is the one order every list of sizes uses, so a form and a listing never
     * read the same set in two different orders.
     *
     * @param  Builder<Size>  $query
     */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('order')->orderBy('id');
    }

    /**
     * The sizes of a category, in the order the catalog reads them.
     *
     * The counts are asked for here and not by the listing: a panel that shows how
     * many variants a size holds would otherwise ask for it once per row, which is
     * one query per size on a category that has eight of them.
     *
     * @param  list<string>  $withCount  Relations to count along with the sizes.
     * @return Collection<int, Size>
     */
    public static function listedForCategory(int $categoryId, array $withCount = []): Collection
    {
        return static::query()
            ->where('category_id', $categoryId)
            ->when($withCount !== [], fn (Builder $query) => $query->withCount($withCount))
            ->ordered()
            ->get();
    }

    /**
     * The sizes a variant can still be created in inside a category, in the order the
     * catalog reads them.
     *
     * @return Collection<int, Size>
     */
    public static function listedActiveForCategory(int $categoryId): Collection
    {
        return static::query()
            ->where('category_id', $categoryId)
            ->active()
            ->ordered()
            ->get();
    }

    /**
     * The `order` a size added at the end of a category gets.
     *
     * The list starts at 1 rather than at 0, which is the position the standard
     * sizes of a category are written with, so a category that has only those eight
     * does not end up with a ninth one in front of them.
     */
    public static function nextOrderInCategory(int $categoryId): int
    {
        $highest = static::query()
            ->where('category_id', $categoryId)
            ->max('order');

        return $highest === null ? 1 : ((int) $highest) + 1;
    }

    /**
     * Give a category the sizes the store sells, and say how many were written.
     *
     * A size that is already there is left completely alone, position included, so
     * this is safe to run again over a category that already has its sizes: which is
     * what makes it usable both when a category is created and when the catalog is
     * seeded from the sizes that used to live in a constant.
     */
    public static function seedStandardSizesFor(Category $category): int
    {
        $created = 0;

        foreach (self::STANDARD_NAMES as $index => $name) {
            $size = static::query()->firstOrCreate(
                ['category_id' => $category->getKey(), 'name' => $name],
                ['order' => $index + 1],
            );

            if ($size->wasRecentlyCreated) {
                $created++;
            }
        }

        return $created;
    }
}
