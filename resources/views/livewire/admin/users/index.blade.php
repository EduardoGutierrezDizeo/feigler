<div>
    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <h1 class="font-display text-3xl font-semibold text-brand-green">
                Usuarios
            </h1>

            <p class="mt-1 text-sm text-clay">
                Gestiona las cuentas internas del personal.
            </p>
        </div>

        <x-primary-button type="button" wire:click="create" class="shrink-0 self-start">
            Nuevo usuario
        </x-primary-button>
    </div>

    <div class="mt-6 flex flex-col gap-3 sm:flex-row sm:items-center">
        <x-text-input
            type="search"
            wire:model.live="search"
            class="block w-full max-w-sm"
            placeholder="Buscar por nombre o correo…"
            aria-label="Buscar usuario"
        />

        <select
            wire:model.live="roleFilter"
            class="block w-auto rounded-md border-sand bg-parchment text-sm text-charcoal shadow-xs focus:border-brand-green focus:ring-brand-green"
            aria-label="Filtrar por rol"
        >
            <option value="">Todos los roles</option>
            @foreach ($internalRoles as $roleName)
                <option value="{{ $roleName }}">{{ ucfirst($roleName) }}</option>
            @endforeach
        </select>

        @if ($search !== '' || $roleFilter !== '')
            <button
                type="button"
                wire:click="$set('search', ''); $set('roleFilter', '')"
                class="shrink-0 text-sm font-medium text-clay hover:text-terracotta"
            >
                Limpiar filtros
            </button>
        @endif
    </div>

    <div class="mt-4 overflow-hidden rounded-xl border border-sand bg-parchment shadow-xs">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-sand text-sm">
                <thead class="bg-cream">
                    <tr class="text-xs font-semibold uppercase tracking-widest text-clay">
                        <th scope="col" class="px-6 py-4 text-start">Nombre</th>
                        <th scope="col" class="px-6 py-4 text-start">Correo</th>
                        <th scope="col" class="px-6 py-4 text-start">Rol</th>
                        <th scope="col" class="px-6 py-4 text-start">Estado</th>
                        <th scope="col" class="px-6 py-4 text-end">Acciones</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-sand">
                    @forelse ($users as $user)
                        <tr wire:key="user-{{ $user->id }}" class="transition duration-150 ease-in-out hover:bg-cream/60">
                            <td class="px-6 py-5 font-medium text-charcoal">{{ $user->name }}</td>
                            <td class="px-6 py-5 text-clay">{{ $user->email }}</td>
                            <td class="px-6 py-5">
                                @foreach ($user->getRoleNames() as $roleName)
                                    <span class="inline-flex items-center rounded-full bg-cream px-2.5 py-1 text-xs font-medium text-brand-green ring-1 ring-gold/40">
                                        {{ ucfirst($roleName) }}
                                    </span>
                                @endforeach
                            </td>

                            <td class="px-6 py-5">
                                <button
                                    type="button"
                                    role="switch"
                                    aria-checked="{{ $user->is_active ? 'true' : 'false' }}"
                                    wire:click="toggleActive({{ $user->id }})"
                                    class="inline-flex items-center gap-2 text-sm font-medium"
                                >
                                    <span @class([
                                        'relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition-colors',
                                        'bg-brand-green' => $user->is_active,
                                        'bg-sand' => ! $user->is_active,
                                    ])>
                                        <span @class([
                                            'inline-block h-5 w-5 rounded-full bg-parchment shadow transition-transform',
                                            'translate-x-5' => $user->is_active,
                                            'translate-x-0.5' => ! $user->is_active,
                                        ])></span>
                                    </span>
                                    <span class="text-charcoal">
                                        {{ $user->is_active ? 'Activo' : 'Inactivo' }}
                                    </span>
                                </button>
                            </td>

                            <td class="px-6 py-5 text-end">
                                <div class="inline-flex items-center gap-4">
                                    <button
                                        type="button"
                                        wire:click="edit({{ $user->id }})"
                                        class="text-sm font-medium text-brand-green hover:text-terracotta"
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
                                        class="text-sm font-medium text-clay hover:text-terracotta"
                                    >
                                        Reenviar invitación
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-6 py-12 text-center text-sm text-clay">
                                @if ($search !== '' || $roleFilter !== '')
                                    <p>No se encontraron usuarios que coincidan con los filtros.</p>

                                    <button
                                        type="button"
                                        wire:click="$set('search', ''); $set('roleFilter', '')"
                                        class="mt-2 font-medium text-terracotta hover:text-wood"
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

    @if ($showForm)
        <div class="fixed inset-0 z-40 bg-charcoal/60" wire:click="closeForm" aria-hidden="true"></div>

        <aside
            role="dialog"
            aria-modal="true"
            aria-labelledby="user-form-title"
            class="fixed inset-y-0 end-0 z-50 flex w-full max-w-md flex-col border-s border-wood/40 bg-parchment shadow-xl"
        >
            <div class="flex items-center justify-between border-b border-sand px-6 py-4">
                <h2 id="user-form-title" class="font-display text-xl font-semibold text-brand-green">
                    {{ $editingId !== null ? 'Editar usuario' : 'Nuevo usuario' }}
                </h2>

                <button
                    type="button"
                    wire:click="closeForm"
                    class="rounded-md p-1 text-clay hover:bg-cream hover:text-terracotta"
                    aria-label="Cerrar formulario"
                >
                    <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>

            <div class="flex-1 space-y-5 overflow-y-auto px-6 py-6">
                <div>
                    <x-input-label for="user-name" value="Nombre" />
                    <x-text-input
                        id="user-name"
                        wire:model="name"
                        type="text"
                        class="mt-1 block w-full"
                        placeholder="Ej. Ana López"
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
                    <select
                        id="user-role"
                        wire:model="role"
                        class="mt-1 block w-full rounded-md border-sand bg-parchment text-sm text-charcoal shadow-xs focus:border-brand-green focus:ring-brand-green"
                    >
                        <option value="">— Selecciona un rol —</option>
                        @foreach ($internalRoles as $roleName)
                            <option value="{{ $roleName }}" @selected($role === $roleName)>
                                {{ ucfirst($roleName) }}
                            </option>
                        @endforeach
                    </select>
                    <x-input-error :messages="$errors->get('role')" class="mt-2" />
                </div>

                @if ($editingId === null)
                    <p class="text-xs text-clay">
                        Al crear, se enviará un correo para que el empleado defina su propia contraseña.
                    </p>
                @endif
            </div>

            <div class="flex flex-col-reverse gap-3 border-t border-sand px-6 py-4 sm:flex-row sm:justify-end">
                <x-secondary-button type="button" wire:click="closeForm">
                    Cancelar
                </x-secondary-button>

                <x-primary-button type="button" wire:click="save" wire:loading.attr="disabled">
                    {{ $editingId !== null ? 'Guardar cambios' : 'Crear usuario' }}
                </x-primary-button>
            </div>
        </aside>
    @endif
</div>