<?php

namespace App\Actions\ProductDetails;

use App\Exceptions\MaterialInUseException;
use App\Models\Material;
use Illuminate\Support\Facades\DB;

/**
 * Delete a material no product is made of.
 *
 * The foreign key of `material_product.material_id` restricts the delete, so the row
 * could not be removed even without this check; it is here to say how many garments
 * describe their composition with it and to name the way out. Turning the material off
 * takes it out of the offer and leaves those products saying what they are made of,
 * which is what deleting it would not do.
 */
class DeleteMaterial
{
    /**
     * Remove the material, once it is known that nothing points at it.
     */
    public function __invoke(Material $material): void
    {
        DB::transaction(function () use ($material): void {
            $locked = Material::query()->lockForUpdate()->findOrFail($material->getKey());

            $products = $locked->products()->count();

            if ($products > 0) {
                throw MaterialInUseException::forMaterial($locked, $products);
            }

            $locked->delete();
        });
    }
}
