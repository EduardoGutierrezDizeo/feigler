<?php

namespace App\Models;

use Database\Factories\CartItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a cart: the variant a shopper asked for and how many units.
 *
 * A variant appears at most once per cart — the unique pair of the table — so a
 * line never holds two quantities of the same garment in the same cart. The
 * quantity is what the shopper asked for, not what the store can deliver: stock
 * is not reserved, so the line may hold more units than the variant has left,
 * and the storefront marks it as needing adjustment instead of silently
 * changing it.
 */
class CartItem extends Model
{
    /** @use HasFactory<CartItemFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'cart_id',
        'product_variant_id',
        'quantity',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
        ];
    }

    /**
     * The cart this line belongs to.
     */
    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }

    /**
     * The variant this line asks for.
     */
    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }
}
