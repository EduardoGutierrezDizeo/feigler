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
use Spatie\Permission\PermissionRegistrar;

/**
 * The categories panel, as an administrator.
 *
 * The panel asks for the admin role again inside the component, not only on the route,
 * so every test that opens it has to arrive as somebody who has that role. The role is
 * the one thing all of these tests have in common and none of them is about, which is
 * why it lives here instead of in a `beforeEach`: the HTTP tests below check what
 * happens to a guest and to a `vendedor`, and a `beforeEach` would have them logging
 * in only to log out again.
 */
function panelDeCategorias(mixed $admin = null): mixed
{
    return Livewire::actingAs($admin ?? adminForPanel())->test(Index::class);
}

/**
 * The roles are a precondition of the fixture and not part of what any test here
 * checks, so they are seeded once for the whole file rather than in each test.
 */
beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
});

test('lists only the categories of the active section', function () {
    $hombre = Category::factory()->section(StoreSection::Hombre)->create(['name' => 'Polos']);
    $mujer = Category::factory()->section(StoreSection::Mujer)->create(['name' => 'Vestidos']);

    panelDeCategorias()
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

    panelDeCategorias()
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
    panelDeCategorias()
        ->set('section', 'invalida')
        ->call('setSection', 'invalida')
        ->assertSet('section', StoreSection::Hombre->value);
});

test('switching section discards the search and dismisses the form', function () {
    Category::factory()->create(['name' => 'Camisetas']);

    panelDeCategorias()
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
    panelDeCategorias()
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
    panelDeCategorias()
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

    panelDeCategorias()
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

    panelDeCategorias()
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

    panelDeCategorias()
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

    panelDeCategorias()
        ->set('section', StoreSection::Mujer->value)
        ->call('edit', $category->id)
        ->set('name', 'Vestidos de fiesta')
        ->call('save')
        ->assertHasNoErrors();

    expect($category->fresh()->section)->toBe(StoreSection::Mujer)
        ->and($category->fresh()->slug)->toBe('vestidos-de-fiesta');
});

test('requires a name to create a category', function () {
    panelDeCategorias()
        ->call('create')
        ->call('save')
        ->assertHasErrors(['name'])
        ->assertSee('El nombre es obligatorio.');

    expect(Category::query()->count())->toBe(0);
});

test('rejects a name without letters or numbers', function () {
    panelDeCategorias()
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

    panelDeCategorias()
        ->call('create')
        ->set('name', 'Camisetas!')
        ->set('skuPrefix', 'CA')
        ->call('save')
        ->assertHasErrors(['name'])
        ->assertSee('Ya existe una categoría con ese nombre.');

    expect(Category::query()->count())->toBe(1);
});

test('requires a SKU prefix to create a category', function () {
    panelDeCategorias()
        ->call('create')
        ->set('name', 'Pantalones')
        ->call('save')
        ->assertHasErrors(['skuPrefix'])
        ->assertSee('El prefijo de SKU es obligatorio.');

    expect(Category::query()->count())->toBe(0);
});

test('rejects a SKU prefix that is not 2 to 4 uppercase letters or numbers', function (string $prefix) {
    panelDeCategorias()
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

    panelDeCategorias()
        ->call('create')
        ->set('name', 'Pantalones')
        ->set('skuPrefix', 'CA')
        ->call('save')
        ->assertHasErrors(['skuPrefix'])
        ->assertSee('Ya existe otra categoría con ese prefijo.');

    expect(Category::query()->where('name', 'Pantalones')->exists())->toBeFalse();
});

test('stores the SKU prefix uppercased and trimmed', function () {
    panelDeCategorias()
        ->call('create')
        ->set('name', 'Pantalones')
        ->set('skuPrefix', '  pl  ')
        ->call('save')
        ->assertHasNoErrors();

    expect(Category::query()->where('slug', 'pantalones')->sole()->sku_prefix)->toBe('PL');
});

