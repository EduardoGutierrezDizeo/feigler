<?php

namespace App\Services\ProductImport;

use App\Actions\Products\CreateProduct;
use App\Actions\Products\CreateProductVariant;
use App\Exceptions\DuplicateImportedProductException;
use App\Exceptions\DuplicateProductVariantException;
use App\Exceptions\InactiveProductMaterialException;
use App\Exceptions\InactiveVariantColorException;
use App\Exceptions\InactiveVariantSizeException;
use App\Exceptions\IncompleteMaterialCompositionException;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\InvalidMaterialPercentageException;
use App\Exceptions\InvalidProductNameException;
use App\Exceptions\InvalidProductStatusException;
use App\Exceptions\InvalidStockAdjustmentException;
use App\Exceptions\InvalidVariantSizeException;
use App\Exceptions\MaterialNotFoundException;
use App\Exceptions\RepeatedProductMaterialException;
use App\Models\Category;
use App\Models\Color;
use App\Models\Product;
use App\Models\User;
use App\Support\Concerns\NormalizesNames;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The second half of the bulk load: the articles the file describes are written into
 * the catalog, one by one, by the same actions the product form uses.
 *
 * The preview `BulkProductImportValidator` builds is a picture of a moment, and the
 * catalog can move between the moment the admin saw it and the moment they confirm
 * it: a product that looked free may have been created by someone else, a material a
 * row leaned on may have been turned off. So this service reads no fact from the
 * preview it is not the author of — it re-runs the validator over the very file that
 * was uploaded and creates only what comes back ready *now*. The preview is for
 * looking at; the confirmation is a fresh reading.
 *
 * **What is reused.** Nothing about a product is decided here. The reference, the
 * slug, the brand of the store, the SKU and the initial stock with its movement are
 * all the work of `CreateProduct` and `CreateProductVariant`, called exactly the way
 * the panel calls them, so a garment loaded from a file and one typed into the form
 * are the same row written two ways. A product is born `inactive` — the admin turns
 * it on once the photographs are in — while its variants are born active, which is
 * what the giveaway of a hidden product is: the storefront never shows the product
 * because of its status, not because of its variants.
 *
 * **What an article is worth.** One article is one product and all of its variants,
 * and it is written inside a transaction of its own: either the whole garment exists
 * or none of it does. A failure half way through — the database refuses a row, a
 * second load claims the same name in the gap between validate and create — rolls
 * back that one article, records the reason, and leaves the rest of the file to be
 * created. A file of fifty garments with one bad egg still loads forty-nine.
 *
 * **On the reference.** `CreateProduct` reserves the next number of the series inside
 * the transaction that writes the row, taking a lock on the category for as long as
 * that transaction lives. Each article here opens that transaction for itself, so two
 * loads running at once queue on the category instead of reading the same counter and
 * colliding. See the class of `nextReferenceFor` for the full account.
 */
final class BulkProductImporter
{
    use NormalizesNames;

    /**
     * The column a refusal that did not come from a cell of the file is filed under.
     *
     * The validator names a real column — `nombre`, `precio`, `materiales` — because
     * its refusals are about a cell. A refusal from the moment of creating is not
     * about any one cell: it is the article as a whole that could not be written, so
     * it is reported under a name that is not one of the file's columns.
     */
    public const CREATION_COLUMN = 'creacion';

    /**
     * The exceptions that already carry a message written for the admin.
     *
     * These are the refusals the actions and the catalog rules design: a size that
     * belongs to another category, a color that was turned off, a composition that
     * does not add up to a hundred. They are the same refusals the preview shows
     * for a bad row, so their text can be handed straight to the report. Nothing
     * else is trusted: a `QueryException`, a PDO error or a programming mistake has
     * no message anyone can read, so it is replaced by the generic notice below and
     * sent to the log instead.
     *
     * `AdjustProductVariantStock` is not called here, but its refusals are listed
     * too: they belong to the same family of messages and would be expected if a
     * later step ever moved stock through it.
     *
     * @var list<class-string<Throwable>>
     */
    private const EXPECTED_EXCEPTIONS = [
        DuplicateImportedProductException::class,
        InvalidProductNameException::class,
        InvalidProductStatusException::class,
        RepeatedProductMaterialException::class,
        InvalidMaterialPercentageException::class,
        MaterialNotFoundException::class,
        InactiveProductMaterialException::class,
        IncompleteMaterialCompositionException::class,
        DuplicateProductVariantException::class,
        InvalidVariantSizeException::class,
        InactiveVariantSizeException::class,
        InactiveVariantColorException::class,
        InvalidStockAdjustmentException::class,
        InsufficientStockException::class,
    ];

