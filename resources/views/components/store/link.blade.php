{{--
    Enlace secundario de la tienda.
    Con `href` se pinta un <a>; sin él, un <button type="submit"> (para acciones
    dentro de un formulario, como cerrar sesión), con el mismo aspecto de enlace.
--}}
@props(['href' => null])

@php
    $classes = 'text-sm text-gris-calido underline underline-offset-4 transition-colors duration-150 ease-in-out hover:text-verde focus:outline-2 focus:outline-offset-2 focus:outline-verde';
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $classes]) }}>{{ $slot }}</a>
@else
    <button type="submit" {{ $attributes->merge(['class' => $classes]) }}>{{ $slot }}</button>
@endif
