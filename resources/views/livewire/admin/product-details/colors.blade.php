<div>
    {{-- Una sola plantilla de columnas para la cabecera y para todas las filas, con las pistas
         de ancho fijo: si fueran `auto`, cada grid la mediría con su propio contenido y las
         cabeceras se desplazarían respecto a sus celdas. Cabecera y filas deben declarar el
         mismo `gap-x-*`, porque el hueco forma parte del ancho de las columnas. --}}
    @php($columns = 'gap-x-4 md:grid-cols-[minmax(0,1fr)_5rem_9rem_9rem_5.5rem_18rem]')

    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <h2 class="font-display text-3xl font-medium text-tinta sm:text-4xl">
                Colores
            </h2>

            <p class="mt-2 text-sm text-gris-calido">
                Los colores con su código, su hexadecimal y las fotos que los ilustran.
            </p>
        </div>

        <x-primary-button type="button" wire:click="create" class="shrink-0 self-start">
            <span aria-hidden="true">+</span> Nuevo color
        </x-primary-button>
    </div>

    <div class="mt-6 flex items-center gap-3">
        <x-text-input
            variant="pill"
            type="search"
            wire:model.live="search"
            class="block w-full max-w-sm"
            placeholder="Buscar color por nombre…"
            aria-label="Buscar color"
        />

        @if ($search !== '')
            <button
                type="button"
                wire:click="$set('search', '')"
                class="shrink-0 text-sm font-medium text-gris-calido transition-colors duration-150 ease-in-out hover:text-ladrillo active:opacity-80"
            >
                Limpiar
            </button>
        @endif
    </div>

    <div class="tarjeta mt-6 overflow-hidden">
        <div class="etiqueta hidden border-b border-arena px-6 py-4 md:grid {{ $columns }}">
            <span>Color</span>
            <span class="text-center">Código</span>
            <span class="text-center">En uso</span>
            <span class="text-center">Estado</span>
            <span class="text-center">Orden</span>
            <span class="text-end">Acciones</span>
        </div>

        {{-- Filas reordenables. `data-flip-scope` marca las que participan en la animación FLIP. --}}
        <div class="divide-y divide-arena" data-flip-scope>
            @forelse ($rows as $row)
                @php($color = $row['color'])

                <div
                    wire:key="color-{{ $color->id }}"
                    data-flip-row
                    class="px-6 py-5 transition-colors duration-150 ease-in-out hover:bg-hueso/50"
                >
                    <div class="grid grid-cols-2 items-center gap-y-3 {{ $columns }}">
                        <div class="col-span-2 flex items-center gap-3 md:col-span-1">
                            {{-- La muestra es el hexadecimal del propio color, así que la lista no
                                 guarda otro valor que el que se pinta: lo que se ve es lo que
                                 se guarda. --}}
                            <span
                                class="h-8 w-8 shrink-0 rounded-full border border-arena"
                                style="background-color: {{ $color->hex }}"
                                title="{{ $color->hex }}"
                                aria-hidden="true"
                            ></span>

                            <span class="min-w-0 truncate font-display text-2xl font-medium text-tinta" title="{{ $color->name }}">
                                {{ $color->name }}
                            </span>
                        </div>

                        <div class="flex items-center justify-start md:justify-center">
                            <span class="rounded-full bg-hueso px-2.5 py-0.5 text-xs font-medium tracking-wider text-gris-calido">
                                {{ $color->code }}
                            </span>
                        </div>

                        <div class="flex items-center justify-start gap-x-3 text-sm text-gris-calido md:justify-center">
                            @if ($color->variants_count === 0 && $color->images_count === 0)
                                <span class="text-gris-calido/60">Nada</span>
                            @else
                                <span title="Variantes que lo usan">{{ $color->variants_count }} var.</span>
                                <span title="Imágenes tomadas en él">{{ $color->images_count }} img.</span>
                            @endif
                        </div>

                        <x-admin-row-actions
                            :row-id="$color->id"
                            :row-name="$color->name"
                            type="color"
                            :is-active="$color->is_active"
                            :is-first="$row['isFirst']"
                            :is-last="$row['isLast']"
                        />
                    </div>
                </div>
            @empty
                <div class="px-6 py-14 text-center text-sm text-gris-calido">
                    {{-- El arco de las puertas coloniales, como marca del estado vacío. --}}
                    <div class="mx-auto mb-5 h-16 w-11 rounded-arco border border-laton/70" aria-hidden="true"></div>

                    @if ($searching)
                        <p>No se encontraron colores que coincidan con «{{ $search }}».</p>

                        <button
                            type="button"
                            wire:click="$set('search', '')"
                            class="mt-2 font-medium text-ladrillo transition-colors duration-150 ease-in-out hover:text-madera active:opacity-80"
                        >
                            Limpiar búsqueda
                        </button>
                    @else
                        Aún no hay colores en la tienda. Crea el primero con el botón «Nuevo color».
                    @endif
                </div>
            @endforelse
        </div>
    </div>

    <x-admin-modal
        :title="$editingId !== null ? 'Editar color' : 'Nuevo color'"
        title-id="color-form-title"
    >
        <div>
            <x-input-label for="color-name" value="Nombre" />
            <x-text-input
                id="color-name"
                wire:model="name"
                type="text"
                maxlength="100"
                class="mt-1 block w-full"
                placeholder="Ej. Azul Marino"
                data-modal-autofocus
            />
            <x-input-error :messages="$errors->get('name')" class="mt-2" />
        </div>

        {{-- El selector nativo y el texto son los dos extremos del mismo hexadecimal, y por eso
             el campo no lleva `wire:model`: los dos los gobierna `colorHexInput`, que
             escribe en el texto lo que se elige en la muestra y mueve la muestra cuando
             el texto llega a ser un hexadecimal completo. Un input con `wire:model` y
             `x-model` a la vez son dos dueños peleándose por el mismo valor del DOM en
             cada morph. --}}
        <div x-data="colorHexInput($wire, 'hex', '{{ $hex }}')">
            <x-input-label for="color-hex" value="Color" />

            <div class="mt-1 flex items-center gap-3">
                <input
                    type="color"
                    x-model="swatch"
                    x-on:input="syncFromColor()"
                    class="h-11 w-16 shrink-0 cursor-pointer rounded-lg border border-arena bg-crema p-1"
                    aria-label="Elegir el color con el selector"
                />

                <x-text-input
                    id="color-hex"
                    x-model="text"
                    x-on:input="syncFromText()"
                    type="text"
                    maxlength="7"
                    class="block w-full font-mono uppercase"
                    placeholder="#1A2B3C"
                    spellcheck="false"
                />
            </div>

            <x-input-error :messages="$errors->get('hex')" class="mt-2" />
        </div>

        <div>
            <x-input-label for="color-code" value="Código" />

            @if ($codeIsLocked)
                {{-- El código está dentro del SKU de las variantes que ya se venden en este
                     color. Se muestra, no se escribe: ofrecer un campo que el catálogo va a
                     rechazar es peor que decir por qué no se puede cambiar. --}}
                <p class="mt-2 rounded-lg border border-arena bg-hueso/60 px-3 py-2 font-mono text-sm uppercase tracking-wider text-gris-calido">
                    {{ $code }}
                </p>

                <p class="mt-2 text-xs text-gris-calido">
                    Está en uso por sus variantes: forma parte de su SKU y el SKU nunca se reescribe. Desactiva el color y crea otro.
                </p>
            @else
                <x-text-input
                    id="color-code"
                    wire:model="code"
                    type="text"
                    maxlength="3"
                    class="mt-1 block w-full font-mono uppercase"
                    placeholder="Se deriva del nombre"
                />

                <p class="mt-2 text-xs text-gris-calido">
                    Opcional: si lo dejas vacío se deriva del nombre, igual que el catálogo.
                </p>
            @endif

            <x-input-error :messages="$errors->get('code')" class="mt-2" />
        </div>

        <x-slot:footer>
            <x-secondary-button type="button" wire:click="closeForm">
                Cancelar
            </x-secondary-button>

            <x-primary-button type="button" wire:click="save" wire:loading.attr="disabled">
                {{ $editingId !== null ? 'Guardar cambios' : 'Crear color' }}
            </x-primary-button>
        </x-slot:footer>
    </x-admin-modal>
</div>