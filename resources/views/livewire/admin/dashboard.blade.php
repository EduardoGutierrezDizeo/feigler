<div>
    <h1 class="font-display text-3xl font-semibold text-brand-green">
        Bienvenido al panel de Feigler
    </h1>

    <p class="mt-2 text-sm text-clay">
        Aquí encontrarás las herramientas de administración de la tienda.
    </p>

    <div class="mt-8 grid gap-6 sm:grid-cols-2">
        <a
            href="{{ route('admin.categories.index') }}"
            class="group border border-sand bg-parchment p-6 shadow-xs transition duration-150 ease-in-out hover:border-terracotta"
        >
            <span class="font-display text-xl font-semibold text-brand-green">Categorías</span>
            <span class="mt-2 block text-sm text-clay">Organiza el catálogo en categorías y subcategorías.</span>
        </a>

        <a
            href="{{ route('admin.users.index') }}"
            class="group border border-sand bg-parchment p-6 shadow-xs transition duration-150 ease-in-out hover:border-terracotta"
        >
            <span class="font-display text-xl font-semibold text-brand-green">Usuarios</span>
            <span class="mt-2 block text-sm text-clay">Gestiona las cuentas internas del personal.</span>
        </a>
    </div>
</div>