    /**
     * What the admin is told when an article could not be written for a reason that
     * was not the file's fault: the raw message could be full of SQL and driver text,
     * so it never reaches the preview, the result or the report.
     */
    private const UNEXPECTED_MESSAGE = 'No se pudo crear este artículo por un error inesperado. Inténtalo de nuevo; si se repite, avisa al administrador del sistema.';

    public function __construct(
        private readonly BulkProductImportValidator $validator = new BulkProductImportValidator,
        private readonly CreateProduct $createProduct = new CreateProduct,
        private readonly CreateProductVariant $createProductVariant = new CreateProductVariant,
    ) {}

    /**
     * Read the file again and write every article it describes that can be written now.
     *
     * The file is validated from scratch — the preview the admin saw is ignored on
     * purpose, because the catalog may have changed under it — and then each ready
     * article is created on its own. The `user` is the admin who confirmed the load,
     * and is recorded on every stock movement the initial stock leaves behind, the way
     * the panel records whoever moved a variant by hand.
     *
     * @param  string  $path  the uploaded CSV or XLSX, as it was handed to the preview.
     * @param  User|null  $user  the admin confirming the load.
     * @return array{
     *     created: list<array{row: int, name: string, reference: string, category: string, variants: int}>,
     *     rejected: list<array{name: string, rows: list<int>, errors: list<array{row: int, column: string, message: string}>}>,
     *     totals: array{created_articles: int, created_variants: int, rejected_articles: int, rejected_rows: int}
     * }
     */
    public function import(string $path, ?User $user = null): array
    {
        $preview = $this->validator->validate($path);

        $created = [];
        $rejected = $preview['rejected'];

        foreach ($preview['ready'] as $article) {
            try {
                $created[] = $this->createArticle($article, $user);
            } catch (Throwable $exception) {
                // Un artículo vale por sí solo: lo que falla se queda fuera con su
                // motivo y el resto del archivo sigue creándose. La transacción de
                // `createArticle` ya deshizo lo que hubiera alcanzado a escribir.
                $rejected[] = $this->creationRejection($article, $exception);
            }
        }

        return [
            'created' => $created,
            'rejected' => $rejected,
            'totals' => $this->totals($created, $rejected),
        ];
    }

    /**
     * Write one article — its product, its composition and its variants — or nothing at all.
     *
     * The product is created inactive and its variants active, exactly as the panel
     * leaves a garment whose photographs are still missing. The actions nest their
     * own transactions into this one as savepoints, so the product, the materials and
     * every variant share a single fate: if the second variant is refused, the first
     * one and the product go back with it.
     *
     * @param  array{
     *     row: int,
     *     category_id: int,
     *     name: string,
     *     brand: string,
     *     price: string,
     *     materials: list<array{id: int, name: string, percentage: int}>,
     *     variants: list<array{row: int, size_id: int, color_id: int, stock: int}>
     * }  $article
     * @return array{row: int, name: string, reference: string, category: string, variants: int}
     */
    private function createArticle(array $article, ?User $user): array
    {
        $category = Category::query()->findOrFail($article['category_id']);

        $product = DB::transaction(function () use ($article, $category, $user): Product {
            // El bloqueo de la categoría va primero, antes de cualquier lectura: es
            // el turno que hace que dos cargas de la misma categoría no se miren a la
            // vez, así que la comprobación de nombre de aquí abajo lee lo que la
            // anterior dejó escrito y no deja pasar dos prendas con el mismo nombre.
            // `nextReferenceFor` vuelve a tomar este mismo bloqueo dentro de la
            // transacción sin problema.
            $category->newQuery()->lockForUpdate()->findOrFail($category->getKey());

            $this->guardNameIsFree($category, $article['name']);

            $product = ($this->createProduct)($category, [
                'name' => $article['name'],
                'brand' => $article['brand'],
                'base_price' => $article['price'],
                'status' => 'inactive',
            ], $this->composition($article['materials']));

            foreach ($article['variants'] as $variant) {
                ($this->createProductVariant)(
                    $product,
                    $variant['size_id'],
                    Color::query()->findOrFail($variant['color_id']),
                    null,
                    $variant['stock'],
                    $user,
                );
            }

            return $product;
        });

        return [
            'row' => $article['row'],
            'name' => $product->name,
            'reference' => $product->reference,
            'category' => $category->name,
            'variants' => count($article['variants']),
        ];
    }

