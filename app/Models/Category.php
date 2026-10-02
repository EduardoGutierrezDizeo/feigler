<?php

namespace App\Models;

use Database\Factories\CategoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

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
        'parent_id',
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
            'order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * The parent category, when this category is a subcategory.
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * The subcategories nested one level below this category.
     *
     * @return HasMany<Category>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
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
     * The `order` a brand new category should get inside its sibling group.
     *
     * Every category created from the panel used to fall back to the migration
     * default of `0`, so a whole group ended up sharing the same value. That is
     * harmless on its own but makes reordering a no-op later on, because
     * swapping two identical values does not move anything. Appending to the
     * current maximum keeps `order` unique per group from the start.
     */
    public static function nextOrderFor(?int $parentId): int
    {
        $maxOrder = static::query()
            ->where('parent_id', $parentId)
            ->max('order');

        return $maxOrder === null ? 0 : ((int) $maxOrder) + 1;
    }

    /**
     * Move a category one slot up (-1) or down (+1) among its siblings.
     *
     * Instead of swapping the two colliding `order` values, the whole group is
     * reindexed: the category is lifted out of the list, inserted at its
     * destination and every sibling is rewritten to a contiguous `0..n-1`
     * sequence. Reindexing is what makes the move actually happen. Swapping only
     * worked while the values were unique, and two siblings sharing a value
     * (which is exactly what the old `create()` produced) turned the swap into a
     * no-op that still ran its UPDATEs. Rebuilding the sequence also heals any
     * group that is already corrupted.
     *
     * The offset is clamped to the bounds of the group, so calling this at
     * either end of the list is a no-op rather than an error.
     */
    public static function moveWithinSiblings(Category $category, int $offset): void
    {
        DB::transaction(function () use ($category, $offset) {
            $siblingIds = static::siblingIdsFor($category->parent_id);

            $currentIndex = array_search($category->id, $siblingIds, true);

            if ($currentIndex === false) {
                return;
            }

            $targetIndex = max(0, min($currentIndex + $offset, count($siblingIds) - 1));

            array_splice($siblingIds, $currentIndex, 1);
            array_splice($siblingIds, $targetIndex, 0, [$category->id]);

            static::writeOrderSequence($siblingIds);
        });
    }

    /**
     * Reindex every sibling group in the table, roots included.
     *
     * This is the data repair counterpart of `moveWithinSiblings()`: it applies
     * the same `orderBy('order')->orderBy('id')` ordering and the same
     * contiguous `0..n-1` rewrite to every group, which removes the duplicate
     * values that shipped before `order` started being assigned on create.
     *
     * @return int the number of rows actually rewritten
     */
    public static function reindexAllSiblingGroups(): int
    {
        $parentIds = static::query()
            ->distinct()
            ->pluck('parent_id');

        $rewritten = 0;

        DB::transaction(function () use ($parentIds, &$rewritten) {
            foreach ($parentIds as $parentId) {
                $rewritten += static::writeOrderSequence(static::siblingIdsFor($parentId));
            }
        });

        return $rewritten;
    }

    /**
     * The ids of a sibling group in display order, the same order `render()` uses.
     *
     * `id` breaks ties so the result is deterministic even when the stored
     * `order` values collide, which is the situation this reindexing exists to
     * repair. Passing `null` returns the root group.
     *
     * @return list<int>
     */
    private static function siblingIdsFor(?int $parentId): array
    {
        return static::query()
            ->where('parent_id', $parentId)
            ->orderBy('order')
            ->orderBy('id')
            ->pluck('id')
            ->all();
    }

    /**
     * Write `order` = 0, 1, 2... n-1 for an ordered list of sibling ids.
     *
     * Rows already sitting at their target value are skipped, so a single move
     * only touches the rows that really changed instead of bumping `updated_at`
     * across the whole group.
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
