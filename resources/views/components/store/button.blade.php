{{-- Botón principal de la tienda. Por defecto ocupa el ancho y envía el formulario. --}}
<button {{ $attributes->merge(['type' => 'submit', 'class' => 'inline-flex w-full items-center justify-center gap-2 rounded-full bg-verde-degradado px-6 py-2.5 text-sm font-medium text-crema shadow-boton transition duration-150 ease-in-out hover:brightness-110 focus:outline-2 focus:outline-offset-2 focus:outline-verde active:opacity-90 disabled:cursor-not-allowed disabled:opacity-60']) }}>
    {{ $slot }}
</button>
