<div>
    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <h1 class="text-2xl font-semibold text-gray-900 dark:text-white">
                Usuarios
            </h1>

            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                Gestiona las cuentas internas del personal.
            </p>
        </div>

        <x-primary-button type="button" wire:click="create" class="shrink-0 self-start">
            Nuevo usuario
        </x-primary-button>
    </div>

    @if ($notice)
        <div
            @class([
                'mt-6 flex items-start justify-between gap-4 rounded-lg border px-4 py-3 text-sm',
                'border-green-200 bg-green-50 text-green-800 dark:border-green-700 dark:bg-green-900/30 dark:text-green-300' => $noticeType === 'success',
                'border-red-200 bg-red-50 text-red-800 dark:border-red-700 dark:bg-red-900/30 dark:text-red-300' => $noticeType === 'error',
            ])
            role="status"
        >
            <p>{{ $notice }}</p>

            <button
                type="button"
                wire:click="$set('notice', null)"
                class="shrink-0 font-medium opacity-70 hover:opacity-100"
                aria-label="Cerrar aviso"
            >
                ✕
            </button>
        </div>
    @endif

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
            class="block w-auto rounded-md border-gray-300 text-sm shadow-xs focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 dark:focus:border-indigo-600 dark:focus:ring-indigo-600"
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
                class="shrink-0 text-sm font-medium text-gray-600 hover:text-gray-900 dark:text-gray-400 dark:hover:text-white"
            >
                Limpiar filtros
            </button>
        @endif
    </div>

    <div class="mt-4 overflow-hidden rounded-xl border border-gray-200 bg-white shadow-xs dark:border-gray-700 dark:bg-gray-800">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
                <thead class="bg-gray-50 dark:bg-gray-900/60">
                    <tr class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        <th scope="col" class="px-4 py-3 text-start">Nombre</th>
                        <th scope="col" class="px-4 py-3 text-start">Correo</th>
                        <th scope="col" class="px-4 py-3 text-start">Rol</th>
                        <th scope="col" class="px-4 py-3 text-start">Estado</th>
                        <th scope="col" class="px-4 py-3 text-end">Acciones</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700/60">
                    @forelse ($users as $user)
                        <tr wire:key="user-{{ $user->id }}">
                            <td class="px-4 py-3 font-medium text-gray-900 dark:text-white">{{ $user->name }}</td>
                            <td class="px-4 py-3 text-gray-700 dark:text-gray-300">{{ $user->email }}</td>
                            <td class="px-4 py-3">
                                @foreach ($user->getRoleNames() as $roleName)
                                    <span class="inline-flex items-center rounded-full bg-indigo-50 px-2 py-0.5 text-xs font-medium text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-300">
                                        {{ ucfirst($roleName) }}
                                    </span>
                                @endforeach
                            </td>

                            <td class="px-4 py-3">
                                <button
                                    type="button"
                                    role="switch"
                                    aria-checked="{{ $user->is_active ? 'true' : 'false' }}"
                                    wire:click="toggleActive({{ $user->id }})"
                                    class="inline-flex items-center gap-2 text-sm font-medium"
                                >
                                    <span @class([
                                        'relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition-colors',
                                        'bg-emerald-500' => $user->is_active,
                                        'bg-gray-300 dark:bg-gray-600' => ! $user->is_active,
                                    ])>
                                        <span @class([
                                            'inline-block h-5 w-5 rounded-full bg-white shadow transition-transform',
                                            'translate-x-5' => $user->is_active,
                                            'translate-x-0.5' => ! $user->is_active,
                                        ])></span>
                                    </span>
                                    <span class="text-gray-700 dark:text-gray-300">
                                        {{ $user->is_active ? 'Activo' : 'Inactivo' }}
                                    </span>
                                </button>
                            </td>

                            <td class="px-4 py-3 text-end">
                                <div class="inline-flex items-center gap-3">
                                    <button
                                        type="button"
                                        wire:click="edit({{ $user->id }})"
                                        class="text-sm font-medium text-indigo-600 hover:text-indigo-900 dark:text-indigo-400 dark:hover:text-indigo-300"
                                    >
                                        Editar
                                    </button>

                                    <button
                                        type="button"
                                        x-on:click="confirm(@js('¿Reenviar el correo de definición de contraseña a «' . $user->email . '»?')) && $wire.resendInvitation({{ $user->id }})"
                                        class="text-sm font-medium text-gray-600 hover:text-gray-900 dark:text-gray-400 dark:hover:text-white"
                                    >
                                        Reenviar invitación
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-4 py-10 text-center text-sm text-gray-500 dark:text-gray-400">
                                @if ($search !== '' || $roleFilter !== '')
                                    <p>No se encontraron usuarios que coincidan con los filtros.</p>

                                    <button
                                        type="button"
                                        wire:click="$set('search', ''); $set('roleFilter', '')"
                                        class="mt-2 font-medium text-indigo-600 hover:text-indigo-500 dark:text-indigo-400"
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
        <div class="fixed inset-0 z-40 bg-gray-900/50" wire:click="closeForm" aria-hidden="true"></div>

        <aside
            role="dialog"
            aria-modal="true"
            aria-labelledby="user-form-title"
            class="fixed inset-y-0 end-0 z-50 flex w-full max-w-md flex-col bg-white shadow-xl dark:border-s dark:border-gray-700 dark:bg-gray-800"
        >
            <div class="flex items-center justify-between border-b border-gray-200 px-6 py-4 dark:border-gray-700">
                <h2 id="user-form-title" class="text-lg font-semibold text-gray-900 dark:text-white">
                    {{ $editingId !== null ? 'Editar usuario' : 'Nuevo usuario' }}
                </h2>

                <button
                    type="button"
                    wire:click="closeForm"
                    class="rounded-md p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-600 dark:hover:bg-gray-700 dark:hover:text-gray-300"
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
                        class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-xs focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 dark:focus:border-indigo-600 dark:focus:ring-indigo-600"
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
                    <p class="text-xs text-gray-500 dark:text-gray-400">
                        Al crear, se enviará un correo para que el empleado defina su propia contraseña.
                    </p>
                @endif
            </div>

            <div class="flex justify-end gap-3 border-t border-gray-200 px-6 py-4 dark:border-gray-700">
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