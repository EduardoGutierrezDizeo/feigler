<?php

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;

test('an order belongs to its customer and lists its items', function () {
    $order = Order::factory()->create();
    $item = OrderItem::factory()->for($order)->create();

    expect($order->user)->not->toBeNull()
        ->and($order->shippingZone)->not->toBeNull()
        ->and($order->items()->count())->toBe(1)
        ->and($order->items->first()->is($item))->toBeTrue();
});

test('a pos order is served by staff and ships nowhere', function () {
    $order = Order::factory()->pos()->create()->refresh();

    expect($order->channel)->toBe('pos')
        ->and($order->served_by)->not->toBeNull()
        ->and($order->servedBy)->toBeInstanceOf(User::class)
        ->and($order->shipping_zone_id)->toBeNull();
});

test('an order is pending and unpaid until the sale settles', function () {
    $order = Order::factory()->create()->refresh();

    expect($order->status)->toBe('pending')
        ->and($order->payment_status)->toBe('pending')
        ->and($order->shipping_cost)->toBe('0.00');
});
