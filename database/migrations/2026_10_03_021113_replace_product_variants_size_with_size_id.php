<?php

use App\Actions\ProductDetails\BackfillProductDetails;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The unique index that survives the change: the same three columns, with the
     * size row in place of the text.
     */
    private const COMBINATION_INDEX = 'product_variants_product_id_size_id_color_id_unique';

    /**
     * The unique index the text column needed, which goes away with the column.
     */
    private const LEGACY_INDEX = 'product_variants_product_id_size_color_id_unique';

    /**
     * Replace the free text of `product_variants.size` with the `size_id` every
     * variant already carries.
     *
     * The order of the steps below is not a matter of taste, it is what MySQL
     * accepts. A column cannot be dropped while an index still covers it, and the
     * text column is covered by a unique index, so the index has to go first; and a
     * column cannot become NOT NULL while a row still leaves it empty, so the copy
     * has to run and come back empty before the column is tightened.
     *
     * The copy is run again on purpose. The sizes were copied by another migration,
     * and a variant created between that migration and this one — by a seeder, by a
     * test, by a colleague running the panel — would still be pointing at nothing.
     * The copy is idempotent, so running it over an already copied catalog writes
     * nothing, and the check afterwards is what turns a variant that cannot be
     * attributed into a migration that stops instead of a column that cannot be made
     * NOT NULL.
     */
    public function up(): void
    {
        $this->backfillAndRefuseIfIncomplete();

        Schema::table('product_variants', function (Blueprint $table): void {
            $table->unsignedBigInteger('size_id')->nullable(false)->change();
        });

        Schema::table('product_variants', function (Blueprint $table): void {
            $table->unique(['product_id', 'size_id', 'color_id'], self::COMBINATION_INDEX);
        });

        Schema::table('product_variants', function (Blueprint $table): void {
            $table->dropUnique(self::LEGACY_INDEX);
        });

        Schema::table('product_variants', function (Blueprint $table): void {
            $table->dropColumn('size');
        });
    }

    /**
     * Put the free text back, out of the size every variant points at.
     *
     * The column comes back empty first and is filled afterwards, because a NOT NULL
     * column cannot be added to a table that already has rows. The names come from
     * `sizes.name`, which is where the text came from in the first place, so a
     * catalog that is rolled back is the catalog that was there.
     *
     * The two unique indexes overlap for a moment, which is fine: the old one does not
     * cover `size_id`, and the two together say the same thing about the same rows.
     */
    public function down(): void
    {
        Schema::table('product_variants', function (Blueprint $table): void {
            $table->string('size')->nullable()->after('product_id');
        });

        // A subquery instead of a join, so the same statement is read the same way by
        // MySQL and by SQLite, which is what the tests run on.
        DB::table('product_variants')->update([
            'size' => DB::raw('(select name from sizes where sizes.id = product_variants.size_id)'),
        ]);

        Schema::table('product_variants', function (Blueprint $table): void {
            $table->string('size')->nullable(false)->change();
        });

        Schema::table('product_variants', function (Blueprint $table): void {
            $table->unique(['product_id', 'size', 'color_id'], self::LEGACY_INDEX);
        });

        Schema::table('product_variants', function (Blueprint $table): void {
            $table->dropUnique(self::COMBINATION_INDEX);
        });

        Schema::table('product_variants', function (Blueprint $table): void {
            $table->unsignedBigInteger('size_id')->nullable()->change();
        });
    }

    /**
     * Point every variant that is not pointing at a size yet at the one its text
     * says, and stop the migration if any of them still cannot be attributed.
     */
    private function backfillAndRefuseIfIncomplete(): void
    {
        DB::transaction(function (): void {
            (new BackfillProductDetails)->sizes();

            $unattributed = DB::table('product_variants')
                ->whereNull('size_id')
                ->count();

            if ($unattributed > 0) {
                throw new RuntimeException(
                    "No se puede eliminar la columna «size»: {$unattributed} variante(s) siguen sin «size_id». "
                    .'Copia la talla de cada una a mano, o vuelve a correr la migración de backfill, y reintenta.'
                );
            }
        });
    }
};
