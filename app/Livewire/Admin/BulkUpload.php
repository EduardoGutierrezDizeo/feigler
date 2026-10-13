<?php

namespace App\Livewire\Admin;

use App\Exceptions\ImportFileEmptyException;
use App\Exceptions\ImportFileTooLargeException;
use App\Exceptions\ImportHeadersMissingException;
use App\Exceptions\ImportRowLimitExceededException;
use App\Exceptions\UnsupportedImportFormatException;
use App\Livewire\Concerns\Notifies;
use App\Livewire\Concerns\RequiresAdmin;
use App\Models\User;
use App\Services\ProductImport\BulkImportFileStore;
use App\Services\ProductImport\BulkProductImporter;
use App\Services\ProductImport\BulkProductImportValidator;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;
use OpenSpout\Common\Exception\OpenSpoutException;

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
 * to be created is reported under the `creacion` column and kept in `result`, which
 * is what the downloadable report of the next step reads.
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
     */
    public string $step = self::STEP_UPLOAD;

    /**
     * Where the reviewed file waits, as a path on the private disk, or `null` when
     * there is nothing to confirm.
     */
    public ?string $storedPath = null;

    /**
     * The name the file had when the admin chose it, kept to name it in the screen.
     */
    public string $originalName = '';

    /**
     * The preview of the file: the counts of the whole file and the first rows of
     * each side. It is emptied when the file is confirmed or cancelled.
     *
     * @var array{
     *     totals?: array{ready_articles: int, ready_variants: int, rejected_articles: int, rejected_rows: int},
     *     ready?: list<array<string, mixed>>,
     *     rejected?: list<array<string, mixed>>
     * }
     */
    public array $preview = [];

    /**
     * What the load created and refused. It is kept after the load because the
     * downloadable report is built from its `rejected` list.
     *
     * @var array{
     *     created?: list<array<string, mixed>>,
     *     rejected?: list<array<string, mixed>>,
     *     totals?: array{created_articles: int, created_variants: int, rejected_articles: int, rejected_rows: int}
     * }
     */
    public array $result = [];

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
            'ready' => array_slice($preview['ready'], 0, self::READY_PREVIEW_LIMIT),
            'rejected' => array_slice($preview['rejected'], 0, self::REJECTED_PREVIEW_LIMIT),
        ];

        $this->step = self::STEP_PREVIEW;
    }

    /**
     * Run the load over the reviewed file and move to the summary.
     *
     * The importer re-reads the file and creates only what is valid now, so this can
     * say anything different from the preview the admin just read. The temporary file
     * is deleted as the last thing that happens, before the step changes: a second
     * call finds nothing to confirm and returns, which is what makes the button safe
     * to double-click.
     */
    public function confirm(): void
    {
        if (! $this->canConfirm()) {
            return;
        }

        $store = new BulkImportFileStore;

        $result = $this->read(
            fn (): array => (new BulkProductImporter)->import($store->absolutePath($this->storedPath), $this->user()),
        );

        if ($result === null) {
            return;
        }

        $this->result = $result;
        $this->forgetFile($store);
        $this->preview = [];
        $this->step = self::STEP_RESULT;

        $this->announceResult($result['totals']);
    }

    /**
     * Drop the reviewed file and go back to the first step, from the preview or from
     * the summary.
     */
    public function cancel(): void
    {
        $this->forgetFile(new BulkImportFileStore);
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

    public function render()
    {
        return view('livewire.admin.bulk-upload', [
            'canConfirm' => $this->canConfirm(),
            'maxSizeLabel' => $this->readableMaxSize(),
        ]);
    }

    /**
     * Read the file through the validator or the importer, turning a file-level
     * refusal into a toast instead of a broken screen.
     *
     * Only the refusals about the file as a whole are caught: a missing column, an
     * empty file, one too heavy or too long, an extension the reader does not know.
     * A workbook that is corrupt or is not one at all fails inside OpenSpout, which
     * says nothing an admin can read, so its detail goes to the log and the screen is
     * told the file could not be read. Anything else is a bug and is left to bubble.
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
        $this->forgetFile(new BulkImportFileStore);
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
     * Drop the temporary file: the copy on the disk and the reference in the state.
     */
    private function forgetFile(BulkImportFileStore $store): void
    {
        $store->delete($this->storedPath);

        $this->storedPath = null;
        $this->file = null;
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
