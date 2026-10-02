@props(['value'])

<label {{ $attributes->merge(['class' => 'block text-[0.6875rem] font-medium uppercase tracking-[0.18em] text-gris-calido']) }}>
    {{ $value ?? $slot }}
</label>