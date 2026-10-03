<?php

use App\Actions\ProductDetails\SyncProductMaterials;
use App\Enums\StoreSection;
use App\Exceptions\InactiveProductMaterialException;
use App\Exceptions\InactiveVariantColorException;
use App\Exceptions\InactiveVariantSizeException;
use App\Exceptions\IncompleteMaterialCompositionException;
use App\Exceptions\InvalidMaterialPercentageException;
use App\Exceptions\RepeatedProductMaterialException;
use App\Exceptions\UnknownColorException;
use App\Exceptions\UnknownMaterialException;
use App\Exceptions\UnknownSizeException;
use App\Models\Category;
use App\Models\Color;
use App\Models\Material;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Size;
use App\Services\ProductDetailsCatalog;

/**
 * A garment of the category, with a variant of the given size and color.
 *
 * It is here because a size only means something inside a category, so a filter test
 * cannot make one up: it has to hang off a garment of the right category for the size to
 * be in use at all.
 */
function garmentWith(Category $category, Size $size, ?Color $color = null, int $stock = 3): Product
{
    $product = Product::factory()->for($category)->create();

    $attributes = ['stock' => $stock];

    if ($color instanceof Color) {
        $attributes['color_id'] = $color->getKey();
    }

    ProductVariant::factory()->for($product)->inSize($size)->create($attributes);

    return $product;
}

/*
|--------------------------------------------------------------------------
| Resolving a name into a row
|--------------------------------------------------------------------------
|
| The names arrive from a keyboard, so the letters are not the same on every row of a
| spreadsheet, and the whole point of the service is that `algodon`, `ALGODÓN` and
| `Algodón ` land on the same material. The three failures are kept apart, because the
| person fixing the file has to do different things about each one.
|
*/

/**
 * The comparison is the one the panel already holds, so a form and a file agree on what
 * two names are.
 */
test('a name is found however it was written', function (string $written, string $stored) {
    $category = numberedCategory('PL', 'Polos');
    $size = Size::factory()->for($category)->create(['name' => $stored]);
    $color = Color::factory()->create(['name' => $stored]);
    $material = Material::factory()->create(['name' => $stored]);
    $catalog = new ProductDetailsCatalog;

    expect($catalog->resolveSize($category, $written)->is($size))->toBeTrue()
        ->and($catalog->resolveColor($written)->is($color))->toBeTrue()
        ->and($catalog->resolveMaterial($written)->is($material))->toBeTrue();
})->with([
    'las mismas letras' => ['ÚNICA', 'ÚNICA'],
    'en minúsculas y con espacios de sobra' => [' unica ', 'ÚNICA'],
    'sin la tilde' => ['Unica', 'ÚNICA'],
    'con la tilde en minúscula' => ['única', 'ÚNICA'],
    'en mayúsculas' => ['ÚNICA', 'ÚNICA'],
    'con tilde al revés' => ['cafe', 'Café'],
    'con tilde y mayúscula' => ['CAFÉ', 'Café'],
    'con un espacio de más en medio' => ['Poliéster  Pettal', 'Poliéster Pettal'],
]);

/**
 * A size only means something inside its category, so a `42` that the trousers carry
 * says nothing about the shirts and is answered as a size the category does not have.
 */
test('a size of another category is a size that does not exist here', function () {
    $polos = numberedCategory('PL', 'Polos');
    $pantalones = numberedCategory('PA', 'Pantalones');

    Size::factory()->for($pantalones)->create(['name' => '42']);

    expect(fn () => (new ProductDetailsCatalog)->resolveSize($polos, '42'))
        ->toThrow(UnknownSizeException::class);
});

