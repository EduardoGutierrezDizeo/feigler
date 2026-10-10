{{--
    Selector de cantidad desplegable de la tienda (ficha y carrito).

    El <select> nativo se mantiene oculto como fuente de verdad: recibe el
    `id`, el enlace (`x-model`/`@change`) y las <option> del llamador, y sigue
    siendo el que leen los tests y los componentes contenedores. Encima se
    pinta el control (botón pastilla + panel) del componente Alpine
    `quantitySelect`, con las mismas clases y tokens que el selector de
    categorías del panel, funcional también al toque.

    `label` es la expresión Alpine que muestra el botón (la cantidad del
    componente contenedor); `disabled` la que lo apaga. Como el panel vive en el
    scope encadenado de Alpine, ambas se leen del componente padre.
--}}
@props(['label' => 'quantity', 'disabled' => 'false'])

<div x-data="quantitySelect()" x-cloak @click.outside="close()" class="relative">
    <select
        x-ref="select"
        tabindex="-1"
        aria-hidden="true"
        :disabled="{{ $disabled }}"
        {{ $attributes->merge(['class' => 'sr-only']) }}
    >
        {{ $slot }}
    </select>

    <button
        type="button"
        x-ref="button"
        @click="toggle()"
        @keydown="onButtonKeydown($event)"
        aria-haspopup="listbox"
        :aria-expanded="open.toString()"
        :aria-controls="$refs.panel?.id || null"
        :disabled="{{ $disabled }}"
        class="flex w-full items-center justify-between rounded-full border border-arena bg-crema py-2.5 ps-5 pe-10 text-sm text-tinta shadow-lift transition duration-150 ease-in-out focus:border-laton focus:ring-1 focus:ring-laton focus:outline-none disabled:cursor-not-allowed disabled:opacity-60"
    >
        <span x-text="{{ $label }}" class="truncate"></span>
        <svg class="pointer-events-none size-4 shrink-0 text-gris-calido" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
            <path fill-rule="evenodd" d="M5.22 8.22a.75.75 0 0 1 1.06 0L10 11.94l3.72-3.72a.75.75 0 1 1 1.06 1.06l-4.25 4.25a.75.75 0 0 1-1.06 0L5.22 9.28a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd" />
        </svg>
    </button>

    <div
        x-ref="panel"
        x-show="open"
        @keydown="onPanelKeydown($event)"
        role="listbox"
        tabindex="-1"
        class="absolute z-30 mt-2 max-h-64 w-full overflow-y-auto rounded-xl border border-arena bg-crema shadow-suave"
        style="display: none;"
    >
        <template x-for="(option, idx) in options" :key="idx">
            <button
                type="button"
                role="option"
                :data-option-index="idx"
                :aria-selected="(option.value == {{ $label }}).toString()"
                :aria-disabled="(option.disabled).toString()"
                :disabled="option.disabled"
                @click="choose(option)"
                @mouseenter="$el.focus({ preventScroll: true })"
                :class="{
                    'px-3 py-2 text-sm text-left w-full flex items-center justify-between': true,
                    'opacity-40 cursor-not-allowed': option.disabled,
                    'hover:bg-hueso focus:bg-hueso focus:outline-none': ! option.disabled,
                    'text-gris-calido': option.disabled,
                    'text-tinta': ! option.disabled,
                }"
            >
                <span x-text="option.text" class="truncate"></span>
                <template x-if="option.value == {{ $label }}">
                    <svg class="h-4 w-4 text-verde" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path fill-rule="evenodd" d="M16.704 5.29a1 1 0 0 1 0 1.42l-7.5 7.5a1 1 0 0 1-1.42 0l-3.5-3.5a1 1 0 1 1 1.42-1.42L8.5 12.08l6.79-6.79a1 1 0 0 1 1.42 0Z" clip-rule="evenodd" />
                    </svg>
                </template>
            </button>
        </template>
    </div>
</div>
