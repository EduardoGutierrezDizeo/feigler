<?php

namespace App\Services\ProductImport;

use App\Exceptions\InvalidStoredImportPathException;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * The private folder where an uploaded file waits between the preview and the confirmation.
 *
 * Livewire's own temporary file only lives five minutes, which is not long enough
 * for an admin to read a preview and decide, so the file is copied here the moment
 * it is reviewed. What the component keeps in its state is the returned path and
 * nothing else: the bytes never travel to the browser, only the name of the file
 * that holds them.
 *
 * The same folder keeps the full report of refusals the load leaves behind, so the
 * next step can offer a download without holding the list in the component state.
 *
 * **The path is data the client can see, so it is treated as hostile.** Every method
 * that turns a stored path into a filesystem operation checks it twice: first the shape
 * (a name of the folder, a random stem and one of the known extensions, and nothing
 * with a hop, a backslash, a drive or a null byte), and then the resolved location
 * (`realpath()` has to fall inside the folder). A path that fails either check is
 * refused, and `delete()` becomes a no-op instead of throwing, because it runs while
 * cleaning up and a bad path there must not break the screen.
 *
 * The folder is swept daily by `bulk-imports:prune`, because a file whose admin
 * closed the tab is a file nobody is going to confirm.
 */
final class BulkImportFileStore
{
    /**
     * The folder of the private disk where the reviewed files and the reports wait.
     */
    public const DIRECTORY = 'bulk-imports';

    /**
     * The disk the folder lives on, which is the private one: `storage/app/private`.
     */
    public const DISK = 'local';

    /**
     * How long a file that nobody confirms is kept, in hours.
     */
    public const RETENTION_HOURS = 24;

    /**
     * The extension of the report of refusals, which is not `csv` nor `xlsx` on
     * purpose: it is written by us and only ever read through `readReport()`, so it
     * must never be mistaken for an upload.
     */
    public const REPORT_EXTENSION = 'json';

    /**
     * The stem every stored name shares: thirty-two hexadecimal characters.
     */
    private const NAME_STEM = '[0-9a-f]{32}';

    /**
     * Copy an uploaded file into the private folder and return the path that names it.
     *
     * The stored name is exactly `<32 hex>.<extension>`, with the extension the reader
     * uses to open the file: the stem is random because two admins reviewing two files
     * at once must not collide, and it is fixed in shape because `absolutePath()`,
     * `delete()` and the report helpers only accept names that follow it.
     */
    public function store(UploadedFile $file): string
    {
        $extension = strtolower($file->getClientOriginalExtension());

        return (string) $file->storeAs(self::DIRECTORY, $this->randomName($extension), self::DISK);
    }

    /**
     * The absolute path of a stored upload, which is what the reader and the importer
     * expect: they open the file from the filesystem and not through the disk.
     *
     * @throws InvalidStoredImportPathException when the path is not a stored upload
     */
    public function absolutePath(string $storedPath): string
    {
        return $this->resolve($storedPath, $this->uploadExtensions());
    }

    /**
     * Write the full list of refusals of a load and return the path that names it.
     *
     * It is stored as JSON under the same folder as the upload, with a different
     * extension. The component keeps only the returned path, so the whole list never
     * travels to the browser; the report is read back with `readReport()` when the
     * download of the next step asks for it.
     *
     * @param  list<array<string, mixed>>  $rejected
     */
    public function storeReport(array $rejected): string
    {
        $path = self::DIRECTORY.'/'.$this->randomName(self::REPORT_EXTENSION);

        $this->disk()->put($path, json_encode(
            ['rejected' => array_values($rejected)],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ));

        return $path;
    }

    /**
     * Read a report written by `storeReport()`, or an empty list when it is gone.
     *
     * @return list<array<string, mixed>>
     *
     * @throws InvalidStoredImportPathException when the path is not a stored report
     */
    public function readReport(string $path): array
    {
        $contents = file_get_contents($this->resolve($path, [self::REPORT_EXTENSION]));

        if ($contents === false) {
            return [];
        }

        $decoded = json_decode($contents, true);

        if (! is_array($decoded) || ! is_array($decoded['rejected'] ?? null)) {
            return [];
        }

        /** @var list<array<string, mixed>> */
        return array_values($decoded['rejected']);
    }

