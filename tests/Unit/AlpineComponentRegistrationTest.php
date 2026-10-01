<?php

/*
 |--------------------------------------------------------------------------
 | Red de seguridad para el registro de componentes Alpine.
 |--------------------------------------------------------------------------
 |
 | Un `x-data="nombreDeComponente"` en Blade solo funciona si el bundle de
 | entrada que sirve esa vista registra `Alpine.data('nombreDeComponente')`.
 | Los tests de Feature no lo detectan: solo verifican el HTML renderizado y
 | nunca ejecutan JavaScript, asi que un componente sin registrar pasaba la
 | suite completa mientras el dialogo de confirmacion se abria solo al cargar.
 |
 | Estos tests leen los fuentes del proyecto y cubren las dos mitades del
 | contrato por separado: que todo bundle de entrada alcance el barrel y que
 | todo nombre usado en Blade este registrado en algun modulo de JS.
 |
 */

/**
 * Resuelve un especificador relativo de import de JavaScript a una ruta real,
 * aceptando la extension implicita y los index de directorio.
 */
function resolveJavascriptImport(string $fromFile, string $specifier): ?string
{
    if (! str_starts_with($specifier, '.')) {
        return null;
    }

    $base = dirname($fromFile).DIRECTORY_SEPARATOR.$specifier;

    foreach ([$base, $base.'.js', $base.DIRECTORY_SEPARATOR.'index.js'] as $candidate) {
        $real = realpath($candidate);

        if ($real !== false && is_file($real)) {
            return $real;
        }
    }

    return null;
}

/**
 * Modulos de JS que el archivo dado alcanza siguiendo sus imports relativos,
 * con rutas normalizadas y sin ciclos infinitos.
 *
 * @return list<string>
 */
function reachableJavascriptModules(string $file, array $seen = []): array
{
    $real = realpath($file);

    if ($real === false || in_array($real, $seen, true)) {
        return [];
    }

    $seen[] = $real;

    preg_match_all('/^\s*import\s+(?:[^;\']*from\s+)?[\'"]([^\'"]+)[\'"]/m', javascriptSource($real), $matches);

    $modules = [];

    foreach ($matches[1] as $specifier) {
        $resolved = resolveJavascriptImport($real, $specifier);

        if ($resolved === null) {
            continue;
        }

        $modules[] = $resolved;
        $modules = array_merge($modules, reachableJavascriptModules($resolved, $seen));
    }

    return array_values(array_unique($modules));
}

/**
 * Rutas de los bundles de entrada de JavaScript declarados en vite.config.js.
 *
 * @return list<string>
 */
function javascriptEntryPoints(string $projectPath): array
{
    $config = (string) file_get_contents($projectPath.'/vite.config.js');

    preg_match('/input:\s*\[(.*?)\]/s', $config, $input);

    if (! isset($input[1])) {
        return [];
    }

    preg_match_all('/[\'"]([^\'"]+\.js)[\'"]/', $input[1], $entries);

    return array_values(array_filter(
        $entries[1],
        fn (string $entry): bool => str_starts_with($entry, 'resources/js/')
    ));
}

/**
 * Nombres de componente referenciados desde Blade con `x-data="nombre"`.
 * Admite los que reciben argumentos (`x-data="adminModal($wire)"`), porque el
 * nombre sigue siendo el que hay que registrar; ignorados los objetos inline
 * (`x-data="{ open: false }"`) y los vacios.
 *
 * @return list<string>
 */
function alpineComponentNamesUsedInBlade(string $projectPath): array
{
    $names = [];

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($projectPath.'/resources/views', RecursiveDirectoryIterator::SKIP_DOTS)
    );

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $view = (string) file_get_contents($file->getPathname());

        preg_match_all(
            '/x-data="([A-Za-z_$][A-Za-z0-9_$]*)\s*(?:\([^\"]*\))?"/',
            (string) preg_replace('#\{\{--.*?--\}\}#s', '', $view),
            $matches
        );

        foreach ($matches[1] as $name) {
            $names[$name] = true;
        }
    }

    return array_keys($names);
}

/**
 * Lee un archivo de JavaScript descartando comentarios de bloque (JSDoc) y de
 * linea completa, para que un nombre mencionado solo en un comentario no cuente
 * como registro. Es una aproximacion deliberada: cubre los comentarios de
 * este proyecto sin arriesgarse a cortar URLs dentro de cadenas.
 */
