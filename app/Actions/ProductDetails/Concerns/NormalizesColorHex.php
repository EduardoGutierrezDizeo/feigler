<?php

namespace App\Actions\ProductDetails\Concerns;

use App\Exceptions\InvalidColorHexException;

/**
 * Writing the hex a color is painted with.
 *
 * The value is stored with a `#` and in upper case, because that is how the panel and
 * the stylesheet read it and how the column was filled before. Both parts are the value
 * rather than a formatting preference: `1a2b3c` and `#1A2B3C` are the same color, and
 * two colors whose hexes differ only in that are the same swatch.
 *
 * A hex without the `#`, or with anything but six hexadecimal digits in it, is not
 * read as a color at all: it is reported instead of being stored as something the panel
 * would paint as nothing.
 */
trait NormalizesColorHex
{
    /**
     * The hex in the form it is stored: `#` and upper case.
     */
    protected function normalizeColorHex(string $hex): string
    {
        $trimmed = trim($hex);

        if (! $this->isColorHex($trimmed)) {
            throw InvalidColorHexException::forValue($hex);
        }

        return '#'.strtoupper(substr($trimmed, 1));
    }

    /**
     * Whether the value is a `#` followed by exactly six hexadecimal digits.
     */
    private function isColorHex(string $value): bool
    {
        return (bool) preg_match('/^#[0-9A-Fa-f]{6}$/', $value);
    }
}
