<?php

use App\Enums\StoreSection;
use App\Livewire\Admin\Products\Index;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

function numberedCategory(string $prefix = 'PL', string $name = 'Prenda', StoreSection $section = StoreSection::Hombre): Category
{
    return Category::factory()->section($section)->create(['sku_prefix' => $prefix, 'name' => $name]);
}

function adminForPanel(): User
{
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    return $admin;
}

test('renders the products page for users with the admin role', function () {
    $this->seed(RoleSeeder::class);

    $this->actingAs(adminForPanel())
        ->get(route('admin.products.index'))
        ->assertOk()
        ->assertSee('Productos')
        ->assertSee('Nuevo producto');
});

test('returns 403 for authenticated users without the admin role', function () {
    $this->seed(RoleSeeder::class);

    $seller = User::factory()->create();
    $seller->assignRole('vendedor');

    $this->actingAs($seller)
        ->get(route('admin.products.index'))
        ->assertForbidden();
});

test('guests are redirected to the login screen from the products page', function () {
    $this->get(route('admin.products.index'))->assertRedirect(route('login'));
});

test('the product form uses the same modal with the tabs of the admin panel', function () {
    $this->seed(RoleSeeder::class);

    Livewire::actingAs(adminForPanel())
        ->test(Index::class)
        ->assertSet('showForm', false)
        ->assertSee('x-data="adminModal($wire)"', false)
        ->assertSee('x-show="$data.open"', false)
        ->assertSee('max-h-[90vh]', false)
        ->assertSee('sm:max-w-2xl', false)
        ->assertSee('role="tablist"', false)
        ->assertSee('role="tabpanel"', false)
        // «Variantes» e «Imágenes» se anuncian pero no se pueden abrir todavía.
        ->assertSee('aria-disabled="true"', false)
        ->assertSee('Próximamente')
        ->call('create')
        ->assertSet('showForm', true)
        ->assertSet('editingId', null);
});

test('lists products with their reference, category and computed status', function () {
    $this->seed(RoleSeeder::class);

    $polos = numberedCategory('PL', 'Polos');
    $producto = Product::factory()->for($polos)->create([
        'name' => 'Camisa oxford',
        'reference' => 'PL-001',
        'base_price' => 89900,
    ]);
    ProductVariant::factory()->for($producto)->create(['stock' => 0]);

    Livewire::actingAs(adminForPanel())
        ->test(Index::class)
        ->assertSee('Camisa oxford')
        ->assertSee('PL-001')
        ->assertSee('Polos')
        ->assertSee('$89.900')
        ->assertSeeHtml('text-xs font-medium bg-ladrillo/10 text-ladrillo"')
        // Agotado se calcula, así que el interruptor sigue encendido: no está inactivo.
        ->assertSeeHtml('aria-checked="true"', false);
});

test('shows a badge per computed status', function () {
    $this->seed(RoleSeeder::class);

    $polos = numberedCategory('PL', 'Polos');

    $activo = Product::factory()->for($polos)->create(['name' => 'Con unidades']);
    ProductVariant::factory()->for($activo)->create(['stock' => 2]);

    $inactivo = Product::factory()->for($polos)->inactive()->create(['name' => 'Retirado']);
    ProductVariant::factory()->for($inactivo)->create(['stock' => 9]);

    $agotado = Product::factory()->for($polos)->create(['name' => 'Sin unidades']);
    ProductVariant::factory()->for($agotado)->create(['stock' => 0]);

    Product::factory()->for($polos)->create(['name' => 'Sin tallas']);

    Livewire::actingAs(adminForPanel())
        ->test(Index::class)
        ->assertSee('Con unidades')
        ->assertSee('Retirado')
        ->assertSee('Sin unidades')
        ->assertSee('Sin tallas')
        // Las cuatro insignias, cada una con su color: activo, inactivo, agotado y sin variantes.
        ->assertSeeHtml('text-xs font-medium bg-verde/10 text-verde"')
        ->assertSeeHtml('text-xs font-medium bg-hueso text-gris-calido"')
        ->assertSeeHtml('text-xs font-medium bg-ladrillo/10 text-ladrillo"')
        ->assertSeeHtml('text-xs font-medium bg-hueso text-gris-calido border border-arena"');
});

