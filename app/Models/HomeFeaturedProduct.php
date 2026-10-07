<?php

namespace App\Models;

use Database\Factories\HomeFeaturedProductFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una elección manual para las «Novedades» de la portada: qué producto se
 * destaca y en qué lugar de la lista.
 *
 * `order` es la posición (0, 1, 2... n-1) que el panel reindexa entera cada vez
 * que algo entra, sale o se mueve, igual que las categorías dentro de su
 * sección. Máximo cinco hay, nunca: el tope es MAX y solo vive aquí, para que
 * ni la acción, ni el servicio de la portada, ni la vista lo copien.
 */
class HomeFeaturedProduct extends Model
{
    /**
     * Cuántos productos caben como máximo en las novedades manuales.
     */
    public const MAX = 4;

    /** @use HasFactory<HomeFeaturedProductFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'product_id',
        'order',
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
        ];
    }

    /**
     * The product this row features.
     *
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * The `order` a brand new row should take, one past the current maximum.
     *
     * The row is appended at the end of the list; Max+1 keeps the invariant
     * even if a row was ever out of sequence.
     */
    public static function nextOrder(): int
    {
        $max = static::query()->max('order');

        return $max === null ? 0 : ((int) $max) + 1;
    }
}
