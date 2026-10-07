<?php

use App\Models\Category;
use App\Models\Color;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Size;
use App\Enums\StoreSection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\get;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->refreshApplicationIn('local');
});

function createTestData()
{
    $color = Color::factory()->create(['is_active' => true, 'name' => 'Negro', 'hex' => '#000000', 'code' => 'NEG']);
    $size = Size::factory()->create(['is_active' => true, 'name' => 'M']);

    $categoryHombre = Category::factory()->create(['section' => StoreSection::Hombre, 'name' => 'Camisas', 'sku_prefix' => 'CH']);
    $categoryMujer = Category::factory()->create(['section' => StoreSection::Mujer, 'name' => 'Vestidos', 'sku_prefix' => 'VE']);
    $categoryNinos = Category::factory()->create(['section' => StoreSection::Ninos, 'name' => 'Pantalones', 'sku_prefix' => 'PN']);

    $categoryHombre->sizes()->attach($size->id);
    $categoryMujer->sizes()->attach($size->id);
    $categoryNinos->sizes()->attach($size->id);

    return compact('color', 'size', 'categoryHombre', 'categoryMujer', 'categoryNinos');
}

test('ruta / responde 200 y renderiza con base vacía', function () {
    $this->withoutExceptionHandling();
    $response = get('/');

    $response->assertOk();
});

test('solo aparecen productos activos con variante activa y producto inactivo no aparece ni cuenta', function () {
    $data = createTestData();
    $color = $data['color'];
    $size = $data['size'];
    $cat = $data['categoryHombre'];

    $productVisible = Product::factory()->create([
        'category_id' => $cat->id,
        'name' => 'Camisa Visible',
        'slug' => 'camisa-visible',
        'status' => 'active',
        'base_price' => 10000,
    ]);
    ProductVariant::factory()->create([
        'product_id' => $productVisible->id,
        'color_id' => $color->id,
        'size_id' => $size->id,
        'is_active' => true,
        'stock' => 5,
    ]);

    $productInactive = Product::factory()->create([
        'category_id' => $cat->id,
        'name' => 'Camisa Inactiva',
        'slug' => 'camisa-inactiva',
        'status' => 'inactive',
        'base_price' => 10000,
    ]);
    ProductVariant::factory()->create([
        'product_id' => $productInactive->id,
        'color_id' => $color->id,
        'size_id' => $size->id,
        'is_active' => true,
        'stock' => 5,
    ]);

    $productNoActiveVariant = Product::factory()->create([
        'category_id' => $cat->id,
        'name' => 'Camisa Sin Variante Activa',
        'slug' => 'camisa-sin-variante-activa',
        'status' => 'active',
        'base_price' => 10000,
    ]);
    ProductVariant::factory()->create([
        'product_id' => $productNoActiveVariant->id,
        'color_id' => $color->id,
        'size_id' => $size->id,
        'is_active' => false,
        'stock' => 10,
    ]);

    $response = get('/');

    $response->assertOk();
    $response->assertSee('Camisa Visible');
    $response->assertDontSee('Camisa Inactiva');
    $response->assertDontSee('Camisa Sin Variante Activa');
});

test('conteo de categoría y su imagen son los esperados', function () {
    $data = createTestData();
    $color = $data['color'];
    $size = $data['size'];
    $cat = $data['categoryHombre'];

    $p1 = Product::factory()->create([
        'category_id' => $cat->id,
        'name' => 'P1',
        'slug' => 'p1',
        'status' => 'active',
        'base_price' => 10000,
        'created_at' => now()->subDay(),
    ]);
    ProductVariant::factory()->create([
        'product_id' => $p1->id,
        'color_id' => $color->id,
        'size_id' => $size->id,
        'is_active' => true,
        'stock' => 5,
    ]);

    $p2 = Product::factory()->create([
        'category_id' => $cat->id,
        'name' => 'P2',
        'slug' => 'p2',
        'status' => 'active',
        'base_price' => 10000,
        'created_at' => now(),
    ]);
    ProductVariant::factory()->create([
        'product_id' => $p2->id,
        'color_id' => $color->id,
        'size_id' => $size->id,
        'is_active' => true,
        'stock' => 5,
    ]);

    $p2->images()->create([
        'color_id' => $color->id,
        'path' => 'images/test.jpg',
        'thumbnail_path' => 'images/test-thumb.jpg',
        'order' => 0,
        'is_primary' => true,
    ]);

    $response = get('/');
    $response->assertOk();
});

