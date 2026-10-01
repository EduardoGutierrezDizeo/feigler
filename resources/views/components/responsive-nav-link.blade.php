@props(['active'])

@php
$classes = ($active ?? false)
            ? 'block w-full border-s-2 border-brand-green bg-sand/50 ps-3 pe-4 py-2 text-start text-base font-medium text-brand-green transition-colors duration-150 ease-in-out'
            : 'block w-full border-s-2 border-transparent ps-3 pe-4 py-2 text-start text-base font-medium text-clay transition-colors duration-150 ease-in-out hover:bg-sand/40 hover:text-brand-green';
@endphp

<a {{ $attributes->merge(['class' => $classes]) }}>
    {{ $slot }}
</a>