test('lets a category keep its own prefix when edited', function () {
    $category = Category::factory()->create(['name' => 'Pantalones', 'sku_prefix' => 'PA']);

    panelDeCategorias()
        ->call('edit', $category->id)
        ->call('save')
        ->assertHasNoErrors();

    expect($category->fresh()->sku_prefix)->toBe('PA');
});

test('shows the prefix of every category next to its name', function () {
    Category::factory()->create(['name' => 'Camisetas', 'sku_prefix' => 'CMT']);

    panelDeCategorias()
        ->assertSee('Camisetas')
        ->assertSee('CMT');
});

test('toggles a category between active and inactive', function () {
    $category = Category::factory()->create();

    panelDeCategorias()
        ->call('toggleActive', $category->id);

    expect($category->fresh()->is_active)->toBeFalse();

    panelDeCategorias()
        ->call('toggleActive', $category->id);

    expect($category->fresh()->is_active)->toBeTrue();
});

test('blocks deleting a category that has products', function () {
    $category = Category::factory()->create();
    $product = Product::factory()->for($category)->create();

    panelDeCategorias()
        ->call('delete', $category->id)
        ->assertSet('notice', "No se puede eliminar «{$category->name}» porque tiene productos asociados. Primero mueve esos productos a otra categoría o elimínalos.")
        ->assertSet('noticeType', 'error')
        // El aviso no se lee del estado del componente, sino del evento `toast` que
        // consume el puente de resources/js/admin.js. Se comprueba el payload
        // entero y no solo el `tone`: si el nombre del evento o las claves viajan
        // mal, el toast se descarta en silencio y el panel queda sin explicar por
        // que la categoria no se borro.
        ->assertDispatched('toast', function (string $name, array $params) use ($category) {
            return $name === 'toast'
                && $params === [
                    'message' => "No se puede eliminar «{$category->name}» porque tiene productos asociados. Primero mueve esos productos a otra categoría o elimínalos.",
                    'tone' => 'error',
                ];
        });

    $this->assertModelExists($category);
    $this->assertModelExists($product);
});

test('deletes a category without products', function () {
    $category = Category::factory()->create();

    panelDeCategorias()
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

    panelDeCategorias()
        ->call('delete', $category->id)
        ->assertSet('noticeType', 'success');

    $this->assertModelMissing($category);
    expect(Size::query()->where('category_id', $category->id)->count())->toBe(0);
});

test('a refused category keeps its sizes', function () {
    $category = Category::factory()->create();
    Product::factory()->for($category)->create();
    Size::seedStandardSizesFor($category);

    panelDeCategorias()
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
    panelDeCategorias()
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
    panelDeCategorias()
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

    expect(fn () => panelDeCategorias()
        ->call('create')
        ->set('name', 'Pantalones')
        ->set('skuPrefix', 'PA')
        ->call('save'))
        ->toThrow(RuntimeException::class);

    expect(Category::query()->where('slug', 'pantalones')->exists())->toBeFalse()
        ->and(Size::query()->count())->toBe(0);
});

test('announces a created category in the toast stack', function () {
    panelDeCategorias()
        ->call('create')
        ->set('name', 'Pantalones')
        ->set('skuPrefix', 'PA')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('toast', message: 'Categoría creada correctamente.', tone: 'success');
});

test('asks for confirmation through the dialog instead of the native confirm', function () {
    Category::factory()->create(['name' => "Camiseta de John's"]);

    panelDeCategorias()
        ->assertSee('ask-confirm')
        ->assertDontSeeHtml('confirm(');
});

test('guests are redirected to the login screen from the product details page', function () {
    $response = $this->get(route('admin.product-details.index'));

    $response->assertRedirect(route('login'));
});

/**
 * The address of the panel is now one of four tabs, so the access rules are asked of
 * the page as a whole rather than of the block of categories that used to have its own.
 */
test('returns 403 for authenticated users without the admin role', function () {
    $user = User::factory()->create();
    $user->assignRole('vendedor');

    $response = $this->actingAs($user)->get(route('admin.product-details.index'));

    $response->assertForbidden();
});

