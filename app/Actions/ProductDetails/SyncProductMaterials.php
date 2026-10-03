<?php

namespace App\Actions\ProductDetails;

use App\Exceptions\InactiveProductMaterialException;
use App\Exceptions\IncompleteMaterialCompositionException;
use App\Exceptions\InvalidMaterialPercentageException;
use App\Exceptions\MaterialNotFoundException;
use App\Exceptions\RepeatedProductMaterialException;
use App\Models\Material;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

/**
 * Write the whole composition of a garment: which materials it is made of and how much
 * of each.
 *
 * The list replaces the previous one instead of adding to it. The percentages are read
 * as a composition rather than as a set of rows to insert, and a composition is only
 * true when it accounts for all of the garment, so the sum is required to be exactly
 * 100. Letting it land at 95 or 105 would leave the admin reading a product page that
 * does not add up and never finding out which material is the one that is wrong.
 *
 * Every rule is checked before anything is written, and the writes happen in one
 * transaction, so a refused composition leaves the product with the one it already had
 * instead of with half of the new one.
 */
class SyncProductMaterials
{
    /**
     * Give the product exactly this composition.
     *
     * The empty list is a composition too: a garment with no materials is what a
     * product looks like before anyone says what it is made of, and the sync is what
     * the panel calls when the admin empties the list.
     *
     * @param  list<array{id: int, percentage: int|string|float}>  $composition
     */
    public function __invoke(Product $product, array $composition): void
    {
        $lines = $this->validateComposition($product, $composition);

        DB::transaction(function () use ($product, $lines): void {
            $product->materials()->detach();

            if ($lines === []) {
                return;
            }

            $product->materials()->attach($lines);
        });
    }

    /**
     * Check the list and read it back in the shape `attach()` wants it: the material id
     * as the key, `['percentage' => ...]` as the value.
     *
     * The materials are fetched once, here, so every rule is asked against rows that
     * exist instead of against ids taken on faith: a material that is not there and a
     * material the store has turned off are told apart, and a material the product
     * already carries is left alone even when it is off.
     *
     * @param  list<array{id: int, percentage: int|string|float}>  $composition
     * @return array<int, array{percentage: int}>
     */
    private function validateComposition(Product $product, array $composition): array
    {
        $ids = array_map(
            fn (array $line): int => (int) $line['id'],
            $composition
        );

        $this->guardNoMaterialIsRepeated($ids);

        $materials = Material::query()
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        $alreadyAssigned = $product->materials()->pluck('materials.id')->all();

        $lines = [];
        $total = 0;

        foreach ($composition as $line) {
            $id = (int) $line['id'];

            $material = $materials->get($id);

            if (! $material instanceof Material) {
                throw MaterialNotFoundException::forProduct($product, $id);
            }

            $this->guardMaterialCanBeAssigned($material, $alreadyAssigned);

            $percentage = $this->guardPercentageIsAWholeShare($material, $line['percentage']);

            $lines[$id] = ['percentage' => $percentage];
            $total += $percentage;
        }

        $this->guardTotalIsTheWholeGarment($total, count($lines));

        return $lines;
    }

    /**
     * Refuse a material listed twice.
     *
     * The pivot table has no key that could catch it, so two rows for the same pair
     * would both survive and the percentages would add up to more than what they were
     * meant to. The ids are compared as numbers, so `7` and `'7'` are the same material
     * and not two of them.
     *
     * @param  list<int>  $ids
     */
    private function guardNoMaterialIsRepeated(array $ids): void
    {
        $seen = [];

        foreach ($ids as $id) {
            if (! in_array($id, $seen, true)) {
                $seen[] = $id;

                continue;
            }

            $material = Material::query()->find($id);

            throw $material instanceof Material
                ? RepeatedProductMaterialException::forMaterial($material)
                : RepeatedProductMaterialException::forId($id);
        }
    }

    /**
     * Refuse a material the store has turned off, unless the product already carries it.
     *
     * Turning a material off is how the store stops giving it to new products without
     * taking it away from the ones that already say they are made of it, so a product
     * that keeps it is left able to keep it.
     *
     * @param  list<int>  $alreadyAssigned
     */
    private function guardMaterialCanBeAssigned(Material $material, array $alreadyAssigned): void
    {
        if (! $material->is_active && ! in_array($material->getKey(), $alreadyAssigned, true)) {
            throw InactiveProductMaterialException::forMaterial($material);
        }
    }

    /**
     * Refuse a percentage that is not a whole share of the garment, and read it as one.
     *
     * The panel asks for whole numbers, so a decimal here means the two sides of the
     * form disagree on what it wants, and zero or more than a hundred says nothing
     * about a share at all. The value is asked what it is before it becomes a whole
     * number, otherwise a `50.5` would quietly turn into a `50` and the composition
     * that gets saved would not be the one that was sent. The number a form sends is
     * text, so a value written as text is asked the same question and accepted when it
     * is a whole number.
     */
    private function guardPercentageIsAWholeShare(Material $material, mixed $percentage): int
    {
        $wholeShare = filter_var($percentage, FILTER_VALIDATE_INT);

        if ($wholeShare === false || $wholeShare < 1 || $wholeShare > 100) {
            throw InvalidMaterialPercentageException::forPercentage($material, $percentage);
        }

        return $wholeShare;
    }

    /**
     * Refuse a composition that does not account for the whole garment.
     *
     * An empty list is left out of this on purpose: a garment whose composition has not
     * been described yet is not a garment with a wrong composition, and the panel calls
     * this with an empty list every time the admin clears the form.
     */
    private function guardTotalIsTheWholeGarment(int $total, int $materials): void
    {
        if ($materials === 0) {
            return;
        }

        if ($total !== 100) {
            throw IncompleteMaterialCompositionException::forTotal($total);
        }
    }
}
