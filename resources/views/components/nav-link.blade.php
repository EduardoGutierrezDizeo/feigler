@props(['active'])

@php
    $classes = ($active ?? false)
            ? 'inline-flex items-center px-1 pt-1 border-b-2 border-brand-green text-sm font-medium leading-5 text-brand-green transition-colors duration-150 ease-in-out focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-green'
            : 'inline-flex items-center px-1 pt-1 border-b-2 border-transparent text-sm font-medium leading-5 text-clay transition-colors duration-150 ease-in-out hover:border-sand hover:text-brand-green focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-green';
@endphp

<a {{ $attributes->merge(['class' => $classes]) }}>
    {{ $slot }}
</a>