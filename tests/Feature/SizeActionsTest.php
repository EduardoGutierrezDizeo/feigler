<?php

use App\Actions\ProductDetails\CreateSize;
use App\Actions\ProductDetails\DeleteSize;
use App\Actions\ProductDetails\MoveSize;
use App\Actions\ProductDetails\ToggleSize;
use App\Actions\ProductDetails\UpdateSize;
use App\Exceptions\DuplicateSizeNameException;
use App\Exceptions\SizeInUseException;
use App\Exceptions\SizeNameLockedException;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Size;

test('a size is created at the end of the list of its category and active', function () {
    $category = Category::factory()->create();
    Size::seedStandardSizesFor($category);

    $size = (new CreateSize)($category, '42');

    expect($size->category_id)->toBe($category->id)
        ->and($size->name)->toBe('42')
        ->and($size->order)->toBe(9)
        ->and($size->is_active)->toBeTrue()
        ->and(Size::listedForCategory($category->id)->pluck('name')->all())
        ->toBe([...Size::STANDARD_NAMES, '42']);
});

test('the first size of a category that has none starts the list at one', function () {
    $category = Category::factory()->create();

    $size = (new CreateSize)($category, '42');

    expect($size->order)->toBe(1);
});

test('a size name is stored without the spaces around it', function () {
    $category = Category::factory()->create();

    expect((new CreateSize)($category, '  42  ')->name)->toBe('42');
});

test('a name already used in the same category is refused', function () {
    $category = Category::factory()->create();
    (new CreateSize)($category, 'M');

    expect(fn () => (new CreateSize)($category, 'M'))
        ->toThrow(DuplicateSizeNameException::class);

    expect($category->sizes()->count())->toBe(1);
});

test('a name already used in another category is not a duplicate', function () {
    $category = Category::factory()->create();
    $other = Category::factory()->create();
    (new CreateSize)($category, 'M');

    expect((new CreateSize)($other, 'M')->name)->toBe('M');
});

test('a name that repeats one only in case or accent is refused', function (string $name) {
    $category = Category::factory()->create();
    (new CreateSize)($category, 'ÚNICA');

    expect(fn () => (new CreateSize)($category, $name))
        ->toThrow(DuplicateSizeNameException::class);
})->with(['única', 'ÚNICA ', 'única']);

test('the refusal names the category and the repeated size', function () {
    $category = Category::factory()->create(['name' => 'Pantalones']);
    (new CreateSize)($category, 'M');

    expect(fn () => (new CreateSize)($category, 'm'))
        ->toThrow(
            DuplicateSizeNameException::class,
            'La categoría «Pantalones» ya tiene una talla llamada «m».'
        );
});

test('a size nobody is sold in can be renamed', function () {
    $category = Category::factory()->create();
    $size = (new CreateSize)($category, '42');

    $renamed = (new UpdateSize)($size, '44');

    expect($renamed->name)->toBe('44')
        ->and($size->fresh()->name)->toBe('44');
});

test('a rename does not move the size or turn it off', function () {
    $category = Category::factory()->create();
    $size = (new CreateSize)($category, '42');
    (new ToggleSize)($size);
    $order = $size->order;

    (new UpdateSize)($size, '44');

    expect($size->fresh()->order)->toBe($order)
        ->and($size->fresh()->is_active)->toBeFalse();
});

test('a size a variant is sold in cannot be renamed', function () {
    $product = Product::factory()->create();
    $size = sizeOfProduct($product, 'M');
    ProductVariant::factory()->for($product)->create(['size_id' => $size->id]);

    expect(fn () => (new UpdateSize)($size, 'G'))
        ->toThrow(SizeNameLockedException::class);

    expect($size->fresh()->name)->toBe('M');
});

test('the refusal explains that the name lives in the sku', function () {
    $product = Product::factory()->create();
    $size = sizeOfProduct($product, 'M');
    ProductVariant::factory()->for($product)->create(['size_id' => $size->id]);

    expect(fn () => (new UpdateSize)($size, 'G'))
        ->toThrow(
            SizeNameLockedException::class,
            'El nombre de la talla «M» no se puede cambiar porque está en uso: forma parte del SKU de sus variantes. Desactívala y crea otra.'
        );
});

test('a size sold in only by an inactive variant still cannot be renamed', function () {
    $product = Product::factory()->create();
    $size = sizeOfProduct($product, 'M');
    ProductVariant::factory()->for($product)->create(['size_id' => $size->id, 'is_active' => false]);

    expect(fn () => (new UpdateSize)($size, 'G'))
        ->toThrow(SizeNameLockedException::class);
});

test('a rename onto a name taken in the same category is refused', function () {
    $category = Category::factory()->create();
    (new CreateSize)($category, 'M');
    $l = (new CreateSize)($category, 'L');

    expect(fn () => (new UpdateSize)($l, 'M'))
        ->toThrow(DuplicateSizeNameException::class);

    expect($l->fresh()->name)->toBe('L');
});

test('a rename onto a name taken in another category is allowed', function () {
    $category = Category::factory()->create();
    $other = Category::factory()->create();
    (new CreateSize)($other, '42');
    $size = (new CreateSize)($category, '44');

    expect((new UpdateSize)($size, '42')->name)->toBe('42');
});

/**
 * Writing a name that is the same one in another case is not a rename: the size keeps
 * the spelling it has, which is the one the SKUs of its variants were built from, and
 * the lock on its name is not tripped by a save that changes nothing.
 */
