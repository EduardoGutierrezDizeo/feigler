<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * The hex given to the colors discovered in the old free text column, so the
     * conversion below always produces a valid, obviously placeholder swatch.
     */
    private const PLACEHOLDER_HEX = '#CCCCCC';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->foreignId('color_id')->nullable()->after('size')->constrained('colors')->restrictOnDelete();
        });

        $this->migrateColorTextToColorId();

        // The new unique index has to exist BEFORE the old one is dropped. In MySQL
        // the (product_id, size, color) index is the only one able to back the
        // product_id foreign key, so dropping it first aborts the migration with
        // errno 1553 ("Cannot drop index ... needed in a foreign key constraint").
        Schema::table('product_variants', function (Blueprint $table) {
            $table->unique(['product_id', 'size', 'color_id'], 'product_variants_product_id_size_color_id_unique');
        });

        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropUnique(['product_id', 'size', 'color']);
        });

        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropColumn('color');
        });

        Schema::table('product_variants', function (Blueprint $table) {
            $table->unsignedBigInteger('color_id')->nullable(false)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->string('color')->nullable()->after('size');
        });

        $this->restoreColorTextFromColorId();

        Schema::table('product_variants', function (Blueprint $table) {
            $table->string('color')->nullable(false)->change();
        });

        Schema::table('product_variants', function (Blueprint $table) {
            $table->unique(['product_id', 'size', 'color']);
        });

        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropUnique('product_variants_product_id_size_color_id_unique');
            $table->dropForeign(['color_id']);
            $table->dropColumn('color_id');
        });
    }

    /**
     * Turn every distinct value of the old `color` text column into a `colors` row
     * and point `color_id` at it.
     */
    private function migrateColorTextToColorId(): void
    {
        $names = DB::table('product_variants')
            ->whereNotNull('color')
            ->distinct()
            ->orderBy('color')
            ->pluck('color');

        foreach ($names as $name) {
            $colorId = $this->resolveColorId((string) $name);

            DB::table('product_variants')
                ->where('color', $name)
                ->update(['color_id' => $colorId]);
        }
    }

    /**
     * Copy the color name of every variant back into the old `color` text column.
     */
    private function restoreColorTextFromColorId(): void
    {
        DB::table('colors')
            ->orderBy('id')
            ->each(function (object $color) {
                DB::table('product_variants')
                    ->where('color_id', $color->id)
                    ->update(['color' => $color->name]);
            });
    }

    /**
     * The id of the color with this name, creating it the first time it shows up.
     */
    private function resolveColorId(string $name): int
    {
        $existingId = DB::table('colors')->where('name', $name)->value('id');

        if ($existingId !== null) {
            return (int) $existingId;
        }

        return (int) DB::table('colors')->insertGetId([
            'name' => $name,
            'hex' => self::PLACEHOLDER_HEX,
            'code' => $this->uniqueCodeFor($name),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * The code of a color discovered in the free text column: the first three
     * alphanumeric characters uppercased, made unique against the existing ones.
     */
    private function uniqueCodeFor(string $name): string
    {
        $alphanumeric = preg_replace('/[^A-Za-z0-9]/', '', $name) ?? '';
        $base = Str::upper(Str::substr(Str::padRight($alphanumeric, 3, 'X'), 0, 3));

        if (! $this->codeIsTaken($base)) {
            return $base;
        }

        $prefix = Str::substr($base, 0, 2);

        foreach (array_merge(range('0', '9'), range('A', 'Z')) as $suffix) {
            $candidate = $prefix.$suffix;

            if (! $this->codeIsTaken($candidate)) {
                return $candidate;
            }
        }

        throw new RuntimeException("No se pudo generar un código de color único para «{$name}».");
    }

    /**
     * Whether a color already owns this code.
     */
    private function codeIsTaken(string $code): bool
    {
        return DB::table('colors')->where('code', $code)->exists();
    }
};
