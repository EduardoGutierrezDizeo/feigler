<?php

namespace App\Actions\ProductDetails;

use App\Actions\ProductDetails\Concerns\DerivesColorCodes;
use App\Actions\ProductDetails\Concerns\NormalizesColorHex;
use App\Exceptions\ColorCodeLockedException;
use App\Exceptions\DuplicateColorCodeException;
use App\Exceptions\DuplicateColorNameException;
use App\Models\Color;
use App\Support\Concerns\NormalizesNames;
use Illuminate\Support\Facades\DB;

/**
 * Edit a color, as far as what the catalog depends on allows.
 *
 * The name and the hex of a color can change: nothing reads them into a SKU, and the
 * hex is what the panel paints the swatch with. The code cannot, while variants are
 * sold in the color, because it is part of the SKU of those variants and the SKU is
 * never rewritten. That case is refused with the way out named, which is to add the
 * new color instead of renaming this one.
 *
 * Writing the name that is already there is not an edit: it is accepted silently,
 * because the row would read the same.
 */
class UpdateColor
{
    use DerivesColorCodes;
    use NormalizesColorHex;
    use NormalizesNames;

    /**
     * Apply the new name and hex, leaving the position and the state alone.
     *
     * The code is only written when the admin typed one, so editing the hex of a color
     * cannot silently move it to a code that was not asked for.
     */
    public function __invoke(Color $color, string $name, string $hex, ?string $code = null): Color
    {
        $name = $this->cleanName($name);
        $hex = $this->normalizeColorHex($hex);
        $code = $code === null || trim($code) === '' ? null : $this->normalizeColorCode($code);

        return DB::transaction(function () use ($color, $name, $hex, $code): Color {
            $locked = Color::query()->lockForUpdate()->findOrFail($color->getKey());

            $changes = ['name' => $name, 'hex' => $hex];

            $this->guardNameIsFree($locked, $name);
            $this->applyCode($locked, $code, $changes);

            $locked->update($changes);

            return $locked;
        });
    }

    /**
     * Write the code, refusing the two things that make it impossible.
     *
     * A code the color already has is not written, so saving a color without touching
     * its code does not go through the lock that refuses changes while variants exist.
     */
    private function applyCode(Color $color, ?string $code, array &$changes): void
    {
        if ($code === null || $code === $color->code) {
            return;
        }

        if ($color->variants()->exists()) {
            throw ColorCodeLockedException::forColor($color);
        }

        if ($this->colorCodeIsTaken($code)) {
            throw DuplicateColorCodeException::forCode($code);
        }

        $changes['code'] = $code;
    }

    /**
     * Refuse a name another color already has, leaving the color being edited out of
     * the search so it does not find itself.
     */
    private function guardNameIsFree(Color $color, string $name): void
    {
        if ($this->nameIsTheSame($color->name, $name)) {
            return;
        }

        $otherNames = Color::query()->whereKeyNot($color->getKey())->pluck('name');

        if ($this->nameIsTaken($otherNames, $name)) {
            throw DuplicateColorNameException::forName($name);
        }
    }
}
