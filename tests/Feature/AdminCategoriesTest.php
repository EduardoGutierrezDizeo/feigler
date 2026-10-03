<?php

use App\Actions\ProductDetails\DeleteCategory;
use App\Enums\StoreSection;
use App\Livewire\Admin\Categories\Index;
use App\Models\Category;
use App\Models\Product;
use App\Models\Size;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

test('lists only the categories of the active section', function () {
    $hombre = Category::factory()->section(StoreSection::Hombre)->create(['name' => 'Polos']);
    $mujer = Category::factory()->section(StoreSection::Mujer)->create(['name' => 'Vestidos']);

    Livewire::test(Index::class)
        ->assertSet('section', StoreSection::Hombre->value)
        ->assertSeeHtml('wire:key="category-'.$hombre->id.'"')
        ->assertDontSeeHtml('wire:key="category-'.$mujer->id.'"')
        ->assertSee('Polos')
        ->assertDontSee('Vestidos');

    expect($hombre->section)->toBe(StoreSection::Hombre)
        ->and($mujer->section)->toBe(StoreSection::Mujer);
});

test('offers the three sections as tabs and switches between them', function () {
    $hombre = Category::factory()->section(StoreSection::Hombre)->create(['name' => 'Polos']);
    $mujer = Category::factory()->section(StoreSection::Mujer)->create(['name' => 'Vestidos']);

    Livewire::test(Index::class)
        ->assertSee('Hombre')
        ->assertSee('Mujer')
        ->assertSee('Niños')
        ->assertSeeHtml('role="tab"')
        ->assertSeeHtml('aria-selected="true"')
        ->call('setSection', StoreSection::Mujer->value)
        ->assertSet('section', StoreSection::Mujer->value)
        ->assertSee('Vestidos')
        // El nombre de la fila se comprueba por su `wire:key` y no con
        // `assertDontSee('Polos')`: el modal lleva `placeholder="Ej. Camisetas"`.
        ->assertDontSeeHtml('wire:key="category-'.$hombre->id.'"')
        ->assertSeeHtml('wire:key="category-'.$mujer->id.'"');
});

test('falls back to hombre when the url holds a section that does not exist', function () {
    Livewire::test(Index::class)
        ->set('section', 'invalida')
        ->call('setSection', 'invalida')
        ->assertSet('section', StoreSection::Hombre->value);
});

test('switching section discards the search and dismisses the form', function () {
    Category::factory()->create(['name' => 'Camisetas']);

    Livewire::test(Index::class)
        ->set('search', 'cami')
        ->call('create')
        ->set('name', 'Pantalones')
        ->assertSet('showForm', true)
        ->call('setSection', StoreSection::Mujer->value)
        ->assertSet('search', '')
        ->assertSet('showForm', false)
        ->assertSet('editingId', null)
        ->assertSet('name', '');
});

test('creates a category with a generated slug in the active section', function () {
    Livewire::test(Index::class)
        ->call('create')
        ->set('name', 'Pantalones')
        ->set('skuPrefix', 'PA')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('showForm', false);

    $category = Category::query()->where('slug', 'pantalones')->sole();

    expect($category->name)->toBe('Pantalones')
        ->and($category->section)->toBe(StoreSection::Hombre)
        ->and($category->sku_prefix)->toBe('PA');
});

test('a created category belongs to the section that was active, not the default one', function () {
    Livewire::test(Index::class)
        ->call('setSection', StoreSection::Ninos->value)
        ->call('create')
        ->set('name', 'Pantalones')
        ->set('skuPrefix', 'PA')
        ->call('save')
        ->assertHasNoErrors();

    expect(Category::query()->where('slug', 'pantalones')->sole()->section)
        ->toBe(StoreSection::Ninos);
});

