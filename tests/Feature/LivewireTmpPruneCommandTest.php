<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;

/*
 * La limpieza de los temporales de Livewire (`livewire-tmp:prune`): borra solo
 * los archivos de la carpeta temporal de Livewire con más de 24 horas, no toca
 * nada de fuera de esa carpeta y no falla cuando la carpeta no existe. El disco y
 * el directorio no se escriben a mano: salen de la misma configuración que usa
 * Livewire para subir, que en las pruebas es un disco falso.
 */

/**
 * El disco falso donde Livewire deja los temporales durante las pruebas, ya
 * preparado para que el comando escriba en el mismo.
 */
function temporaryUploadDisk(): Filesystem
{
    return Storage::fake(FileUploadConfiguration::disk());
}

/**
 * Un archivo temporal en la carpeta de Livewire, con la antigüedad indicada.
 */
function temporaryUpload(string $name, int $hoursOld, string $contents = 'contenido'): string
{
    $path = FileUploadConfiguration::path().'/'.$name;

    Storage::disk(FileUploadConfiguration::disk())->put($path, $contents);
    touch(Storage::disk(FileUploadConfiguration::disk())->path($path), now()->subHours($hoursOld)->getTimestamp());

    return $path;
}

test('borra los temporales con más de 24 horas y conserva los recientes', function () {
    $disk = temporaryUploadDisk();

    $viejo = temporaryUpload('viejo.txt', 25);
    $reciente = temporaryUpload('reciente.txt', 1);

    $this->artisan('livewire-tmp:prune')
        ->expectsOutputToContain('Archivos temporales de Livewire borrados: 1 (')
        ->assertExitCode(0);

    expect($disk->exists($viejo))->toBeFalse()
        ->and($disk->exists($reciente))->toBeTrue();
});

test('el barrido reporta cuántos KB ocupaban los archivos borrados', function () {
    $disk = temporaryUploadDisk();

    temporaryUpload('dos-kb.bin', 30, str_repeat('x', 2048));

    $this->artisan('livewire-tmp:prune')
        ->expectsOutputToContain('Archivos temporales de Livewire borrados: 1 (2,00 KB).')
        ->assertExitCode(0);

    expect($disk->allFiles(FileUploadConfiguration::path()))->toBe([]);
});

test('--dry-run cuenta lo que borraría pero no borra nada', function () {
    $disk = temporaryUploadDisk();

    $viejo = temporaryUpload('viejo.txt', 30);

    $this->artisan('livewire-tmp:prune', ['--dry-run' => true])
        ->expectsOutputToContain('Archivos temporales de Livewire que se borrarían: 1 (')
        ->assertExitCode(0);

    expect($disk->exists($viejo))->toBeTrue();
});

test('una carpeta temporal que no existe no hace fallar el barrido', function () {
    temporaryUploadDisk();

    $this->artisan('livewire-tmp:prune')
        ->expectsOutputToContain('Archivos temporales de Livewire borrados: 0 (0,00 KB).')
        ->assertExitCode(0);
});

test('no toca ningún archivo de fuera de la carpeta temporal', function () {
    $disk = temporaryUploadDisk();

    $disk->put('ajeno.txt', 'no es un temporal de Livewire');
    $disk->put('otra-carpeta/ajeno.txt', 'tampoco');

    temporaryUpload('viejo.txt', 30);

    $this->artisan('livewire-tmp:prune')->assertExitCode(0);

    expect($disk->exists('ajeno.txt'))->toBeTrue()
        ->and($disk->exists('otra-carpeta/ajeno.txt'))->toBeTrue()
        ->and($disk->allFiles(FileUploadConfiguration::path()))->toBe([]);
});

test('el barrido se puede adelantar con --hours', function () {
    $disk = temporaryUploadDisk();

    $deDosHoras = temporaryUpload('dos-horas.txt', 2);

    $this->artisan('livewire-tmp:prune', ['--hours' => 1])
        ->expectsOutputToContain('Archivos temporales de Livewire borrados: 1 (')
        ->assertExitCode(0);

    expect($disk->exists($deDosHoras))->toBeFalse();
});

test('unas horas inválidas cortan el barrido sin borrar nada', function () {
    $disk = temporaryUploadDisk();

    $viejo = temporaryUpload('viejo.txt', 48);

    $this->artisan('livewire-tmp:prune', ['--hours' => 'muchas'])
        ->expectsOutputToContain('--hours debe ser un entero mayor o igual que cero.')
        ->assertExitCode(2);

    expect($disk->exists($viejo))->toBeTrue();
});

test('el barrido está programado a diario media hora después de la medianoche', function () {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($event): bool => str_contains((string) $event->command, 'livewire-tmp:prune'));

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('30 0 * * *');
});
