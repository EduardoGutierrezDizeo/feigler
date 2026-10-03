<?php

use App\Enums\StoreSection;
use App\Livewire\Admin\Products\Index;
use App\Models\Category;
use App\Models\Material;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

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
        // Las dos pestañas que viven en un producto guardado se anuncian cerradas
        // mientras no lo haya.
        ->assertSee('aria-disabled="true"', false)
        ->assertSee('Guarda el producto primero', false)
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
    $algodon = Material::factory()->create(['name' => 'Algodón']);
    $poliester = Material::factory()->create(['name' => 'Poliéster']);

    $creado = Livewire::actingAs(adminForPanel())
        ->test(Index::class)
        ->call('create')
        ->set('name', 'Polo clásico piqué')
        ->set('categoryId', $polos->id)
        ->set('description', 'Piqué de algodón peinado.')
        ->set('composition', [
            ['material_id' => (string) $algodon->getKey(), 'percentage' => '80'],
            ['material_id' => (string) $poliester->getKey(), 'percentage' => '20'],
        ])
        ->set('basePrice', '89900')
        ->call('save')
        ->assertHasNoErrors()
        // El modal sigue abierto y en modo edición: las variantes se crean sobre el
        // producto que acaba de existir, y para eso necesita su id y su referencia.
        ->assertSet('showForm', true)
        ->assertSet('notice', 'Producto creado correctamente.')
        ->assertDispatched('open-product-variants-tab');

    $producto = Product::query()->where('name', 'Polo clásico piqué')->sole();

    $creado->assertSet('editingId', $producto->id)
        ->assertSet('editingReference', 'PL-001');

    expect($producto->reference)->toBe('PL-001')
        ->and($producto->slug)->toBe('polo-clasico-pique')
        ->and($producto->brand)->toBe('Feigler')
        ->and($producto->status)->toBe('active')
        ->and($producto->status)->not->toBe('out_of_stock')
        ->and($producto->description)->toBe('Piqué de algodón peinado.')
        ->and($producto->materials()->orderBy('materials.id')->pluck('materials.name')->all())->toBe(['Algodón', 'Poliéster'])
        ->and(array_map(
            fn (Material $material): int => $material->pivot->percentage,
            $producto->materials()->orderBy('materials.id')->get()->all(),
        ))->toBe([80, 20]);

    Livewire::actingAs(adminForPanel())
        ->test(Index::class)
        ->call('create')
        ->set('name', 'Polo uno más')
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

/**
 * El campo del precio muestra el monto con los separadores de siempre y lo que
 * llega a la columna es el número pelado.
 *
 * Los puntos y las comas son solo presentación. El input no lleva `wire:model`:
 * Alpine es dueño del texto que se ve y le empuja los dígitos a Livewire, que es
 * lo que hace que `numeric` acepte el valor y que la columna guarde `89900.50` en
 * lugar del texto con separadores. El valor formateado lo pinta el servidor para
 * que el campo ya salga bien en el primer render, en vez de destellar el número.
 *
 * El formato es el de la app: punto para los miles y coma para los decimales, lo
 * mismo que el `$89.901` del listado de arriba y que el «99.999.999,99» del
 * mensaje de validación. Y los centavos no se redondean al pintarlos: agrupar
 * `89900.50` sin decimales mostraría «89.901», y ese redondeo es justo lo que se
 * acabaría guardando.
 */
