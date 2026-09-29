<?php

use App\Models\Address;
use App\Models\Order;
use App\Models\ShippingZone;

test('a shipping zone is active until it says otherwise', function () {
    $zone = ShippingZone::factory()->create()->refresh();

    expect($zone->is_active)->toBeTrue();
});

test('deleting a shipping zone leaves its addresses and orders behind', function () {
    $zone = ShippingZone::factory()->create();
    $address = Address::factory()->for($zone, 'shippingZone')->create();
    $order = Order::factory()->for($zone, 'shippingZone')->create();

    $zone->delete();

    expect($address->refresh()->shipping_zone_id)->toBeNull()
        ->and($order->refresh()->shipping_zone_id)->toBeNull();
});
