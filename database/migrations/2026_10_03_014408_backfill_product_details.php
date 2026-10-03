<?php

use App\Actions\ProductDetails\BackfillProductDetails;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The copy lives in its own migration, apart from the structure it needs, so a
     * failed copy can be retried without dropping and rebuilding the tables. Every
     * step is idempotent, so re-running this file over a catalog that was already
     * copied writes nothing.
     */
    public function up(): void
    {
        $backfill = new BackfillProductDetails;

        $backfill->sizes();
        $backfill->colors();
        $backfill->materials();
    }

    /**
     * Reverse the migrations.
     *
     * Nothing is deleted on purpose. The structures that came out of this migration
     * — the sizes of each category, the order of the colors, the materials and the
     * percentage of each product — were written by hand as much as by the copy: a
     * store that has been ordering its colors or naming its materials since then
     * would lose that work, and so would a `size_id` a variant points at.
     *
     * Rolling this migration back therefore leaves the copied data in place. It is
     * removed with the structures it belongs to, when the migration that created
     * them is rolled back.
     */
    public function down(): void
    {
        // Intentionally empty: see the note above.
    }
};
