<div>
    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <h1 class="font-display text-4xl font-medium text-tinta sm:text-5xl">
                Usuarios
            </h1>

            <p class="mt-2 text-sm text-gris-calido">
                Gestiona las cuentas internas del personal.
            </p>
        </div>

        {{-- Crear usuario sigue siendo un botón que abre el modal (no un formulario en la vista). --}}
        <x-primary-button type="button" wire:click="create" class="shrink-0 self-start">
            <span aria-hidden="true">+</span> Nuevo usuario
        </x-primary-button>
    </div>

    <div class="mt-8 flex flex-col gap-3 sm:flex-row sm:items-center">
        <x-text-input
            variant="pill"
            type="search"
            wire:model.live="search"
            class="block w-full max-w-sm"
            placeholder="Buscar por nombre o correo…"
            aria-label="Buscar usuario"
        />

        <x-select-input
            variant="pill"
            wire:model.live="roleFilter"
            class="block w-auto"
            aria-label="Filtrar por rol"
        >
            <option value="">Todos los roles</option>
            @foreach ($internalRoles as $roleName)
                <option value="{{ $roleName }}">{{ ucfirst($roleName) }}</option>
            @endforeach
        </x-select-input>

        @if ($search !== '' || $roleFilter !== '')
            <button
                type="button"
                wire:click="$set('search', ''); $set('roleFilter', '')"
                class="shrink-0 text-sm font-medium text-gris-calido transition-colors duration-150 ease-in-out hover:text-ladrillo active:opacity-80"
            >
                Limpiar filtros
            </button>
        @endif
    </div>

    <div class="tarjeta mt-6 overflow-hidden">
        <div class="overflow-x-auto">
            {{-- `divide-y` va en el `tbody` (en la tabla no llega a las filas). `w-full min-w-full`
                 reparte el sobrante entre columnas: cabecera y celdas comparten ancho, lo que
                 mantiene «Estado» encima de su interruptor. --}}
            <table class="w-full min-w-full text-sm">
                <thead>
                    <tr class="etiqueta border-b border-arena">
                        <th scope="col" class="px-6 py-4 text-start">Nombre</th>
                        <th scope="col" class="px-6 py-4 text-start">Correo electrónico</th>
                        <th scope="col" class="px-6 py-4 text-start">Rol</th>
                        <th scope="col" class="px-6 py-4 text-center">Estado</th>
                        <th scope="col" class="px-6 py-4 text-end">Acciones</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-arena">
                    @forelse ($users as $user)
                        @php
                            $initials = \Illuminate\Support\Str::of($user->name)
                                ->explode(' ')
                                ->filter()
                                ->take(2)
                                ->map(fn ($part) => \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($part, 0, 1)))
                                ->implode('');
                        @endphp

                        <tr wire:key="user-{{ $user->id }}" class="transition-colors duration-150 ease-in-out hover:bg-hueso/50">
                            <td class="px-6 py-4">
                                <div class="flex items-center gap-3">
                                    <span class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-laton-degradado text-xs font-semibold text-verde-hondo" aria-hidden="true">{{ $initials }}</span>
                                    <span class="font-medium text-tinta">{{ $user->name }}</span>
                                </div>
                            </td>
                            <td class="px-6 py-4 text-gris-calido">{{ $user->email }}</td>
                            <td class="px-6 py-4">
                                @foreach ($user->getRoleNames() as $roleName)
                                    <span class="inline-flex items-center rounded-full border border-arena bg-hueso px-2.5 py-1 text-xs font-medium text-verde">
                                        {{ ucfirst($roleName) }}
                                    </span>
                                @endforeach
                            </td>

                            <td class="px-6 py-4 text-center">
                                <button
                                    type="button"
                                    role="switch"
                                    aria-checked="{{ $user->is_active ? 'true' : 'false' }}"
                                    wire:click="toggleActive({{ $user->id }})"
                                    class="inline-flex items-center gap-2 text-sm"
                                >
                                    <span @class([
                                        'relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition-colors duration-200 ease-in-out',
                                        'bg-verde' => $user->is_active,
                                        'bg-arena' => ! $user->is_active,
                                    ])>
                                        <span @class([
                                            'inline-block h-5 w-5 rounded-full bg-crema shadow-lift transition-transform duration-200 ease-in-out',
                                            'translate-x-5' => $user->is_active,
                                            'translate-x-0.5' => ! $user->is_active,
                                        ])></span>
                                    </span>
                                    <span class="text-tinta">
                                        {{ $user->is_active ? 'Activo' : 'Inactivo' }}
                                    </span>
                                </button>
                            </td>

                            <td class="px-6 py-4 text-end">
                                <div class="inline-flex items-center gap-4">
                                    <button
                                        type="button"
                                        wire:click="edit({{ $user->id }})"
                                        class="border-b border-laton pb-px text-sm font-medium text-verde transition-colors duration-150 ease-in-out hover:text-verde-hondo active:opacity-80"
                                    >
                                        Editar
                                    </button>

                                    <button
                                        type="button"
                                        x-on:click="$dispatch('ask-confirm', {
                                            title: 'Reenviar invitación',
                                            message: @js('Se enviará un correo a «' . $user->email . '» para que defina su contraseña.'),
                                            confirmLabel: 'Enviar correo',
                                            onConfirm: () => $wire.resendInvitation({{ $user->id }}),
                                        })"
                                        class="text-sm font-medium text-gris-calido transition-colors duration-150 ease-in-out hover:text-verde active:opacity-80"
                                    >
                                        Reenviar invitación
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-6 py-12 text-center text-sm text-gris-calido">
                                <div class="mx-auto mb-5 h-16 w-11 rounded-arco border border-laton/70" aria-hidden="true"></div>

                                @if ($search !== '' || $roleFilter !== '')
                                    <p>No se encontraron usuarios que coincidan con los filtros.</p>

                                    <button
                                        type="button"
                                        wire:click="$set('search', ''); $set('roleFilter', '')"
                                        class="mt-2 font-medium text-ladrillo transition-colors duration-150 ease-in-out hover:text-madera active:opacity-80"
                                    >
                                        Limpiar filtros
                                    </button>
                                @else
                                    Aún no hay usuarios internos. Crea el primero con el botón «Nuevo usuario».
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <x-admin-modal
        :title="$editingId !== null ? 'Editar usuario' : 'Nuevo usuario'"
        title-id="user-form-title"
    >
        <div>
            <x-input-label for="user-name" value="Nombre" />
            <x-text-input
                id="user-name"
                wire:model="name"
                type="text"
                class="mt-1 block w-full"
                placeholder="Ej. Ana López"
                data-modal-autofocus
            />
            <x-input-error :messages="$errors->get('name')" class="mt-2" />
        </div>

        <div>
            <x-input-label for="user-email" value="Correo electrónico" />
            <x-text-input
                id="user-email"
                wire:model="email"
                type="email"
                class="mt-1 block w-full"
                placeholder="Ej. ana@feigler.com"
            />
            <x-input-error :messages="$errors->get('email')" class="mt-2" />
        </div>

        <div>
            <x-input-label for="user-role" value="Rol" />
            <x-select-input
                id="user-role"
                wire:model="role"
                class="mt-1 block w-full"
            >
                <option value="">— Selecciona un rol —</option>
                @foreach ($internalRoles as $roleName)
                    <option value="{{ $roleName }}" @selected($role === $roleName)>
                        {{ ucfirst($roleName) }}
                    </option>
                @endforeach
            </x-select-input>
            <x-input-error :messages="$errors->get('role')" class="mt-2" />
        </div>

        @if ($editingId === null)
            <p class="text-xs text-gris-calido">
                Al crear, se enviará un correo para que el empleado defina su propia contraseña.
            </p>
        @endif

        <x-slot:footer>
            <x-secondary-button type="button" wire:click="closeForm">
                Cancelar
            </x-secondary-button>

            <x-primary-button type="button" wire:click="save" wire:loading.attr="disabled">
                {{ $editingId !== null ? 'Guardar cambios' : 'Crear usuario' }}
            </x-primary-button>
        </x-slot:footer>
    </x-admin-modal>
</div>