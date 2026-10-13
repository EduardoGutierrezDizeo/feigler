<?php

namespace App\Livewire\Admin;

use App\Exceptions\ImportFileEmptyException;
use App\Exceptions\ImportFileTooLargeException;
use App\Exceptions\ImportHeadersMissingException;
use App\Exceptions\ImportRowLimitExceededException;
use App\Exceptions\InvalidStoredImportPathException;
use App\Exceptions\UnsupportedImportFormatException;
use App\Livewire\Concerns\Notifies;
use App\Livewire\Concerns\RequiresAdmin;
use App\Models\User;
use App\Services\ProductImport\BulkImportFileStore;
use App\Services\ProductImport\BulkImportReport;
use App\Services\ProductImport\BulkImportTemplate;
use App\Services\ProductImport\BulkProductImporter;
use App\Services\ProductImport\BulkProductImportValidator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;
use OpenSpout\Common\Exception\OpenSpoutException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The bulk load of products, in the three steps the panel says it in: choose a file,
 * read the preview, confirm.
 *
 * The file the admin picks arrives as Livewire's own temporary upload, which lives
 * five minutes; that is not enough to read a preview and make a decision, so the
 * moment the file is reviewed it is copied to a private folder and the component
 * keeps only the path. What travels to the browser between the preview and the
 * confirmation is the preview — already trimmed to what the screen shows — and never
 * the bytes of the file.
 *
 * **The three steps are one state.** `step` is the page being shown and the rest of
 * the state belongs to it: while uploading, `file` holds the pick; while previewing,
 * `preview` holds the counts and the first rows; once the load ran, `result` holds
 * what was created and what was refused. `cancel()` from anywhere returns to the
 * first step, and confirming clears the temporary file so the same screen cannot be
 * run twice.
 *
 * **The confirmation is a fresh reading.** `BulkProductImporter::import()` re-reads
 * the file and creates only what is still valid at that moment, so the preview is a
 * picture to look at and not a promise the panel then writes blindly. Whatever fails
 * to be created is reported under the `creacion` column.
 *
 * **The state does not grow with the file.** `result` keeps the counts of the whole
 * load and only the first rows of each side; the full list of refusals is written to a
 * private file whose path alone is kept, so the next step can offer the report without
 * carrying it. The state is also read-only from the browser (`#[Locked]`), and the load
 * itself runs under a per-admin lock so a double click creates the articles once.
 */
#[Layout('layouts::admin')]
#[Title('Carga masiva')]
class BulkUpload extends Component
{
    use Notifies;
    use RequiresAdmin;
    use WithFileUploads;

    /**
     * The first step: no file reviewed yet.
     */
    public const STEP_UPLOAD = 'upload';

    /**
     * The second step: a file was read and the preview is being shown.
     */
    public const STEP_PREVIEW = 'preview';

    /**
     * The third step: the load ran and the summary is being shown.
     */
    public const STEP_RESULT = 'result';

    /**
     * How many ready articles the preview lists, and how many refusals.
     *
     * The counts at the top are the whole file; the tables are the first slice of it,
     * because a file of two thousand rows is not something anyone reads in full. A
     * refusal is listed deeper than a ready article because it is the part the admin
     * has to fix.
     */
    public const READY_PREVIEW_LIMIT = 50;

    public const REJECTED_PREVIEW_LIMIT = 100;

    /**
     * How long the per-admin lock of a confirmation is held before it frees itself.
     *
     * It only has to outlive a normal load: the lock is released in a `finally`, so
     * the seconds are a safety net for a process that dies mid-load, not the expected
     * duration.
     */
    private const CONFIRM_LOCK_SECONDS = 120;

    /**
     * The name of the per-admin lock. The user is in it so two admins loading at the
     * same time do not wait for each other.
     */
    private const CONFIRM_LOCK_PREFIX = 'bulk-import:';

    /**
     * What the admin is told when the path of the reviewed file no longer names a file
     * of the private folder, either because it was tampered with or because the file is
     * gone.
     */
    private const INVALID_FILE_MESSAGE = 'El archivo de la carga no es válido o ya no está disponible. Vuelve a subirlo.';

    /**
     * What the admin is told when a load is already running for them.
     */
    private const BUSY_MESSAGE = 'Ya hay una carga en proceso. Espera a que termine e inténtalo de nuevo.';

    /**
     * What the admin is told when the file cannot be opened at all, which is not a
     * refusal by a row but a file that is not the workbook it claims to be.
     */
    private const UNREADABLE_MESSAGE = 'No se pudo leer el archivo. Comprueba que sea un CSV o un Excel válido.';

    /**
     * The file the admin picked, still in Livewire's temporary folder.
     *
     * It only exists during the first step: as soon as the file is reviewed it is
     * copied to the private folder and this is emptied, so the content never sits in
     * the component state between the preview and the confirmation.
     *
     * @var mixed
     */
    public $file = null;