    /**
     * Delete a file that was stored here, or do nothing when there is none.
     *
     * It accepts both an upload and a report, and it never throws: a path that is not
     * one of ours is left alone, which is exactly what has to happen while cleaning up.
     */
    public function delete(?string $storedPath): void
    {
        if ($storedPath === null || $storedPath === '') {
            return;
        }

        $absolute = $this->tryResolve($storedPath, [...$this->uploadExtensions(), self::REPORT_EXTENSION]);

        if ($absolute === null) {
            return;
        }

        $this->disk()->delete($storedPath);
    }

    /**
     * Delete the files nobody has touched for a while, and answer how many went.
     */
    public function prune(int $hours = self::RETENTION_HOURS): int
    {
        $disk = $this->disk();
        $cutoff = Carbon::now()->subHours($hours)->getTimestamp();
        $deleted = 0;

        foreach ($disk->files(self::DIRECTORY) as $file) {
            if ($disk->lastModified($file) < $cutoff) {
                $disk->delete($file);
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * The absolute path of a stored name once it passed both checks.
     *
     * @param  list<string>  $extensions
     *
     * @throws InvalidStoredImportPathException
     */
    private function resolve(string $storedPath, array $extensions): string
    {
        $this->assertSafe($storedPath, $extensions);

        $absolute = realpath($this->disk()->path($storedPath));

        if ($absolute === false || ! $this->isInsideFolder($absolute)) {
            throw InvalidStoredImportPathException::forPath($storedPath);
        }

        return $absolute;
    }

    /**
     * The absolute path of a stored name, or `null` when it is not one of ours.
     *
     * @param  list<string>  $extensions
     */
    private function tryResolve(string $storedPath, array $extensions): ?string
    {
        try {
            return $this->resolve($storedPath, $extensions);
        } catch (InvalidStoredImportPathException) {
            return null;
        }
    }

    /**
     * Refuse anything that does not name a file of this folder and this alone.
     *
     * The shape is checked explicitly and not only through the final pattern, so the
     * reason a path is refused is readable in the code: a null byte, a relative hop,
     * a backslash (which Windows reads as a separator), an absolute path or a drive.
     * The pattern then pins the three parts together and rejects the siblings that
     * only share the name, like `bulk-imports-otro/...`.
     *
     * @param  list<string>  $extensions
     *
     * @throws InvalidStoredImportPathException
     */
    private function assertSafe(string $storedPath, array $extensions): void
    {
        if (
            str_contains($storedPath, "\0")
            || str_contains($storedPath, '..')
            || str_contains($storedPath, '\\')
            || str_starts_with($storedPath, '/')
            || preg_match('#^[a-zA-Z]:#', $storedPath) === 1
        ) {
            throw InvalidStoredImportPathException::forPath($storedPath);
        }

        $extensions = array_map(fn (string $extension): string => preg_quote($extension, '#'), $extensions);
        $pattern = '#^'.preg_quote(self::DIRECTORY, '#').'/'.self::NAME_STEM.'\.('.implode('|', $extensions).')$#';

        if (preg_match($pattern, $storedPath) !== 1) {
            throw InvalidStoredImportPathException::forPath($storedPath);
        }
    }

    /**
     * Whether a resolved path really sits inside the resolved folder.
     *
     * The separator is kept at the end of the folder so a sibling whose name merely
     * starts with the folder's — `bulk-imports-otro` next to `bulk-imports` — is not
     * taken for a child.
     */
    private function isInsideFolder(string $absolute): bool
    {
        $folder = realpath($this->disk()->path(self::DIRECTORY));

        if ($folder === false) {
            return false;
        }

        return str_starts_with($absolute, rtrim($folder, '\\/').DIRECTORY_SEPARATOR);
    }

    /**
     * A name of the fixed shape the store always writes.
     */
    private function randomName(string $extension): string
    {
        return bin2hex(random_bytes(16)).'.'.$extension;
    }

    /**
     * The extensions an upload can carry, and the only ones an upload path accepts.
     *
     * @return list<string>
     */
    private function uploadExtensions(): array
    {
        /** @var list<string> $extensions */
        $extensions = config('bulk_import.allowed_extensions', ['csv', 'xlsx']);

        return $extensions;
    }

    private function disk(): Filesystem
    {
        return Storage::disk(self::DISK);
    }
}