test('an inactive variant does not add up to the stock of its product', function () {
    $this->seed(RoleSeeder::class);

    $polos = numberedCategory('PL', 'Polos');
    $producto = Product::factory()->for($polos)->create(['name' => 'Solo tallas apagadas']);
    ProductVariant::factory()->for($producto)->inactive()->create(['stock' => 12]);

    Livewire::actingAs(adminForPanel())
        ->test(Index::class)
        ->assertSee('Solo tallas apagadas')
        ->assertSeeHtml('text-xs font-medium bg-ladrillo/10 text-ladrillo"');
});

test('searches products by name or reference regardless of case', function () {
    $this->seed(RoleSeeder::class);

    $polos = numberedCategory('PL', 'Polos');
    Product::factory()->for($polos)->create(['name' => 'Camisa oxford', 'reference' => 'PL-001']);
    Product::factory()->for($polos)->create(['name' => 'Pantalón sastre', 'reference' => 'PA-014']);

    Livewire::actingAs(adminForPanel())
        ->test(Index::class)
        ->set('search', 'cAmIsA')
        ->assertSee('Camisa oxford')
        ->assertDontSee('Pantalón sastre');

    Livewire::actingAs(adminForPanel())
        ->test(Index::class)
        ->set('search', 'pa-014')
        ->assertSee('Pantalón sastre')
        ->assertDontSee('Camisa oxford');

    Livewire::actingAs(adminForPanel())
        ->test(Index::class)
        ->set('search', 'pl-001')
        ->assertSee('Camisa oxford')
        ->assertDontSee('Pantalón sastre');
});

test('filters products by category', function () {
    $this->seed(RoleSeeder::class);

    $polos = numberedCategory('PL', 'Polos');
    $camisetas = numberedCategory('CM', 'Camisetas');

    $polo = Product::factory()->for($polos)->create(['name' => 'Polo de la raíz']);
    $camiseta = Product::factory()->for($camisetas)->create(['name' => 'Camiseta de la otra']);

    Livewire::actingAs(adminForPanel())
        ->test(Index::class)
        ->set('categoryFilter', (string) $polos->id)
        ->assertSeeHtml('wire:key="product-'.$polo->id.'"')
        ->assertDontSeeHtml('wire:key="product-'.$camiseta->id.'"')
        ->assertSee('Polo de la raíz');

    Livewire::actingAs(adminForPanel())
        ->test(Index::class)
        ->set('categoryFilter', (string) $camisetas->id)
        ->assertSeeHtml('wire:key="product-'.$camiseta->id.'"')
        ->assertDontSeeHtml('wire:key="product-'.$polo->id.'"');
});

test('lists only the products of the active section', function () {
    $this->seed(RoleSeeder::class);

    $hombre = numberedCategory('PL', 'Polos');
    $mujer = numberedCategory('VM', 'Vestidos', StoreSection::Mujer);

    $productoHombre = Product::factory()->for($hombre)->create(['name' => 'Polo de hombre']);
    $productoMujer = Product::factory()->for($mujer)->create(['name' => 'Vestido de mujer']);

    Livewire::actingAs(adminForPanel())
        ->test(Index::class)
        ->assertSet('section', StoreSection::Hombre->value)
        ->assertSeeHtml('wire:key="product-'.$productoHombre->id.'"')
        ->assertDontSeeHtml('wire:key="product-'.$productoMujer->id.'"')
        ->call('setSection', StoreSection::Mujer->value)
        ->assertSeeHtml('wire:key="product-'.$productoMujer->id.'"')
        ->assertDontSeeHtml('wire:key="product-'.$productoHombre->id.'"');
});

test('a category filter from another section matches nothing', function () {
    $this->seed(RoleSeeder::class);

    $mujer = numberedCategory('VM', 'Vestidos', StoreSection::Mujer);
    $producto = Product::factory()->for($mujer)->create(['name' => 'Vestido de mujer']);

    Livewire::actingAs(adminForPanel())
        ->test(Index::class)
        ->set('categoryFilter', (string) $mujer->id)
        ->assertDontSeeHtml('wire:key="product-'.$producto->id.'"');
});

