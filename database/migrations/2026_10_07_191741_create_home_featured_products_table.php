<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The admin picks up to four visible products for the "Novedades" of the
     * home page and their order; this table is that choice, one row per picked
     * product with `order` being its position in the list. The product is a
     * foreign key that cascades because a product that is deleted cannot be
     * featured: keeping the row would point the home page at nothing.
     */
    public function up(): void
    {
        Schema::create('home_featured_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete()->unique();
            $table->unsignedInteger('order')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('home_featured_products');
    }
};
