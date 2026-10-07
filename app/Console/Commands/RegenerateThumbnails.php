<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\ProductImage;
use App\Services\ProductImageThumbnailer;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Regenera con el tamaño actual las miniaturas que ya existen.
 *
 * La generación diaria solo cubre las imáganes nuevas; cuando el tamaño de la
 * miniatura cambia (como al subir de 480 a 960 px), las ya hechas se quedan
 * con la medida vieja y la portada las sigue usando hasta que alguien las
 * rehace. Este comando es ese alguien: recorre las imágenes de producto y las
 * fotos propias de las categorías y pide la miniatura de cada original, que se
 * escribe siempre en la misma dirección determinista, de modo que la copia
 * nueva reemplaza a la vieja y la columna queda apuntando a ella.
 *
 * Recorre las filas por tandas de id para no cargar el catálogo entero en
 * memoria, y pregunta por el archivo original antes de tocar nada: una fila
 * cuyo original falta no puede volver a fotografiarse y se reporta, sin
 * detener al resto. En modo seco no escribe ni un byte y solo cuenta cuántas
 * se regenerarían y qué originales faltan.
 */
class RegenerateThumbnails extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'images:regenerate-thumbnails
                            {--dry-run : no escribe nada, solo cuenta cuántas se regenerarían y qué originales faltan}
                            {--only= : regenera solo \'products\' o solo \'categories\'}';

    /**
     * The console command description.
     */
    protected $description = 'Regenera la miniatura WebP de los productos y de las fotos propias de las categorías con el tamaño actual';

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
     * The folder of the disk where the own photos of the categories live.
     */
    private const FOLDER_CATEGORIAS = 'home/categories';

    /**
     * The files the disk really holds, read once for the whole run.
     *
     * A row that says it has a thumbnail may be pointing at a file that was
     * deleted, moved or never written, so the disk is asked what it has once,
     * instead of asking it about every single row.
     *
     * @var array<string, true>
     */
    private array $enDisco = [];

    /**
     * Execute the console command.
     */
    public function handle(ProductImageThumbnailer $thumbnailer): int
    {
        $only = $this->option('only');

        if ($only !== null && ! in_array($only, ['products', 'categories'], true)) {
            $this->components->error(sprintf('--only solo acepta "products" o "categories", no "%s".', $only));

            return self::INVALID;
        }

        $dry = (bool) $this->option('dry-run');

        $this->enDisco = array_fill_keys([
            ...Storage::disk(ProductImage::DISK)->allFiles(self::RAIZ),
            ...Storage::disk(ProductImage::DISK)->allFiles(self::FOLDER_CATEGORIAS),
        ], true);

        $procesadas = 0;
        $regeneradas = 0;
        $omitidas = 0;
        $fallidas = 0;

        $this->components->info($dry
            ? 'No se escribirá nada: solo se cuentan las miniaturas que se regenerarían.'
            : 'Regenerando las miniaturas.');

        if ($only === null || $only === 'products') {
            $this->newLine();
            $this->components->info('Productos:');

            ProductImage::query()
                ->orderBy('id')
                ->chunkById(self::CHUNK, function (Collection $images) use ($thumbnailer, $dry, &$procesadas, &$regeneradas, &$omitidas, &$fallidas): void {
                    foreach ($images as $image) {
                        $procesadas++;

                        // El original va primero: sin él no hay nada que rehacer, y una
                        // fila que lo perdió dice algo roto del despliegue o del respaldo.
                        if (! $this->existeEnDisco($image->path)) {
                            $omitidas++;
                            $this->reportaOriginalAusente('la imagen '.$image->getKey(), $image->path);

                            continue;
                        }

                        if ($dry) {
                            $regeneradas++;

                            continue;
                        }

                        if ($this->regeneraProducto($image, $thumbnailer)) {
                            $regeneradas++;
                        } else {
                            $fallidas++;
                        }
                    }
                });
        }

        if ($only === null || $only === 'categories') {
            $this->newLine();
            $this->components->info('Fotos propias de categorías:');

            Category::query()
                ->whereNotNull('home_image_path')
                ->orderBy('id')
                ->chunkById(self::CHUNK, function (Collection $categories) use ($thumbnailer, $dry, &$procesadas, &$regeneradas, &$omitidas, &$fallidas): void {
                    foreach ($categories as $category) {
                        $procesadas++;

                        if (! $this->existeEnDisco($category->home_image_path)) {
                            $omitidas++;
                            $this->reportaOriginalAusente('la categoría '.$category->getKey(), $category->home_image_path);

                            continue;
                        }

                        if ($dry) {
                            $regeneradas++;

                            continue;
                        }

                        if ($this->regeneraCategoria($category, $thumbnailer)) {
                            $regeneradas++;
                        } else {
                            $fallidas++;
                        }
                    }
                });
        }

        $this->newLine();
        $this->components->info(sprintf('Procesadas: %d.', $procesadas));
        $this->components->info(sprintf($dry ? 'Se regenerarían: %d.' : 'Regeneradas: %d.', $regeneradas));
        $this->components->info(sprintf('Omitidas: %d.', $omitidas));
        $this->components->info(sprintf('Fallidas: %d.', $fallidas));

        return ($fallidas > 0 || $omitidas > 0) ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Ask the thumbnailer for the thumbnail of a product image and point the
     * row at it.
     */
    private function regeneraProducto(ProductImage $image, ProductImageThumbnailer $thumbnailer): bool
    {
        $thumbnail = $thumbnailer($image->path, $image->product_id, $image->color_id);

        if ($thumbnail === null) {
            $this->reportaFallida('la imagen '.$image->getKey());

            return false;
        }

        $image->update(['thumbnail_path' => $thumbnail]);

        return true;
    }

    /**
     * Ask the thumbnailer for the thumbnail of the own photo of a category and
     * point the row at it.
     */
    private function regeneraCategoria(Category $category, ProductImageThumbnailer $thumbnailer): bool
    {
        $thumbnail = $thumbnailer($category->home_image_path, null, null);

        if ($thumbnail === null) {
            $this->reportaFallida('la categoría '.$category->getKey());

            return false;
        }

        $category->update(['home_image_thumbnail_path' => $thumbnail]);

        return true;
    }

    /**
     * Say in the log and on the console which original was lost.
     */
    private function reportaOriginalAusente(string $quien, string $ruta): void
    {
        Log::warning(sprintf(
            'images:regenerate-thumbnails: %s no tiene original en "%s", su miniatura no se puede regenerar.',
            $quien,
            $ruta,
        ));

        $this->components->warn(sprintf('%s no tiene original en "%s".', $quien, $ruta));
    }

    /**
     * Say in the log and on the console whose thumbnail could not be remade.
     */
    private function reportaFallida(string $quien): void
    {
        Log::warning(sprintf('images:regenerate-thumbnails: no se pudo regenerar la miniatura de %s.', $quien));

        $this->components->warn(sprintf('No se pudo regenerar la miniatura de %s.', $quien));
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
        if (Str::startsWith($ruta, self::RAIZ.'/') || Str::startsWith($ruta, self::FOLDER_CATEGORIAS.'/')) {
            return isset($this->enDisco[$ruta]);
        }

        return Storage::disk(ProductImage::DISK)->exists($ruta);
    }
}
