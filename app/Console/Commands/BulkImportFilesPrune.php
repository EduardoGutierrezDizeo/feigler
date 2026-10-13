<?php

namespace App\Console\Commands;

use App\Services\ProductImport\BulkImportFileStore;
use Illuminate\Console\Command;

/**
 * Borra los archivos de la carga masiva que nadie llegó a confirmar.
 *
 * Un archivo revisado se copia a una carpeta privada para que la vista previa
 * sobreviva a los cinco minutos del temporal de Livewire; el precio de eso es
 * que un archivo cuya pestaña se cerró se queda ahí sin que nadie lo reclame. La
 * copia se descarta al confirmar y al cancelar, así que lo que este barrido
 * encuentra es justo lo que quedó a medias: se borra a las 24 horas por defecto.
 * Al final imprime cuántos archivos se borraron.
 */
class BulkImportFilesPrune extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'bulk-imports:prune
                            {--hours=24 : borra los archivos sin tocar desde hace tantas horas}';

    /**
     * The console command description.
     */
    protected $description = 'Borra los archivos temporales de la carga masiva que nadie confirmó';

    /**
     * Execute the console command.
     */
    public function handle(BulkImportFileStore $store): int
    {
        $hours = filter_var($this->option('hours'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);

        if ($hours === false) {
            $this->components->error('--hours debe ser un entero mayor o igual que cero.');

            return self::INVALID;
        }

        $deleted = $store->prune((int) $hours);

        $this->components->info(sprintf('Archivos de carga masiva borrados: %d.', $deleted));

        return self::SUCCESS;
    }
}
