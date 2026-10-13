<?php

namespace App\Services\ProductImport;

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
 * The folder is swept daily by `bulk-imports:prune`, because a file whose admin
 * closed the tab is a file nobody is going to confirm.
 */
final class BulkImportFileStore
{
    /**
     * The folder of the private disk where the reviewed files wait.
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
     * Copy an uploaded file into the private folder and return the path that names it.
     *
     * The name is random because two admins reviewing two files at once must not
     * collide, and the extension is kept because the reader decides how to open the
     * file by looking at it.
     */
    public function store(UploadedFile $file): string
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $name = bin2hex(random_bytes(16)).($extension === '' ? '' : '.'.$extension);

        return (string) $file->storeAs(self::DIRECTORY, $name, self::DISK);
    }

    /**
     * The absolute path of a stored file, which is what the reader and the importer
     * expect: they open the file from the filesystem and not through the disk.
     */
    public function absolutePath(string $storedPath): string
    {
        return $this->disk()->path($storedPath);
    }

    /**
     * Delete a file that was stored here, or do nothing when there is none.
     */
    public function delete(?string $storedPath): void
    {
        if ($storedPath === null || $storedPath === '') {
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

    private function disk(): Filesystem
    {
        return Storage::disk(self::DISK);
    }
}