    /**
     * The step being shown.
     *
     * Locked because the step is only ever moved by the component itself: a request
     * that could set it from the browser could jump to the preview with a path of its
     * own and make the confirmation read a file that is not the one that was reviewed.
     */
    #[Locked]
    public string $step = self::STEP_UPLOAD;

    /**
     * Where the reviewed file waits, as a path on the private disk, or `null` when
     * there is nothing to confirm.
     */
    #[Locked]
    public ?string $storedPath = null;

    /**
     * The name the file had when the admin chose it, kept to name it in the screen.
     */
    #[Locked]
    public string $originalName = '';

    /**
     * The preview of the file: the counts of the whole file and the first rows of
     * each side. It is emptied when the file is confirmed or cancelled.
     *
     * @var array{
     *     totals?: array{ready_articles: int, ready_variants: int, rejected_articles: int, rejected_rows: int},
     *     warnings?: list<array{section_label: string, category: string, articles: int}>,
     *     ready?: list<array<string, mixed>>,
     *     rejected?: list<array<string, mixed>>
     * }
     */
    #[Locked]
    public array $preview = [];

    /**
     * The summary of the load: the counts of the whole load and the first rows of each
     * side, and never the whole file.
     *
     * It is trimmed on purpose. A file of two thousand rows would otherwise leave a
     * hundred kilobytes of state to send back on every request, and the rest of the
     * refusals do not need to be here: the full list lives in the report file whose
     * path is `reportPath`.
     *
     * @var array{
     *     totals?: array{created_articles: int, created_variants: int, rejected_articles: int, rejected_rows: int},
     *     created?: list<array{name: string, reference: string, category: string, variants: int}>,
     *     rejected?: list<array<string, mixed>>
     * }
     */
    #[Locked]
    public array $result = [];

    /**
     * Where the full report of refusals waits, as a path on the private disk, or `null`
     * when the load refused nothing.
     *
     * The list itself is not in the state: only this path is, and the report is read
     * back with `BulkImportFileStore::readReport()` when the next step asks for it.
     */
    #[Locked]
    public ?string $reportPath = null;

    /**
     * Read the picked file and move to the preview.
     *
     * The file is checked here and not only in the browser: the input accepts CSV and
     * XLSX and nothing bigger than the limit, and the same rules are asked again on
     * the server, because a hand-built request does not have to respect the input.
     * The file is then copied to the private folder — the Livewire temporary is gone in
     * five minutes — and read from there.
     */
    public function review(): void
    {
        $this->validate([
            'file' => [
                'required',
                'file',
                'max:'.$this->maxFileSizeInKilobytes(),
                'extensions:'.implode(',', $this->allowedExtensions()),
            ],
        ], [
            'file.required' => 'Selecciona un archivo para revisar.',
            'file.file' => 'El archivo no es válido.',
            'file.max' => 'El archivo supera el máximo de '.$this->readableMaxSize().'.',
            'file.extensions' => 'Solo se aceptan archivos CSV y XLSX.',
        ]);

        $store = new BulkImportFileStore;

        $this->forgetReport($store);

        $this->originalName = $this->file->getClientOriginalName();
        $this->storedPath = $store->store($this->file);
        $this->file = null;

        $preview = $this->read(
            fn (): array => (new BulkProductImportValidator)->validate($store->absolutePath($this->storedPath)),
        );

        if ($preview === null) {
            return;
        }

        $this->preview = [
            'totals' => $preview['totals'],
            'warnings' => $preview['warnings'],
            'ready' => array_slice($preview['ready'], 0, self::READY_PREVIEW_LIMIT),
            'rejected' => array_slice($preview['rejected'], 0, self::REJECTED_PREVIEW_LIMIT),
        ];

        $this->step = self::STEP_PREVIEW;
    }

    /**
     * Run the load over the reviewed file and move to the summary.
     *
     * The importer re-reads the file and creates only what is valid now, so this can
     * say anything different from the preview the admin just read. The whole thing runs
     * under a lock held per admin: a double click does not start two loads, it tells the
     * second one that one is already running. The temporary file is deleted as the last
     * thing that happens, before the step changes, so even a request that slips past the
     * lock finds nothing to confirm.
     */
    public function confirm(): void
    {
        if (! $this->canConfirm()) {
            return;
        }

        $lock = Cache::lock(self::CONFIRM_LOCK_PREFIX.(auth()->id() ?? 'guest'), self::CONFIRM_LOCK_SECONDS);

        if (! $lock->get()) {
            $this->notifyError(self::BUSY_MESSAGE);

            return;
        }

        try {
            $store = new BulkImportFileStore;

            $result = $this->read(
                fn (): array => app(BulkProductImporter::class)->import($store->absolutePath($this->storedPath), $this->user()),
            );

            if ($result === null) {
                return;
            }

            $this->result = $this->summarize($result);
            $this->reportPath = $result['rejected'] === [] ? null : $store->storeReport($result['rejected']);
            $this->forgetUpload($store);
            $this->preview = [];
            $this->step = self::STEP_RESULT;

            $this->announceResult($result['totals']);
        } finally {
            $lock->release();
        }
    }

