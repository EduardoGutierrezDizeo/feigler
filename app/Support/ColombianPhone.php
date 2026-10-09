<?php

namespace App\Support;

use Illuminate\Contracts\Validation\ValidationRule;

/**
 * El celular colombiano: cómo se reduce a sus diez dígitos y qué forma tiene uno
 * válido.
 *
 * El registro y la edición del perfil comparten esta pieza para que un mismo
 * número se guarde igual desde los dos sitios. Un valor que no es un texto se
 * devuelve tal cual, y un texto sin dígitos se reduce a la cadena vacía: en los
 * dos casos la regla de `required` es la que decide.
 */
final class ColombianPhone
{
    /**
     * El número reducido a dígitos: se quita todo lo que no sea dígito y, si
     * tiene doce dígitos y empieza por 57, se descarta el indicativo del país.
     */
    public static function normalize(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $digits = preg_replace('/\D+/', '', $value);

        if (strlen($digits) === 12 && str_starts_with($digits, '57')) {
            return substr($digits, 2);
        }

        return $digits;
    }

    /**
     * Las reglas de validación de un celular colombiano: obligatorio y con la
     * forma de un móvil (un tres seguido de nueve dígitos).
     *
     * @return array<int, ValidationRule|string>
     */
    public static function rules(): array
    {
        return ['required', 'string', 'regex:/^3\d{9}$/'];
    }
}
