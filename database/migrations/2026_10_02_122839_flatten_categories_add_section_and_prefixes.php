<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    // Ajusta si tu validación de sku_prefix permite otra longitud.
    private const MAX_PREFIX = 5;

    public function up(): void
    {
        // Si ya existe, esta migración ya se aplicó (MySQL no revierte DDL).
        if (Schema::hasColumn('categories', 'section')) {
            return;
        }

        Schema::table('categories', function (Blueprint $table) {
            // El default solo garantiza que las filas actuales queden en 'hombre'.
            $table->string('section', 20)->default('hombre')->after('slug');
        });

        try {
            DB::transaction(fn () => $this->migrateData());
        } catch (Throwable $e) {
            // El DDL no se revierte solo: deshacemos la columna a mano para poder reintentar limpio.
            Schema::table('categories', function (Blueprint $table) {
                $table->dropColumn('section');
            });

            throw $e;
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('categories', 'section')) {
            Schema::table('categories', function (Blueprint $table) {
                $table->dropColumn('section');
            });
        }
    }

    private function migrateData(): void
    {
        $hasParent = Schema::hasColumn('categories', 'parent_id');

        $select = ['id', 'name', 'slug', 'sku_prefix'];
        if ($hasParent) {
            $select[] = 'parent_id';
        }

        $rows = DB::table('categories')->orderBy('id')->get($select);

        $isRoot = fn ($r) => ! $hasParent || $r->parent_id === null;

        // Raíces primero (conservan su prefijo base), subcategorías después.
        $ordered = $rows->filter($isRoot)->concat($rows->reject($isRoot));

        $usedPrefixes = [];
        $usedNames = [];
        $usedSlugs = [];

        foreach ($ordered as $row) {
            $existing = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $row->sku_prefix));

            $base = ($isRoot($row) && $existing !== '')
                ? $existing
                : substr(strtoupper(preg_replace('/[^A-Za-z]/', '', Str::ascii((string) $row->name))), 0, 3);

            if ($base === '') {
                $base = 'CAT';
            }

            DB::table('categories')->where('id', $row->id)->update([
                'sku_prefix' => $this->uniquePrefix($base, $usedPrefixes),
                'name' => $this->uniqueValue((string) $row->name, $usedNames, ' (%d)'),
                'slug' => $this->uniqueValue((string) $row->slug, $usedSlugs, '-%d'),
                'section' => 'hombre',
            ]);
        }

        if ($hasParent) {
            DB::table('categories')->update(['parent_id' => null]);
        }
    }

    private function uniquePrefix(string $base, array &$used): string
    {
        $stem = substr($base, 0, self::MAX_PREFIX - 1);
        $candidate = $stem.'H';
        $n = 2;

        while (isset($used[$candidate])) {
            $suffix = (string) $n++;
            $candidate = substr($stem, 0, self::MAX_PREFIX - 1 - strlen($suffix)).$suffix.'H';
        }

        return $used[$candidate] = $candidate;
    }

    private function uniqueValue(string $value, array &$used, string $format): string
    {
        $candidate = $value;
        $n = 2;

        while (isset($used[mb_strtolower($candidate)])) {
            $candidate = $value.sprintf($format, $n++);
        }

        $used[mb_strtolower($candidate)] = true;

        return $candidate;
    }
};
