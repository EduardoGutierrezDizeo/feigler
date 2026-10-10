<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The cart of a customer or of a visitor, never of both at once.
     *
     * An account has at most one cart (the unique `user_id`) and a visitor is
     * recognized by the `token` carried in his browser. The two owners are kept
     * apart on purpose: a row with a `user_id` has no `token`, and a row with a
     * `token` has no `user_id`. When a visitor logs in later, his cart is
     * claimed by the account and the `token` disappears.
     *
     * A cart is not a quote: it holds no price, because the price of a product
     * is read live whenever the cart is shown. `last_activity_at` exists so a
     * forgotten cart can be told apart from one that is still in use, and it is
     * refreshed by every operation that touches the cart.
     */
    public function up(): void
    {
        Schema::create('carts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->unique()->constrained()->cascadeOnDelete();
            $table->string('token', 36)->nullable()->unique();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('carts');
    }
};
