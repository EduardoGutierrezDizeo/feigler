{{--
    Pila de notificaciones tipo toast.

    Se alimenta de la store Alpine `toast`, que a su vez recibe los avisos que
    Livewire despacha desde PHP mediante el evento `toast` (`$this->dispatch` en
    el trait App\Livewire\Concerns\Notifies). Para mostrar un aviso desde
    Alpine se usa `$store.toast.success('…')` o `$store.toast.error('…')`.
--}}
<div
    class="pointer-events-none fixed inset-x-0 bottom-0 z-[60] flex flex-col items-center gap-2 p-4 sm:inset-x-auto sm:end-0 sm:top-0 sm:bottom-auto sm:items-end sm:pe-6 sm:ps-0 sm:pt-6"
    aria-live="polite"
    aria-atomic="false"
>
    <template x-for="toast in $store.toast.items" :key="toast.id">
        <div
            class="pointer-events-auto flex w-full max-w-sm items-start gap-3 rounded-lg border border-arena bg-crema px-4 py-3 text-sm shadow-md sm:w-96"
            :class="{
                'border-verde/40': toast.tone === 'success',
                'border-ladrillo/50': toast.tone === 'error',
            }"
            role="status"
            x-transition:enter="ease-out duration-200"
            x-transition:enter-start="translate-y-3 opacity-0 sm:translate-x-3 sm:translate-y-0"
            x-transition:enter-end="translate-x-0 translate-y-0 opacity-100"
            x-transition:leave="ease-in duration-150"
            x-transition:leave-start="opacity-100"
            x-transition:leave-end="translate-y-2 opacity-0 sm:translate-x-3 sm:translate-y-0"
        >
            <span
                class="mt-0.5 inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-full"
                :class="toast.tone === 'success' ? 'bg-verde text-crema' : 'bg-ladrillo text-crema'"
                aria-hidden="true"
            >
                <svg
                    class="h-4 w-4"
                    xmlns="http://www.w3.org/2000/svg"
                    fill="none"
                    viewBox="0 0 24 24"
                    stroke-width="2"
                    stroke="currentColor"
                >
                    <template x-if="toast.tone === 'success'">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                    </template>

                    <template x-if="toast.tone !== 'success'">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z" />
                    </template>
                </svg>
            </span>

            <p
                class="min-w-0 flex-1"
                :class="toast.tone === 'success' ? 'text-verde' : 'text-ladrillo'"
                x-text="toast.message"
            ></p>

            <button
                type="button"
                class="-me-1 -mt-1 shrink-0 rounded-md p-1 text-gris-calido transition-colors duration-150 ease-in-out hover:bg-sand/50 hover:text-verde"
                aria-label="Cerrar aviso"
                x-on:click="$store.toast.dismiss(toast.id)"
            >
                <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                </svg>
            </button>
        </div>
    </template>
</div>