test('filters products by every computed status', function () {
    $this->seed(RoleSeeder::class);

    $polos = numberedCategory('PL', 'Polos');

    $activo = Product::factory()->for($polos)->create(['name' => 'Producto activo']);
    ProductVariant::factory()->for($activo)->create(['stock' => 5]);

    $inactivo = Product::factory()->for($polos)->inactive()->create(['name' => 'Producto inactivo']);
    ProductVariant::factory()->for($inactivo)->create(['stock' => 5]);

    $agotado = Product::factory()->for($polos)->create(['name' => 'Producto agotado']);
    ProductVariant::factory()->for($agotado)->create(['stock' => 0]);

    $apagado = Product::factory()->for($polos)->create(['name' => 'Producto con tallas apagadas']);
    ProductVariant::factory()->for($apagado)->inactive()->create(['stock' => 7]);

    $sinVariantes = Product::factory()->for($polos)->create(['name' => 'Producto sin tallas']);

    $component = fn (string $status) => Livewire::actingAs(adminForPanel())
        ->test(Index::class)
        ->set('statusFilter', $status);

    $component('active')
        ->assertSee('Producto activo')
        ->assertDontSee('Producto inactivo')
        ->assertDontSee('Producto agotado')
        ->assertDontSee('Producto con tallas apagadas')
        ->assertDontSee('Producto sin tallas');

    $component('inactive')
        ->assertSee('Producto inactivo')
        ->assertDontSee('Producto activo');

    // Agotado agrupa al que se quedó sin unidades y al que solo tiene tallas apagadas:
    // en los dos casos hay variantes, pero su stock activo suma cero.
    $component('out_of_stock')
        ->assertSee('Producto agotado')
        ->assertSee('Producto con tallas apagadas')
        ->assertDontSee('Producto activo')
        ->assertDontSee('Producto sin tallas');

    $component('no_variants')
        ->assertSee('Producto sin tallas')
        ->assertDontSee('Producto agotado')
        ->assertDontSee('Producto activo');
});

test('creates a product with a generated reference, slug, brand and status', function () {
    $this->seed(RoleSeeder::class);

    $polos = numberedCategory('PL', 'Polos');

    Livewire::actingAs(adminForPanel())
        ->test(Index::class)
        ->call('create')
        ->set('name', 'Polo clásico piqué')
        ->set('categoryId', $polos->id)
        ->set('description', 'Piqué de algodón peinado.')
        ->set('material', 'Algodón piqué')
        ->set('basePrice', '89900')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('showForm', false)
        ->assertSet('notice', 'Producto creado correctamente.');

    $producto = Product::query()->where('name', 'Polo clásico piqué')->sole();

    expect($producto->reference)->toBe('PL-001')
        ->and($producto->slug)->toBe('polo-clasico-pique')
        ->and($producto->brand)->toBe('Feigler')
        ->and($producto->status)->toBe('active')
        ->and($producto->status)->not->toBe('out_of_stock')
        ->and($producto->description)->toBe('Piqué de algodón peinado.')
        ->and($producto->material)->toBe('Algodón piqué');

    Livewire::actingAs(adminForPanel())
        ->test(Index::class)
        ->call('create')
        ->set('name', 'Polo，一次 más')
        ->set('categoryId', $polos->id)
        ->set('basePrice', '59900')
        ->call('save')
        ->assertHasNoErrors();

    expect(Product::query()->orderBy('id')->pluck('reference')->all())->toBe(['PL-001', 'PL-002']);
});

test('a duplicate slug gets a numeric suffix', function () {
    $this->seed(RoleSeeder::class);

    $polos = numberedCategory('PL', 'Polos');

    Product::factory()->for($polos)->create(['name' => 'Polo básico', 'slug' => 'polo-basico']);

    Livewire::actingAs(adminForPanel())
        ->test(Index::class)
        ->call('create')
        ->set('name', 'Polo básico')
        ->set('categoryId', $polos->id)
        ->set('basePrice', '39900')
        ->call('save')
        ->assertHasNoErrors();

    $producto = Product::query()->where('name', 'Polo básico')->latest('id')->first();

    expect($producto->slug)->toBe('polo-basico-2')
        ->and(Product::query()->where('slug', 'polo-basico')->count())->toBe(1);
});

test('creating in a category of another section fails and stores nothing', function () {
    $this->seed(RoleSeeder::class);

    // El prefijo de SKU ya no puede faltar: es `NOT NULL` y único en toda la
    // tienda. Lo que sigue vedado es cambiar de sección, y la garantía pasa a
    // ser que la categoría elegida tiene que pertenecer a la sección abierta.
    $mujer = numberedCategory('VM', 'Vestidos', StoreSection::Mujer);

    Livewire::actingAs(adminForPanel())
        ->test(Index::class)
        ->call('create')
        ->set('name', 'Producto de otra sección')
        ->set('categoryId', $mujer->id)
        ->set('basePrice', '19900')
        ->call('save')
        ->assertHasErrors(['categoryId'])
        ->assertSee('La categoría seleccionada no existe en esta sección.')
        ->assertSet('showForm', true);

    expect(Product::query()->count())->toBe(0);
});

