<?php

namespace App\Console\Commands;

use App\Models\ProductImage;
use App\Services\ProductImageThumbnailer;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class GenerateProductThumbnails extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'products:generate-thumbnails
                            {--force : rehace también las miniaturas que ya existen}';

    /**
     * The console command description.
     */
    protected $description = 'Genera la miniatura WebP de las imágenes de producto que no la tienen y repara las que quedaron rotas';

    /**
     * How many pictures are read at a time.
     *
     * The rows are walked in chunks of id because a catalog can hold as many
     * pictures as it wants and asking for all of them at once would put every
     * row, and every thumbnail waiting to be made, in memory at the same time.
     */
    private const CHUNK = 100;

    /**
     * The folder of the disk where the pictures of the catalog live.
     */
    private const RAIZ = 'products';

    /**
     * The files the disk really holds, read once for the whole run.
     *
     * A row that says it has a thumbnail may be pointing at a file that was
     * deleted, moved or never written, and that is the difference between a
     * picture whose catalog page loads in a few kilobytes and one that pulls the
     * original over the wire. The disk is asked what it has once, instead of
     * asking it about every single row: a repair run walks the whole catalog, and
     * one stat call per picture turns it into a scan slower than making the
     * thumbnails it is there to make.
     *
     * @var array<string, true>
     */
    private array $enDisco = [];

    /**
     * Execute the console command.
     */
    public function handle(ProductImageThumbnailer $thumbnailer): int
    {
        $force = (bool) $this->option('force');

        $this->enDisco = array_fill_keys(Storage::disk(ProductImage::DISK)->allFiles(self::RAIZ), true);

        $generadas = 0;
        $validas = 0;
        $rotas = 0;
        $sinOriginal = 0;
        $errores = 0;

        $this->components->info($force
            ? 'Rehaciendo la miniatura de todas las imágenes.'
            : 'Generando la miniatura de las que no la tienen y reparando las rotas.');

        ProductImage::query()
            ->orderBy('id')
            ->chunkById(self::CHUNK, function (Collection $images) use ($force, $thumbnailer, &$generadas, &$validas, &$rotas, &$sinOriginal, &$errores): void {
                foreach ($images as $image) {
                    // El original va primero: sin él no hay nada que rehacer, y una
                    // fila que lo perdió dice algo roto del despliegue, del respaldo
                    // o de un borrado manual que el catálogo debe poder ver aunque la
                    // miniatura que se le haga caso siga en su sitio.
                    if (! $this->existeEnDisco($image->path)) {
                        $sinOriginal++;

                        Log::warning(sprintf(
                            'products:generate-thumbnails: la imagen %d no tiene original en "%s", su miniatura no se puede rehacer.',
                            $image->getKey(),
                            $image->path,
                        ));

                        $this->components->warn(sprintf(
                            'La imagen %d no tiene original en "%s".',
                            $image->getKey(),
                            $image->path,
                        ));

                        continue;
                    }

                    $miniaturaRota = $image->thumbnail_path !== null
                        && ! $this->existeEnDisco($image->thumbnail_path);

                    if (! $force && ! $miniaturaRota && $image->thumbnail_path !== null) {
                        $validas++;

                        continue;
                    }

                    $thumbnail = $thumbnailer($image->path, $image->product_id, $image->color_id);

                    if ($thumbnail === null) {
                        $errores++;

                        continue;
                    }

                    $image->update(['thumbnail_path' => $thumbnail]);

                    if ($miniaturaRota) {
                        $rotas++;
                    } else {
                        $generadas++;
                    }
                }
            });

        $this->newLine();
        $this->components->info(sprintf('Generadas: %d.', $generadas));
        $this->components->info(sprintf('Ya tenían miniatura válida: %d.', $validas));
        $this->components->info(sprintf('Miniaturas rotas rehechas: %d.', $rotas));
        $this->components->info(sprintf('Original ausente: %d.', $sinOriginal));

        if ($errores > 0) {
            $this->components->warn(sprintf('No se pudieron generar: %d.', $errores));
        }

        return self::SUCCESS;
    }

    /**
     * Whether a file is really there, answered from the list read once in handle().
     *
     * The list only holds the pictures of the catalog, so a row pointing
     * somewhere else is asked about on the disk itself rather than being taken
     * for a file that does not exist.
     */
    private function existeEnDisco(string $ruta): bool
    {
        if (Str::startsWith($ruta, self::RAIZ.'/')) {
            return isset($this->enDisco[$ruta]);
        }

        return Storage::disk(ProductImage::DISK)->exists($ruta);
    }
}