test('rejects a name already used in the same section', function () {
    Category::factory()->section(StoreSection::Hombre)->create(['name' => 'Camisetas']);

    Livewire::test(Index::class)
        ->call('create')
        ->set('name', 'Camisetas')
        ->set('skuPrefix', 'CA')
        ->call('save')
        ->assertHasErrors(['name'])
        ->assertSee('Ya existe una categoría con ese nombre en esta sección.');

    expect(Category::query()->count())->toBe(1);
});

test('lets another section reuse a name already taken in the active one', function () {
    Category::factory()->section(StoreSection::Hombre)->create(['name' => 'Polos', 'slug' => 'polos', 'sku_prefix' => 'PLH']);

    Livewire::test(Index::class)
        ->call('setSection', StoreSection::Mujer->value)
        ->call('create')
        ->set('name', 'Polos')
        ->set('skuPrefix', 'PLM')
        ->call('save')
        ->assertHasNoErrors();

    expect(Category::query()->where('slug', 'polos')->count())->toBe(2);
});

test('generates a new slug when editing a category', function () {
    $category = Category::factory()->create(['name' => 'Sudaderas', 'slug' => 'sudaderas']);

    Livewire::test(Index::class)
        ->call('edit', $category->id)
        ->assertSet('editingId', $category->id)
        ->assertSet('name', 'Sudaderas')
        ->set('skuPrefix', 'SU')
        ->call('save')
        ->assertHasNoErrors()
        ->call('edit', $category->id)
        ->set('name', 'Sudaderas con capucha')
        ->set('skuPrefix', 'SC')
        ->call('save')
        ->assertHasNoErrors();

    $category->refresh();

    expect($category->name)->toBe('Sudaderas con capucha')
        ->and($category->slug)->toBe('sudaderas-con-capucha');
});

test('editing a category never moves it to another section', function () {
    $category = Category::factory()->section(StoreSection::Mujer)->create(['name' => 'Vestidos']);

    Livewire::test(Index::class)
        ->set('section', StoreSection::Mujer->value)
        ->call('edit', $category->id)
        ->set('name', 'Vestidos de fiesta')
        ->call('save')
        ->assertHasNoErrors();

    expect($category->fresh()->section)->toBe(StoreSection::Mujer)
        ->and($category->fresh()->slug)->toBe('vestidos-de-fiesta');
});

test('requires a name to create a category', function () {
    Livewire::test(Index::class)
        ->call('create')
        ->call('save')
        ->assertHasErrors(['name'])
        ->assertSee('El nombre es obligatorio.');

    expect(Category::query()->count())->toBe(0);
});

test('rejects a name without letters or numbers', function () {
    Livewire::test(Index::class)
        ->call('create')
        ->set('name', '!!!')
        ->set('skuPrefix', 'XX')
        ->call('save')
        ->assertHasErrors(['name'])
        ->assertSee('El nombre debe contener letras o números.');

    expect(Category::query()->count())->toBe(0);
});

test('rejects a name that collides with another category slug', function () {
    Category::factory()->create(['name' => 'Camisetas', 'slug' => 'camisetas']);

    Livewire::test(Index::class)
        ->call('create')
        ->set('name', 'Camisetas!')
        ->set('skuPrefix', 'CA')
        ->call('save')
        ->assertHasErrors(['name'])
        ->assertSee('Ya existe una categoría con ese nombre.');

    expect(Category::query()->count())->toBe(1);
});

test('requires a SKU prefix to create a category', function () {
    Livewire::test(Index::class)
        ->call('create')
        ->set('name', 'Pantalones')
        ->call('save')
        ->assertHasErrors(['skuPrefix'])
        ->assertSee('El prefijo de SKU es obligatorio.');

    expect(Category::query()->count())->toBe(0);
});

test('rejects a SKU prefix that is not 2 to 4 uppercase letters or numbers', function (string $prefix) {
    Livewire::test(Index::class)
        ->call('create')
        ->set('name', 'Pantalones')
        ->set('skuPrefix', $prefix)
        ->call('save')
        ->assertHasErrors(['skuPrefix'])
        ->assertSee('Usa de 2 a 4 letras o números, sin espacios.');

    expect(Category::query()->count())->toBe(0);
})->with(['P!', 'AB!D', 'ABCDE', 'PL H']);

