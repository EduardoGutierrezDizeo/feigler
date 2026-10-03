<?php

namespace App\Actions\ProductDetails;

use App\Actions\ProductDetails\Concerns\NormalizesNames;
use App\Exceptions\DuplicateSizeNameException;
use App\Exceptions\SizeNameLockedException;
use App\Models\Size;
use Illuminate\Support\Facades\DB;

/**
 * Rename a size, when nothing that depends on its name is holding it back.
 *
 * The name of a size that has variants in it is part of the SKU of those variants,
 * and the SKU is never rewritten, so a rename would leave every one of them saying a
 * size the store does not sell. That case is refused with the way out named; a size
 * nobody is selling in can be renamed freely.
 *
 * A name that is not really a new one is not a rename at all: rewriting `M` as `m`, or
 * with the spaces around it, is accepted silently and the stored spelling is kept, so
 * the size keeps reading the same as the SKUs that were built from it and the lock on
 * its name is not tripped by a save that changes nothing.
 */
class UpdateSize
{
    use NormalizesNames;

    /**
     * Give the size a new name, leaving its position and its state alone.
     */
    public function __invoke(Size $size, string $name): Size
    {
        $name = $this->cleanName($name);

        return DB::transaction(function () use ($size, $name): Size {
            $locked = Size::query()->lockForUpdate()->findOrFail($size->getKey());

            // The comparison is by key, so `única` against `ÚNICA` counts as the same
            // name and does not trip the lock of a size nobody is selling in.
            if ($this->nameIsTheSame($locked->name, $name)) {
                return $locked;
            }

            $this->guardNameIsNotInUse($locked);
            $this->guardNameIsFree($locked, $name);

            $locked->update(['name' => $name]);

            return $locked;
        });
    }

    /**
     * Refuse a rename while variants are sold in the size.
     */
    private function guardNameIsNotInUse(Size $size): void
    {
        if ($size->variants()->exists()) {
            throw SizeNameLockedException::forSize($size);
        }
    }

    /**
     * Refuse a name another size of the same category already carries.
     *
     * The size being edited is left out of the search, or it would find itself and
     * refuse every rename.
     */
    private function guardNameIsFree(Size $size, string $name): void
    {
        $otherNames = $size->category->sizes()
            ->whereKeyNot($size->getKey())
            ->pluck('name');

        if ($this->nameIsTaken($otherNames, $name)) {
            throw DuplicateSizeNameException::forName($size->category, $name);
        }
    }
}
