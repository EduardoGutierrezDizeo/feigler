<?php

namespace App\Models;

use App\Enums\StoreSection;
use Database\Factories\CategoryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class Category extends Model
{
    /** @use HasFactory<CategoryFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'slug',
        'section',
        'sku_prefix',
        'order',
        'is_active',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'section' => StoreSection::class,
            'order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * The products assigned to this category.
     *
     * @return HasMany<Product>
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'category_id');
    }

    /**
     * The sizes the garments of this category are sold in, smallest first.
     *
     * The order is the one the catalog reads them in, and the id breaks the ties of
     * two sizes that share it, so the same list never changes between two reads.
     *
     * @return HasMany<Size>
     */
    public function sizes(): HasMany
    {
        return $this->hasMany(Size::class)
            ->orderBy('order')
            ->orderBy('id');
    }

    /**
     * Only the categories of one store section.
     *
     * @param  Builder<Category>  $query
     */
    public function scopeInSection(Builder $query, StoreSection $section): void
    {
        $query->where('section', $section->value);
    }

    /**
     * The `order` a brand new category should get inside its section.
     *
     * Every category created from the panel used to fall back to the migration
     * default of `0`, so a whole group ended up sharing the same value. That is
     * harmless on its own but makes reordering a no-op later on, because
     * swapping two identical values does not move anything. Appending to the
     * current maximum keeps `order` unique per section from the start.
     */
    public static function nextOrderFor(StoreSection $section): int
    {
        $maxOrder = static::query()
            ->inSection($section)
            ->max('order');

        return $maxOrder === null ? 0 : ((int) $maxOrder) + 1;
    }

    /**
     * Move a category one slot up (-1) or down (+1) inside its section.
     *
     * Instead of swapping the two colliding `order` values, the whole section is
     * reindexed: the category is lifted out of the list, inserted at its
     * destination and every category of the section is rewritten to a contiguous
     * `0..n-1` sequence. Reindexing is what makes the move actually happen.
     * Swapping only worked while the values were unique, and two categories
     * sharing a value (which is exactly what the old `create()` produced) turned
     * the swap into a no-op that still ran its UPDATEs. Rebuilding the sequence
     * also heals any section that is already corrupted.
     *
     * The offset is clamped to the bounds of the section, so calling this at
     * either end of the list is a no-op rather than an error.
     */
    public static function moveWithinSection(Category $category, int $offset): void
    {
        DB::transaction(function () use ($category, $offset) {
            $sectionIds = static::sectionIdsFor($category->section);

            $currentIndex = array_search($category->id, $sectionIds, true);

            if ($currentIndex === false) {
                return;
            }

            $targetIndex = max(0, min($currentIndex + $offset, count($sectionIds) - 1));

            array_splice($sectionIds, $currentIndex, 1);
            array_splice($sectionIds, $targetIndex, 0, [$category->id]);

            static::writeOrderSequence($sectionIds);
        });
    }

    /**
     * Reindex every section in the table.
     *
     * This is the data repair counterpart of `moveWithinSection()`: it applies
     * the same `orderBy('order')->orderBy('id')` ordering and the same
     * contiguous `0..n-1` rewrite to every section, which removes the duplicate
     * values that shipped before `order` started being assigned on create.
     *
     * The name still says "siblings" because the migration that repairs the old
     * data calls it that way; what a sibling group is now is a store section.
     *
     * @return int the number of rows actually rewritten
     */
    public static function reindexAllSiblingGroups(): int
    {
        // The historical migration that calls this runs before `section` exists,
        // so on a fresh database the whole table is still one single group.
        /** @var list<StoreSection|null> $sections */
        $sections = Schema::hasColumn('categories', 'section')
            ? static::query()->distinct()->pluck('section')
                ->map(fn (mixed $section) => $section instanceof StoreSection
                    ? $section
                    : StoreSection::from((string) $section))
                ->all()
            : [null];

        $rewritten = 0;

        DB::transaction(function () use ($sections, &$rewritten) {
            foreach ($sections as $section) {
                $rewritten += static::writeOrderSequence(static::sectionIdsFor($section));
            }
        });

        return $rewritten;
    }

    /**
     * The ids of one section in display order, the same order `render()` uses.
     *
     * `id` breaks ties so the result is deterministic even when the stored
     * `order` values collide, which is the situation this reindexing exists to
     * repair.
     *
     * A `null` section reindexes the whole table, which is what the migration
     * that predates `section` needs.
     *
     * @return list<int>
     */
    private static function sectionIdsFor(?StoreSection $section): array
    {
        $query = static::query()
            ->orderBy('order')
            ->orderBy('id');

        if ($section !== null) {
            $query->inSection($section);
        }

        return $query->pluck('id')->all();
    }

    /**
     * Write `order` = 0, 1, 2... n-1 for an ordered list of category ids.
     *
     * Rows already sitting at their target value are skipped, so a single move
     * only touches the rows that really changed instead of bumping `updated_at`
     * across the whole section.
     *
     * @param  list<int>  $orderedIds
     * @return int the number of rows actually rewritten
     */
    private static function writeOrderSequence(array $orderedIds): int
    {
        $currentOrders = static::query()
            ->whereIn('id', $orderedIds)
            ->pluck('order', 'id');

        $rewritten = 0;

        foreach ($orderedIds as $position => $id) {
            if (($currentOrders[$id] ?? null) === $position) {
                continue;
            }

            static::query()->whereKey($id)->update(['order' => $position]);

            $rewritten++;
        }

        return $rewritten;
    }
}