test('rejects a SKU prefix already used by another category anywhere in the store', function () {
    Category::factory()->section(StoreSection::Mujer)->create(['name' => 'Camisetas', 'slug' => 'camisetas', 'sku_prefix' => 'CA']);

    Livewire::test(Index::class)
        ->call('create')
        ->set('name', 'Pantalones')
        ->set('skuPrefix', 'CA')
        ->call('save')
        ->assertHasErrors(['skuPrefix'])
        ->assertSee('Ya existe otra categoría con ese prefijo.');

    expect(Category::query()->where('name', 'Pantalones')->exists())->toBeFalse();
});

test('stores the SKU prefix uppercased and trimmed', function () {
    Livewire::test(Index::class)
        ->call('create')
        ->set('name', 'Pantalones')
        ->set('skuPrefix', '  pl  ')
        ->call('save')
        ->assertHasNoErrors();

    expect(Category::query()->where('slug', 'pantalones')->sole()->sku_prefix)->toBe('PL');
});

test('lets a category keep its own prefix when edited', function () {
    $category = Category::factory()->create(['name' => 'Pantalones', 'sku_prefix' => 'PA']);

    Livewire::test(Index::class)
        ->call('edit', $category->id)
        ->call('save')
        ->assertHasNoErrors();

    expect($category->fresh()->sku_prefix)->toBe('PA');
});

test('shows the prefix of every category next to its name', function () {
    Category::factory()->create(['name' => 'Camisetas', 'sku_prefix' => 'CMT']);

    Livewire::test(Index::class)
        ->assertSee('Camisetas')
        ->assertSee('CMT');
});

test('toggles a category between active and inactive', function () {
    $category = Category::factory()->create();

    Livewire::test(Index::class)
        ->call('toggleActive', $category->id);

    expect($category->fresh()->is_active)->toBeFalse();

    Livewire::test(Index::class)
        ->call('toggleActive', $category->id);

    expect($category->fresh()->is_active)->toBeTrue();
});

test('blocks deleting a category that has products', function () {
    $category = Category::factory()->create();
    $product = Product::factory()->for($category)->create();

    Livewire::test(Index::class)
        ->call('delete', $category->id)
        ->assertSet('noticeType', 'error')
        ->assertDispatched('toast', function (string $name, array $params) {
            return $name === 'toast'
                && $params['tone'] === 'error'
                && str_contains($params['message'], 'productos asociados');
        });

    $this->assertModelExists($category);
    $this->assertModelExists($product);
});

test('deletes a category without products', function () {
    $category = Category::factory()->create();

    Livewire::test(Index::class)
        ->call('delete', $category->id)
        ->assertSet('noticeType', 'success');

    $this->assertModelMissing($category);
});

/**
 * The sizes belong to the category, so a category is deleted together with them.
 *
 * Without this, the foreign key of `sizes.category_id` restricts the delete and no
 * category of the store could ever be removed, not even one created seconds ago.
 */
test('deletes the sizes of a category that sells nothing', function () {
    $category = Category::factory()->create();
    $sizes = Size::seedStandardSizesFor($category);

    expect($sizes)->toBe(8)
        ->and(Size::query()->where('category_id', $category->id)->count())->toBe(8);

    Livewire::test(Index::class)
        ->call('delete', $category->id)
        ->assertSet('noticeType', 'success');

    $this->assertModelMissing($category);
    expect(Size::query()->where('category_id', $category->id)->count())->toBe(0);
});

test('a refused category keeps its sizes', function () {
    $category = Category::factory()->create();
    Product::factory()->for($category)->create();
    Size::seedStandardSizesFor($category);

    Livewire::test(Index::class)
        ->call('delete', $category->id)
        ->assertSet('noticeType', 'error');

    $this->assertModelExists($category);
    expect(Size::query()->where('category_id', $category->id)->count())->toBe(8);
});

