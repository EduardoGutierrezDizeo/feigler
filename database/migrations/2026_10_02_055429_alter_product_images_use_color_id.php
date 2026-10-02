<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('product_images', function (Blueprint $table) {
            $table->foreignId('color_id')->nullable()->after('product_id')->constrained('colors')->restrictOnDelete();
        });

        $this->inheritColorIdFromVariant();

        Schema::table('product_images', function (Blueprint $table) {
            $table->dropForeign(['product_variant_id']);
        });

        Schema::table('product_images', function (Blueprint $table) {
            $table->dropColumn('product_variant_id');
        });

        Schema::table('product_images', function (Blueprint $table) {
            $table->index(['product_id', 'color_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // The variant an image used to belong to cannot be recovered: the new schema
        // only stores the color, and a color is shared by every size of a product, so
        // there is no way to tell which size (if any) the image was tied to. The column
        // is recreated for the schema to be back in place and left entirely NULL,
        // which is exactly what a general product image looks like.
        Schema::table('product_images', function (Blueprint $table) {
            $table->foreignId('product_variant_id')->nullable()->after('product_id')->constrained('product_variants')->nullOnDelete();
        });

        // The (product_id, color_id) index added above is the one left backing the
        // product_id foreign key: when it was created MySQL quietly dropped the plain
        // product_id index the foreign key was created with, because the composite one
        // could serve the constraint just as well. So that plain index is put back
        // first, otherwise dropping the composite one aborts with errno 1553
        // ("Cannot drop index ... needed in a foreign key constraint").
        Schema::table('product_images', function (Blueprint $table) {
            $table->index('product_id');
        });

        Schema::table('product_images', function (Blueprint $table) {
            $table->dropIndex(['product_id', 'color_id']);
        });

        Schema::table('product_images', function (Blueprint $table) {
            $table->dropForeign(['color_id']);
            $table->dropColumn('color_id');
        });
    }

    /**
     * Move the color of the variant onto the image, which is the whole meaning of
     * the change: images now hang from a color instead of from a single size.
     */
    private function inheritColorIdFromVariant(): void
    {
        DB::table('product_variants')
            ->select('id', 'color_id')
            ->whereNotNull('color_id')
            ->orderBy('id')
            ->each(function (object $variant) {
                DB::table('product_images')
                    ->where('product_variant_id', $variant->id)
                    ->update(['color_id' => $variant->color_id]);
            });
    }
};
