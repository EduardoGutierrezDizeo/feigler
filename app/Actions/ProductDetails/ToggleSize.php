<?php

namespace App\Actions\ProductDetails;

use App\Models\Size;

/**
 * Turn a size on or off.
 *
 * Both directions are always allowed, and that is the point of the column: turning a
 * size off is how the store stops offering it without losing the variants already
 * sold in it. A size that variants exist in is the normal case here and not the
 * exception, so nothing about this action can refuse.
 *
 * Turning a size back on does not take any variant with it: the variants that were
 * there are still there, deactivated or not by their own state, and the size simply
 * becomes available to be chosen again.
 */
class ToggleSize
{
    public function __invoke(Size $size): Size
    {
        // The stored value is read back instead of the one the caller happens to hold:
        // a size that was found with `firstOrCreate()` never had `is_active` in its
        // attributes, and toggling a null would turn the size on instead of off.
        $size->refresh();

        $size->update(['is_active' => ! $size->is_active]);

        return $size;
    }
}