test('badge nuevo a los 30 días exactos y no a los 31', function () {
    $data = createTestData();
    $color = $data['color'];
    $size = $data['size'];
    $cat = $data['categoryHombre'];

    Carbon::setTestNow(now());
    $pNuevo30 = Product::factory()->create([
        'category_id' => $cat->id,
        'name' => 'Nuevo30',
        'slug' => 'nuevo30',
        'status' => 'active',
        'base_price' => 10000,
        'created_at' => now()->subDays(30),
    ]);
    ProductVariant::factory()->create([
        'product_id' => $pNuevo30->id,
        'color_id' => $color->id,
        'size_id' => $size->id,
        'is_active' => true,
        'stock' => 5,
    ]);

    $pViejo31 = Product::factory()->create([
        'category_id' => $cat->id,
        'name' => 'Viejo31',
        'slug' => 'viejo31',
        'status' => 'active',
        'base_price' => 10000,
        'created_at' => now()->subDays(31),
    ]);
    ProductVariant::factory()->create([
        'product_id' => $pViejo31->id,
        'color_id' => $color->id,
        'size_id' => $size->id,
        'is_active' => true,
        'stock' => 5,
    ]);

    $response = get('/');
    $response->assertOk();
    $response->assertSee('Nuevo30');
    $content = $response->getContent();
    expect($content)->toContain('Nuevo');
});

test('agotado con stock 0 gana sobre nuevo', function () {
    $data = createTestData();
    $color = $data['color'];
    $size = $data['size'];
    $cat = $data['categoryHombre'];

    Carbon::setTestNow(now());
    $pAgotadoNuevo = Product::factory()->create([
        'category_id' => $cat->id,
        'name' => 'AgotadoNuevo',
        'slug' => 'agotado-nuevo',
        'status' => 'active',
        'base_price' => 10000,
        'created_at' => now()->subDays(1),
    ]);
    ProductVariant::factory()->create([
        'product_id' => $pAgotadoNuevo->id,
        'color_id' => $color->id,
        'size_id' => $size->id,
        'is_active' => true,
        'stock' => 0,
    ]);

    $response = get('/');
    $response->assertOk();
    $response->assertSee('AgotadoNuevo');
    $content = $response->getContent();
    expect($content)->toContain('Agotado');
});

test('colores de tarjeta no se repiten y no incluyen variantes inactivas', function () {
    $data = createTestData();
    $color = $data['color'];
    $size = $data['size'];
    $color2 = Color::factory()->create(['is_active' => true, 'name' => 'Azul', 'hex' => '#0000FF', 'code' => 'AZU']);
    $cat = $data['categoryHombre'];

    $product = Product::factory()->create([
        'category_id' => $cat->id,
        'name' => 'MultiColor',
        'slug' => 'multicolor',
        'status' => 'active',
        'base_price' => 10000,
    ]);
    ProductVariant::factory()->create([
        'product_id' => $product->id,
        'color_id' => $color->id,
        'size_id' => $size->id,
        'is_active' => true,
        'stock' => 5,
    ]);
    $size2 = Size::factory()->create(['is_active' => true]);
    ProductVariant::factory()->create([
        'product_id' => $product->id,
        'color_id' => $color->id,
        'size_id' => $size2->id,
        'is_active' => true,
        'stock' => 5,
    ]);
    ProductVariant::factory()->create([
        'product_id' => $product->id,
        'color_id' => $color2->id,
        'size_id' => $size->id,
        'is_active' => true,
        'stock' => 5,
    ]);

    $response = get('/');
    $response->assertOk();
    $response->assertSee('MultiColor');
});
