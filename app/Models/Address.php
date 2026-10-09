<?php

namespace App\Models;

use Database\Factories\AddressFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Address extends Model
{
    /** @use HasFactory<AddressFactory> */
    use HasFactory;

    /**
     * Los únicos campos que el cliente puede escribir. El dueño (user_id), la
     * predeterminada (is_default) y la zona de envío no se asignan en masa desde
     * un request: los decide la tienda (CustomerAddresses).
     *
     * @var list<string>
     */
    protected $fillable = [
        'recipient_name',
        'phone',
        'department_code',
        'department',
        'city_code',
        'city',
        'label',
        'line1',
        'line2',
        'instructions',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
        ];
    }

    /**
     * The customer that owns this address.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The delivery zone this address falls into, when one has been assigned.
     */
    public function shippingZone(): BelongsTo
    {
        return $this->belongsTo(ShippingZone::class);
    }
}