function javascriptSource(string $path): string
{
    $source = (string) file_get_contents($path);

    $source = preg_replace('#/\*.*?\*/#s', '', $source);
    $source = preg_replace('#^\s*//.*$#m', '', (string) $source);

    return (string) $source;
}

/**
 * Nombres que algun modulo de resources/js registra con Alpine.data(),
 * ignorando los que solo aparecen mencionados en comentarios.
 *
 * @return list<string>
 */
function registeredAlpineComponents(string $projectPath): array
{
    $registered = [];

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($projectPath.'/resources/js', RecursiveDirectoryIterator::SKIP_DOTS)
    );

    foreach ($files as $file) {
        if ($file->getExtension() !== 'js') {
            continue;
        }

        preg_match_all(
            '/Alpine\.data\(\s*[\'"]([A-Za-z_$][A-Za-z0-9_$]*)[\'"]/',
            javascriptSource($file->getPathname()),
            $matches
        );

        foreach ($matches[1] as $name) {
            $registered[$name] = true;
        }
    }

    return array_keys($registered);
}

test('every javascript entry point reaches the Alpine barrel', function () {
    $projectPath = dirname(__DIR__, 2);

    $barrel = realpath($projectPath.'/resources/js/alpine/index.js');
    $entries = javascriptEntryPoints($projectPath);

    expect($barrel)->not->toBeFalse();
    expect($entries)->not->toBeEmpty();

    $unreachable = array_values(array_filter(
        $entries,
        fn (string $entry): bool => ! in_array($barrel, reachableJavascriptModules($projectPath.'/'.$entry), true)
    ));

    expect($unreachable)->toBeEmpty();
});

test('every Alpine component referenced in Blade is registered in a javascript module', function () {
    $projectPath = dirname(__DIR__, 2);

    $registered = registeredAlpineComponents($projectPath);
    $referenced = alpineComponentNamesUsedInBlade($projectPath);

    expect($registered)->toContain('confirmDialog');

    $missing = array_values(array_filter(
        $referenced,
        fn (string $name): bool => ! in_array($name, $registered, true)
    ));

    expect($missing)->toBeEmpty();
});

test('the admin form modal entangles showForm in the x-data object so it can open and close', function () {
    $projectPath = dirname(__DIR__, 2);

    // El modal de formularios vive siempre en el DOM y su visibilidad es la
    // propiedad `showForm` del componente Livewire padre. Si el enlace se
    // renombrara o se perdiera, el modal se quedaria siempre oculto (o siempre
    // visible) sin que ningun test de Feature lo notara, porque no ejecutan
    // JavaScript. El scroll-lock se comprueba en el mismo sitio por la misma
    // razon: es lo que impide que el fondo se mueva con el modal abierto.
    $source = javascriptSource($projectPath.'/resources/js/alpine/admin-modal.js');

    expect($source)
        ->toMatch('/open:\s*wire\.entangle\(\'showForm\'\)/')
        ->toContain("classList.toggle('overflow-y-hidden', isOpen)");

    // El enlace tiene que declararse en la definicion de `open`, y no dentro de
    // `init()`: `$wire.entangle()` devuelve un interceptor de Alpine que solo se
    // activa si la propiedad esta en el objeto de `x-data` cuando Alpine la
    // recorre, y ese recorrido ocurre antes de invocar `init()`. En `init()`
    // quedaba un objeto plano, truthy y sin enlace con el servidor: el modal se
    // abria al cargar la pagina y ni la X ni «Cancelar» lo cerraban. La forma
    // exacta importa, asi que se comprueba con un patron y no con un `toContain`
    // que dejaria pasar esa variante.
    expect($source)->not->toMatch('/init\s*\(\s*\)\s*\{[^}]*entangle/s');

    // Y el proveedor solo recibe `$wire` si Blade se lo pasa como argumento.
    expect(alpineComponentNamesUsedInBlade($projectPath))->toContain('adminModal');

    $view = (string) file_get_contents($projectPath.'/resources/views/components/admin-modal.blade.php');

    expect($view)->toContain('x-data="adminModal($wire)"');
});
