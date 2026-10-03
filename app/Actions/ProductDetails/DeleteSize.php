<?php

namespace App\Actions\ProductDetails;

use App\Exceptions\SizeInUseException;
use App\Models\Size;
use Illuminate\Support\Facades\DB;

/**
 * Delete a size nobody is selling in.
 *
 * The foreign key of `product_variants.size_id` restricts the delete, so a size with
 * variants could not be removed even without this check; it is here to say how many
 * variants are in the way and to name the way out. Turning the size off takes it out
 * of the offer and keeps the variants, which is what deleting it would not do.
 */
class DeleteSize
{
    /**
     * Remove the size, once it is known that nothing points at it.
     */
    public function __invoke(Size $size): void
    {
        DB::transaction(function () use ($size): void {
            $locked = Size::query()->lockForUpdate()->findOrFail($size->getKey());

            $variants = $locked->variants()->count();

            if ($variants > 0) {
                throw SizeInUseException::forSize($locked, $variants);
            }

            $locked->delete();
        });
    }
}
