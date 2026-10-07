<?php

/*
 |--------------------------------------------------------------------------
 | Red de seguridad estática para el panel de <x-select-input>
 |--------------------------------------------------------------------------
 |
 | El panel es `position: fixed` con su propio `overflow-y-auto`. Dos fallos
 | de escritorio no dejan rastro en los tests de Feature, porque no ejecutan
 | JavaScript:
 |
 |   1. Un listener global de scroll (captura en window) cerraba el panel
 |      también con el scroll interno del propio panel: la rueda del ratón
 |      no lo movía y la lista se cerraba.
 |   2. focus() sin preventScroll desplaza el elemento a la vista, lo que
 |      arrastra a los ancestros, dispara otro scroll (que cerraba el panel)
 |      y hace pelear a la rueda con el foco.
 |
 | Aquí solo se leen los fuentes: es una red de seguridad débil, no una
 | prueba de comportamiento. Comprueba forma, no ejecución. La verificación
 | real es manual, con ratón: rueda y barra de scroll dentro del panel,
 | pasar el ratón por las opciones, flechas/Home/End/typeahead, scroll de la
 | ventana y resize con el panel abierto, y scroll de un contenedor externo
 | (modal) que deba cerrarlo.
 |
 */

/**
 * Ruta absoluta del componente y su controlador Alpine.
 */
function selectInputPaths(): array
{
    $projectPath = dirname(__DIR__, 2);

    return [
        $projectPath.'/resources/js/alpine/select-input.js',
        $projectPath.'/resources/views/components/select-input.blade.php',
    ];
}

/**
 * Fuentes del componente sin comentarios, para que una mención dentro de un
 * comentario no haga pasar ninguna comprobación.
 */
function selectInputSources(): array
{
    [$jsPath, $bladePath] = selectInputPaths();

    $js = (string) file_get_contents($jsPath);
    $js = preg_replace('#/\*.*?\*/#s', '', $js);
    $js = preg_replace('#^\s*//.*$#m', '', (string) $js);

    return [(string) $js, (string) file_get_contents($bladePath)];
}

test('the select panel ignores its own scroll events and still closes on window scroll or resize', function () {
    [$js] = selectInputSources();

    // El scroll interno del panel (rueda y barra) llega con el panel como
    // target: ese caso no debe cerrar. Cualquier otro target (window, el
    // documento, un modal) sí.
    expect($js)
        ->toContain('event.target === panel')
        ->toContain('panel.contains(event.target)')
        ->toContain("window.addEventListener('scroll', closeOnScroll, true)");

    // resize va dirigido a window, que no es un Node: su handler no puede
    // depender de event.target, o el cierre por resize fallaría.
    $start = strpos($js, 'const closeOnResize');
    $end = strpos($js, "window.addEventListener('resize'");

    expect($start)->not->toBeFalse();
    expect($end)->not->toBeFalse();

    expect(substr($js, $start, $end - $start))
        ->not->toContain('event');

    expect($js)->toContain("window.addEventListener('resize', closeOnResize)");
});

test('every focus call in the select component opts out of scrolling and keyboard scrolling stays inside the panel', function () {
    [$js, $blade] = selectInputSources();

    // Ningún focus() de este componente puede desplazar la vista: ni sobre el
    // panel, ni sobre las opciones, ni el que devuelve el foco al botón.
    preg_match_all('/\.focus\(([^)]*)\)/', $js.$blade, $matches);

    expect($matches[1])->not->toBeEmpty();

    foreach (array_unique($matches[1]) as $arguments) {
        expect($arguments)->toContain('preventScroll: true');
    }

    // El ratón solo enfoca: nunca pide desplazamiento, para que pasar por
    // las opciones no mueva la lista ni la cierre.
    expect($blade)->toContain('@mouseenter="$el.focus({ preventScroll: true })"');

    // El desplazamiento por teclado y el de apertura se hacen moviendo el
    // scrollTop del panel, nunca con el desplazamiento del navegador, que
    // arrastraría a los contenedores externos.
    expect($js)
        ->not->toContain('scrollIntoView')
        ->toContain('el.offsetTop')
        ->toContain('el.offsetHeight')
        ->toContain('panel.scrollTop = ');
});
