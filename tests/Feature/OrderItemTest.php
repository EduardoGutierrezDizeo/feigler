<?php

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductVariant;
use Illuminate\Database\QueryException;

test('an order line belongs to its order and to the variant sold', function () {
    $order = Order::factory()->create();
    $variant = ProductVariant::factory()->create();

    $item = OrderItem::factory()
        ->for($order)
        ->for($variant, 'productVariant')
        ->create();

    expect($item->order->is($order))->toBeTrue()
        ->and($item->productVariant->is($variant))->toBeTrue()
        ->and($order->items()->count())->toBe(1)
        ->and($variant->orderItems()->count())->toBe(1);
});

test('an order line records no returns until they are booked', function () {
    $item = OrderItem::factory()->create()->refresh();

    expect($item->returned_quantity)->toBe(0);
});

test('a variant with an order line cannot be deleted', function () {
    $variant = ProductVariant::factory()->create();
    OrderItem::factory()->for($variant, 'productVariant')->create();

    expect(fn () => $variant->delete())->toThrow(QueryException::class);

    expect($variant->exists)->toBeTrue()
        ->and($variant->orderItems()->count())->toBe(1);
});
