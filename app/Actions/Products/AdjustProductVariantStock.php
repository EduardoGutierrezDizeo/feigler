<?php

namespace App\Actions\Products;

use App\Exceptions\InvalidStockAdjustmentException;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class AdjustProductVariantStock
{
    /**
     * The longest a reason can be, which is the length of the note column.
     */
    public const MAX_REASON_LENGTH = 255;

    /**
     * Move the stock of a variant up or down and say why.
     *
     * The delta is signed: positive is merchandise coming in, negative is
     * merchandise leaving. The movement type follows the sign, and the movement
     * stores the same signed amount, so reading the history of a variant is a
     * plain sum of its movements.
     *
     * The variant is resolved through the product, and `recordStockChange` reads
     * the stock again with `lockForUpdate` inside the transaction instead of
     * trusting the copy held in memory, so two adjustments at the same time
     * cannot both read the same stock and both leave the wrong number behind.
     */
    public function __invoke(
        Product $product,
        int $variantId,
        int $delta,
        string $reason,
        ?User $user = null,
    ): ProductVariant {
        if ($delta === 0) {
            throw InvalidStockAdjustmentException::zeroDelta();
        }

        $reason = trim($reason);

        if ($reason === '') {
            throw InvalidStockAdjustmentException::missingReason();
        }

        if (mb_strlen($reason) > self::MAX_REASON_LENGTH) {
            throw InvalidStockAdjustmentException::reasonTooLong(self::MAX_REASON_LENGTH);
        }

        return DB::transaction(function () use ($product, $variantId, $delta, $reason, $user): ProductVariant {
            $variant = $product->variants()->findOrFail($variantId);

            $variant->recordStockChange(
                $delta > 0 ? 'ajuste_entrada' : 'ajuste_salida',
                abs($delta),
                $user?->getKey(),
                $reason,
            );

            return $variant;
        });
    }
}