test('the refusal for an unknown size names the category and the sizes it does carry', function () {
    $polos = numberedCategory('PL', 'Polos');
    $pantalones = numberedCategory('PA', 'Pantalones');

    Size::factory()->for($pantalones)->create(['name' => '42']);
    Size::factory()->for($polos)->create(['name' => 'M', 'order' => 1]);
    Size::factory()->for($polos)->create(['name' => 'L', 'order' => 2]);
    Size::factory()->for($polos)->inactive()->create(['name' => 'XL', 'order' => 3]);

    expect(fn () => (new ProductDetailsCatalog)->resolveSize($polos, ' 42 '))
        ->toThrow(
            UnknownSizeException::class,
            'La talla «42» no existe en la categoría «Polos». Tallas disponibles: M, L.',
        );
});

test('a category that carries no active size says so instead of listing nothing', function () {
    $polos = numberedCategory('PL', 'Polos');

    Size::factory()->for($polos)->inactive()->create(['name' => 'M']);

    expect(fn () => (new ProductDetailsCatalog)->resolveSize($polos, '42'))
        ->toThrow(
            UnknownSizeException::class,
            'La talla «42» no existe en la categoría «Polos». Tallas disponibles: ninguna.',
        );
});

test('the refusal for an unknown color names the color that came', function () {
    expect(fn () => (new ProductDetailsCatalog)->resolveColor(' mostaza '))
        ->toThrow(UnknownColorException::class, 'El color «mostaza» no existe.');
});

test('the refusal for an unknown material names the material that came', function () {
    expect(fn () => (new ProductDetailsCatalog)->resolveMaterial('  lino  '))
        ->toThrow(UnknownMaterialException::class, 'El material «lino» no existe.');
});

/**
 * A name the catalog does not carry and a name it carries but has turned off are two
 * different problems, so they are two different exceptions with two different messages:
 * the first is a typo or a row the store has to add, the second only needs turning back
 * on.
 */
test('a size that is turned off is not the same thing as one that is not there', function () {
    $category = numberedCategory('PL', 'Polos');

    Size::factory()->for($category)->inactive()->create(['name' => 'M']);

    expect(fn () => (new ProductDetailsCatalog)->resolveSize($category, 'm'))
        ->toThrow(
            InactiveVariantSizeException::class,
            'La talla «M» está desactivada y no admite variantes nuevas.',
        );
});

test('a color that is turned off is not the same thing as one that is not there', function () {
    Color::factory()->inactive()->create(['name' => 'Rojo']);

    expect(fn () => (new ProductDetailsCatalog)->resolveColor('ROJO'))
        ->toThrow(
            InactiveVariantColorException::class,
            'El color «Rojo» está desactivado y no admite variantes nuevas.',
        );
});

test('a material that is turned off is not the same thing as one that is not there', function () {
    Material::factory()->inactive()->create(['name' => 'Lino']);

    expect(fn () => (new ProductDetailsCatalog)->resolveMaterial('lino'))
        ->toThrow(
            InactiveProductMaterialException::class,
            'El material «Lino» está desactivado y no se puede añadir a un producto.',
        );
});

test('the three unknown exceptions are not the three inactive ones', function () {
    $unknown = [UnknownSizeException::class, UnknownColorException::class, UnknownMaterialException::class];
    $inactive = [InactiveVariantSizeException::class, InactiveVariantColorException::class, InactiveProductMaterialException::class];

    expect(array_intersect($unknown, $inactive))->toBe([]);
});

/**
 * Reading a garment that is being composed has to happen before the line is written, so
 * the caller asks for the turned-off row on purpose when that is what it means.
 */