test('the sizes of another category are not touched by a deletion', function () {
    $sought = Category::factory()->create();
    $kept = Category::factory()->create();
    Size::seedStandardSizesFor($sought);
    Size::seedStandardSizesFor($kept);

    (new DeleteCategory)($sought);

    expect(Size::query()->where('category_id', $kept->id)->count())->toBe(8);
});

test('a category created from the panel starts with the eight sizes of the store', function () {
    Livewire::test(Index::class)
        ->call('create')
        ->set('name', 'Pantalones')
        ->set('skuPrefix', 'PA')
        ->call('save')
        ->assertHasNoErrors();

    $category = Category::query()->where('slug', 'pantalones')->sole();

    expect(Size::listedForCategory($category->id)->pluck('name')->all())
        ->toBe(Size::STANDARD_NAMES);
});

test('the sizes of a new category come out active and in order', function () {
    Livewire::test(Index::class)
        ->call('create')
        ->set('name', 'Camisetas')
        ->set('skuPrefix', 'CM')
        ->call('save')
        ->assertHasNoErrors();

    $sizes = Size::listedForCategory(Category::query()->where('slug', 'camisetas')->sole()->id);

    expect($sizes->pluck('order')->all())->toBe([1, 2, 3, 4, 5, 6, 7, 8])
        ->and($sizes->every(fn (Size $size): bool => $size->is_active))->toBeTrue()
        ->and(Size::listedActiveForCategory($sizes->first()->category_id))->toHaveCount(8);
});

/**
 * The two writes are one unit of work, so a category is never left in the store
 * without the sizes it sells in.
 */
test('a category is not left behind when writing its sizes fails', function () {
    Event::listen('eloquent.created: '.Size::class, function (): void {
        throw new RuntimeException('Fallo simulado al escribir las tallas');
    });

    expect(fn () => Livewire::test(Index::class)
        ->call('create')
        ->set('name', 'Pantalones')
        ->set('skuPrefix', 'PA')
        ->call('save'))
        ->toThrow(RuntimeException::class);

    expect(Category::query()->where('slug', 'pantalones')->exists())->toBeFalse()
        ->and(Size::query()->count())->toBe(0);
});

test('announces a created category in the toast stack', function () {
    Livewire::test(Index::class)
        ->call('create')
        ->set('name', 'Pantalones')
        ->set('skuPrefix', 'PA')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('toast', message: 'Categoría creada correctamente.', tone: 'success');
});

test('asks for confirmation through the dialog instead of the native confirm', function () {
    Category::factory()->create(['name' => "Camiseta de John's"]);

    Livewire::test(Index::class)
        ->assertSee('ask-confirm')
        ->assertDontSeeHtml('confirm(');
});

test('guests are redirected to the login screen from the categories page', function () {
    $response = $this->get(route('admin.categories.index'));

    $response->assertRedirect(route('login'));
});

test('returns 403 for authenticated users without the admin role', function () {
    $this->seed(RoleSeeder::class);

    $user = User::factory()->create();
    $user->assignRole('vendedor');

    $response = $this->actingAs($user)->get(route('admin.categories.index'));

    $response->assertForbidden();
});

test('renders the categories page for users with the admin role', function () {
    $this->seed(RoleSeeder::class);

    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $response = $this->actingAs($admin)->get(route('admin.categories.index'));

    $response
        ->assertOk()
        ->assertSee('Categorías')
        ->assertSee('Nueva categoría')
        ->assertDontSee("entered ? 'translate-y-0", false);
});

