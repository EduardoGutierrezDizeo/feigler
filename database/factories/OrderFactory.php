<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\ShippingZone;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $subtotal = fake()->randomFloat(2, 50, 1000);

        return [
            'user_id' => User::factory(),
            'channel' => 'online',
            'shipping_zone_id' => ShippingZone::factory(),
            'subtotal' => $subtotal,
            'total' => $subtotal,
        ];
    }

    /**
     * Indicate that the order was taken at the POS instead of the online channel.
     */
    public function pos(): static
    {
        return $this->state(fn (array $attributes) => [
            'channel' => 'pos',
            'shipping_zone_id' => null,
            'served_by' => User::factory(),
        ]);
    }
}
