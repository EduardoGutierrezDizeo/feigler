<div>
    <h1 class="font-display text-3xl font-semibold text-brand-green sm:text-4xl">
        Bienvenido al panel de Feigler
    </h1>

    <p class="mt-3 max-w-prose text-sm text-clay">
        Aquí encontrarás las herramientas de administración de la tienda.
    </p>

    {{-- Cards sit on parchment, a lighter cream than the page: the difference
         is subtle enough to stay in the same family. Wood appears only as a
         thin rule under each title, a tertiary detail rather than a fill.

         On hover the card gains the single lift shadow of the design system and
         the wood rule stretches a little — the only two motions on the page. --}}
    <div class="mt-12 grid gap-x-8 gap-y-10 sm:grid-cols-2">
        <a
            href="{{ route('admin.categories.index') }}"
            class="group rounded-lg border border-sand bg-parchment p-8 transition-[border-color,box-shadow] duration-200 ease-in-out hover:border-brand-green/40 hover:shadow-lift focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-green"
        >
            <span class="inline-block h-px w-8 bg-wood transition-[width] duration-200 ease-in-out group-hover:w-12" aria-hidden="true"></span>
            <span class="mt-4 block font-display text-xl font-semibold text-brand-green">Categorías</span>
            <span class="mt-2 block text-sm text-clay">Organiza el catálogo por secciones.</span>
        </a>

        <a
            href="{{ route('admin.users.index') }}"
            class="group rounded-lg border border-sand bg-parchment p-8 transition-[border-color,box-shadow] duration-200 ease-in-out hover:border-brand-green/40 hover:shadow-lift focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-green"
        >
            <span class="inline-block h-px w-8 bg-wood transition-[width] duration-200 ease-in-out group-hover:w-12" aria-hidden="true"></span>
            <span class="mt-4 block font-display text-xl font-semibold text-brand-green">Usuarios</span>
            <span class="mt-2 block text-sm text-clay">Gestiona las cuentas internas del personal.</span>
        </a>
    </div>
</div>