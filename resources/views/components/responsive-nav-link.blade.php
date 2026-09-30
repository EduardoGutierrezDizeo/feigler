@props(['active'])

@php
$classes = ($active ?? false)
            ? 'block w-full border-l-4 border-terracotta bg-cream ps-3 pe-4 py-2 text-start text-base font-medium text-brand-green focus:outline-hidden focus:border-terracotta transition duration-150 ease-in-out'
            : 'block w-full border-l-4 border-transparent ps-3 pe-4 py-2 text-start text-base font-medium text-clay hover:border-sand hover:bg-cream hover:text-brand-green focus:outline-hidden focus:border-sand focus:bg-cream focus:text-brand-green transition duration-150 ease-in-out';
@endphp

<a {{ $attributes->merge(['class' => $classes]) }}>
    {{ $slot }}
</a>