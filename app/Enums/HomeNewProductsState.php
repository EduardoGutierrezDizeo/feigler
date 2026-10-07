<?php

namespace App\Enums;

/**
 * Why «Novedades» on the home page shows what it shows today.
 *
 * The state answers the third question of the tab «Lo más nuevo» of the panel:
 * not only what is displayed today, but why. A manual decision wins over the
 * automatic rule, and it stops being the decision the moment none of its
 * chosen products is visible anymore.
 */
enum HomeNewProductsState: string
{
    case Manual = 'manual';
    case SinElegidos = 'sin-elegidos';
    case ElegidosOcultos = 'elegidos-ocultos';
}