test('writing the same name back keeps the stored spelling and is accepted', function (string $name) {
    $product = Product::factory()->create();
    $size = sizeOfProduct($product, 'ÚNICA');
    ProductVariant::factory()->for($product)->create(['size_id' => $size->id]);

    expect((new UpdateSize)($size, $name)->name)->toBe('ÚNICA')
        ->and($size->fresh()->name)->toBe('ÚNICA');
})->with(['ÚNICA', 'única', ' ÚNICA ']);

test('a size can be turned off and on again with the same action', function () {
    $category = Category::factory()->create();
    $size = (new CreateSize)($category, '42');
    $toggle = new ToggleSize;

    expect($toggle($size)->is_active)->toBeFalse()
        ->and($toggle($size)->is_active)->toBeTrue()
        ->and($size->fresh()->is_active)->toBeTrue();
});

test('turning a size off keeps the variants sold in it', function () {
    $product = Product::factory()->create();
    $size = sizeOfProduct($product, 'M');
    ProductVariant::factory()->for($product)->create(['size_id' => $size->id]);

    (new ToggleSize)($size);

    expect($size->fresh()->variants()->count())->toBe(1)
        ->and(Size::listedActiveForCategory($size->category_id)->pluck('name')->all())->toBe([]);
});

test('a size nobody is sold in can be deleted', function () {
    $category = Category::factory()->create();
    $size = (new CreateSize)($category, '42');

    (new DeleteSize)($size);

    expect(Size::query()->whereKey($size->id)->exists())->toBeFalse();
});

test('a size a variant is sold in cannot be deleted', function () {
    $product = Product::factory()->create();
    $size = sizeOfProduct($product, 'M');
    ProductVariant::factory()->for($product)->create(['size_id' => $size->id]);

    expect(fn () => (new DeleteSize)($size))->toThrow(SizeInUseException::class);

    expect(Size::query()->whereKey($size->id)->exists())->toBeTrue();
});

test('the refusal counts the variants and names the way out', function () {
    $product = Product::factory()->create();
    $size = sizeOfProduct($product, 'M');
    ProductVariant::factory()->for($product)->create(['size_id' => $size->id]);

    expect(fn () => (new DeleteSize)($size))->toThrow(
        SizeInUseException::class,
        'No se puede eliminar la talla «M» porque 1 variante la usa'
    );
});

test('deleting a size in use leaves the variants alone', function () {
    $product = Product::factory()->create();
    $size = sizeOfProduct($product, 'M');
    ProductVariant::factory()->for($product)->create(['size_id' => $size->id]);

    expect(fn () => (new DeleteSize)($size))->toThrow(SizeInUseException::class);

    expect($product->variants()->count())->toBe(1)
        ->and($product->variants()->sole()->size_id)->toBe($size->id);
});

test('a size moves up one slot inside its category', function () {
    $category = Category::factory()->create();
    $sizes = collect(['XS', 'S', 'M'])->map(fn (string $name): Size => (new CreateSize)($category, $name));

    (new MoveSize)($sizes[2], -1);

    expect(Size::listedForCategory($category->id)->pluck('name')->all())->toBe(['XS', 'M', 'S']);
});

test('a size moves down one slot inside its category', function () {
    $category = Category::factory()->create();
    $sizes = collect(['XS', 'S', 'M'])->map(fn (string $name): Size => (new CreateSize)($category, $name));

    (new MoveSize)($sizes[0], 1);

    expect(Size::listedForCategory($category->id)->pluck('name')->all())->toBe(['S', 'XS', 'M']);
});

test('moving a size reindexes the whole list of the category', function () {
    $category = Category::factory()->create();
    $sizes = collect(['XS', 'S', 'M'])->map(fn (string $name): Size => (new CreateSize)($category, $name));

    (new MoveSize)($sizes[2], -1);

    expect(Size::listedForCategory($category->id)->pluck('order')->all())->toBe([0, 1, 2]);
});

test('cannot move the first size up or the last one down', function () {
    $category = Category::factory()->create();
    $sizes = collect(['XS', 'S', 'M'])->map(fn (string $name): Size => (new CreateSize)($category, $name));

    (new MoveSize)($sizes[0], -1);
    (new MoveSize)($sizes[2], 1);

    expect(Size::listedForCategory($category->id)->pluck('name')->all())->toBe(['XS', 'S', 'M']);
});

test('reordering a category leaves the sizes of the other one alone', function () {
    $category = Category::factory()->create();
    $other = Category::factory()->create();
    $sizes = collect(['XS', 'S', 'M'])->map(fn (string $name): Size => (new CreateSize)($category, $name));
    $others = collect(['40', '42'])->map(fn (string $name): Size => (new CreateSize)($other, $name));

    (new MoveSize)($sizes[0], 1);

    expect(Size::listedForCategory($other->id)->pluck('name')->all())->toBe(['40', '42'])
        ->and($others[0]->fresh()->order)->toBe(1);
});

test('moving a size heals a list where two sizes share a position', function () {
    $category = Category::factory()->create();
    $first = (new CreateSize)($category, 'XS');
    $second = (new CreateSize)($category, 'S');
    $first->update(['order' => 0]);
    $second->update(['order' => 0]);

    (new MoveSize)($second, -1);

    expect(Size::listedForCategory($category->id)->pluck('name')->all())->toBe(['S', 'XS'])
        ->and($second->fresh()->order)->toBe(0)
        ->and($first->fresh()->order)->toBe(1);
});