test('validates the general data of a product', function () {
    $this->seed(RoleSeeder::class);

    Livewire::actingAs(adminForPanel())
        ->test(Index::class)
        ->call('create')
        ->call('save')
        ->assertHasErrors(['name', 'categoryId', 'basePrice']);

    $polos = numberedCategory('PL', 'Polos');

    Livewire::actingAs(adminForPanel())
        ->test(Index::class)
        ->call('create')
        ->set('name', 'Precio negativo')
        ->set('categoryId', $polos->id)
        ->set('basePrice', '-10')
        ->call('save')
        ->assertHasErrors(['basePrice'])
        ->assertSee('El precio base no puede ser negativo.');

    Livewire::actingAs(adminForPanel())
        ->test(Index::class)
        ->call('create')
        ->set('name', 'Precio de texto')
        ->set('categoryId', $polos->id)
        ->set('basePrice', 'mucho')
        ->call('save')
        ->assertHasErrors(['basePrice'])
        ->assertSee('El precio base debe ser un número.');

    Livewire::actingAs(adminForPanel())
        ->test(Index::class)
        ->call('create')
        ->set('name', 'Categoría que no existe')
        ->set('categoryId', 9999)
        ->set('basePrice', '19900')
        ->call('save')
        ->assertHasErrors(['categoryId'])
        ->assertSee('La categoría seleccionada no existe en esta sección.');

    // «Agotado» es un estado calculado: nunca se escribe, y menos aún desde el formulario.
    Livewire::actingAs(adminForPanel())
        ->test(Index::class)
        ->call('create')
        ->set('name', 'Agotado a mano')
        ->set('categoryId', $polos->id)
        ->set('basePrice', '19900')
        ->set('status', 'out_of_stock')
        ->call('save')
        ->assertHasErrors(['status'])
        ->assertSee('El estado seleccionado no es válido.');

    expect(Product::query()->count())->toBe(0);
});

test('editing keeps the slug and the reference even when the name or category change', function () {
    $this->seed(RoleSeeder::class);

    $polos = numberedCategory('PL', 'Polos');
    $camisetas = numberedCategory('CM', 'Camisetas');

    $producto = Product::factory()->for($polos)->create([
        'name' => 'Polo clásico',
        'slug' => 'polo-clasico',
        'reference' => 'PL-001',
    ]);

    Livewire::actingAs(adminForPanel())
        ->test(Index::class)
        ->call('edit', $producto->id)
        ->assertSet('editingId', $producto->id)
        ->assertSet('editingReference', 'PL-001')
        ->set('name', 'Polo clásico de algodón')
        ->set('categoryId', $camisetas->id)
        ->set('basePrice', '74900')
        ->set('status', 'inactive')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('notice', 'Producto actualizado correctamente.');

    $producto->refresh();

    expect($producto->name)->toBe('Polo clásico de algodón')
        ->and($producto->slug)->toBe('polo-clasico')
        ->and($producto->reference)->toBe('PL-001')
        ->and($producto->category_id)->toBe($camisetas->id)
        ->and($producto->status)->toBe('inactive')
        ->and($producto->base_price)->toBe('74900.00');
});

test('editing into a category of another section fails and keeps the product where it was', function () {
    $this->seed(RoleSeeder::class);

    $polos = numberedCategory('PL', 'Polos');
    $vestidos = numberedCategory('VM', 'Vestidos', StoreSection::Mujer);

    $producto = Product::factory()->for($polos)->create([
        'name' => 'Polo clásico',
        'reference' => 'PL-001',
    ]);

    // El formulario se abre en la sección del producto, no en la pestaña que
    // esté activa, así que moverlo a otra sección es rechazado por validación.
    Livewire::actingAs(adminForPanel())
        ->test(Index::class)
        ->call('edit', $producto->id)
        ->set('categoryId', $vestidos->id)
        ->call('save')
        ->assertHasErrors(['categoryId'])
        ->assertSee('La categoría seleccionada no existe en esta sección.');

    expect($producto->refresh()->category_id)->toBe($polos->id)
        ->and($producto->section)->toBe(StoreSection::Hombre);
});

