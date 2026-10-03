<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * This migration is purely additive: nothing that exists today is dropped or
     * altered, so the catalog keeps reading `product_variants.size` and
     * `products.material` exactly as it did before. The new structures sit next to
     * them and stay empty until the copy of data that follows.
     *
     * The loose index on `product_variants.product_id` is added on purpose, even
     * though nothing queries it yet: today the only index able to back the
     * `product_id` foreign key is the unique `(product_id, size, color_id)`, so a
     * later migration replacing that unique with a `(product_id, size_id,
     * color_id)` one would abort with errno 1553 unless a plain index exists
     * first. See the note in
     * `2026_10_02_055428_alter_product_variants_use_color_id.php`.
     */
    public function up(): void
    {
        Schema::create('sizes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained()->restrictOnDelete();
            $table->string('name', 20);
            $table->unsignedInteger('order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['category_id', 'name']);
            $table->index(['category_id', 'order']);
        });

        Schema::create('materials', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->unsignedInteger('order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('material_product', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('material_id')->constrained()->restrictOnDelete();
            $table->unsignedTinyInteger('percentage');

            $table->unique(['product_id', 'material_id']);
        });

        Schema::table('colors', function (Blueprint $table) {
            $table->unsignedInteger('order')->default(0)->after('code');
            $table->boolean('is_active')->default(true)->after('order');
        });

        Schema::table('product_variants', function (Blueprint $table) {
            $table->foreignId('size_id')->nullable()->after('size')->constrained('sizes')->restrictOnDelete();
        });

        Schema::table('product_variants', function (Blueprint $table) {
            $table->index('product_id');
        });
    }

    /**
     * Reverse the migrations.
     *
     * The `sizes`, `materials` and `material_product` tables are dropped only after
     * the foreign keys pointing at them are gone, so the drops never trip a
     * constraint. `colors.order` and `colors.is_active` go back to not existing:
     * they hold no data anybody depends on yet, the copy of data leaves `order` at
     * zero on the rows it cannot attribute and `is_active` at true.
     */
    public function down(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropIndex('product_id');
        });

        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropConstrainedForeignId('size_id');
        });

        Schema::table('colors', function (Blueprint $table) {
            $table->dropColumn(['order', 'is_active']);
        });

        Schema::dropIfExists('material_product');
        Schema::dropIfExists('materials');
        Schema::dropIfExists('sizes');
    }
};
