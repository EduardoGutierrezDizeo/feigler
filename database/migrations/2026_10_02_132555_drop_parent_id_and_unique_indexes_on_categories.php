<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1) Quitar parent_id con sus llaves foráneas e índices (sea cual sea su nombre).
        if (Schema::hasColumn('categories', 'parent_id')) {
            foreach (Schema::getForeignKeys('categories') as $fk) {
                if (in_array('parent_id', $fk['columns'], true)) {
                    Schema::table('categories', fn (Blueprint $t) => $t->dropForeign($fk['columns']));
                }
            }

            foreach (Schema::getIndexes('categories') as $ix) {
                if (! $ix['primary'] && in_array('parent_id', $ix['columns'], true)) {
                    Schema::table('categories', fn (Blueprint $t) => $t->dropIndex($ix['name']));
                }
            }

            Schema::table('categories', fn (Blueprint $t) => $t->dropColumn('parent_id'));
        }

        // 2) Quitar uniques globales de name/slug: ahora son únicos por sección
        //    (si no, "Polos" en Hombre bloquearía "Polos" en Mujer).
        foreach (Schema::getIndexes('categories') as $ix) {
            if ($ix['unique'] && ! $ix['primary']
                && count($ix['columns']) === 1
                && in_array($ix['columns'][0], ['name', 'slug'], true)) {
                Schema::table('categories', fn (Blueprint $t) => $t->dropIndex($ix['name']));
            }
        }

        // 3) Índices nuevos, solo si faltan.
        if (! Schema::hasIndex('categories', ['section', 'name'], 'unique')) {
            Schema::table('categories', fn (Blueprint $t) => $t->unique(['section', 'name']));
        }

        if (! Schema::hasIndex('categories', ['section', 'slug'], 'unique')) {
            Schema::table('categories', fn (Blueprint $t) => $t->unique(['section', 'slug']));
        }

        if (! Schema::hasIndex('categories', ['sku_prefix'], 'unique')) {
            Schema::table('categories', fn (Blueprint $t) => $t->unique('sku_prefix'));
        }

        foreach (['sort_order', 'order'] as $sortColumn) {
            if (Schema::hasColumn('categories', $sortColumn)) {
                if (! Schema::hasIndex('categories', ['section', $sortColumn])) {
                    Schema::table('categories', fn (Blueprint $t) => $t->index(['section', $sortColumn]));
                }
                break;
            }
        }
    }

    public function down(): void
    {
        // Reversión de mejor esfuerzo: no restaura la jerarquía original.
        if (Schema::hasIndex('categories', ['section', 'name'], 'unique')) {
            Schema::table('categories', fn (Blueprint $t) => $t->dropUnique(['section', 'name']));
        }

        if (Schema::hasIndex('categories', ['section', 'slug'], 'unique')) {
            Schema::table('categories', fn (Blueprint $t) => $t->dropUnique(['section', 'slug']));
        }

        if (! Schema::hasColumn('categories', 'parent_id')) {
            Schema::table('categories', function (Blueprint $t) {
                $t->foreignId('parent_id')->nullable()->constrained('categories')->restrictOnDelete();
            });
        }
    }
};
