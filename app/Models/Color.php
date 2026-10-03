<?php

namespace App\Models;

use Database\Factories\ColorFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A color a product can be sold in, carried by its variants and by its pictures.
 *
 * The `code` is the short identifier that tells two colors apart inside a SKU and the
 * `hex` is what the panel paints the swatch with; the name is what the admin reads.
 */
class Color extends Model
{
    /** @use HasFactory<ColorFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'hex',
        'code',
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
     * Only the colors a product can still be sold in.
     *
     * A color that is turned off stops being offered without losing the variants or
     * the pictures that already carry it: they keep pointing at it, which is what
     * the restricting foreign key is there for.
     *
     * @param  Builder<Color>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * In the order the catalog reads colors, with the id breaking the tie between
     * two colors that share a position.
     *
     * It is the one order every list of colors uses, so a form and a listing never
     * read the same set in two different orders.
     *
     * @param  Builder<Color>  $query
     */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('order')->orderBy('id');
    }

    /**
     * Every color of the store, in the order the catalog reads them.
     *
     * @return Collection<int, Color>
     */
    public static function listed(): Collection
    {
        return static::query()->ordered()->get();
    }

    /**
     * The colors a variant can still be created in, in the order the catalog reads
     * them.
     *
     * @return Collection<int, Color>
     */
    public static function listedActive(): Collection
    {
        return static::query()->active()->ordered()->get();
    }

    /**
     * The ids of every color, in the order the catalog reads them.
     *
     * @return list<int>
     */
    public static function orderedIds(): array
    {
        return static::query()->ordered()->pluck('id')->all();
    }

    /**
     * The `order` a color added at the end gets.
     */
    public static function nextOrder(): int
    {
        $highest = static::query()->max('order');

        return $highest === null ? 0 : ((int) $highest) + 1;
    }

    /**
     * The variants sold in this color.
     *
     * @return HasMany<ProductVariant>
     */
    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    /**
     * The product images taken in this color.
     *
     * @return HasMany<ProductImage>
     */
    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class);
    }
}
