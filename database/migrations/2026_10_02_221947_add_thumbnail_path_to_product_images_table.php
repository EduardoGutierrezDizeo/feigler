<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The column is the address of the small copy of the picture that the listing
     * and the gallery show, and it is nullable on purpose: an image whose thumbnail
     * could not be made keeps its original and is shown as it is, which is a
     * better catalog than one with holes in it. `products:generate-thumbnails`
     * fills the column in for the images that are left without one.
     */
    public function up(): void
    {
        Schema::table('product_images', function (Blueprint $table) {
            $table->string('thumbnail_path')->nullable()->after('path');
        });
    }

    /**
     * Reverse the migrations.
     *
     * Only the address goes: the files themselves are not touched by a schema
     * change, and the copy command rebuilds them.
     */
    public function down(): void
    {
        Schema::table('product_images', function (Blueprint $table) {
            $table->dropColumn('thumbnail_path');
        });
    }
};
