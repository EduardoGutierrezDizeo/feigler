<?php

namespace App\Actions\ProductDetails\Concerns;

use App\Exceptions\ColorCodeUnavailableException;
use App\Models\Color;
use Illuminate\Support\Str;

/**
 * Working out the code that tells two colors apart.
 *
 * A color code is not a piece of data the admin is asked to invent: it is derived from
 * the name, so `Azul` becomes `AZU` and `Blanco` becomes `BLA`, and the admin only
 * types the name. When the derived code is already taken, it is not an error either —
 * `Azul claro` and `Azul oscuro` both want `AZU`, so the two leading characters are
 * kept and the third is tried, which is what this concern walks through.
 *
 * The rule is the one the catalog migration already used when it filled the code of
 * every existing color, so the colors written then and the colors written now come out
 * with the same code for the same name.
 */
trait DerivesColorCodes
{
    /**
     * Give a color the code it can be told apart by.
     *
     * The letters and digits of the name are what the code is made of, so anything else
     * it contains is dropped: `Café con leche` gives `CAF`. A name with fewer than
     * three of them is padded on the right with `X`, because a code is three characters
     * long even when the name is not, and it is the width of the code that the variants
     * of a store have been carrying.
     *
     * When the code is taken, the two leading characters are kept and the third one
     * goes through the digits and then through the letters, which is enough for far
     * more colors than a store sells. Running out of them is the one case that has no
     * code, and it is reported instead of returning something already taken.
     */
    protected function uniqueColorCode(string $name): string
    {
        $lettersAndDigits = preg_replace('/[^A-Za-z0-9]/', '', $name) ?? '';

        $base = Str::upper(Str::substr(Str::padRight($lettersAndDigits, 3, 'X'), 0, 3));

        if (! $this->colorCodeIsTaken($base)) {
            return $base;
        }

        $prefix = Str::substr($base, 0, 2);

        foreach (array_merge(range('0', '9'), range('A', 'Z')) as $character) {
            $candidate = $prefix.$character;

            if (! $this->colorCodeIsTaken($candidate)) {
                return $candidate;
            }
        }

        throw ColorCodeUnavailableException::forName($name);
    }

    /**
     * Whether a color already carries this code.
     *
     * The comparison is on the normalized code, so `azu` does not get past `AZU`.
     */
    protected function colorCodeIsTaken(string $code): bool
    {
        return Color::query()->where('code', $this->normalizeColorCode($code))->exists();
    }

    /**
     * The shape a code is stored with: three characters, upper case, no spaces.
     */
    protected function normalizeColorCode(string $code): string
    {
        $lettersAndDigits = preg_replace('/[^A-Za-z0-9]/', '', $code) ?? '';

        return Str::upper(Str::substr(Str::padRight($lettersAndDigits, 3, 'X'), 0, 3));
    }
}