test('the category form is a full-viewport modal that stays mounted so it can animate its exit', function () {
    $this->seed(RoleSeeder::class);

    Livewire::test(Index::class)
        ->assertSet('showForm', false)
        // Con el formulario cerrado el modal sigue en el DOM. Su visibilidad la
        // gobierna Alpine y no un `@if` de Blade: por eso el cierre tiene un
        // estado real al que volver y se puede interpolar en vez de desaparecer
        // el nodo en el mismo instante en que el servidor borra el formulario.
        ->assertSee('x-data="adminModal($wire)"', false)
        ->assertSee('x-show="$data.open"', false)
        // La entrada y la salida viven en el overlay y en la tarjeta, que son
        // los elementos con `x-show`: Alpine solo interpola la visibilidad de un
        // elemento si él mismo declara la transición.
        ->assertSee('x-transition:enter="duration-[350ms] ease-out"', false)
        ->assertSee('x-transition:leave="duration-[250ms] ease-in"', false)
        ->assertSee('x-transition:enter-start="opacity-0"', false)
        ->assertSee('x-transition:leave-end="translate-y-3 scale-[0.96] opacity-0"', false)
        // El contenedor no puede quedarse interceptando los clics de la página
        // con el modal cerrado, de ahí el `pointer-events-none` permanente y el
        // `pointer-events-auto` limitado al overlay y a la tarjeta.
        ->assertSee('pointer-events-none fixed inset-0', false)
        ->assertSee('pointer-events-auto absolute inset-0 bg-tinta/40', false)
        // Geometría: el overlay cubre el viewport entero y el scroll ocurre
        // dentro de la tarjeta, no en la página de detrás.
        ->assertSee('max-h-[90vh]', false)
        ->assertSee('min-h-0 flex-1', false)
        // El foco entra por el primer campo y no por el botón de cerrar.
        ->assertSee('data-modal-autofocus', false)
        ->call('create')
        ->assertSet('showForm', true)
        ->assertSet('editingId', null);
});

test('the form shows the active section instead of a selector', function () {
    Livewire::test(Index::class)
        ->call('create')
        ->assertSee('Sección')
        ->assertSee('Hombre')
        ->assertDontSeeHtml('<select')
        ->call('setSection', StoreSection::Ninos->value)
        ->call('create')
        ->assertSee('Niños');
});

test('filters categories by name', function () {
    Category::factory()->create(['name' => 'Camisetas', 'slug' => 'camisetas']);
    Category::factory()->create(['name' => 'Pantalones', 'slug' => 'pantalones']);

    Livewire::test(Index::class)
        ->set('search', 'cami')
        ->assertSee('Camisetas')
        ->assertDontSee('Pantalones');
});

test('escapes the search term when it has no results', function () {
    Livewire::test(Index::class)
        ->set('search', '<script>alert(1)</script>')
        ->assertSee('<script>alert(1)</script>');
});

test('moves a category up above its previous one and reindexes the section', function () {
    $first = Category::factory()->create(['name' => 'Camisetas', 'order' => 1]);
    $second = Category::factory()->create(['name' => 'Pantalones', 'order' => 2]);

    Livewire::test(Index::class)
        ->call('moveUp', $second->id);

    // La sección se reescribe a 0..n-1 en el orden mostrado, en vez de
    // intercambiar los dos valores sueltos.
    expect(Category::query()->orderBy('order')->pluck('name')->all())
        ->toBe(['Pantalones', 'Camisetas'])
        ->and($first->fresh()->order)->toBe(1)
        ->and($second->fresh()->order)->toBe(0);
});

test('moves a category down below its next one and reindexes the section', function () {
    $first = Category::factory()->create(['name' => 'Camisetas', 'order' => 1]);
    $second = Category::factory()->create(['name' => 'Pantalones', 'order' => 2]);

    Livewire::test(Index::class)
        ->call('moveDown', $first->id);

    expect(Category::query()->orderBy('order')->pluck('name')->all())
        ->toBe(['Pantalones', 'Camisetas'])
        ->and($first->fresh()->order)->toBe(1)
        ->and($second->fresh()->order)->toBe(0);
});

