<?php

namespace App\Services\Storefront;

/**
 * El texto que una persona buscó en la tienda, ya saneado.
 *
 * Es un objeto de valor: no consulta la base. El saneo es sintáctico — recorta
 * los espacios de los extremos, acota el texto a `MAX_LENGTH` caracteres y lo
 * parte por espacios en `MAX_WORDS` palabras como mucho — y no responde con un
 * error a un texto raro: un `q` que llega como arreglo, vacío, solo con espacios
 * o con menos de `MIN_LENGTH` caracteres no se busca (es `null`).
 *
 * Las palabras son la unidad de la búsqueda: cada una tiene que aparecer en al
 * menos uno de los campos (nombre, referencia o nombre de la categoría) y todas
 * tienen que aparecer, que es la regla que el alcance aplica.
 */
final class SearchTerm
{
    /**
     * El texto más corto que se llega a buscar.
     */
    public const MIN_LENGTH = 2;

    /**
     * El texto más largo que se busca: lo que sobre se trunca, no da error.
     */
    public const MAX_LENGTH = 60;

    /**
     * Las palabras como mucho que se buscan: las que sobren se ignoran.
     */
    public const MAX_WORDS = 5;

    /**
     * @param  string  $text  El texto ya recortado y acotado, tal y como se muestra.
     * @param  list<string>  $words  Las palabras que se buscan, como mucho `MAX_WORDS`.
     */
    private function __construct(
        public readonly string $text,
        public readonly array $words,
    ) {}

    /**
     * El texto buscado, o nada cuando no hay nada que buscar.
     */
    public static function from(mixed $raw): ?self
    {
        if (! is_scalar($raw)) {
            return null;
        }

        $text = trim((string) $raw);

        if (mb_strlen($text) < self::MIN_LENGTH) {
            return null;
        }

        $text = mb_substr($text, 0, self::MAX_LENGTH);

        $words = array_values(array_filter(
            preg_split('/\s+/u', $text) ?: [],
            static fn (string $word): bool => $word !== '',
        ));

        $words = array_slice($words, 0, self::MAX_WORDS);

        if ($words === []) {
            return null;
        }

        return new self($text, $words);
    }
}