test('a row that is turned off is handed over when the caller asks for it anyway', function () {
    $category = numberedCategory('PL', 'Polos');
    $size = Size::factory()->for($category)->inactive()->create(['name' => 'M']);
    $color = Color::factory()->inactive()->create(['name' => 'Rojo']);
    $material = Material::factory()->inactive()->create(['name' => 'Lino']);
    $catalog = new ProductDetailsCatalog;

    expect($catalog->resolveSize($category, 'M', false)->is($size))->toBeTrue()
        ->and($catalog->resolveColor('Rojo', false)->is($color))->toBeTrue()
        ->and($catalog->resolveMaterial('Lino', false)->is($material))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Reading a composition out of a cell
|--------------------------------------------------------------------------
|
| The text is typed by a person, so it is read the way a person writes it, and it is not
| judged: the percentages come back as the text that was written precisely so that the
| action that writes them is the one that says whether a composition adds up.
|
*/

test('a material with no number is the whole garment', function () {
    $algodon = Material::factory()->create(['name' => 'Algodón']);

    expect((new ProductDetailsCatalog)->resolveComposition('Algodón'))
        ->toBe([['id' => $algodon->id, 'percentage' => '100']]);
});

test('two materials are read with either separator', function (string $text) {
    $algodon = Material::factory()->create(['name' => 'Algodón']);
    $poliester = Material::factory()->create(['name' => 'Poliéster']);

    expect((new ProductDetailsCatalog)->resolveComposition($text))->toBe([
        ['id' => $algodon->id, 'percentage' => '80'],
        ['id' => $poliester->id, 'percentage' => '20'],
    ]);
})->with([
    'barra vertical' => ['Algodón 80 | Poliéster 20'],
    'barra oblicua' => ['Algodón 80 / Poliéster 20'],
    'con porcentajes' => ['Algodón 80% | Poliéster 20%'],
    'con barra oblicua y porcentajes' => ['Algodón 80% / Poliéster 20%'],
]);

test('the percent sign and the spaces are free', function (string $text) {
    $algodon = Material::factory()->create(['name' => 'Algodón']);
    $poliester = Material::factory()->create(['name' => 'Poliéster']);

    expect((new ProductDetailsCatalog)->resolveComposition($text))->toBe([
        ['id' => $algodon->id, 'percentage' => '80'],
        ['id' => $poliester->id, 'percentage' => '20'],
    ]);
})->with([
    'sin espacio en el separador' => ['Algodón 80|Poliéster 20'],
    'con espacios de sobra' => ['  Algodón   80  |   Poliéster  20  '],
    'con el signo suelto' => ['Algodón 80 % | Poliéster 20 %'],
    'con el signo pegado' => ['Algodón 80%|Poliéster 20%'],
    'con saltos de linea' => ["Algodón 80\n| Poliéster 20"],
]);

/**
 * A material whose own name has a space in it is not cut in half: everything before the
 * last word is the name and the last word is the share.
 */
test('a material whose name has a space in it is not cut', function () {
    $peinado = Material::factory()->create(['name' => 'Poliéster Peinado']);

    expect((new ProductDetailsCatalog)->resolveComposition('Poliéster Peinado 100'))
        ->toBe([['id' => $peinado->id, 'percentage' => '100']]);
});

test('text of nothing is a garment with no materials', function (string $text) {
    expect((new ProductDetailsCatalog)->resolveComposition($text))->toBe([]);
})->with(['', '   ', "\n", ' | ', '//']);

test('separators left hanging at either end are ignored', function (string $text) {
    $algodon = Material::factory()->create(['name' => 'Algodón']);

    expect((new ProductDetailsCatalog)->resolveComposition($text))
        ->toBe([['id' => $algodon->id, 'percentage' => '100']]);
})->with([
    'al final' => ['Algodón 100 |'],
    'al principio' => ['| Algodón 100'],
    'en los dos' => ['| Algodón 100 |'],
    'repetidos' => ['Algodón 100 || |'],
]);

/**
 * How a cell was typed is not the question: `polièster` carries a grave accent, which is not
 * even a Spanish one, and it still has to land on the `Poliéster` the store already has.
 */
test('the materials of a cell are read however they were written', function () {
    $algodon = Material::factory()->create(['name' => 'Algodón']);
    $poliester = Material::factory()->create(['name' => 'Poliéster']);

    expect((new ProductDetailsCatalog)->resolveComposition('ALGODÓN 80 | polièster 20'))
        ->toBe([
            ['id' => $algodon->id, 'percentage' => '80'],
            ['id' => $poliester->id, 'percentage' => '20'],
        ]);
});

test('a material the store does not have stops the cell and says which', function () {
    Material::factory()->create(['name' => 'Algodón']);

    expect(fn () => (new ProductDetailsCatalog)->resolveComposition('Algodón 80 | Lino 20'))
        ->toThrow(UnknownMaterialException::class, 'El material «Lino» no existe.');
});

/**
 * A composition is being written now, so a material the store has turned off cannot go
 * into a new one, and the message names the one to turn back on.
 */
test('a material the store has turned off stops the cell and says which', function () {
    Material::factory()->create(['name' => 'Algodón']);
    Material::factory()->inactive()->create(['name' => 'Lino']);

    expect(fn () => (new ProductDetailsCatalog)->resolveComposition('algodón 80 | LINO 20'))
        ->toThrow(
            InactiveProductMaterialException::class,
            'El material «Lino» está desactivado y no se puede añadir a un producto.',
        );
});

/**
 * A share that is not a whole number is not judged here, only carried: rounding it would
 * save a composition nobody asked for, and the action that writes it is where the message
 * that names the value belongs.
 */
test('a share that is not a whole number arrives exactly as it was written', function (string $written) {
    $algodon = Material::factory()->create(['name' => 'Algodón']);

    expect((new ProductDetailsCatalog)->resolveComposition('Algodón '.$written))
        ->toBe([['id' => $algodon->id, 'percentage' => $written]]);
})->with(['50,5', '50.5', 'abc', '-10', '101']);

test('the same material written twice comes back as two lines', function () {
    $algodon = Material::factory()->create(['name' => 'Algodón']);

    expect((new ProductDetailsCatalog)->resolveComposition('Algodón 50 | ALGODÓN 50'))
        ->toBe([
            ['id' => $algodon->id, 'percentage' => '50'],
            ['id' => $algodon->id, 'percentage' => '50'],
        ]);
});

/*
|--------------------------------------------------------------------------
| The reader next to the action that writes
|--------------------------------------------------------------------------
|
| The split of the work only means something if the two halves meet: a cell that reads
| fine is refused by the action for the reason it deserves, and a cell that adds up goes
| in.
|
*/

test('a composition that does not add up is refused by the action and not by the reader', function () {
    $algodon = Material::factory()->create(['name' => 'Algodón']);
    $poliester = Material::factory()->create(['name' => 'Poliéster']);
    $product = Product::factory()->create();
    $catalog = new ProductDetailsCatalog;

    expect(fn () => (new SyncProductMaterials)($product, $catalog->resolveComposition('Algodón 60 | Poliéster 30')))
        ->toThrow(
            IncompleteMaterialCompositionException::class,
            'Los porcentajes de los materiales suman 90 y deben sumar exactamente 100.',
        );
});

test('a cell that adds up goes in as it was typed', function () {
    $algodon = Material::factory()->create(['name' => 'Algodón']);
    $poliester = Material::factory()->create(['name' => 'Poliéster']);
    $product = Product::factory()->create();

    (new SyncProductMaterials)($product, (new ProductDetailsCatalog)->resolveComposition('algodón 60 | POLIÉSTER 40'));

    expect($product->materials()->orderBy('materials.id')->pluck('materials.name')->all())
        ->toBe(['Algodón', 'Poliéster'])
        ->and($product->materials()->orderBy('materials.id')->pluck('percentage')->all())
        ->toBe([60, 40]);
});

test('a share that is not a whole number is refused when the composition is written', function () {
    $algodon = Material::factory()->create(['name' => 'Algodón']);
    $product = Product::factory()->create();

    expect(fn () => (new SyncProductMaterials)($product, (new ProductDetailsCatalog)->resolveComposition('Algodón 50,5')))
        ->toThrow(
            InvalidMaterialPercentageException::class,
            'El porcentaje del material «Algodón» debe ser un número entero entre 1 y 100; se ha recibido 50,5.',
        );
});

test('a material written twice is refused when the composition is written', function () {
    $algodon = Material::factory()->create(['name' => 'Algodón']);
    $product = Product::factory()->create();

    expect(fn () => (new SyncProductMaterials)($product, (new ProductDetailsCatalog)->resolveComposition('Algodón 50 | Algodón 50')))
        ->toThrow(RepeatedProductMaterialException::class, 'El material «Algodón» está repetido');
});

/*
|--------------------------------------------------------------------------
| The options a filter may offer
|--------------------------------------------------------------------------
|
| A filter that offers something nobody can be shown is worse than a filter that offers
| nothing, so all four lists are held back by one definition of a product in the
| catalog. Stock is not part of it: a size that ran out is still a size the store sells.
|
*/

test('a filter offers only what the store still has turned on', function () {
    $category = numberedCategory('PL', 'Polos');
    $size = Size::factory()->for($category)->create(['name' => 'M']);
    $color = Color::factory()->create(['name' => 'Rojo']);
    $material = Material::factory()->create(['name' => 'Algodón']);

    $apagada = Size::factory()->for($category)->inactive()->create(['name' => 'L']);
    $colorApagado = Color::factory()->inactive()->create(['name' => 'Azul']);
    $materialApagado = Material::factory()->inactive()->create(['name' => 'Lino']);

    $producto = garmentWith($category, $size, $color);
    $producto->materials()->attach($material, ['percentage' => 100]);

    $apagado = garmentWith($category, $apagada, $colorApagado);
    $apagado->materials()->attach($materialApagado, ['percentage' => 100]);

    $catalog = new ProductDetailsCatalog;

    expect($catalog->sizesForFilter($category)->pluck('name')->all())->toBe(['M'])
        ->and($catalog->sizeNamesForFilter()->pluck('name')->all())->toBe(['M'])
        ->and($catalog->colorsForFilter()->pluck('name')->all())->toBe(['Rojo'])
        ->and($catalog->materialsForFilter()->pluck('name')->all())->toBe(['Algodón']);
});

test('a filter leaves out what nothing uses', function () {
    $category = numberedCategory('PL', 'Polos');
    $otro = numberedCategory('PA', 'Pantalones');

    Size::factory()->for($category)->create(['name' => 'M']);
    Size::factory()->for($otro)->create(['name' => '42']);
    Color::factory()->create(['name' => 'Rojo']);
    Material::factory()->create(['name' => 'Algodón']);

    $catalog = new ProductDetailsCatalog;

    expect($catalog->sizesForFilter($category))->toHaveCount(0)
        ->and($catalog->sizeNamesForFilter())->toHaveCount(0)
        ->and($catalog->colorsForFilter())->toHaveCount(0)
        ->and($catalog->materialsForFilter())->toHaveCount(0);
});

test('a filter leaves out a size and a color that only an inactive variant carries', function () {
    $category = numberedCategory('PL', 'Polos');
    $usada = Size::factory()->for($category)->create(['name' => 'M']);
    $apagada = Size::factory()->for($category)->create(['name' => 'L']);
    $color = Color::factory()->create(['name' => 'Rojo']);
    $colorApagado = Color::factory()->create(['name' => 'Azul']);

    $producto = garmentWith($category, $usada, $color);
    ProductVariant::factory()->for($producto)->inSize($apagada)->inactive()->create(['color_id' => $colorApagado->id]);

    $catalog = new ProductDetailsCatalog;

    expect($catalog->sizesForFilter($category)->pluck('name')->all())->toBe(['M'])
        ->and($catalog->colorsForFilter()->pluck('name')->all())->toBe(['Rojo']);
});

test('a filter leaves out what only a garment that is turned off carries', function () {
    $category = numberedCategory('PL', 'Polos');
    $size = Size::factory()->for($category)->create(['name' => 'M']);
    $color = Color::factory()->create(['name' => 'Rojo']);
    $material = Material::factory()->create(['name' => 'Algodón']);

    $apagado = Product::factory()->for($category)->inactive()->create();
    ProductVariant::factory()->for($apagado)->inSize($size)->create(['color_id' => $color->id]);
    $apagado->materials()->attach($material, ['percentage' => 100]);

    $catalog = new ProductDetailsCatalog;

    expect($catalog->sizesForFilter($category))->toHaveCount(0)
        ->and($catalog->sizeNamesForFilter())->toHaveCount(0)
        ->and($catalog->colorsForFilter())->toHaveCount(0)
        ->and($catalog->materialsForFilter())->toHaveCount(0);
});

/**
 * A garment that has run out is still in the catalog: hiding its sizes because there are
 * no units left would tell the shopper the store has stopped selling them.
 */
test('a garment that ran out of stock is still a garment of the catalog', function () {
    $category = numberedCategory('PL', 'Polos');
    $size = Size::factory()->for($category)->create(['name' => 'M']);
    $color = Color::factory()->create(['name' => 'Rojo']);
    $material = Material::factory()->create(['name' => 'Algodón']);

    $agotado = garmentWith($category, $size, $color, stock: 0);
    $agotado->materials()->attach($material, ['percentage' => 100]);

    expect($agotado->display_status)->toBe('out_of_stock');

    $catalog = new ProductDetailsCatalog;

    expect($catalog->sizesForFilter($category)->pluck('name')->all())->toBe(['M'])
        ->and($catalog->colorsForFilter()->pluck('name')->all())->toBe(['Rojo'])
        ->and($catalog->materialsForFilter()->pluck('name')->all())->toBe(['Algodón']);
});

/**
 * The order the catalog reads is `order` and then id, and it is the one order every list
 * uses, so a filter never shows the same set in two different orders.
 */
test('the sizes come back in the order of the catalog', function () {
    $category = numberedCategory('PL', 'Polos');
    $l = Size::factory()->for($category)->create(['name' => 'L', 'order' => 2]);
    $m = Size::factory()->for($category)->create(['name' => 'M', 'order' => 1]);
    $empate = Size::factory()->for($category)->create(['name' => 'S', 'order' => 1]);

    garmentWith($category, $l);
    garmentWith($category, $m);
    garmentWith($category, $empate);

    expect((new ProductDetailsCatalog)->sizesForFilter($category)->pluck('name')->all())
        ->toBe(['M', 'S', 'L']);
});

test('the colors and the materials come back in the order of the catalog', function () {
    $category = numberedCategory('PL', 'Polos');
    $rojo = Color::factory()->create(['name' => 'Rojo', 'order' => 2]);
    $azul = Color::factory()->create(['name' => 'Azul', 'order' => 1]);
    $algodon = Material::factory()->create(['name' => 'Algodón', 'order' => 2]);
    $lino = Material::factory()->create(['name' => 'Lino', 'order' => 1]);
    $size = Size::factory()->for($category)->create(['name' => 'M']);

    $productoAzul = garmentWith($category, $size, $azul);
    $productoAzul->materials()->attach($lino, ['percentage' => 100]);

    $productoRojo = garmentWith($category, $size, $rojo);
    $productoRojo->materials()->attach($algodon, ['percentage' => 100]);

    $catalog = new ProductDetailsCatalog;

    expect($catalog->colorsForFilter()->pluck('name')->all())->toBe(['Azul', 'Rojo'])
        ->and($catalog->materialsForFilter()->pluck('name')->all())->toBe(['Lino', 'Algodón']);
});

/**
 * Every category has its own rows, so the store has three `M` that mean the same thing
 * to whoever is shopping, and a global filter on `M` has to reach all of them.
 */
/**
 * The same name is one group wherever it is carried, and the groups come back in catalog
 * order: `S` sits ahead of `XL` because it was numbered first, not because S comes first in
 * the alphabet.
 */
test('the sizes of the same name are gathered across categories', function () {
    $polos = numberedCategory('PL', 'Polos');
    $pantalones = numberedCategory('PA', 'Pantalones');

    $m = Size::factory()->for($pantalones)->create(['name' => 'M', 'order' => 1]);
    $xl = Size::factory()->for($polos)->create(['name' => 'XL', 'order' => 2]);
    $s = Size::factory()->for($polos)->create(['name' => 'S', 'order' => 3]);

    garmentWith($pantalones, $m);
    garmentWith($polos, $xl);
    garmentWith($polos, $s);

    expect((new ProductDetailsCatalog)->sizeNamesForFilter()->all())->toBe([
        ['name' => 'M', 'ids' => [$m->id]],
        ['name' => 'XL', 'ids' => [$xl->id]],
        ['name' => 'S', 'ids' => [$s->id]],
    ]);
});

/**
 * Two spellings of the same size are one group, and the group is labelled with whichever
 * came first in catalog order.
 */
test('two spellings of the same size are one group', function () {
    $polos = numberedCategory('PL', 'Polos');
    $pantalones = numberedCategory('PA', 'Pantalones');

    $conTilde = Size::factory()->for($polos)->create(['name' => 'ÚNICA', 'order' => 1]);
    $sinTilde = Size::factory()->for($pantalones)->create(['name' => 'Unica', 'order' => 1]);

    garmentWith($polos, $conTilde);
    garmentWith($pantalones, $sinTilde);

    expect((new ProductDetailsCatalog)->sizeNamesForFilter()->all())->toBe([
        ['name' => 'ÚNICA', 'ids' => [$conTilde->id, $sinTilde->id]],
    ]);
});

test('the section narrows the global list of sizes', function () {
    $hombre = numberedCategory('PL', 'Polos', StoreSection::Hombre);
    $mujer = numberedCategory('PJ', 'Polos de mujer', StoreSection::Mujer);

    $mHombre = Size::factory()->for($hombre)->create(['name' => 'M', 'order' => 1]);
    $mMujer = Size::factory()->for($mujer)->create(['name' => 'M', 'order' => 1]);
    $sMujer = Size::factory()->for($mujer)->create(['name' => 'S', 'order' => 2]);

    garmentWith($hombre, $mHombre);
    garmentWith($mujer, $mMujer);
    garmentWith($mujer, $sMujer);

    $catalog = new ProductDetailsCatalog;

    expect($catalog->sizeNamesForFilter())->toHaveCount(2)
        ->and($catalog->sizeNamesForFilter(StoreSection::Hombre)->all())->toBe([
            ['name' => 'M', 'ids' => [$mHombre->id]],
        ])
        ->and($catalog->sizeNamesForFilter(StoreSection::Mujer)->all())->toBe([
            ['name' => 'M', 'ids' => [$mMujer->id]],
            ['name' => 'S', 'ids' => [$sMujer->id]],
        ]);
});

/**
 * A filter is asked for once per page, so one query per list is the budget: a filter
 * that asked again for each row would be one query per size of the store.
 */
test('each list is a single query', function () {
    $category = numberedCategory('PL', 'Polos');
    $size = Size::factory()->for($category)->create(['name' => 'M']);
    $color = Color::factory()->create(['name' => 'Rojo']);
    $material = Material::factory()->create(['name' => 'Algodón']);

    $producto = garmentWith($category, $size, $color);
    $producto->materials()->attach($material, ['percentage' => 100]);

    $catalog = new ProductDetailsCatalog;
    $queries = [];

    foreach ([
        'sizesForFilter' => fn (): int => $catalog->sizesForFilter($category)->count(),
        'sizeNamesForFilter' => fn (): int => $catalog->sizeNamesForFilter(StoreSection::Hombre)->count(),
        'sizeNamesForFilterSinSeccion' => fn (): int => $catalog->sizeNamesForFilter()->count(),
        'colorsForFilter' => fn (): int => $catalog->colorsForFilter()->count(),
        'materialsForFilter' => fn (): int => $catalog->materialsForFilter()->count(),
    ] as $method => $call) {
        DB::connection()->flushQueryLog();
        DB::connection()->enableQueryLog();

        $call();

        $queries[$method] = count(DB::connection()->getQueryLog());

        DB::connection()->disableQueryLog();
    }

    expect($queries)->toBe([
        'sizesForFilter' => 1,
        'sizeNamesForFilter' => 1,
        'sizeNamesForFilterSinSeccion' => 1,
        'colorsForFilter' => 1,
        'materialsForFilter' => 1,
    ]);
});
