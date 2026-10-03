<?php

namespace App\Actions\ProductDetails;

use App\Actions\ProductDetails\Concerns\DerivesColorCodes;
use App\Actions\ProductDetails\Concerns\NormalizesColorHex;
use App\Actions\ProductDetails\Concerns\NormalizesNames;
use App\Exceptions\DuplicateColorCodeException;
use App\Exceptions\DuplicateColorNameException;
use App\Models\Color;

/**
 * Add a color to the store.
 *
 * Two of the three values are worked out instead of being asked for. The hex follows
 * the name and the code the panel expects from the catalog, and the code is derived
 * from the name unless the admin gives one, which is what happens when a name derives
 * to a code another color already has.
 *
 * The new color is active and goes to the end of the list, so it is offered from the
 * moment it is created without being put in front of the colors the store already had.
 */
class CreateColor
{
    use DerivesColorCodes;
    use NormalizesColorHex;
    use NormalizesNames;

    /**
     * Write the color, with the code it can be told apart by.
     */
    public function __invoke(string $name, string $hex, ?string $code = null): Color
    {
        $name = $this->cleanName($name);
        $hex = $this->normalizeColorHex($hex);

        $this->guardNameIsFree($name);

        $code = $this->resolveCode($code, $name);

        return Color::query()->create([
            'name' => $name,
            'hex' => $hex,
            'code' => $code,
            'order' => Color::nextOrder(),
            'is_active' => true,
        ]);
    }

    /**
     * The code the color is written with: the one the admin gave, or the one derived
     * from the name.
     */
    private function resolveCode(?string $code, string $name): string
    {
        if ($code === null || trim($code) === '') {
            return $this->uniqueColorCode($name);
        }

        $normalized = $this->normalizeColorCode($code);

        if ($this->colorCodeIsTaken($normalized)) {
            throw DuplicateColorCodeException::forCode($normalized);
        }

        return $normalized;
    }

    /**
     * Refuse a name the store already has a color called.
     */
    private function guardNameIsFree(string $name): void
    {
        if ($this->nameIsTaken(Color::query()->pluck('name'), $name)) {
            throw DuplicateColorNameException::forName($name);
        }
    }
}
