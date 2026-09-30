@props(['disabled' => false])

<input @disabled($disabled) {{ $attributes->merge(['class' => 'rounded-md border-sand bg-parchment text-charcoal shadow-xs placeholder:text-clay focus:border-brand-green focus:ring-brand-green']) }}>