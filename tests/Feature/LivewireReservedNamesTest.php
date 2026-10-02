<?php

/*
 * Nombres que `$wire` ya ocupa en JavaScript, y que por lo tanto un método de
 * PHP no puede usar: el Proxy de `$wire` los resuelve ANTES de llamar al
 * servidor (ver `generateWireObject` en el mismo archivo, donde `aliases` se
 * consulta antes que `state` y antes que el `getFallback` que sí hace la
 * llamada remota), así que un `wire:click="upload(...)"` reacharía el gestor de
 * archivos de Livewire en vez de a nuestro método.
 *
 * La lista está sacada del código instalado, no de memoria: es el objeto
 * `aliases` de `vendor/livewire/livewire/dist/livewire.esm.js` (líneas
 * 13594-13624, bloque `var aliases = { ... }`), en livewire/livewire v4.4.7.
 * Los nombres con prefijo `$` (`$upload`, `$set`, `$dispatch`...) se descartan
 * porque PHP no admite métodos que empiecen por `$`: lo que choca son los alias
 * sin prefijo. El test de abajo vuelve a leer ese bloque del dist para que una
 * actualización de Livewire que añada un alias no deje la lista desfasada.
 */
const LIVEWIRE_NOMBRES_RESERVADOS = [
    'call',
    'cancelUpload',
    'commit',
    'dirty',
    'dispatch',
    'dispatchEl',
    'dispatchRef',
    'dispatchSelf',
    'dispatchTo',
    'effect',
    'el',
    'entangle',
    'errors',
    'get',
    'hook',
    'id',
    'intercept',
    'interceptAction',
    'interceptMessage',
    'interceptRequest',
    'island',
    'js',
    'on',
    'refs',
    'removeUpload',
    'set',
    'upload',
    'uploadMultiple',
    'watch',
];

/**
 * Every Livewire class of the application, whether or not a test has touched it.
 *
 * @return array<int, class-string>
 */
function componentesLivewire(): array
{
    $prefijo = 'app'.DIRECTORY_SEPARATOR.'Livewire'.DIRECTORY_SEPARATOR;

    return collect(File::allFiles(app_path('Livewire')))
        ->filter(fn (SplFileInfo $archivo): bool => $archivo->getExtension() === 'php')
        ->map(fn (SplFileInfo $archivo): string => 'App\\Livewire\\'.str_replace(
            [DIRECTORY_SEPARATOR, '.php'],
            ['\\', ''],
            Str::after($archivo->getPathname(), $prefijo),
        ))
        ->filter(fn (string $clase): bool => class_exists($clase))
        ->values()
        ->all();
}

test('no Livewire component declares a public method with a name $wire already uses', function () {
    $colisiones = [];

    foreach (componentesLivewire() as $componente) {
        $clase = new ReflectionClass($componente);

        foreach ($clase->getMethods(ReflectionMethod::IS_PUBLIC) as $metodo) {
            // Un método que viene de un trait (`WithFileUploads`) o de una clase
            // padre está escrito en otro archivo: su nombre no es cosa nuestra y
            // Livewire ya sabe qué hacer con él.
            if ($metodo->getFileName() !== $clase->getFileName()) {
                continue;
            }

            if (in_array($metodo->getName(), LIVEWIRE_NOMBRES_RESERVADOS, true)) {
                $colisiones[] = sprintf(
                    '%s::%s() en %s línea %d',
                    $componente,
                    $metodo->getName(),
                    Str::after((string) $metodo->getFileName(), base_path().DIRECTORY_SEPARATOR),
                    $metodo->getStartLine(),
                );
            }
        }
    }

    expect($colisiones)->toBe([]);
});

test('the reserved name list is still the one the installed Livewire ships', function () {
    $dist = base_path('vendor'.DIRECTORY_SEPARATOR.'livewire'.DIRECTORY_SEPARATOR.'livewire'.DIRECTORY_SEPARATOR.'dist'.DIRECTORY_SEPARATOR.'livewire.esm.js');

    if (! file_exists($dist)) {
        $this->markTestSkipped('El JavaScript de Livewire no está instalado en este entorno.');
    }

    expect(LIVEWIRE_NOMBRES_RESERVADOS)->toContain('upload');

    preg_match('/var aliases = \{(?<dentro>[^}]*)\}/', (string) file_get_contents($dist), $bloque);

    preg_match_all('/"([A-Za-z_][A-Za-z0-9_]*)":/', $bloque['dentro'] ?? '', $encontrados);

    $delPaquete = $encontrados[1];
    sort($delPaquete);

    expect($delPaquete)->toBe(LIVEWIRE_NOMBRES_RESERVADOS);
});
