@props(['disabled' => false])

{{-- Same focus treatment as `x-text-input`, so every field in the interface
     signals focus the same way. Used by the selects that previously inlined the
     ring utilities in each view. --}}
<select @disabled($disabled) {{ $attributes->merge(['class' => 'rounded-md border-sand bg-cream text-sm text-charcoal transition-[border-color,box-shadow] duration-150 ease-in-out focus:border-brand-green focus:ring-2 focus:ring-brand-green/25']) }}>
    {{ $slot }}
</select>
