<?php

namespace App\Models;

use Database\Factories\CartFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The cart of a customer or of a visitor, never of both at once.
 *
 * An account has at most one cart and a visitor is recognized by the cookie
 * `token`. The two owners are exclusive: a row with a `user_id` has no `token`
 * and a row with a `token` has no `user_id`. Everything a cart can do lives in
 * `CartService`; the model only holds the rows and their relations.
 *
 * The cart stores no prices: the price of each line is read live from the
 * product and the variant whenever the cart is shown, so a sale that happens
 * between two visits is reflected without touching the cart.
 */
class Cart extends Model
{
    /** @use HasFactory<CartFactory> */
    use HasFactory;

    /**
     * The cookie that tells a visitor's cart apart from everyone else's.
     *
     * It lives here because the model and the service both name it, and a rule
     * that has two homes drifts. The value is a `Str::uuid()` stored in the
     * `token` column, encrypted by the cookie middleware exactly like every
     * other cookie of the application.
     */
    public const TOKEN_COOKIE = 'cart_token';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'token',
        'last_activity_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_activity_at' => 'datetime',
        ];
    }

    /**
     * The account the cart belongs to, when the shopper logged in.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The lines the cart holds.
     *
     * @return HasMany<CartItem>
     */
    public function items(): HasMany
    {
        return $this->hasMany(CartItem::class);
    }

    /**
     * Whether this cart belongs to an account instead of a visitor.
     *
     * A cart belongs to exactly one of the two, so a token is only meaningful
     * while the cart stays a visitor's: once an account claims it, the cookie
     * must not open it anymore.
     */
    public function belongsToAccount(): bool
    {
        return $this->user_id !== null;
    }

    /**
     * Mark the cart as used right now.
     *
     * Every operation that touches the cart calls this, so `last_activity_at`
     * is a true picture of which carts are alive and which were abandoned.
     */
    public function touchActivity(): void
    {
        $this->last_activity_at = now();
        $this->save();
    }
}