    /**
     * Drop the reviewed file and go back to the first step, from the preview or from
     * the summary.
     */
    public function cancel(): void
    {
        $store = new BulkImportFileStore;

        $this->forgetUpload($store);
        $this->forgetReport($store);
        $this->resetValidation();
        $this->preview = [];
        $this->result = [];
        $this->originalName = '';
        $this->step = self::STEP_UPLOAD;
    }

    /**
     * Whether there is a reviewed file with something to create, which is what arms
     * the confirm button.
     */
    public function canConfirm(): bool
    {
        return $this->step === self::STEP_PREVIEW
            && $this->storedPath !== null
            && ($this->preview['totals']['ready_articles'] ?? 0) > 0;
    }

    /**
     * Send the spreadsheet the admin fills in, built from the live catalog.
     *
     * It is an action of the component and not a public file, so every download
     * passes through `RequiresAdmin` like the rest of the screen: there is no URL an
     * anonymous visitor could guess. The bytes are built on the spot and streamed, so
     * nothing is written to a public folder.
     */
    public function downloadTemplate(): StreamedResponse
    {
        $contents = (new BulkImportTemplate)->xlsxContents();

        return response()->streamDownload(
            fn () => print $contents,
            BulkImportTemplate::FILENAME,
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        );
    }

    /**
     * Send the whole list of refusals as CSV, not only the slice the screen shows.
     *
     * The list is read from wherever the step keeps it: the report file once the load
     * ran, or the file itself re-read while the preview is on screen, because the
     * preview holds only the first hundred refusals. Nothing to report is said with a
     * toast; the download still goes out with its header so the button never breaks.
     */
    public function downloadErrorReport(): StreamedResponse
    {
        $rejected = $this->reportableRejections();

        if ($rejected === []) {
            $this->notifyError('No hay errores que descargar.');
        }

        return response()->streamDownload(
            fn () => print (new BulkImportReport)->csv($rejected ?? []),
            BulkImportReport::FILENAME,
            ['Content-Type' => 'text/csv; charset=UTF-8'],
        );
    }

    /**
     * The refusals a download should carry, read from the step that owns them.
     *
     * The result step keeps the full list in its report file and only its path in the
     * state; the preview step keeps only the first slice, so the file is read again to
     * get them all. A path that no longer names a file of the private folder is left
     * to `read()`, which turns it into the same toast a tampered upload gets and
     * answers `null`, so the empty report is not mistaken for one with nothing to say.
     *
     * @return list<array<string, mixed>>|null
     */
    private function reportableRejections(): ?array
    {
        $store = new BulkImportFileStore;

        if ($this->step === self::STEP_RESULT && $this->reportPath !== null) {
            return $this->read(fn (): array => $store->readReport($this->reportPath));
        }

        if ($this->step === self::STEP_PREVIEW && $this->storedPath !== null) {
            $preview = $this->read(
                fn (): array => (new BulkProductImportValidator)->validate($store->absolutePath($this->storedPath)),
            );

            return $preview['rejected'] ?? null;
        }

        return [];
    }

    public function render()
    {
        return view('livewire.admin.bulk-upload', [
            'canConfirm' => $this->canConfirm(),
            'maxSizeLabel' => $this->readableMaxSize(),
            'maxRowsLabel' => $this->readableMaxRows(),
        ]);
    }

    /**
     * Read the file through the validator or the importer, turning a file-level
     * refusal into a toast instead of a broken screen.
     *
     * Only the refusals about the file as a whole are caught: a missing column, an
     * empty file, one too heavy or too long, an extension the reader does not know. A
     * stored path that no longer names a file of the private folder is a refusal too —
     * the file is gone or the state was tampered with — and is answered the same way. A
     * workbook that is corrupt or is not one at all fails inside OpenSpout, which says
     * nothing an admin can read, so its detail goes to the log and the screen is told
     * the file could not be read. Anything else is a bug and is left to bubble.
     *
     * @param  callable(): array<string, mixed>  $operation
     * @return array<string, mixed>|null
     */
    private function read(callable $operation): ?array
    {
        try {
            return $operation();
        } catch (ImportFileEmptyException|ImportFileTooLargeException|ImportHeadersMissingException|ImportRowLimitExceededException|UnsupportedImportFormatException $exception) {
            $this->fail($exception->getMessage());
        } catch (InvalidStoredImportPathException $exception) {
            Log::error('La ruta de un archivo de la carga masiva no es válida.', [
                'path' => $this->storedPath,
                'exception' => $exception,
            ]);

            $this->fail(self::INVALID_FILE_MESSAGE);
        } catch (OpenSpoutException $exception) {
            Log::error('No se pudo leer un archivo de la carga masiva.', [
                'file' => $this->originalName,
                'exception' => $exception,
            ]);

            $this->fail(self::UNREADABLE_MESSAGE);
        }

        return null;
    }

