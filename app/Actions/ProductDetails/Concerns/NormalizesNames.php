<?php

namespace App\Actions\ProductDetails\Concerns;

use Illuminate\Support\Str;

/**
 * How the store names things, and how two names are said to be the same one.
 *
 * MySQL compares text with a collation that ignores case and accents, and SQLite —
 * which is what the tests run on — does not. Leaving the comparison of names to the
 * database would therefore make a rule hold on one driver and not on the other, so
 * names are compared here in PHP, where both drivers read the same answer.
 *
 * The lists a name is checked against are the sizes of one category or the colors
 * and the materials of the whole store, which are small enough to bring in and
 * compare in memory.
 */
trait NormalizesNames
{
    /**
     * A name as it is stored: the spaces around it gone and the runs of spaces
     * inside it collapsed into one, so `  XXL  ` and `XXL` are stored the same.
     */
    private function cleanName(string $name): string
    {
        return Str::squish($name);
    }

    /**
     * The key two names are compared by: lowercase, without accents and without the
     * punctuation of the language, so `ÚNICA`, `única` and `Unica` are one size.
     */
    private function nameKey(string $name): string
    {
        return Str::lower(Str::ascii($name));
    }

    /**
     * Whether a name is already used among the ones given, ignoring case and
     * accents.
     *
     * @param  iterable<string>  $existingNames
     */
    private function nameIsTaken(iterable $existingNames, string $name): bool
    {
        $key = $this->nameKey($name);

        foreach ($existingNames as $existingName) {
            if ($this->nameKey($existingName) === $key) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether two names are the same one, ignoring case and accents.
     *
     * It is what tells an edit that renames a row from an edit that only rewrites
     * the same name with other letters, which for the checks below is no rename at
     * all.
     */
    private function nameIsTheSame(string $name, string $other): bool
    {
        return $this->nameKey($name) === $this->nameKey($other);
    }
}
