<?php

use App\Actions\ProductDetails\CreateColor;
use App\Actions\ProductDetails\DeleteColor;
use App\Actions\ProductDetails\MoveColor;
use App\Actions\ProductDetails\ToggleColor;
use App\Actions\ProductDetails\UpdateColor;
use App\Exceptions\ColorCodeLockedException;
use App\Exceptions\ColorInUseException;
use App\Exceptions\DuplicateColorCodeException;
use App\Exceptions\DuplicateColorNameException;
use App\Exceptions\InvalidColorHexException;
use App\Models\Category;
use App\Models\Color;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\Size;

test('a color is created with the code derived from its name', function () {
    $color = (new CreateColor)('Azul', '#1A2B3C');

    expect($color->name)->toBe('Azul')
        ->and($color->hex)->toBe('#1A2B3C')
        ->and($color->code)->toBe('AZU')
        ->and($color->order)->toBe(0)
        ->and($color->is_active)->toBeTrue();
});

test('the derived code drops what is not a letter or a digit', function (string $name, string $code) {
    expect((new CreateColor)($name, '#1A2B3C')->code)->toBe($code);
})->with([
    ['Café con leche', 'CAF'],
    ['Azul 2024', 'AZU'],
    ['Gris-medio', 'GRI'],
]);

test('a name with fewer than three characters is padded to the width of a code', function () {
    expect((new CreateColor)('Fe', '#1A2B3C')->code)->toBe('FEX');
});

test('a derived code already taken gets the two leading characters and another one', function () {
    (new CreateColor)('Azul', '#1A2B3C');

    $claro = (new CreateColor)('Azul claro', '#4D5E6F');

    expect($claro->code)->toBe('AZ0');
});

test('the fallback walks the digits before the letters', function () {
    (new CreateColor)('Azul', '#1A2B3C');

    foreach (range(0, 9) as $digit) {
        (new CreateColor)('Azul '.$digit, '#1A2B3C');
    }

    expect((new CreateColor)('Azul?', '#1A2B3C')->code)->toBe('AZA');
});

test('a code given by the admin is used as it comes, normalized', function () {
    expect((new CreateColor)('Verde', '#1A2B3C', ' vrd ')->code)->toBe('VRD');
});

test('a code given by the admin that is already taken is refused', function () {
    (new CreateColor)('Azul', '#1A2B3C');

    expect(fn () => (new CreateColor)('Verde', '#1A2B3C', 'AZU'))
        ->toThrow(DuplicateColorCodeException::class);

    expect(Color::query()->where('name', 'Verde')->exists())->toBeFalse();
});

test('a name the store already has is refused', function () {
    (new CreateColor)('Azul', '#1A2B3C');

    expect(fn () => (new CreateColor)('Azul', '#4D5E6F'))
        ->toThrow(DuplicateColorNameException::class);

    expect(Color::query()->count())->toBe(1);
});

test('a name that repeats one only in case or accent is refused', function (string $name) {
    (new CreateColor)('Café', '#1A2B3C');

    expect(fn () => (new CreateColor)($name, '#4D5E6F'))
        ->toThrow(DuplicateColorNameException::class);
})->with(['cafe', 'CAFÉ', ' café ']);

test('the refusal names the repeated color', function () {
    (new CreateColor)('Azul', '#1A2B3C');

    expect(fn () => (new CreateColor)('azul', '#4D5E6F'))
        ->toThrow(DuplicateColorNameException::class, 'Ya existe un color llamado «azul».');
});

test('a hex is stored with its hash and in upper case', function (string $hex, string $stored) {
    expect((new CreateColor)('Azul', $hex)->hex)->toBe($stored);
})->with([
    ['#1a2b3c', '#1A2B3C'],
    ['#1A2B3C', '#1A2B3C'],
    ['  #1A2B3C  ', '#1A2B3C'],
]);

test('a value that is not a hex is refused', function (string $hex) {
    expect(fn () => (new CreateColor)('Azul', $hex))
        ->toThrow(InvalidColorHexException::class);
})->with(['1A2B3C', '#1A2B3', '#1A2B3CC', '#GGGGGG', 'azul']);

