<?php

namespace App\Support;

/**
 * El enlace de WhatsApp de la tienda.
 *
 * El valor de `config('tienda.whatsapp')` puede llegar con formato (espacios,
 * prefijo, paréntesis), vacío o con un marcador como «#». Este ayudante lo
 * reduce al mismo número que el registro, antepone el indicativo 57 y solo
 * entonces devuelve el enlace `wa.me`. Sin un número válido no hay enlace.
 */
final class WhatsAppLink
{
    public static function for(mixed $value): ?string
    {
        $digits = ColombianPhone::normalize($value);

        if (! is_string($digits) || preg_match('/^3\d{9}$/', $digits) !== 1) {
            return null;
        }

        return 'https://wa.me/57'.$digits;
    }
}
