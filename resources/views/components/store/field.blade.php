{{--
    Campo de formulario de la tienda: etiqueta, input y errores del campo.
    Los atributos extra (value, required, autocomplete, inputmode, …) se aplican al input.
    `name` se usa como id y name a la vez para que la etiqueta quede siempre asociada.
--}}
@props(['label', 'name', 'messages' => [], 'type' => 'text'])

<div>
    <label for="{{ $name }}" class="block text-[0.6875rem] font-medium uppercase tracking-[0.18em] text-gris-calido">{{ $label }}</label>

    <input
        id="{{ $name }}"
        name="{{ $name }}"
        type="{{ $type }}"
        {{ $attributes->merge(['class' => 'mt-1.5 block w-full rounded-xl border border-arena bg-crema px-4 py-2.5 text-sm text-tinta placeholder:text-gris-calido/70 shadow-none transition focus:border-verde focus:ring-1 focus:ring-verde/20']) }}
    >

    @if ($messages)
        <ul class="mt-1.5 space-y-1 text-sm text-ladrillo">
            @foreach ((array) $messages as $message)
                <li>{{ $message }}</li>
            @endforeach
        </ul>
    @endif
</div>