test('cannot move the first category up or the last one down', function () {
    $first = Category::factory()->create(['name' => 'Camisetas', 'order' => 1]);
    $second = Category::factory()->create(['name' => 'Pantalones', 'order' => 2]);
    $third = Category::factory()->create(['name' => 'Zapatos', 'order' => 3]);

    Livewire::test(Index::class)
        ->call('moveUp', $first->id)
        ->call('moveDown', $third->id);

    expect($first->fresh()->order)->toBe(1)
        ->and($second->fresh()->order)->toBe(2)
        ->and($third->fresh()->order)->toBe(3);
});

test('moves a category down even when another one shares its order value', function () {
    // Estado corrupto reproducido a propósito: dos categorías con order 0.
    $first = Category::factory()->create(['name' => 'Camisetas', 'order' => 0]);
    $second = Category::factory()->create(['name' => 'Gorras', 'order' => 0]);

    Livewire::test(Index::class)
        ->call('moveDown', $first->id);

    expect(Category::query()->orderBy('order')->pluck('name')->all())
        ->toBe(['Gorras', 'Camisetas'])
        ->and($first->fresh()->order)->toBe(1)
        ->and($second->fresh()->order)->toBe(0);
});

test('moves a category up even when another one shares its order value', function () {
    $first = Category::factory()->create(['name' => 'Camisetas', 'order' => 0]);
    $second = Category::factory()->create(['name' => 'Gorras', 'order' => 0]);

    Livewire::test(Index::class)
        ->call('moveUp', $second->id);

    expect(Category::query()->orderBy('order')->pluck('name')->all())
        ->toBe(['Gorras', 'Camisetas'])
        ->and($first->fresh()->order)->toBe(1)
        ->and($second->fresh()->order)->toBe(0);
});

test('reordering one section leaves the other sections untouched', function () {
    $hombreFirst = Category::factory()->section(StoreSection::Hombre)->create(['name' => 'Camisetas', 'order' => 1]);
    $hombreSecond = Category::factory()->section(StoreSection::Hombre)->create(['name' => 'Pantalones', 'order' => 2]);
    $mujerFirst = Category::factory()->section(StoreSection::Mujer)->create(['name' => 'Vestidos', 'order' => 1]);
    $mujerSecond = Category::factory()->section(StoreSection::Mujer)->create(['name' => 'Faldas', 'order' => 2]);

    Livewire::test(Index::class)
        ->call('moveUp', $hombreSecond->id);

    expect($hombreFirst->fresh()->order)->toBe(1)
        ->and($hombreSecond->fresh()->order)->toBe(0)
        ->and($mujerFirst->fresh()->order)->toBe(1)
        ->and($mujerSecond->fresh()->order)->toBe(2);
});

test('assigns consecutive order values when creating categories in sequence', function () {
    $this->seed(RoleSeeder::class);
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    Livewire::actingAs($admin)
        ->test(Index::class)
        ->call('create')
        ->set('name', 'Camisetas')
        ->set('skuPrefix', 'CM')
        ->call('save')
        ->call('create')
        ->set('name', 'Pantalones')
        ->set('skuPrefix', 'PA')
        ->call('save')
        ->call('create')
        ->set('name', 'Gorras')
        ->set('skuPrefix', 'GO')
        ->call('save');

    $orders = Category::query()->orderBy('id')->pluck('order')->all();
    expect($orders)->toBe([0, 1, 2]);
});

test('starts the order of a new section from zero', function () {
    Category::factory()->section(StoreSection::Hombre)->create(['order' => 5]);

    Livewire::test(Index::class)
        ->call('setSection', StoreSection::Ninos->value)
        ->call('create')
        ->set('name', 'Pantalones')
        ->set('skuPrefix', 'PA')
        ->call('save')
        ->assertHasNoErrors();

    expect(Category::query()->where('slug', 'pantalones')->sole()->order)->toBe(0);
});
