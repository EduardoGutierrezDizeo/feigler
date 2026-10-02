<?php

namespace App\Models;

use Database\Factories\ProductImageFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class ProductImage extends Model
{
    /** @use HasFactory<ProductImageFactory> */
    use HasFactory;

    /**
     * The disk every product image is written to and served from.
     */
    public const DISK = 'public';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'product_id',
        'color_id',
        'path',
        'thumbnail_path',
        'order',
        'is_primary',
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
            'is_primary' => 'boolean',
        ];
    }

    /**
     * The product this image belongs to.
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * The color this image shows.
     *
     * The image is attached to a color instead of to a size because a photo in,
     * say, blue tells the same story for every blue size of the product. It is
     * also what makes the galleries independent from one another: an image can
     * only be the main one of its own color.
     */
    public function color(): BelongsTo
    {
        return $this->belongsTo(Color::class);
    }

    /**
     * The address the file is served from.
     *
     * It is built from the disk instead of being stored, so a change of disk or
     * of domain does not leave a column full of stale links behind.
     */
    protected function url(): Attribute
    {
        return Attribute::get(fn (): string => Storage::disk(self::DISK)->url($this->path));
    }

    /**
     * The address the small copy of the picture is served from, which is what
     * the listing and the gallery show.
     *
     * It falls back to the original when there is no thumbnail, and it is asked
     * of the column instead of of the disk on purpose: a listing of fifteen
     * products would otherwise send a request to the storage for every picture
     * it paints, and an image whose thumbnail is missing on disk is a broken
     * picture whether the address is asked about it or not. `products:generate-thumbnails`
     * is what fills the column in.
     *
     * @return string The address of the thumbnail, or that of the original when there is none.
     */
    public function thumbnailUrl(): string
    {
        return Storage::disk(self::DISK)->url($this->thumbnail_path ?? $this->path);
    }
}
