<?php

use App\Models\Address;
use App\Models\ShippingZone;
use App\Models\User;

test('an address belongs to its customer and resolves its shipping zone', function () {
    $user = User::factory()->create();
    $zone = ShippingZone::factory()->create();

    $address = Address::factory()
        ->for($user)
        ->for($zone, 'shippingZone')
        ->create();

    expect($address->user->is($user))->toBeTrue()
        ->and($address->shippingZone->is($zone))->toBeTrue()
        ->and($user->addresses()->count())->toBe(1);
});

test('an address is not the default and carries no zone until they are assigned', function () {
    $address = Address::factory()->create()->refresh();

    expect($address->is_default)->toBeFalse()
        ->and($address->shipping_zone_id)->toBeNull();
});