test('the price field shows the amount with separators and stores the plain number', function () {
    $this->seed(RoleSeeder::class);

    $polos = numberedCategory('PL', 'Polos');

    // Vacío al abrir: nada de «0,00» inventado.
    Livewire::actingAs(adminForPanel())
        ->test(Index::class)
        ->call('create')
        ->assertSee("priceInput(\$wire, 'basePrice', '')", false)
        ->assertDontSeeHtml('wire:model="basePrice"');

    $producto = Product::factory()->for($polos, 'category')->create([
        'name' => 'Polo con centavos',
        'base_price' => 89900.5,
    ]);

    Livewire::actingAs(adminForPanel())
        ->test(Index::class)
        ->call('edit', $producto)
        ->assertSee("priceInput(\$wire, 'basePrice', '89.900,50')", false);

    // Editar sin tocar el precio deja el número como estaba, sin redondear.
    Livewire::actingAs(adminForPanel())
        ->test(Index::class)
        ->call('edit', $producto)
        ->set('name', 'Polo con centavos editado')
        ->call('save')
        ->assertHasNoErrors();

    expect((float) $producto->refresh()->base_price)->toEqual(89900.5);

    // Y tras guardar, el modal se limpia: el input vuelve a quedar vacío.
    Livewire::actingAs(adminForPanel())
        ->test(Index::class)
        ->call('create')
        ->assertSee("priceInput(\$wire, 'basePrice', '')", false);
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

/**
 * A product that already sells something cannot be moved to another category of its
 * section: its sizes are rows of the category it is in, and the new index forbids a
 * variant whose size belongs elsewhere. The whole form is refused, so the data the
 * admin typed alongside the new category is not written either.
 */
test('a product with variants cannot be moved to another category of its section', function () {
    $this->seed(RoleSeeder::class);

    $polos = numberedCategory('PL', 'Polos');
    $camisetas = numberedCategory('CM', 'Camisetas');

    $producto = Product::factory()->for($polos)->create([
        'name' => 'Polo clásico',
        'slug' => 'polo-clasico',
        'reference' => 'PL-001',
        'base_price' => '1990.00',
        'description' => 'Piqué de algodón.',
    ]);

    ProductVariant::factory()->for($producto)->create();

    Livewire::actingAs(adminForPanel())
        ->test(Index::class)
        ->call('edit', $producto->id)
        ->set('categoryId', $camisetas->id)
        ->set('name', 'Polo de algodón')
        ->set('basePrice', '2990')
        ->set('description', 'Otra descripción.')
        ->set('status', 'inactive')
        ->call('save')
        ->assertHasErrors(['categoryId'])
        ->assertSee('No puedes cambiar la categoría de un producto que ya tiene variantes: sus tallas pertenecen a la categoría actual');

    // Ni la categoría se mueve ni se guarda el resto del formulario: el rechazo es
    // del formulario entero, no de un campo.
    expect($producto->refresh())
        ->category_id->toBe($polos->id)
        ->name->toBe('Polo clásico')
        ->base_price->toEqual('1990.00')
        ->description->toBe('Piqué de algodón.')
        ->status->toBe('active')
        ->and($producto->variants()->count())->toBe(1);

    // El aviso de éxito no aparece: la fila sigue siendo la de antes, con su nombre.
    Livewire::actingAs(adminForPanel())
        ->test(Index::class)
        ->assertSeeHtml('wire:key="product-'.$producto->id.'"')
        ->assertSee('Polo clásico')
        ->assertDontSeeHtml('Polo de algodón')
        ->assertDontSeeHtml('Otra descripción.');
});

/**
 * The regression of the rule above: refusing a category change must not stop a product
 * that has variants from being edited, since staying in its category while changing its
 * name, its price or its status is the ordinary case, not a special one.
 */
test('a product with variants is still edited while it stays in its category', function () {
    $this->seed(RoleSeeder::class);

    $polos = numberedCategory('PL', 'Polos');

    $producto = Product::factory()->for($polos)->create([
        'name' => 'Polo clásico',
        'slug' => 'polo-clasico',
        'reference' => 'PL-001',
        'base_price' => '1990.00',
    ]);

    ProductVariant::factory()->for($producto)->create();

    Livewire::actingAs(adminForPanel())
        ->test(Index::class)
        ->call('edit', $producto->id)
        // La categoría se vuelve a poner la misma: es lo que hace el formulario cuando
        // se abre, y aun así tiene que guardarse.
        ->set('categoryId', $polos->id)
        ->set('name', 'Polo clásico piqué')
        ->set('basePrice', '2490')
        ->set('description', 'Piqué de algodón peinado.')
        ->set('status', 'inactive')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('notice', 'Producto actualizado correctamente.');

    expect($producto->refresh())
        ->category_id->toBe($polos->id)
        ->name->toBe('Polo clásico piqué')
        // El slug se deja a propósito al editar, así que sigue siendo el de antes.
        ->slug->toBe('polo-clasico')
        ->base_price->toEqual('2490.00')
        ->description->toBe('Piqué de algodón peinado.')
        ->status->toBe('inactive')
        // La referencia no se toca, así que las variantes que ya tiene siguen siendo
        // las mismas y con el mismo SKU.
        ->reference->toBe('PL-001')
        ->and($producto->variants()->count())->toBe(1);
});

/**
 * The other side of the rule: a product that sells nothing has no sizes pointing at
 * its category, so moving it is allowed. It is born without variants and the tab that
 * fills them is what stops the category from moving afterwards.
 */
test('a product without variants can be moved to another category of its section', function () {
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
        ->set('categoryId', $camisetas->id)
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('notice', 'Producto actualizado correctamente.');

    expect($producto->refresh())
        ->category_id->toBe($camisetas->id)
        ->name->toBe('Polo clásico')
        ->and($producto->variants()->count())->toBe(0);

    // La fila del listado lleva la categoría nueva.
    Livewire::actingAs(adminForPanel())
        ->test(Index::class)
        ->assertSeeHtml('wire:key="product-'.$producto->id.'"')
        ->assertSee('Camisetas');
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

/*
|-------------------------------------------------------------------------------
| La composición de materiales
|-------------------------------------------------------------------------------
|
| Un producto dice de qué está hecho con filas y no con una frase, así que lo que se
| prueba aquí es que el formulario ofrezca lo que hace falta para llenarlas, que avise
| de la suma mientras se escribe, y que no deje un producto a medio camino cuando la
| composición es la que no cuadra.
|
*/

describe('la composición de materiales', function () {
    beforeEach(function (): void {
        $this->seed(RoleSeeder::class);
    });

    test('la primera fila empieza en cien y las siguientes en lo que falta', function () {
        Livewire::actingAs(adminForPanel())
            ->test(Index::class)
            ->call('create')
            ->call('addMaterialRow')
            ->assertSet('composition', [['material_id' => '', 'percentage' => '100']])
            // Lo que falta nunca es cero: una fila con un 0 % no se puede guardar, y
            // partir de ahí sólo cambiaría el mensaje de error por el mismo problema.
            ->call('addMaterialRow')
            ->assertSet('composition', [
                ['material_id' => '', 'percentage' => '100'],
                ['material_id' => '', 'percentage' => '1'],
            ])
            ->set('composition', [['material_id' => '', 'percentage' => '80']])
            ->call('addMaterialRow')
            ->assertSet('composition', [
                ['material_id' => '', 'percentage' => '80'],
                ['material_id' => '', 'percentage' => '20'],
            ]);
    });

    test('quitar una fila deja las demás en orden y sin huecos', function () {
        $algodon = Material::factory()->create(['name' => 'Algodón']);
        $poliester = Material::factory()->create(['name' => 'Poliéster']);
        $lino = Material::factory()->create(['name' => 'Lino']);

        Livewire::actingAs(adminForPanel())
            ->test(Index::class)
            ->call('create')
            ->call('addMaterialRow')
            ->call('addMaterialRow')
            ->call('addMaterialRow')
            ->set('composition', [
                ['material_id' => (string) $algodon->getKey(), 'percentage' => '60'],
                ['material_id' => (string) $poliester->getKey(), 'percentage' => '30'],
                ['material_id' => (string) $lino->getKey(), 'percentage' => '10'],
            ])
            ->call('removeMaterialRow', 1)
            // Un hueco en medio dejaría `composition.2` apuntando a una fila que ya
            // no existe, y el nombre del campo del error sería el equivocado.
            ->assertSet('composition', [
                ['material_id' => (string) $algodon->getKey(), 'percentage' => '60'],
                ['material_id' => (string) $lino->getKey(), 'percentage' => '10'],
            ]);
    });

    test('la suma se avisa mientras se escribe', function () {
        $algodon = Material::factory()->create(['name' => 'Algodón']);
        $poliester = Material::factory()->create(['name' => 'Poliéster']);

        $composicion = [
            ['material_id' => (string) $algodon->getKey(), 'percentage' => '80'],
            ['material_id' => (string) $poliester->getKey(), 'percentage' => '20'],
        ];

        Livewire::actingAs(adminForPanel())
            ->test(Index::class)
            ->call('create')
            ->set('composition', $composicion)
            ->assertSee('Suma: 100 % — completa')
            ->set('composition.1.percentage', '10')
            ->assertSee('Suma: 90 % — faltan 10 %')
            ->set('composition.1.percentage', '40')
            ->assertSee('Suma: 120 % — se pasa por 20 %');
    });

    test('un producto sin ninguna fila se guarda igual', function () {
        $polos = numberedCategory('PL', 'Polos');

        Livewire::actingAs(adminForPanel())
            ->test(Index::class)
            ->call('create')
            ->set('name', 'Polo sin materiales')
            ->set('categoryId', $polos->id)
            ->set('basePrice', '89900')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('notice', 'Producto creado correctamente.');

        expect(Product::query()->sole()->materials()->count())->toBe(0);
    });

    test('una composición que no suma cien se rechaza y no deja el producto a medio guardar', function () {
        $polos = numberedCategory('PL', 'Polos');
        $algodon = Material::factory()->create(['name' => 'Algodón']);
        $existente = Product::factory()->for($polos)->create(['name' => 'Polo existente']);
        $existente->materials()->attach($algodon->getKey(), ['percentage' => 50]);

        Livewire::actingAs(adminForPanel())
            ->test(Index::class)
            ->call('edit', $existente->getKey())
            ->set('composition', [['material_id' => (string) $algodon->getKey(), 'percentage' => '80']])
            ->call('save')
            // El error va bajo «Materiales», que es donde el admin está mirando, y no
            // bajo el campo de precio que sí se llegó a escribir.
            ->assertHasErrors(['composition'])
            ->assertSet('notice', null);

        // Ni la fila del pivote ni el nombre del producto se han movido: la operación
        // entera es o no es.
        expect($existente->fresh()->materials()->sole()->pivot->percentage)->toBe(50)
            ->and($existente->fresh()->name)->toBe('Polo existente')
            ->and(Product::query()->count())->toBe(1);
    });

    test('un porcentaje que no es un número entero del uno al cien se rechaza en su fila', function (string $porcentaje, string $mensaje) {
        $polos = numberedCategory('PL', 'Polos');
        $algodon = Material::factory()->create(['name' => 'Algodón']);

        Livewire::actingAs(adminForPanel())
            ->test(Index::class)
            ->call('create')
            ->set('name', 'Polo clásico')
            ->set('categoryId', $polos->id)
            ->set('basePrice', '89900')
            ->call('addMaterialRow')
            ->set('composition', [['material_id' => (string) $algodon->getKey(), 'percentage' => $porcentaje]])
            ->call('save')
            ->assertHasErrors(['composition.0.percentage' => $mensaje]);
    })->with([
        ['0', 'El porcentaje no puede ser menor que 1.'],
        ['101', 'El porcentaje no puede superar 100.'],
        ['diez', 'El porcentaje debe ser un número entero.'],
    ]);

    test('un material repetido se rechaza en la fila que lo repite', function () {
        $polos = numberedCategory('PL', 'Polos');
        $algodon = Material::factory()->create(['name' => 'Algodón']);
        $fila = (string) $algodon->getKey();

        Livewire::actingAs(adminForPanel())
            ->test(Index::class)
            ->call('create')
            ->set('name', 'Polo clásico')
            ->set('categoryId', $polos->id)
            ->set('basePrice', '89900')
            ->call('addMaterialRow')
            ->call('addMaterialRow')
            ->set('composition', [
                ['material_id' => $fila, 'percentage' => '50'],
                ['material_id' => $fila, 'percentage' => '50'],
            ])
            ->call('save')
            ->assertHasErrors('composition');

        expect(Product::query()->count())->toBe(0);
    });

    test('un material desactivado que el producto no tiene se rechaza, y uno que sí tiene se conserva', function () {
        $algodon = Material::factory()->create(['name' => 'Algodón']);
        $lino = Material::factory()->create(['name' => 'Lino']);
        $lino->update(['is_active' => false]);

        $polos = numberedCategory('PL', 'Polos');
        $existente = Product::factory()->for($polos)->create(['name' => 'Polo de lino']);
        $existente->materials()->attach($lino->getKey(), ['percentage' => 100]);

        $panel = Livewire::actingAs(adminForPanel())->test(Index::class);

        // Guardar sin tocar nada tiene que servir: un material apagado no es motivo
        // para obligar a cambiar la composición.
        $panel->call('edit', $existente->getKey())
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('notice', 'Producto actualizado correctamente.');

        expect($existente->fresh()->materials()->sole()->pivot->percentage)->toBe(100);

        // El mismo material en un producto que no lo tenía sí se rechaza.
        $panel->call('create')
            ->set('name', 'Polo nuevo')
            ->set('categoryId', $polos->id)
            ->set('basePrice', '89900')
            ->call('addMaterialRow')
            ->set('composition', [['material_id' => (string) $lino->getKey(), 'percentage' => '100']])
            ->call('save')
            ->assertHasErrors('composition');

        expect(Product::query()->where('name', 'Polo nuevo')->exists())->toBeFalse();

        // Y el selector lo ofrece cuando ya está asignado, marked as inactive.
        $panel->call('edit', $existente->getKey())
            ->assertSee('Lino (inactivo)')
            ->assertDontSee('Algodón (inactivo)');
    });

    test('el selector ofrece los materiales activos más los del producto, sin repetir los de otras filas', function () {
        $algodon = Material::factory()->create(['name' => 'Algodón', 'order' => 1]);
        $poliester = Material::factory()->create(['name' => 'Poliéster', 'order' => 2]);
        $lino = Material::factory()->create(['name' => 'Lino', 'order' => 3]);
        $lino->update(['is_active' => false]);

        $polos = numberedCategory('PL', 'Polos');
        $existente = Product::factory()->for($polos)->create(['name' => 'Polo de lino']);
        $existente->materials()->attach($lino->getKey(), ['percentage' => 100]);

        $formulario = Livewire::actingAs(adminForPanel())
            ->test(Index::class)
            ->call('edit', $existente->getKey())
            ->set('composition', [
                ['material_id' => (string) $algodon->getKey(), 'percentage' => '60'],
                ['material_id' => (string) $lino->getKey(), 'percentage' => '40'],
            ])
            ->html();

        $selectorDe = function (int $indice) use ($formulario): string {
            preg_match('/id="composition-'.$indice.'-material".*?<\/select>/s', $formulario, $encontrado);

            return $encontrado[0] ?? '';
        };

        $primera = $selectorDe(0);
        $segunda = $selectorDe(1);

        // La primera fila lleva el Algodón, así que no puede ofrecerlo otra vez; la
        // segunda lo quita de sus opciones aunque siga activo. El Lino está apagado,
        // pero el producto lo tiene, así que sale en las dos marcadas como inactivo.
        expect($primera)->toContain('value="'.$algodon->getKey().'"')
            ->and($primera)->toContain('value="'.$poliester->getKey().'"')
            ->and($primera)->not->toContain('value="'.$lino->getKey().'"')
            ->and($segunda)->toContain('value="'.$poliester->getKey().'"')
            ->and($segunda)->toContain('value="'.$lino->getKey().'"')
            ->and($segunda)->not->toContain('value="'.$algodon->getKey().'"')
            ->and($segunda)->toContain('Lino (inactivo)');
    });
});
