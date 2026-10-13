<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Límites de la carga masiva de productos
    |--------------------------------------------------------------------------
    |
    | El archivo que el admin sube se lee en memoria para construir la vista
    | previa, así que estos topes son los que mantienen la lectura acotada. El
    | tamaño se expresa en kilobytes y la fila es una fila de datos, sin contar
    | el encabezado.
    |
    */

    // 2 MB: el mismo tope que `upload_max_filesize` del servidor.
    'max_file_size_kb' => 2048,

    // 1.000 filas de datos. Confirmar esa cantidad tarda alrededor de once
    // segundos, así que entra con margen en el `max_execution_time` de 30 s del
    // servidor web; un archivo más largo se pide dividido en varios.
    'max_rows' => 1000,

    /*
    |--------------------------------------------------------------------------
    | Formatos aceptados
    |--------------------------------------------------------------------------
    |
    | La extensión del archivo decide cómo se lee: CSV pasa por la detección de
    | codificación y separador, y XLSX es siempre un libro de OpenSpout.
    |
    */

    'allowed_extensions' => ['csv', 'xlsx'],
];
