<?php

use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Wishlist;
use Illuminate\Database\QueryException;

test('a wishlist entry belongs to its customer and to the saved variant', function () {
    $user = User::factory()->create();
    $variant = ProductVariant::factory()->create();

    $entry = Wishlist::factory()
        ->for($user)
        ->for($variant, 'productVariant')
        ->create();

    expect($entry->user->is($user))->toBeTrue()
        ->and($entry->productVariant->is($variant))->toBeTrue()
        ->and($user->wishlists()->count())->toBe(1)
        ->and($variant->wishlists()->count())->toBe(1);
});

test('a customer cannot save the same variant twice', function () {
    $entry = Wishlist::factory()->create();

    $this->expectException(QueryException::class);

    Wishlist::factory()
        ->for($entry->user)
        ->for($entry->productVariant, 'productVariant')
        ->create();
});
