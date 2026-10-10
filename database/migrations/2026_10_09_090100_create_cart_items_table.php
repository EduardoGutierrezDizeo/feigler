<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One line of a cart: a variant and how many units of it were asked for.
     *
     * A variant appears at most once per cart — the pair is unique — because a
     * cart has no business showing two lines for the same garment. Quantity is
     * always counted in whole units, and the application refuses anything below
     * one; the column being unsigned is the database's half of that promise.
     *
     * Both foreign keys cascade: a cart that is removed takes its lines with it,
     * and a variant that is deleted cannot have lines pointing at nothing.
     */
    public function up(): void
    {
        Schema::create('cart_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cart_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('quantity');
            $table->timestamps();

            $table->unique(['cart_id', 'product_variant_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cart_items');
    }
};