    /**
     * Refuse a name that a product of the same category already uses.
     *
     * The importer is the only place that can hold this rule: `products` has no unique
     * index on the name, and the values compared are the ones the preview compares —
     * the name normalized, so accents and case do not make two names look like one —
     * so a load confirmed twice cannot slip a second copy past the panel.
     */
    private function guardNameIsFree(Category $category, string $name): void
    {
        $name = $this->cleanName($name);

        $taken = Product::query()
            ->where('category_id', $category->getKey())
            ->pluck('name')
            ->contains(fn (string $existing): bool => $this->nameIsTheSame($this->cleanName($existing), $name));

        if ($taken) {
            throw DuplicateImportedProductException::forName($name, $category);
        }
    }

    /**
     * The composition as `CreateProduct` reads it.
     *
     * @param  list<array{id: int, name: string, percentage: int}>  $materials
     * @return list<array{id: int, percentage: int}>
     */
    private function composition(array $materials): array
    {
        return array_values(array_map(
            fn (array $material): array => [
                'id' => (int) $material['id'],
                'percentage' => (int) $material['percentage'],
            ],
            $materials,
        ));
    }

    /**
     * An article that failed at the moment of creating, in the same shape the preview
     * uses for the ones it refused.
     *
     * The rows are the ones the article was written with, so a report can point the
     * admin at the part of the file the refusal is about. The reason is the message of
     * the action when it is one of the refusals of the panel, and the generic notice
     * when it is not: a message from the database names the SQL and the driver, which
     * is nothing an admin can act on. That unexpected failure still has to be
     * understood later, so its full detail goes to the log with the article and its
     * rows, and never reaches the preview.
     *
     * @param  array{row: int, name: string, variants: list<array{row: int}>}  $article
     * @return array{name: string, rows: list<int>, errors: list<array{row: int, column: string, message: string}>}
     */
    private function creationRejection(array $article, Throwable $exception): array
    {
        $rows = array_map(fn (array $variant): int => $variant['row'], $article['variants']);
        $rows = $rows === [] ? [$article['row']] : $rows;

        $expected = $this->isExpectedRefusal($exception);

        if (! $expected) {
            Log::error('No se pudo crear un artículo de la carga masiva por un error inesperado.', [
                'article' => $article['name'],
                'rows' => $rows,
                'exception' => $exception,
            ]);
        }

        return [
            'name' => $article['name'],
            'rows' => $rows,
            'errors' => [[
                'row' => $article['row'],
                'column' => self::CREATION_COLUMN,
                'message' => $expected ? $exception->getMessage() : self::UNEXPECTED_MESSAGE,
            ]],
        ];
    }

    /**
     * Whether an exception is one of the refusals the panel already knows how to word.
     */
    private function isExpectedRefusal(Throwable $exception): bool
    {
        foreach (self::EXPECTED_EXCEPTIONS as $expected) {
            if ($exception instanceof $expected) {
                return true;
            }
        }

        return false;
    }

    /**
     * The counts the confirmation screen shows, over both halves of the file.
     *
     * @param  list<array{variants: int}>  $created
     * @param  list<array{rows: list<int>}>  $rejected
     * @return array{created_articles: int, created_variants: int, rejected_articles: int, rejected_rows: int}
     */
    private function totals(array $created, array $rejected): array
    {
        return [
            'created_articles' => count($created),
            'created_variants' => array_sum(array_map(fn (array $article): int => $article['variants'], $created)),
            'rejected_articles' => count($rejected),
            'rejected_rows' => array_sum(array_map(fn (array $article): int => count($article['rows']), $rejected)),
        ];
    }
}
