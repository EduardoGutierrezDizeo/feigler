<?php

namespace App\Models;

use App\Exceptions\InsufficientStockException;
use Database\Factories\ProductVariantFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

class ProductVariant extends Model
{
    /** @use HasFactory<ProductVariantFactory> */
    use HasFactory;

    /**
     * The movement types that take units out of the stock of a variant.
     *
     * Everything else puts them back. The movement carries the sign, so reading a
     * report is a plain sum: a negative row is stock that left, a positive one is
     * stock that came back.
     *
     * @var list<string>
     */
    public const STOCK_REDUCING_TYPES = ['venta_online', 'venta_pos', 'ajuste_salida'];

    /**
     * The movement types that put units back into the stock of a variant.
     *
     * @var list<string>
     */
    public const STOCK_ADDING_TYPES = ['ajuste_entrada', 'devolucion'];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'product_id',
        'size_id',
        'color_id',
        'sku',
        'stock',
        'price_override',
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
            'stock' => 'integer',
            'price_override' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    /**
     * The product this variant belongs to.
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * The color this variant is sold in.
     */
    public function color(): BelongsTo
    {
        return $this->belongsTo(Color::class);
    }

    /**
     * The size this variant is sold in, among the sizes the category of its product
     * offers.
     *
     * The relation took the name of the column it replaced: while `size` was a free
     * text column this could not be called `size`, because a relation of that name
     * would collide with the attribute of the same name and one of the two would
     * always read the wrong thing.
     */
    public function size(): BelongsTo
    {
        return $this->belongsTo(Size::class, 'size_id');
    }

    /**
     * The images that show this variant, which are the images of its color.
     *
     * A color is shared by every size of a product, so a photo taken in, say,
     * blue is worth showing for the blue XL as well. The photos are not owned by
     * the variant: they belong to the product and are attached to the color.
     *
     * @return HasMany<ProductImage>
     */
    public function gallery(): HasMany
    {
        return $this->product->images()
            ->where('color_id', $this->color_id)
            ->orderBy('order')
            ->orderBy('id');
    }

    /**
     * The wishlist entries that point at this variant.
     *
     * @return HasMany<Wishlist>
     */
    public function wishlists(): HasMany
    {
        return $this->hasMany(Wishlist::class);
    }

    /**
     * The order lines where this variant was sold.
     *
     * @return HasMany<OrderItem>
     */
    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * The inventory movements that changed this variant's stock.
     *
     * @return HasMany<InventoryMovement>
     */
    public function inventoryMovements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class);
    }

    /**
     * The SKU of a size/color combination: the reference of the product, the size
     * and the code of the color, e.g. `PL-001-M-AZU`.
     *
     * The name of the size is normalized because it reaches this method from the
     * database, where the store typed it: `m`, ` m ` and `M` are the same size and
     * have to produce the same SKU, or the same garment would end up with two
     * different codes. The normalization is left exactly as it was when the size
     * arrived as free text, so the SKU of a variant created before the sizes moved
     * into their own table is the SKU of one created after.
     *
     * The reference is required and is never invented here. A reference that does
     * not exist yet means the product was never persisted, and numbering it on the
     * spot would hand out a reference that nobody stored, so every SKU built from
     * it would point at a product that does not exist either.
     *
     * @throws LogicException when the product has no reference
     */
    public static function makeSku(Product $product, Size $size, Color $color): string
    {
        if (blank($product->reference)) {
            throw new LogicException("El producto «{$product->name}» no tiene referencia; no se puede generar el SKU.");
        }

        $normalizedSize = Str::upper(preg_replace('/\s+/', '', $size->name) ?? $size->name);

        return "{$product->reference}-{$normalizedSize}-{$color->code}";
    }

    /**
     * Move the stock of this variant and leave a movement behind saying why.
     *
     * The caller passes the magnitude, always positive, and the type decides which
     * way the stock goes; the movement then stores that same amount signed, the way
     * `InventoryMovement` has always stored it. Stock is read again with
     * `lockForUpdate` inside the transaction instead of trusting the in-memory
     * value, so two concurrent sales cannot both read the same stock and both
     * succeed. A movement that would push the stock below zero is refused before
     * anything is written.
     */
    public function recordStockChange(string $type, int $quantity, ?int $userId = null, ?string $note = null, ?int $orderId = null): InventoryMovement
    {
        if ($quantity <= 0) {
            throw new InvalidArgumentException("La cantidad de un movimiento de inventario debe ser mayor que cero, se recibió {$quantity}.");
        }

        if (! in_array($type, array_merge(self::STOCK_REDUCING_TYPES, self::STOCK_ADDING_TYPES), true)) {
            throw new InvalidArgumentException("El tipo de movimiento «{$type}» no está permitido.");
        }

        $signedQuantity = static::signedQuantityFor($type, $quantity);

        [$movement, $newStock] = DB::transaction(function () use ($type, $signedQuantity, $userId, $note, $orderId) {
            $variant = static::query()->lockForUpdate()->findOrFail($this->getKey());

            $newStock = $variant->stock + $signedQuantity;

            if ($newStock < 0) {
                throw InsufficientStockException::forVariant($variant, abs($signedQuantity));
            }

            $variant->update(['stock' => $newStock]);

            $movement = InventoryMovement::create([
                'product_variant_id' => $variant->getKey(),
                'type' => $type,
                'quantity' => $signedQuantity,
                'order_id' => $orderId,
                'user_id' => $userId,
                'note' => $note,
            ]);

            return [$movement, $newStock];
        });

        $this->setAttribute('stock', $newStock);

        return $movement;
    }

    /**
     * The amount a movement of this type stores: negative for the types that take
     * units out, positive for the ones that put them back.
     */
    private static function signedQuantityFor(string $type, int $magnitude): int
    {
        return in_array($type, self::STOCK_REDUCING_TYPES, true) ? -$magnitude : $magnitude;
    }
}
