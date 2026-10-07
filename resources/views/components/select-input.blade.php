@props(['disabled' => false, 'variant' => 'field', 'error' => false])

@php
    $buttonClasses = match ($variant) {
        'pill' => 'flex w-full items-center justify-between rounded-full border border-arena bg-crema py-2.5 ps-5 pe-10 text-sm text-tinta shadow-lift transition duration-150 ease-in-out focus:border-laton focus:ring-1 focus:ring-laton focus:outline-none disabled:cursor-not-allowed disabled:opacity-60',
        default => 'flex w-full items-center justify-between rounded-none border-0 border-b border-arena bg-transparent px-0 py-2 pe-8 text-sm text-tinta shadow-none transition duration-150 ease-in-out focus:border-verde focus:outline-none focus:ring-0 disabled:cursor-not-allowed disabled:opacity-60',
    };
    $errorClasses = $error ? 'border-ladrillo focus:border-ladrillo focus:ring-ladrillo' : '';
@endphp

<div
    x-data="selectInput(@js($attributes->wire('model')->value() ?? null))"
    class="relative"
    x-cloak
>
    <select
        x-ref="select"
        data-variant="{{ $variant }}"
        @disabled($disabled)
        tabindex="-1"
        aria-hidden="true"
        {{ $attributes->merge(['class' => 'sr-only']) }}
    >
        {{ $slot }}
    </select>

    <button
        x-ref="button"
        type="button"
        :disabled="isDisabled"
        @click="toggle()"
        @keydown="onButtonKeydown($event)"
        :aria-haspopup="'listbox'"
        :aria-expanded="open.toString()"
        :aria-controls="$refs.panel?.id || null"
        class="{{ $buttonClasses }} {{ $errorClasses }}"
        :class="{ 'border-ladrillo focus:border-ladrillo focus:ring-ladrillo': isError }"
    >
        <span x-text="buttonLabel" class="truncate"></span>
        <svg class="pointer-events-none h-5 w-5 shrink-0 text-gris-calido" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
            <path fill-rule="evenodd" d="M5.22 8.22a.75.75 0 0 1 1.06 0L10 11.94l3.72-3.72a.75.75 0 1 1 1.06 1.06l-4.25 4.25a.75.75 0 0 1-1.06 0L5.22 9.28a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd" />
        </svg>
    </button>

    <template x-if="pointerFine">
        <div
            x-ref="panel"
            x-show="open"
            @click.outside="close()"
            @keydown="onPanelKeydown($event)"
            role="listbox"
            tabindex="-1"
            class="fixed z-[9999] max-h-64 overflow-y-auto rounded-xl border border-arena bg-crema shadow-suave"
            style="display: none;"
        >
            <template x-for="(option, idx) in options" :key="idx">
                <div>
                    <template x-if="option.type === 'group'">
                        <div class="px-3 py-2 text-xs font-semibold uppercase tracking-wider text-gris-calido bg-hueso/50">
                            <span x-text="option.label"></span>
                        </div>
                    </template>
                    <template x-if="option.type === 'option'">
                        <button
                            type="button"
                            role="option"
                            :data-option-index="idx"
                            :aria-selected="(option.selected).toString()"
                            :aria-disabled="(option.disabled).toString()"
                            :disabled="option.disabled"
                            @click="selectOption(option)"
                            @mouseenter="$el.focus({ preventScroll: true })"
                            :class="{
                                'px-3 py-2 text-sm text-left w-full flex items-center justify-between': true,
                                'opacity-40 cursor-not-allowed': option.disabled,
                                'hover:bg-hueso focus:bg-hueso focus:outline-none': !option.disabled,
                                'text-gris-calido': option.placeholder,
                                'text-tinta': !option.placeholder,
                            }"
                        >
                            <span x-text="option.text" class="truncate"></span>
                            <template x-if="option.selected">
                                <svg class="h-4 w-4 text-verde" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                    <path fill-rule="evenodd" d="M16.704 5.29a1 1 0 0 1 0 1.42l-7.5 7.5a1 1 0 0 1-1.42 0l-3.5-3.5a1 1 0 1 1 1.42-1.42L8.5 12.08l6.79-6.79a1 1 0 0 1 1.42 0Z" clip-rule="evenodd" />
                                </svg>
                            </template>
                        </button>
                    </template>
                </div>
            </template>
        </div>
    </template>
</div>
