<?php

use App\Models\Category;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Repair the sibling groups whose `order` values collide.
     *
     * Categories created from the panel never received an explicit `order`, so
     * they all inherited the migration default of `0`. Any group that got more
     * than one such category ended up with duplicate values, and a duplicate
     * makes a move a no-op: swapping two identical values changes nothing while
     * still running its UPDATEs. The reindex rewrites every group to a
     * contiguous `0..n-1` sequence, keeping the relative order the panel was
     * already showing (`orderBy('order')->orderBy('id')`).
     *
     * It goes in a migration rather than a one-off command so every environment
     * gets the repair on deploy instead of only the one where the data was
     * noticed.
     */
    public function up(): void
    {
        Category::reindexAllSiblingGroups();
    }

    /**
     * Reverse the migrations.
     *
     * There is nothing to restore: `order` is a display position, not a value
     * with meaning of its own, and the only previous values to go back to are
     * the duplicated ones this migration exists to remove.
     */
    public function down(): void
    {
        //
    }
};
