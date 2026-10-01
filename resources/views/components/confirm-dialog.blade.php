{{--
    Diálogo de confirmación global.

    Una sola instancia por página. Para abrirlo desde cualquier vista (incluidas
    las filas de un componente Livewire) se despacha el evento `ask-confirm`:

        $dispatch('ask-confirm', {
            title: 'Eliminar categoría',
            message: '…',
            confirmLabel: 'Eliminar',
            destructive: true,
            onConfirm: () => $wire.delete(1),
        })

    `onConfirm` viaja dentro del `detail` del evento, así que se ejecuta con el
    ámbito Alpine de la fila que lo espachó. Escape, el fondo y «Cancelar» lo
    descartan.
--}}
<div
    x-cloak
    x-data="confirmDialog"
    x-on:ask-confirm.window="ask($event)"
    x-on:keydown.escape.window="$data.open && reset()"
    x-on:keydown.tab="trap($event)"
    class="pointer-events-none fixed inset-0 z-[70] flex items-end justify-center p-4 sm:items-center"
    role="dialog"
    aria-modal="true"
    aria-labelledby="confirm-dialog-title"
    aria-describedby="confirm-dialog-message"
>
    <div
        x-cloak
        x-show="$data.open"
        class="pointer-events-auto absolute inset-0 bg-charcoal/40"
        x-on:click="reset()"
        aria-hidden="true"
        x-transition:enter="duration-[350ms] ease-out"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="duration-[250ms] ease-in"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
    ></div>

    <div
        x-cloak
        x-show="$data.open"
        class="pointer-events-auto relative flex w-full max-w-md flex-col overflow-hidden rounded-xl border border-sand bg-cream shadow-lg"
        x-transition:enter="duration-[350ms] ease-[cubic-bezier(0.22,1,0.36,1)]"
        x-transition:enter-start="translate-y-4 opacity-0 sm:translate-y-0 sm:scale-95"
        x-transition:enter-end="translate-y-0 opacity-100 sm:scale-100"
        x-transition:leave="duration-[250ms] ease-in"
        x-transition:leave-start="translate-y-0 opacity-100 sm:scale-100"
        x-transition:leave-end="translate-y-4 opacity-0 sm:translate-y-0 sm:scale-95"
    >
        <div class="flex items-start gap-4 border-b border-sand px-6 py-5">
            <span
                class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-full"
                :class="destructive ? 'bg-terracotta/10 text-terracotta' : 'bg-brand-green/10 text-brand-green'"
                aria-hidden="true"
            >
                <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z" />
                </svg>
            </span>

            <div class="min-w-0 flex-1">
                <h2 id="confirm-dialog-title" class="font-display text-lg font-semibold text-brand-green" x-text="title"></h2>

                <p id="confirm-dialog-message" class="mt-1 text-sm text-clay" x-show="$data.message" x-text="message"></p>
            </div>
        </div>

        {{ $slot }}

        <div class="flex flex-col-reverse gap-3 border-t border-sand px-6 py-4 sm:flex-row sm:justify-end">
            <x-secondary-button type="button" x-on:click="reset()">
                Cancelar
            </x-secondary-button>

            <button
                type="button"
                class="inline-flex items-center justify-center rounded-md px-5 py-2.5 text-sm font-semibold text-cream transition-colors duration-150 ease-in-out focus-visible:outline-2 focus-visible:outline-offset-2"
                :class="destructive
                    ? 'bg-terracotta hover:bg-wood focus-visible:outline-terracotta'
                    : 'bg-brand-green hover:bg-wood focus-visible:outline-brand-green'"
                x-on:click="confirm()"
                x-text="confirmLabel"
            ></button>
        </div>
    </div>
</div>