test('editing a product keeps it inside the section of its own category', function () {
    $this->seed(RoleSeeder::class);

    $mujer = numberedCategory('VM', 'Vestidos', StoreSection::Mujer);
    $falda = numberedCategory('FD', 'Faldas', StoreSection::Mujer);

    $producto = Product::factory()->for($mujer)->create([
        'name' => 'Vestido de fiesta',
        'reference' => 'VM-001',
    ]);

    // Aunque la pestaña activa sea la de hombre, el formulario de este producto
    // solo ofrece las categorías de mujer.
    Livewire::actingAs(adminForPanel())
        ->test(Index::class)
        ->call('edit', $producto->id)
        ->assertSee('Mujer')
        ->set('categoryId', $falda->id)
        ->call('save')
        ->assertHasNoErrors();

    expect($producto->refresh()->category_id)->toBe($falda->id)
        ->and($producto->section)->toBe(StoreSection::Mujer);
});

test('the status toggle alternates active and inactive and notifies', function () {
    $this->seed(RoleSeeder::class);

    $polos = numberedCategory('PL', 'Polos');
    $producto = Product::factory()->for($polos)->create(['name' => 'Polo clásico', 'status' => 'active']);

    Livewire::actingAs(adminForPanel())
        ->test(Index::class)
        ->call('toggleActive', $producto->id)
        ->assertSet('noticeType', 'success')
        ->assertDispatched('toast', tone: 'success', message: 'Producto «Polo clásico» desactivado correctamente.');

    expect($producto->refresh()->status)->toBe('inactive');

    Livewire::actingAs(adminForPanel())
        ->test(Index::class)
        ->call('toggleActive', $producto->id)
        ->assertSet('noticeType', 'success')
        ->assertDispatched('toast', tone: 'success', message: 'Producto «Polo clásico» activado correctamente.');

    expect($producto->refresh()->status)->toBe('active');
});

test('shows twenty products at a time and loads twenty more on demand', function () {
    $this->seed(RoleSeeder::class);

    $polos = numberedCategory('PL', 'Polos');

    foreach (range(1, 25) as $numero) {
        Product::factory()->for($polos)->create([
            'name' => sprintf('Polo número %02d', $numero),
            'reference' => sprintf('PL-%03d', $numero),
        ]);
    }

    $component = Livewire::actingAs(adminForPanel())->test(Index::class);

    $component->assertSee('Polo número 20')
        ->assertSee('Mostrar más — quedan 5')
        ->assertDontSee('Polo número 21')
        ->call('loadMore')
        ->assertSet('perPage', 40)
        ->assertSee('Polo número 25')
        ->assertDontSee('Mostrar más');
});

test('searching or filtering starts the list over from the first twenty', function () {
    $this->seed(RoleSeeder::class);

    $polos = numberedCategory('PL', 'Polos');

    foreach (range(1, 25) as $numero) {
        Product::factory()->for($polos)->create([
            'name' => sprintf('Prenda %02d', $numero),
            'reference' => sprintf('PL-%03d', $numero),
        ]);
    }

    Livewire::actingAs(adminForPanel())
        ->test(Index::class)
        ->call('loadMore')
        ->assertSet('perPage', 40)
        ->set('search', 'Prenda 07')
        ->assertSet('perPage', 20)
        ->set('search', '')
        ->call('loadMore')
        ->set('categoryFilter', (string) $polos->id)
        ->assertSet('perPage', 20)
        ->call('loadMore')
        ->set('statusFilter', 'no_variants')
        ->assertSet('perPage', 20);
});

test('the listing runs the same number of queries with three products as with fifteen', function () {
    $this->seed(RoleSeeder::class);

    $admin = adminForPanel();
    $polos = numberedCategory('PL', 'Polos');

    $queriesFor = function (int $cantidad) use ($admin, $polos): int {
        Product::factory()->count($cantidad)->for($polos)->create();

        DB::connection()->flushQueryLog();
        DB::connection()->enableQueryLog();

        Livewire::actingAs($admin)->test(Index::class);

        $consultas = count(DB::connection()->getQueryLog());

        DB::connection()->disableQueryLog();

        Product::query()->delete();

        return $consultas;
    };

    $conTres = $queriesFor(3);
    $conQuince = $queriesFor(15);

    // Con `category` y `variants` cargados por adelantado, leer más filas no cuesta
    // más consultas: si el listado volviera a preguntar fila por fila, los dos
    // números se separarían.
    expect($conQuince)->toBe($conTres);
});
