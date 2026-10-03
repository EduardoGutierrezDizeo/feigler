<?php

namespace App\Models;

use Database\Factories\MaterialFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A material a garment is made of, which a product can combine with others.
 *
 * A garment made of several materials carries one of these per material, and the
 * percentage of each is kept in the pivot table: a product whose only material is
 * cotton holds all of it.
 */
class Material extends Model
{
    /** @use HasFactory<MaterialFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
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
     * The products made of this material.
     *
     * @return BelongsToMany<Product, $this>
     */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class)
            ->withPivot('percentage');
    }

    /**
     * Only the materials a product can still be given.
     *
     * @param  Builder<Material>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * In the order the catalog reads materials, with the id breaking the tie between
     * two materials that share a position.
     *
     * It is the one order every list of materials uses, so a form and a listing never
     * read the same set in two different orders.
     *
     * @param  Builder<Material>  $query
     */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('order')->orderBy('id');
    }

    /**
     * Every material of the store, in the order the catalog reads them.
     *
     * @return Collection<int, Material>
     */
    public static function listed(): Collection
    {
        return static::query()->ordered()->get();
    }

    /**
     * The materials a product can still be given, in the order the catalog reads
     * them.
     *
     * @return Collection<int, Material>
     */
    public static function listedActive(): Collection
    {
        return static::query()->active()->ordered()->get();
    }

    /**
     * The ids of every material, in the order the catalog reads them.
     *
     * @return list<int>
     */
    public static function orderedIds(): array
    {
        return static::query()->ordered()->pluck('id')->all();
    }

    /**
     * The `order` a material added at the end gets.
     */
    public static function nextOrder(): int
    {
        $highest = static::query()->max('order');

        return $highest === null ? 0 : ((int) $highest) + 1;
    }
}