test('the refusal repeats the value that was not a hex', function () {
    expect(fn () => (new CreateColor)('Azul', '1A2B3C'))
        ->toThrow(InvalidColorHexException::class, '«1A2B3C» no es un hexadecimal válido');
});

test('the name and the hex of a color can be edited', function () {
    $color = (new CreateColor)('Azul', '#1A2B3C');

    $edited = (new UpdateColor)($color, 'Azul marino', '#0A1B2C');

    expect($edited->name)->toBe('Azul marino')
        ->and($edited->hex)->toBe('#0A1B2C')
        ->and($edited->code)->toBe('AZU')
        ->and($color->fresh()->code)->toBe('AZU');
});

test('the code of a color can be changed while no variant is sold in it', function () {
    $color = (new CreateColor)('Azul', '#1A2B3C');

    expect((new UpdateColor)($color, 'Azul', '#1A2B3C', 'AZM')->code)->toBe('AZM');
});

test('the code of a color a variant is sold in cannot be changed', function () {
    $product = Product::factory()->create();
    $color = (new CreateColor)('Azul', '#1A2B3C');
    ProductVariant::factory()->for($product)->create([
        'size_id' => sizeOfProduct($product)->id,
        'color_id' => $color->id,
    ]);

    expect(fn () => (new UpdateColor)($color, 'Azul', '#1A2B3C', 'AZM'))
        ->toThrow(ColorCodeLockedException::class);

    expect($color->fresh()->code)->toBe('AZU');
});

test('the refusal explains that the code lives in the sku', function () {
    $product = Product::factory()->create();
    $color = (new CreateColor)('Azul', '#1A2B3C');
    ProductVariant::factory()->for($product)->create([
        'size_id' => sizeOfProduct($product)->id,
        'color_id' => $color->id,
    ]);

    expect(fn () => (new UpdateColor)($color, 'Azul', '#1A2B3C', 'AZM'))
        ->toThrow(ColorCodeLockedException::class, 'SKU de sus variantes');
});

test('editing the hex of a color a variant is sold in is allowed', function () {
    $product = Product::factory()->create();
    $color = (new CreateColor)('Azul', '#1A2B3C');
    ProductVariant::factory()->for($product)->create([
        'size_id' => sizeOfProduct($product)->id,
        'color_id' => $color->id,
    ]);

    expect((new UpdateColor)($color, 'Azul', '#0A1B2C')->hex)->toBe('#0A1B2C');
});

test('a color can be renamed onto a free code change', function () {
    $color = (new CreateColor)('Azul', '#1A2B3C');

    expect((new UpdateColor)($color, 'Azul claro', '#1A2B3C', 'AZC')->code)->toBe('AZC');
});

test('a rename onto a name the store already has is refused', function () {
    (new CreateColor)('Azul', '#1A2B3C');
    $color = (new CreateColor)('Verde', '#4D5E6F', 'VRD');

    expect(fn () => (new UpdateColor)($color, 'azul', '#4D5E6F'))
        ->toThrow(DuplicateColorNameException::class);

    expect($color->fresh()->name)->toBe('Verde');
});

test('a new code already taken by another color is refused', function () {
    (new CreateColor)('Azul', '#1A2B3C');
    $color = (new CreateColor)('Verde', '#4D5E6F', 'VRD');

    expect(fn () => (new UpdateColor)($color, 'Verde', '#4D5E6F', 'AZU'))
        ->toThrow(DuplicateColorCodeException::class);

    expect($color->fresh()->code)->toBe('VRD');
});

test('editing a color without touching its code is not a code change', function () {
    $product = Product::factory()->create();
    $color = (new CreateColor)('Azul', '#1A2B3C');
    ProductVariant::factory()->for($product)->create([
        'size_id' => sizeOfProduct($product)->id,
        'color_id' => $color->id,
    ]);

    expect((new UpdateColor)($color, 'Azul', '#0A1B2C')->hex)->toBe('#0A1B2C');
});

/**
 * The name of a color does not travel into a SKU, so writing it back in another case
 * is an edit and not a refusal: what makes it a refusal is a name the store already
 * has somewhere else.
 */
test('writing a name that only differs in case is accepted and stored as it comes', function (string $name, string $stored) {
    $color = (new CreateColor)('Café', '#1A2B3C');

    expect((new UpdateColor)($color, $name, '#1A2B3C')->name)->toBe($stored);
})->with([
    ['Café', 'Café'],
    ['café', 'café'],
    [' Café ', 'Café'],
]);

