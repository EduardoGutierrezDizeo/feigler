<?php

namespace App\Support;

/**
 * El patrón de un texto para un `LIKE`, con los comodines escapados.
 *
 * `%` y `_` son comodines de `LIKE` y `\` es el carácter de escape, así que el
 * texto que alguien escribe se busca como el texto que es y no como un patrón:
 * escribir «%» no devuelve el catálogo entero. Los buscadores que ya existían
 * comparten este ayudante para que la regla no viva en dos sitios.
 */
final class LikePattern
{
    /**
     * El patrón «contiene este texto», con los comodines de `LIKE` escapados.
     */
    public static function contains(string $text): string
    {
        return '%'.addcslashes($text, '%_\\').'%';
    }

    /**
     * El carácter de escape que acompaña al patrón en la cláusula `ESCAPE` de
     * una consulta, para que SQLite y MySQL lo entiendan igual.
     */
    public static function escapeCharacter(): string
    {
        return '\\';
    }
}
