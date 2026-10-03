<?php

namespace App\Models;

use Database\Factories\SizeFactory;
use Illuminate\Database\Eloquent\Builder;
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
}
