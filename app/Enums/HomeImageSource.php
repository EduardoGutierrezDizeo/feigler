<?php

namespace App\Enums;

/**
 * De dónde sale la foto de una categoría en la portada.
 *
 * Auto es la regla con la que la tienda nació (la miniatura de portada del
 * producto visible más reciente), Upload es una foto propia que el administrador
 * subió, y Product es una foto elegida de un producto de la propia categoría.
 * Cada valor es también lo que guarda la columna `home_image_source`.
 */
enum HomeImageSource: string
{
    case Auto = 'auto';
    case Upload = 'upload';
    case Product = 'product';
}
