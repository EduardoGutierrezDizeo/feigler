<?php

namespace App\Actions\Products\Concerns;

use App\Exceptions\InactiveVariantColorException;
use App\Models\Color;
use App\Models\ProductVariant;

/**
 * The color rule shared by creating and editing a variant.
 *
 * Turning a color off is how the store takes it out of the offer, and a variant in a
 * color that is not offered is not for sale in anything. The rule has one exception,
 * and it is the reason the two cases are told apart here instead of in one check: a
 * variant that is already in a deactivated color stays in it, because the store is
 * still selling what it already has. Only a variant that is moving there, or being
 * created there, is refused.
 *
 * A variant without a color is left alone. Nothing is offered in no color, but nothing
 * is refused either, and that is not this check's business.
 */
trait GuardsVariantColor
{
    /**
     * Refuse a color that is off, unless the variant is already in it.
     */
    private function guardColorIsSettable(Color $color, ?ProductVariant $variant = null): void
    {
        $keepsItsColor = $variant !== null && (int) $variant->color_id === (int) $color->getKey();

        if (! $color->is_active && ! $keepsItsColor) {
            throw InactiveVariantColorException::forColor($color);
        }
    }
}
