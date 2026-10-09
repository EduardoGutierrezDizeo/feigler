<?php

namespace App\Enums;

use Illuminate\Http\Request;

/**
 * Las pestañas de Mi cuenta.
 *
 * La lista vive solo aquí: la barra, el valor de la URL (`?tab=perfil`) y la
 * vista parcial que renderiza cada pestaña se leen de este enum, así que una
 * pestaña nueva es solo un case más. Un valor inválido o ausente cae en la
 * primera pestaña.
 */
enum AccountTab: string
{
    case Perfil = 'perfil';

    case Seguridad = 'seguridad';

    public function label(): string
    {
        return match ($this) {
            self::Perfil => 'Perfil',
            self::Seguridad => 'Seguridad',
        };
    }

    /**
     * La vista parcial que pinta el contenido de la pestaña.
     */
    public function view(): string
    {
        return match ($this) {
            self::Perfil => 'storefront.account.tabs.perfil',
            self::Seguridad => 'storefront.account.tabs.seguridad',
        };
    }

    /**
     * La primera pestaña de la lista, el destino de un valor ausente o inválido.
     */
    public static function default(): self
    {
        return self::cases()[0];
    }

    /**
     * La pestaña activa según `?tab=`.
     */
    public static function fromRequest(Request $request): self
    {
        return self::tryFrom((string) $request->query('tab', '')) ?? self::default();
    }
}