test('a color can be turned off and on again with the same action', function () {
    $color = (new CreateColor)('Azul', '#1A2B3C');
    $toggle = new ToggleColor;

    expect($toggle($color)->is_active)->toBeFalse()
        ->and($toggle($color)->is_active)->toBeTrue();
});

test('turning a color off takes it out of the offer and keeps the variants', function () {
    $product = Product::factory()->create();
    $color = (new CreateColor)('Azul', '#1A2B3C');
    ProductVariant::factory()->for($product)->create([
        'size_id' => sizeOfProduct($product)->id,
        'color_id' => $color->id,
    ]);

    (new ToggleColor)($color);

    expect(Color::listedActive()->pluck('name')->all())->toBe([])
        ->and($color->fresh()->variants()->count())->toBe(1);
});

test('a color nothing points at can be deleted', function () {
    $color = (new CreateColor)('Azul', '#1A2B3C');

    (new DeleteColor)($color);

    expect(Color::query()->whereKey($color->id)->exists())->toBeFalse();
});

test('a color a variant is sold in cannot be deleted', function () {
    $product = Product::factory()->create();
    $color = (new CreateColor)('Azul', '#1A2B3C');
    ProductVariant::factory()->for($product)->create([
        'size_id' => sizeOfProduct($product)->id,
        'color_id' => $color->id,
    ]);

    expect(fn () => (new DeleteColor)($color))->toThrow(ColorInUseException::class);

    expect(Color::query()->whereKey($color->id)->exists())->toBeTrue();
});

test('a color a picture is in cannot be deleted', function () {
    $product = Product::factory()->create();
    $color = (new CreateColor)('Azul', '#1A2B3C');
    ProductImage::factory()->for($product)->create(['color_id' => $color->id]);

    expect(fn () => (new DeleteColor)($color))->toThrow(ColorInUseException::class);
});

test('the refusal counts the variants and the pictures that hold the color', function () {
    $product = Product::factory()->create();
    $color = (new CreateColor)('Azul', '#1A2B3C');
    ProductVariant::factory()->for($product)->create([
        'size_id' => sizeOfProduct($product)->id,
        'color_id' => $color->id,
    ]);
    ProductImage::factory()->count(2)->for($product)->create(['color_id' => $color->id]);

    expect(fn () => (new DeleteColor)($color))->toThrow(
        ColorInUseException::class,
        '1 variante la usa y 2 imágenes están en ella'
    );
});

test('a color moves up and down inside the store', function () {
    $azul = (new CreateColor)('Azul', '#1A2B3C');
    $rojo = (new CreateColor)('Rojo', '#4D5E6F');
    $verde = (new CreateColor)('Verde', '#0A1B2C');

    (new MoveColor)($verde, -1);

    expect(Color::listed()->pluck('name')->all())->toBe(['Azul', 'Verde', 'Rojo']);

    (new MoveColor)($azul, 1);

    expect(Color::listed()->pluck('name')->all())->toBe(['Verde', 'Azul', 'Rojo'])
        ->and(Color::listed()->pluck('order')->all())->toBe([0, 1, 2]);
});

test('cannot move the first color up or the last one down', function () {
    $azul = (new CreateColor)('Azul', '#1A2B3C');
    (new CreateColor)('Rojo', '#4D5E6F');

    (new MoveColor)($azul, -1);

    expect(Color::listed()->pluck('name')->all())->toBe(['Azul', 'Rojo']);
});

test('a color is added at the end of the list of the store', function () {
    $azul = (new CreateColor)('Azul', '#1A2B3C');
    (new CreateColor)('Rojo', '#4D5E6F');

    expect((new CreateColor)('Verde', '#0A1B2C')->order)->toBe(2)
        ->and($azul->fresh()->order)->toBe(0);
});

test('the sizes of a category do not interfere with the order of the colors', function () {
    $category = Category::factory()->create();
    Size::seedStandardSizesFor($category);
    (new CreateColor)('Azul', '#1A2B3C');

    expect(Color::listed()->pluck('order')->all())->toBe([0]);
});