test('renders the product details page for users with the admin role', function () {
    $response = $this->actingAs(adminForPanel())->get(route('admin.product-details.index'));

    $response
        ->assertOk()
        ->assertSee('Detalles de productos')
        ->assertSee('Nueva categoría')
        ->assertDontSee("entered ? 'translate-y-0", false);
});

/**
 * The block of categories moved inside the page of the four catalogs, but it is still
 * its own Livewire component, and `/livewire/update` does not pass through the
 * middleware of the page that rendered it. Without a guard inside the component, a
 * `vendedor` who never saw the page could still open a snapshot of the block and call
 * its methods by hand.
 */
test('a user without the admin role cannot mount the categories block', function () {
    $user = User::factory()->create();
    $user->assignRole('vendedor');

    Livewire::actingAs($user)
        ->test(Index::class)
        ->assertForbidden();
});

/**
 * The role is asked again on every request, not only on the mount.
 *
 * This is the request that carries the snapshot, so it is where a method call arrives:
 * the panel was opened by an administrator who has since lost the role. Every method is
 * checked on its own, because a 403 leaves no snapshot behind to chain the next call
 * onto.
 */
test('an admin who loses the role can no longer act on the categories block', function (string $metodo, bool $conId) {
    $admin = adminForPanel();

    $categoria = Category::factory()->create(['name' => 'Camisetas']);
    $otra = Category::factory()->create(['name' => 'Gorras']);

    $panel = panelDeCategorias($admin);

    // El rol se va con la página ya abierta, que es justo lo que un snapshot guardado
    // en el navegador permite hacer.
    $admin->syncRoles([]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $panel->call($metodo, ...($conId ? [$categoria->getKey()] : []))->assertForbidden();

    // Y nada de eso tocó la base.
    expect($categoria->fresh()->name)->toBe('Camisetas')
        ->and($otra->fresh()->name)->toBe('Gorras');
})->with([
    'setSection' => ['setSection', true],
    'create' => ['create', false],
    'save' => ['save', false],
    'edit' => ['edit', true],
    'closeForm' => ['closeForm', false],
    'toggleActive' => ['toggleActive', true],
    'delete' => ['delete', true],
    'moveUp' => ['moveUp', true],
    'moveDown' => ['moveDown', true],
]);

test('even a guest cannot mount the categories block', function () {
    Livewire::test(Index::class)
        ->assertForbidden();
});

test('the category form is a full-viewport modal that stays mounted so it can animate its exit', function () {
    $this->seed(RoleSeeder::class);

    panelDeCategorias()
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
    panelDeCategorias()
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

    panelDeCategorias()
        ->set('search', 'cami')
        ->assertSee('Camisetas')
        ->assertDontSee('Pantalones');
});

test('escapes the search term when it has no results', function () {
    panelDeCategorias()
        ->set('search', '<script>alert(1)</script>')
        ->assertSee('<script>alert(1)</script>');
});

test('moves a category up above its previous one and reindexes the section', function () {
    $first = Category::factory()->create(['name' => 'Camisetas', 'order' => 1]);
    $second = Category::factory()->create(['name' => 'Pantalones', 'order' => 2]);

    panelDeCategorias()
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

    panelDeCategorias()
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

    panelDeCategorias()
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

    panelDeCategorias()
        ->call('moveDown', $first->id);

    expect(Category::query()->orderBy('order')->pluck('name')->all())
        ->toBe(['Gorras', 'Camisetas'])
        ->and($first->fresh()->order)->toBe(1)
        ->and($second->fresh()->order)->toBe(0);
});

test('moves a category up even when another one shares its order value', function () {
    $first = Category::factory()->create(['name' => 'Camisetas', 'order' => 0]);
    $second = Category::factory()->create(['name' => 'Gorras', 'order' => 0]);

    panelDeCategorias()
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

    panelDeCategorias()
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

    panelDeCategorias()
        ->call('setSection', StoreSection::Ninos->value)
        ->call('create')
        ->set('name', 'Pantalones')
        ->set('skuPrefix', 'PA')
        ->call('save')
        ->assertHasNoErrors();

    expect(Category::query()->where('slug', 'pantalones')->sole()->order)->toBe(0);
});
