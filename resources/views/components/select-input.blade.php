@props(['disabled' => false, 'variant' => 'field'])

@php
    $classes = $variant === 'pill'
        ? 'rounded-full border border-arena bg-crema py-2.5 ps-5 pe-10 text-sm text-tinta shadow-none focus:border-laton focus:ring-1 focus:ring-laton'
        : 'rounded-none border-0 border-b border-arena bg-transparent px-0 py-2 pe-8 text-sm text-tinta shadow-none focus:border-verde focus:outline-none focus:ring-0';
@endphp

<select @disabled($disabled) {{ $attributes->merge(['class' => $classes]) }}>
    {{ $slot }}
</select>