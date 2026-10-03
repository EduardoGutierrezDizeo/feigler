<?php

use App\Actions\ProductDetails\BackfillProductDetails;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * How wide the free text is when it comes back.
     *
     * It is wider than the text it replaces (a name of a hundred characters, plus a
     * percentage and a separator) because a composition of several materials does not
     * fit in the column it is replacing, and a rollback that cannot run is worse than
     * one that says a little less.
     */
    private const LEGACY_COLUMN_LENGTH = 255;

    /**
     * The percentage a material takes in the text of a garment made of several of them.
     */
    private const PERCENTAGE_SUFFIX = ' %';

    /**
     * What separates two materials in the text of a garment made of several of them.
     */
    private const MATERIAL_SEPARATOR = ', ';

    /**
     * Replace the free text of `products.material` with the composition every product
     * already carries.
     *
     * The copy is run again on purpose. The materials were copied by another migration,
     * and a product created between that migration and this one — by a seeder, by a
     * test, by a colleague running the panel — would still be saying its material in a
     * bare column. The copy is idempotent, so running it over an already copied catalog
     * writes nothing, and the check afterwards is what turns a product whose text
     * cannot be attributed into a migration that stops instead of a catalog that quietly
     * loses a material.
     */
    public function up(): void
    {
        $this->backfillAndRefuseIfIncomplete();

        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn('material');
        });
    }

    /**
     * Put the free text back, out of the composition each product carries.
     *
     * The column comes back empty first and is filled afterwards, because a column
     * cannot be added to a table that already has rows. The text is read out of the
     * pivot, so a catalog that is rolled back is the catalog that was there: a garment
     * made of one material goes back to naming it, and a garment made of several goes
     * back naming all of them with the share each one takes.
     *
     * The reconstruction is a plain read of the pivot and a plain update, with no
     * dialect-specific statement in between, which is what keeps it reading the same
     * way on MySQL and on the SQLite the tests run on.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->string('material', self::LEGACY_COLUMN_LENGTH)->nullable()->after('brand');
        });

        $this->rewriteTheTextOfEveryProductThatHasMaterials();
    }

    /**
     * Turn every material of every product into the sentence the column used to hold.
     *
     * A sentence longer than the column is cut at its last character that fits instead
     * of being refused: the column is a summary of a composition, and a rollback cannot
     * afford to fail over a name that is longer than the text it is put back into when
     * the composition that says exactly what it is made of is still in `material_product`.
     */
    private function rewriteTheTextOfEveryProductThatHasMaterials(): void
    {
        $products = DB::table('material_product')
            ->join('products', 'products.id', '=', 'material_product.product_id')
            ->join('materials', 'materials.id', '=', 'material_product.material_id')
            ->orderBy('products.id')
            ->orderBy('materials.order')
            ->orderBy('materials.id')
            ->get(['products.id as product_id', 'materials.name', 'material_product.percentage']);

        $sentences = $products->groupBy('product_id');

        foreach ($sentences as $productId => $lines) {
            DB::table('products')
                ->where('id', $productId)
                ->update([
                    'material' => Str::limit($this->sentenceFor($lines), self::LEGACY_COLUMN_LENGTH, ''),
                ]);
        }
    }

    /**
     * The sentence a composition is written as: the name of a single material on its
     * own, and every name with the share it takes when there is more than one.
     *
     * @param  Collection<int, object{name: string, percentage: int|string}>  $lines
     */
    private function sentenceFor(Collection $lines): string
    {
        if ($lines->count() === 1) {
            return Str::squish($lines->first()->name);
        }

        return $lines
            ->map(fn (object $line): string => Str::squish($line->name).' '.$line->percentage.self::PERCENTAGE_SUFFIX)
            ->implode(self::MATERIAL_SEPARATOR);
    }

    /**
     * Give every product whose text says a material to that material, and stop the
     * migration if any of them still ends up without a composition that accounts for
     * the whole garment.
     *
     * A composition is only true when its shares add up to a hundred, which is the same
     * rule `SyncProductMaterials` holds the panel to, and it is the rule that makes this
     * check reachable instead of a formality. A product that already carries a share of
     * one material keeps it — the copy only adds what the text was naming — so a
     * product that names a second material ends up at more than a hundred, and a product
     * whose only share is short ends up below it. Either way the column still holds
     * something the pivot does not say, and dropping it would lose it.
     *
     * The check runs inside the same transaction as the copy, so a catalog with a text
     * that cannot be attributed is left exactly as it was: not the materials that were
     * created before reaching it, not the rows that were attached. The migration stops
     * and says how many products are the ones to look at.
     */
    private function backfillAndRefuseIfIncomplete(): void
    {
        DB::transaction(function (): void {
            (new BackfillProductDetails)->materials();

            $unattributed = DB::table('products')
                ->whereNotNull('material')
                ->get(['id', 'material'])
                // Un texto de puros espacios no nombra ningún material, así que no
                // cuenta como pendiente: bloquear la migración por un `  ` no le
                // quitaría información a nadie.
                ->filter(fn (object $row): bool => Str::squish((string) $row->material) !== '')
                ->reject(fn (object $row): bool => $this->wholeGarmentIsAccountedFor($row->id))
                ->count();

            if ($unattributed > 0) {
                throw new RuntimeException(
                    "No se puede eliminar la columna «material»: {$unattributed} producto(s) siguen con texto y su composición no suma 100 %. "
                    .'Pasa cada uno de esos materiales a su composición, o vuelve a correr la migración de backfill, y reintenta.'
                );
            }
        });
    }

    /**
     * Whether the shares this product carries already account for all of it.
     *
     * Asked one product at a time and only of the ones that still have text left to
     * read, which is a handful of rows on a migration that runs once: a single grouped
     * sum would have to be assembled by hand in SQL to keep it portable, and a list of
     * products that is read once does not need to be clever.
     */
    private function wholeGarmentIsAccountedFor(int $productId): bool
    {
        return (int) DB::table('material_product')
            ->where('product_id', $productId)
            ->sum('percentage') === 100;
    }
};
