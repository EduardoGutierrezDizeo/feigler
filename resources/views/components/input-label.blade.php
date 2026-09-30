@props(['value'])

<label {{ $attributes->merge(['class' => 'block text-sm font-medium text-brand-green']) }}>
    {{ $value ?? $slot }}
</label>