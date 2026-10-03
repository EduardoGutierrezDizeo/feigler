<?php

namespace App\Actions\ProductDetails;

use App\Exceptions\ColorInUseException;
use App\Models\Color;
use Illuminate\Support\Facades\DB;

/**
 * Delete a color nothing points at.
 *
 * Two foreign keys restrict the delete: the variants sold in the color and the
 * pictures taken in it. Both are counted before the delete so the admin is told how
 * much of each is holding the color, and so the way out — turning it off — is named
 * instead of left to be guessed. Turning it off keeps the variants and the pictures and
 * takes it out of the offer, which is what deleting it would not do.
 */
class DeleteColor
{
    /**
     * Remove the color, once it is known that nothing points at it.
     */
    public function __invoke(Color $color): void
    {
        DB::transaction(function () use ($color): void {
            $locked = Color::query()->lockForUpdate()->findOrFail($color->getKey());

            $variants = $locked->variants()->count();
            $images = $locked->images()->count();

            if ($variants > 0 || $images > 0) {
                throw ColorInUseException::forColor($locked, $variants, $images);
            }

            $locked->delete();
        });
    }
}
