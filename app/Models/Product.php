<?php

namespace App\Models;

use App\Enums\StoreSection;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    /**
     * The days a freshly created product is considered new. The «Nuevo» badge
     * on the cards and the Novedades page both read this single rule, so the
     * two can never disagree about what counts as a novelty.
     */
    public const NUEVO_DIAS = 30;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'category_id',
        'name',
        'slug',
        'reference',
        'description',
        'brand',
        'base_price',
        'status',
        'cover_color_id',
    ];

    /**
     * The image files to erase once the row of the product is gone.
     *
     * It holds the originals and the thumbnails alike: a small copy that outlives
     * the picture it stands for is a file nobody will ever clean up, because
     * nothing but the row of the product remembers that it was there.
     *
     * @var list<string>
     */
    private array $imagePathsToDelete = [];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'base_price' => 'decimal:2',
        ];
    }

    /**
     * Give the product a reference when nobody provided one.
     *
     * It only happens on insert: a product that already has a reference keeps it
     * even if it is moved to another category, because its SKUs were built from it.
     *
     * A product that goes away takes its image files with it. The paths are read
     * before the delete and the files are erased after it: the rows of the images
     * are removed by the foreign key, so afterwards there is nothing left to ask
     * for their paths, and a delete that ends up rolled back has not yet taken the
     * pictures off the disk when the row is still there.
     */
    protected static function booted(): void
    {
        static::creating(function (self $product): void {
            if (filled($product->reference) || $product->category === null) {
                return;
            }

            $product->reference = static::nextReferenceFor($product->category);
        });

        static::deleting(function (self $product): void {
            $product->imagePathsToDelete = $product->images()
                ->get(['path', 'thumbnail_path'])
                ->flatMap(fn (ProductImage $image): array => array_filter([$image->path, $image->thumbnail_path]))
                ->values()
                ->all();
        });

        static::deleted(function (self $product): void {
            foreach ($product->imagePathsToDelete as $path) {
                Storage::disk(ProductImage::DISK)->delete($path);
            }

            $product->imagePathsToDelete = [];
        });
    }

    /**
     * The category the product belongs to.
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * The materials this garment is made of, in the order the catalog reads them.
     *
     * The order is settled by the material itself and the id breaks the ties of two
     * materials that share it, so the same product never swaps two materials between
     * two reads. `percentage` comes along with the relation: a garment made of
     * several materials has to say how much of each.
     *
     * @return BelongsToMany<Material, $this>
     */
    public function materials(): BelongsToMany
    {
        return $this->belongsToMany(Material::class)
            ->withPivot('percentage')
            ->orderBy('materials.order')
            ->orderBy('materials.id');
    }

    /**
     * The sellable variants (size/color combinations) of the product.
     *
     * @return HasMany<ProductVariant>
     */
    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    /**
     * The images attached to the product as a whole.
     *
     * They come in the order the gallery shows them, which is the order they
     * were uploaded in, and the id breaks the ties of images that share one, so
     * that two reads of the same product never swap two pictures between them.
     * That matters beyond the gallery: `cover_image` picks out of this relation
     * the main image of the cover color, and without the order the catalog
     * could show two different pictures of the same product on two visits.
     *
     * @return HasMany<ProductImage>
     */
    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)
            ->orderBy('order')
            ->orderBy('id');
    }

    /**
     * The color whose main image is the picture of the product.
     *
     * It is the color the catalog shows first, so it is a choice of the store and
     * not something the images decide on their own. It is nullable because a
     * product may have no color chosen yet, and `cover_image` falls back to the
     * main image of any other color while that is the case.
     */
    public function coverColor(): BelongsTo
    {
        return $this->belongsTo(Color::class, 'cover_color_id');
    }

    /**
     * The store section the product belongs to, inherited from its category.
     *
     * It is never stored on the product: the catalog is divided into sections by
     * the categories, and a product is in a section for as long as it sits in a
     * category of that section.
     */
    protected function section(): Attribute
    {
        return Attribute::get(fn (): ?StoreSection => $this->category?->section);
    }

    /**
     * The next free reference for the products of this category: its SKU prefix
     * plus a counter of at least three digits, e.g. `PLH-001`, `PLH-002`.
     *
     * The prefix belongs to the category and to nobody else, which is what makes
     * the series independent: `PL-001` (a category of the old catalog) is left
     * out of the count of `PLH`, because the match demands the prefix followed by
     * a dash and nothing else.
     *
     * The counter is the highest number already used under that prefix plus one,
     * read and compared as a number instead of as text. Ordering references as text
     * breaks at the fourth digit, where `PLH-1000` sorts before `PLH-999` and the next
     * product would be numbered `PLH-001` all over again. References that are not
     * `PREFIX-` followed by digits are left out of the count.
     *
     * The category row is locked for the whole transaction. Locking only the
     * last product would not be enough: with no product yet there is no row to
     * lock, so two products created at the same time would both read "none" and
     * both claim `PLH-001`. The category row always exists, and every product that
     * wants a `PLH-` reference queues behind it.
     *
     * Callers creating a product must do it inside `DB::transaction()`. The lock
     * lives no longer than the outermost transaction, and the read of the counter
     * is only useful while it is held: without an outer transaction the lock is
     * released as soon as this method returns, and the INSERT that follows lands
     * outside of it, which is exactly the race the lock was there to prevent.
     */
    public static function nextReferenceFor(Category $category): string
    {
        $prefix = $category->sku_prefix;

        return DB::transaction(function () use ($category, $prefix) {
            $category->newQuery()->lockForUpdate()->findOrFail($category->getKey());

            $highest = 0;

            foreach (static::referencesInSeries($prefix) as $reference) {
                if (preg_match('/^'.preg_quote($prefix, '/').'-(\d+)$/', $reference, $matches) !== 1) {
                    continue;
                }

                $highest = max($highest, (int) $matches[1]);
            }

            return $prefix.'-'.str_pad((string) ($highest + 1), 3, '0', STR_PAD_LEFT);
        });
    }

    /**
     * The references already taken in this series, locked until the transaction
     * that asked for them ends.
     *
     * @return list<string>
     */
    private static function referencesInSeries(string $prefix): array
    {
        return static::query()
            ->where('reference', 'like', $prefix.'-%')
            ->lockForUpdate()
            ->pluck('reference')
            ->all();
    }

    /**
     * The units available for sale: the stock of the active variants only.
     *
     * An inactive variant is out of the catalog, so counting it would advertise
     * stock nobody can buy.
     */
    protected function stockTotal(): Attribute
    {
        return Attribute::get(fn (): int => (int) $this->variants
            ->where('is_active', true)
            ->sum('stock'));
    }

    /**
     * The status to show, which is not always the stored one.
     *
     * `out_of_stock` is never written to the `status` column: it is what happens
     * when the stock runs out, and a stored copy would go stale the moment a
     * movement is recorded. A stored `out_of_stock` is ignored on purpose, since
     * those are rows written before the status became computed.
     *
     * The variant count is read from the `variants` relation whenever it is already
     * loaded, and only falls back to a query when it is not. A listing eager-loads
     * the relation to read `stock_total`, so asking the database again here would
     * put one query per row back into a table that had none.
     */
    protected function displayStatus(): Attribute
    {
        return Attribute::get(function (): string {
            if ($this->status === 'inactive') {
                return 'inactive';
            }

            $hasVariants = $this->relationLoaded('variants')
                ? $this->variants->isNotEmpty()
                : $this->variants()->exists();

            if (! $hasVariants) {
                return 'no_variants';
            }

            return $this->stock_total <= 0 ? 'out_of_stock' : 'active';
        });
    }

    /**
     * The products from the A to the Z, by name, however the name was typed.
     *
     * A name is folded to its plain letters before it is compared, the same way
     * `NormalizesNames` folds it before it is looked for: `Ámbar` has to land where
     * `Ambar` would, or the panel and the search would disagree about which comes
     * first. The folding is spelled out as a chain of `REPLACE` and wrapped in `LOWER`
     * because neither driver folds on its own the same way: MySQL gets it from the
     * collation of the column, `utf8mb4_unicode_ci`, and SQLite's `LOWER()` only
     * touches ASCII, so `Á` stayed `Á` and every accented name sorted to the end.
     * The chain is written so that it agrees with MySQL rather than with SQLite, since
     * the store runs on MySQL and the tests run on SQLite: a rule that only held on one
     * of the two would be a rule that only held in the tests.
     *
     * The id breaks the tie of two products with the same name, so that reading a
     * longer stretch of the list never moves a row between two reads, which is what a
     * listing that pages by growing a limit would show.
     *
     * @param  Builder<Product>  $query
     */
    public function scopeAlphabetically(Builder $query): void
    {
        $query->orderByRaw(static::foldedName('name'))->orderBy('id');
    }

    /**
     * The garments the storefront may show: never turned off, and still sold in
     * at least one variant that is on.
     *
     * This is the same rule the home page and the product page both need, so it
     * lives here rather than once per caller. Stock is deliberately not part of
     * it: a garment that sold out is still sold, it has just run out.
     *
     * @param  Builder<Product>  $query
     */
    public function scopeVisible(Builder $query): void
    {
        $query->where('status', '!=', 'inactive')
            ->whereHas('variants', fn (Builder $variants): Builder => $variants->where('is_active', true));
    }

    /**
     * The instant from which a product counts as new: the start of today minus
     * NUEVO_DIAS days. The «Nuevo» badge and the Novedades page both compare
     * against this same boundary, which is what makes the rule a single source.
     */
    public static function newCutoff(): CarbonInterface
    {
        return now()->subDays(static::NUEVO_DIAS)->startOfDay();
    }

    /**
     * Whether the product entered the catalog NUEVO_DIAS days ago or less.
     *
     * A product with no creation date is never new: there is nothing to compare
     * against, and treating it as new would put a product of unknown age on a
     * page that promises recency.
     */
    public function isNew(): bool
    {
        if (! $this->created_at) {
            return false;
        }

        $created = $this->created_at instanceof Carbon
            ? $this->created_at
            : Carbon::parse($this->created_at);

        return $created->greaterThanOrEqualTo(static::newCutoff());
    }

    /**
     * The SQL that reads a text column as its folded, lowercase form.
     *
     * The letters are the ones Spanish product names actually carry, plus the
     * diaeresis. `ñ` is folded onto `n` and not left as a letter of its own, because
     * that is what the collation of the column does and the two have to agree.
     */
    private static function foldedName(string $column): string
    {
        $letters = [
            'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N',
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
        ];

        $folded = $column;

        foreach ($letters as $written => $plain) {
            $folded = "REPLACE({$folded}, '{$written}', '{$plain}')";
        }

        return "LOWER({$folded})";
    }

    /**
     * The images taken in this color.
     *
     * The `order` of an image is the place it was uploaded in, and the id breaks
     * the ties of images that share one, so the same gallery never changes its
     * order between two reads.
     *
     * @return HasMany<ProductImage>
     */
    public function imagesForColor(Color $color): HasMany
    {
        return $this->images()
            ->where('color_id', $color->getKey())
            ->orderBy('order')
            ->orderBy('id');
    }

    /**
     * The image that stands for the product: the main one of its cover color, or
     * the main one of whatever color has images, or nothing at all.
     *
     * The `images` relation is read from memory whenever it is already loaded, so
     * a listing eager-loading it to show the cover of every row does not end up
     * with a query per product. Only the products whose images were never loaded
     * ask the database, and they do it once.
     */
    protected function coverImage(): Attribute
    {
        return Attribute::get(function (): ?ProductImage {
            $images = $this->relationLoaded('images')
                ? $this->images
                : $this->images()->get();

            $primaries = $images->where('is_primary', true);

            if ($this->cover_color_id === null) {
                return $primaries->first();
            }

            return $primaries->firstWhere('color_id', $this->cover_color_id) ?? $primaries->first();
        });
    }

    /**
     * The colors this product is sold in, in the order they first show up.
     *
     * @return Collection<int, Color>
     */
    public function colors(): Collection
    {
        return Color::query()
            ->whereHas('variants', fn ($query) => $query->where('product_id', $this->getKey()))
            ->orderBy('colors.id')
            ->get();
    }
}
