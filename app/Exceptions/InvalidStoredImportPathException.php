<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A path kept in the component state does not name a file of the private folder.
 *
 * The component only ever keeps the path the store handed it, so this is a path that
 * was tampered with: a value that does not follow the exact shape of a stored name,
 * or one that escapes the folder through a relative hop. It is refused before the
 * disk is touched, so a request that lies about the path can neither read nor delete
 * a file that is not one of ours.
 */
class InvalidStoredImportPathException extends RuntimeException
{
    public static function forPath(string $path): self
    {
        return new self('El archivo de la carga no es válido o ya no está disponible. Vuelve a subirlo.');
    }
}
