@props(['disabled' => false, 'variant' => 'field'])

{{-- `field` (por defecto): campo de formulario con solo filete inferior.
     `pill`: píldora para buscadores y filtros. --}}
@php
    $classes = $variant === 'pill'
        ? 'rounded-full border border-arena bg-crema px-5 py-2.5 text-sm text-tinta placeholder:text-gris-calido/70 shadow-none focus:border-laton focus:ring-1 focus:ring-laton'
        : 'rounded-none border-0 border-b border-arena bg-transparent px-0 py-2 text-sm text-tinta placeholder:text-gris-calido/70 shadow-none focus:border-verde focus:outline-none focus:ring-0';
@endphp

<input @disabled($disabled) {{ $attributes->merge(['class' => $classes]) }}>