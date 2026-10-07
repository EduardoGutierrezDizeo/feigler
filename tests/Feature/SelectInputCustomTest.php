<?php

use Livewire\Livewire;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Blade;

it('renders select with placeholder, optgroup and disabled option preserving native options and has aria-haspopup', function () {
    $view = Blade::render(<<<'BLADE'
        <x-select-input>
            <option value="">— Selecciona una opción —</option>
            <optgroup label="Grupo A">
                <option value="1">Opción 1</option>
                <option value="2" disabled>Opción 2 (deshabilitada)</option>
            </optgroup>
            <optgroup label="Grupo B">
                <option value="3">Opción 3</option>
            </optgroup>
        </x-select-input>
    BLADE);

    expect($view)->toContain('<option value="">— Selecciona una opción —</option>');
    expect($view)->toContain('<optgroup label="Grupo A">');
    expect($view)->toContain('<option value="2" disabled>Opción 2 (deshabilitada)</option>');
    expect($view)->toContain('aria-haspopup');
});