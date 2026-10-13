<div>
    <h1 class="font-display text-3xl font-semibold text-brand-green sm:text-4xl">
        Bienvenido al panel de Feigler
    </h1>

    <p class="mt-3 max-w-prose text-sm text-clay">
        Aquí encontrarás las herramientas de administración de la tienda.
    </p>

    {{-- Cards sit on parchment, a lighter cream than the page: the difference
         is subtle enough to stay in the same family. Each module carries its
         sidebar icon in a soft green square, a tertiary detail rather than a
         fill. The same icon in both places keeps the map consistent.

         On hover the card gains the single lift shadow of the design system and
         the icon square deepens a step — the only two motions on the page. --}}
    @php
        $modules = [
            ['admin.home-page.index', 'home-page', 'Vista principal', 'Portada, destacados y categorías de la tienda.'],
            ['admin.product-details.index', 'product-details', 'Detalles de productos', 'Categorías, tallas, colores y materiales.'],
            ['admin.products.index', 'products', 'Productos', 'Prendas, variantes e imágenes del catálogo.'],
            ['admin.users.index', 'users', 'Usuarios', 'Gestiona las cuentas internas del personal.'],
        ];
    @endphp

    <div class="mt-12 grid gap-x-8 gap-y-10 sm:grid-cols-2">
        @foreach ($modules as [$route, $icon, $label, $description])
            <a
                href="{{ route($route) }}"
                class="group rounded-lg border border-sand bg-parchment p-8 transition-[border-color,box-shadow] duration-200 ease-in-out hover:border-brand-green/40 hover:shadow-lift focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-green"
            >
                <span class="inline-flex h-10 w-10 items-center justify-center rounded-lg bg-brand-green/5 text-brand-green transition-colors duration-200 ease-in-out group-hover:bg-brand-green/10">
                    <x-admin-module-icon :name="$icon" class="h-5 w-5" />
                </span>
                <span class="mt-4 block font-display text-xl font-semibold text-brand-green">{{ $label }}</span>
                <span class="mt-2 block text-sm text-clay">{{ $description }}</span>
            </a>
        @endforeach
    </div>
</div>
