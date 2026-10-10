<?php

namespace Database\Factories;

use App\Models\Cart;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Cart>
 */
class CartFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * The default is a visitor's cart: it carries the `token` that identifies
     * it and no account, which is the cart a store usually deals with. An
     * account's cart is built with `account($user)`, which drops the token —
     * a cart never belongs to an account and a visitor at once.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => null,
            'token' => (string) Str::uuid(),
        ];
    }

    /**
     * Indicate that the cart belongs to this account instead of a visitor.
     */
    public function account(User $user): static
    {
        return $this->state(fn (array $attributes): array => [
            'user_id' => $user->getKey(),
            'token' => null,
        ]);
    }
}
