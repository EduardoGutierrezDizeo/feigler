@props(['disabled' => false])

{{-- The border colour and the ring are animated so the focus state arrives as a
     soft fade instead of a snap. `focus:ring-2` over a translucent colour keeps
     the ring discreet while still satisfying the 3:1 focus indicator ratio
     against the cream surface. --}}
<input @disabled($disabled) {{ $attributes->merge(['class' => 'rounded-md border-sand bg-cream text-charcoal transition-[border-color,box-shadow] duration-150 ease-in-out placeholder:text-clay focus:border-brand-green focus:ring-2 focus:ring-brand-green/25']) }}>
