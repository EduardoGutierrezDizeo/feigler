<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $this->giveEveryImageAColor();

        Schema::table('product_images', function (Blueprint $table) {
            $table->boolean('is_primary')->default(false)->after('order');
        });

        $this->markTheOldestImageOfEachColorAsPrimary();

        Schema::table('product_images', function (Blueprint $table) {
            $table->unsignedBigInteger('color_id')->nullable(false)->change();
        });

        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('cover_color_id')->nullable()->after('status')->constrained('colors')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign(['cover_color_id']);
            $table->dropColumn('cover_color_id');
        });

        Schema::table('product_images', function (Blueprint $table) {
            $table->dropColumn('is_primary');
        });

        Schema::table('product_images', function (Blueprint $table) {
            $table->unsignedBigInteger('color_id')->nullable()->change();
        });
    }

    /**
     * Give a color to every image that was left without one.
     *
     * The migration that moved images from a variant to a color copied the color
     * of the variant along, so what is still without a color is what used to be a
     * general image of the product: a photo that hung from no size/color
     * combination at all. Those rows have to land in some color now, and the only
     * one that can be picked without inventing anything is a color the product is
     * actually sold in, so the first of its variants by id decides.
     *
     * An image of a product with no variant at all cannot be given a color: the
     * product is sold in no color, and the catalog does not get a color invented
     * for it. Those rows are the only thing this migration has to remove, because
     * the column is about to stop accepting NULL, and their files are reported
     * instead of being erased here: a migration that deletes files is one that
     * fails halfway and leaves the disk in a state nobody wrote down.
     *
     * The rows are read in id order, which is what `each()` needs to chunk, and the
     * color of an image is the only thing that changes, so no row is skipped.
     */
    private function giveEveryImageAColor(): void
    {
        /** @var list<string> $orphaned */
        $orphaned = [];

        DB::table('product_images')
            ->select('id', 'product_id')
            ->whereNull('color_id')
            ->orderBy('id')
            ->each(function (object $image) use (&$orphaned): void {
                $colorId = DB::table('product_variants')
                    ->where('product_id', $image->product_id)
                    ->whereNotNull('color_id')
                    ->orderBy('id')
                    ->value('color_id');

                if ($colorId !== null) {
                    DB::table('product_images')
                        ->where('id', $image->id)
                        ->update(['color_id' => $colorId]);

                    return;
                }

                $orphaned[] = (string) DB::table('product_images')
                    ->where('id', $image->id)
                    ->value('path');

                DB::table('product_images')->where('id', $image->id)->delete();
            });

        if ($orphaned !== []) {
            Log::warning(
                'Se eliminaron imágenes de producto sin color porque el producto no tiene '
                .'ninguna variante de la que heredarlo; sus archivos quedan en el disco y '
                .'conviene borrarlos a mano: '.implode(', ', $orphaned)
            );
        }
    }

    /**
     * Leave one primary image per color, which is what the column is about: a
     * color with images has exactly one of them as its main one.
     *
     * The column is created as false for every existing row, so without this the
     * galleries already in the database would have no main image at all and a
     * product would show nothing. The oldest image of each color wins, which is the
     * one that would have been uploaded first, since the id follows the upload.
     */
    private function markTheOldestImageOfEachColorAsPrimary(): void
    {
        DB::table('product_images')
            ->select('product_id', 'color_id')
            ->distinct()
            ->orderBy('product_id')
            ->orderBy('color_id')
            ->each(function (object $color): void {
                $oldestId = DB::table('product_images')
                    ->where('product_id', $color->product_id)
                    ->where('color_id', $color->color_id)
                    ->orderBy('id')
                    ->value('id');

                DB::table('product_images')
                    ->where('id', $oldestId)
                    ->update(['is_primary' => true]);
            });
    }
};
