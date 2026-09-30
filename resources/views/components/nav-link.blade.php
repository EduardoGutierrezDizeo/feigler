@props(['active'])

@php
$classes = ($active ?? false)
            ? 'inline-flex items-center px-1 pt-1 border-b-2 border-terracotta text-sm font-medium leading-5 text-brand-green focus:outline-hidden focus:border-terracotta transition duration-150 ease-in-out'
            : 'inline-flex items-center px-1 pt-1 border-b-2 border-transparent text-sm font-medium leading-5 text-clay hover:text-brand-green hover:border-sand focus:outline-hidden focus:text-brand-green focus:border-sand transition duration-150 ease-in-out';
@endphp

<a {{ $attributes->merge(['class' => $classes]) }}>
    {{ $slot }}
</a>