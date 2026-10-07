<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A category may show on the home page a picture of its own choosing: the
     * automatic rule it was born with (the default), a picture the admin
     * uploaded for it, or a picture picked from one of its products. These four
     * columns are that decision; `home_image_source` says which mode is on and
     * the other three only hold something for the two manual ones.
     *
     * The link to the chosen product picture is a foreign key with nullOnDelete
     * on purpose: a picture that is deleted from the product leaves the decision
     * broken, and the home page has to be able to fall back to the automatic
     * rule without a deleted row blocking anything.
     */
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->string('home_image_source', 20)->default('auto')->after('is_active');
            $table->string('home_image_path')->nullable()->after('home_image_source');
            $table->string('home_image_thumbnail_path')->nullable()->after('home_image_path');
            $table->foreignId('home_image_product_image_id')
                ->nullable()
                ->after('home_image_thumbnail_path')
                ->constrained('product_images')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     *
     * The foreign key is dropped before the columns, so the rollback never
     * trips a constraint.
     */
    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropConstrainedForeignId('home_image_product_image_id');
            $table->dropColumn(['home_image_source', 'home_image_path', 'home_image_thumbnail_path']);
        });
    }
};
