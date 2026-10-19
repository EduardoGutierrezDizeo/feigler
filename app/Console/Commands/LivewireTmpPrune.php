<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;

/**
 * Borra los archivos temporales de Livewire que nadie llegó a confirmar.
 *
 * Cada subida pasa por `storage/app/private/livewire-tmp` antes de quedarse en
 * el catálogo: Livewire la escribe ahí y solo la borra cuando la subida se
 * confirma, así que la de una subida que se quedó a medias se queda ocupando
 * disco sin que nadie la reclame. La limpieza propia de Livewire solo entra en
 * acción cuando alguien vuelve a subir un archivo, así que en un panel con poco
 * tráfico lo que quedó a medias se acumula: este barrido corre a diario por su
 * cuenta y borra lo que tiene más de 24 horas.
 *
 * El disco y el directorio no se escriben aquí: se leen de la configuración real
 * de Livewire (`FileUploadConfiguration`), que es la misma que decide dónde se
 * guarda cada subida, así que el barrido y la subida nunca pueden apuntar a
 * carpetas distintas. Solo se borran archivos de esa carpeta y nunca nada de
 * fuera; si la carpeta no existe, el barrido no falla, simplemente no encuentra
 * nada. Al final imprime cuántos archivos se borraron y cuántos KB ocupaban.
 */
class LivewireTmpPrune extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'livewire-tmp:prune
                            {--hours=24 : borra los archivos temporales con más de tantas horas}
                            {--dry-run : cuenta lo que borraría sin borrar nada}';

    /**
     * The console command description.
     */
    protected $description = 'Borra los archivos temporales de Livewire que nadie llegó a confirmar';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $hours = filter_var($this->option('hours'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);

        if ($hours === false) {
            $this->components->error('--hours debe ser un entero mayor o igual que cero.');

            return self::INVALID;
        }

        $dryRun = (bool) $this->option('dry-run');

        $storage = FileUploadConfiguration::storage();
        $directory = FileUploadConfiguration::path();
        $cutoff = now()->subHours((int) $hours)->getTimestamp();

        $deleted = 0;
        $kilobytes = 0.0;

        foreach ($storage->allFiles($directory) as $file) {
            // La limpieza propia de Livewire puede borrarlo entre el listado y la
            // lectura: un archivo que ya no está no es cosa de este barrido.
            if (! $storage->exists($file)) {
                continue;
            }

            if ($storage->lastModified($file) >= $cutoff) {
                continue;
            }

            $kilobytes += $storage->size($file) / 1024;
            $deleted++;

            if (! $dryRun) {
                $storage->delete($file);
            }
        }

        $this->components->info(sprintf(
            '%s: %d (%s KB).',
            $dryRun
                ? 'Archivos temporales de Livewire que se borrarían'
                : 'Archivos temporales de Livewire borrados',
            $deleted,
            number_format($kilobytes, 2, ',', '.'),
        ));

        return self::SUCCESS;
    }
}
