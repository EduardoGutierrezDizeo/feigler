<?php

namespace App\Actions\ProductDetails;

use App\Models\Color;

/**
 * Turn a color on or off.
 *
 * Both directions are always allowed, and that is the point of the column: turning a
 * color off is how the store stops offering it without losing the variants already
 * sold in it or the pictures already taken in it. A color that variants exist in is the
 * normal case here and not the exception, so nothing about this action can refuse.
 */
class ToggleColor
{
    public function __invoke(Color $color): Color
    {
        // The stored value is read back instead of the one the caller happens to hold:
        // a color that was found with `firstOrCreate()` never had `is_active` in its
        // attributes, and toggling a null would turn the color on instead of off.
        $color->refresh();

        $color->update(['is_active' => ! $color->is_active]);

        return $color;
    }
}