    /**
     * Leave the screen in a state that can be tried again: no reviewed file, no
     * preview, back to the first step, with the reason in a toast.
     */
    private function fail(string $message): void
    {
        $store = new BulkImportFileStore;

        $this->forgetUpload($store);
        $this->forgetReport($store);
        $this->resetValidation();
        $this->preview = [];
        $this->result = [];
        $this->originalName = '';
        $this->step = self::STEP_UPLOAD;
        $this->notifyError($message);
    }

    /**
     * Say in a toast how the load went, with the reminder of what a loaded article is.
     *
     * @param  array{created_articles: int, created_variants: int}  $totals
     */
    private function announceResult(array $totals): void
    {
        if ($totals['created_articles'] === 0) {
            $this->notifyError('No se creó ningún artículo. Revisa los motivos en el resumen.');

            return;
        }

        $this->notifySuccess($this->createdMessage($totals['created_articles'], $totals['created_variants']));
    }

    /**
     * The success message, which has to remind the admin that the articles are not
     * for sale yet: they wait for their photographs, and are turned on by hand.
     */
    private function createdMessage(int $articles, int $variants): string
    {
        $variantsText = $variants === 1 ? '1 variante' : $variants.' variantes';

        if ($articles === 1) {
            return "Se creó 1 artículo con {$variantsText}. Queda inactivo y sin fotos: actívalo desde Productos cuando subas las imágenes.";
        }

        return "Se crearon {$articles} artículos con {$variantsText}. Quedan inactivos y sin fotos: actívalos desde Productos cuando subas las imágenes.";
    }

    /**
     * The trimmed copy of the load that travels in the state.
     *
     * The counts are the whole load, but the rows are only the first slice of each
     * side: the ones the screen prints, and a created article is reduced to the four
     * fields the table shows. The full list of refusals is not here; it is written to
     * the report file and reached through its path.
     *
     * @param  array{
     *     created: list<array<string, mixed>>,
     *     rejected: list<array<string, mixed>>,
     *     totals: array<string, int>
     * }  $result
     * @return array{
     *     totals: array<string, int>,
     *     created: list<array{name: string, reference: string, category: string, variants: int}>,
     *     rejected: list<array<string, mixed>>
     * }
     */
    private function summarize(array $result): array
    {
        return [
            'totals' => $result['totals'],
            'created' => array_map(
                fn (array $article): array => [
                    'name' => $article['name'],
                    'reference' => $article['reference'],
                    'category' => $article['category'],
                    'variants' => $article['variants'],
                ],
                array_slice($result['created'], 0, self::READY_PREVIEW_LIMIT),
            ),
            'rejected' => array_slice($result['rejected'], 0, self::REJECTED_PREVIEW_LIMIT),
        ];
    }

    /**
     * Drop the reviewed file: the copy on the disk and the reference in the state.
     */
    private function forgetUpload(BulkImportFileStore $store): void
    {
        $store->delete($this->storedPath);

        $this->storedPath = null;
        $this->file = null;
    }

    /**
     * Drop the report of refusals: the file on the disk and the reference in the state.
     */
    private function forgetReport(BulkImportFileStore $store): void
    {
        $store->delete($this->reportPath);

        $this->reportPath = null;
    }

    /**
     * The admin who confirmed the load, recorded on every stock movement the initial
     * stock leaves behind.
     */
    private function user(): ?User
    {
        return auth()->user();
    }

    private function maxFileSizeInKilobytes(): int
    {
        return max(1, (int) config('bulk_import.max_file_size_kb', 2048));
    }

    /**
     * The maximum as the screen writes it, in megabytes.
     */
    private function readableMaxSize(): string
    {
        return number_format($this->maxFileSizeInKilobytes() / 1024, 0, ',', '.').' MB';
    }

    private function maxRows(): int
    {
        return max(1, (int) config('bulk_import.max_rows', 1000));
    }

    /**
     * The maximum number of rows as the screen writes it, with the thousands
     * separator the rest of the screen uses.
     */
    private function readableMaxRows(): string
    {
        return number_format($this->maxRows(), 0, ',', '.');
    }

    /**
     * @return list<string>
     */
    private function allowedExtensions(): array
    {
        /** @var list<string> $extensions */
        $extensions = config('bulk_import.allowed_extensions', ['csv', 'xlsx']);

        return $extensions;
    }
}
