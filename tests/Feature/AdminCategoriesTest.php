<?php

use App\Livewire\Admin\Categories\Index;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Livewire\Livewire;

test('lists categories and their subcategories', function () {
    $root = Category::factory()->create(['name' => 'Camisetas', 'slug' => 'camisetas']);
    Category::factory()->for($root, 'parent')->create(['name' => 'Camisetas básicas', 'slug' => 'camisetas-basicas']);

    Livewire::test(Index::class)
        ->call('toggleExpanded', $root->id)
        ->assertSee('Categorías')
        ->assertSee('Camisetas')
        ->assertSee('Camisetas básicas');
});

test('creates a root category with a generated slug', function () {
    Livewire::test(Index::class)
        ->call('create')
        ->set('name', 'Pantalones')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('showForm', false)
        ->assertSet('notice', 'Categoría creada correctamente.');

    $category = Category::query()->where('slug', 'pantalones')->sole();

    expect($category->name)->toBe('Pantalones')
        ->and($category->parent_id)->toBeNull();
});

test('creates a valid subcategory under a root category', function () {
    $root = Category::factory()->create();

    Livewire::test(Index::class)
        ->call('create')
        ->set('name', 'Camisetas básicas')
        ->set('parentId', $root->id)
        ->call('save')
        ->assertHasNoErrors();

    $category = Category::query()->where('slug', 'camisetas-basicas')->sole();

    expect($category->name)->toBe('Camisetas básicas')
        ->and($category->parent->is($root))->toBeTrue();
});

test('creates a root category without offering a parent selector', function () {
    Livewire::test(Index::class)
        ->call('create')
        ->assertDontSeeHtml('<select')
        ->assertSee('Se creará como categoría raíz.')
        ->set('name', 'Pantalones')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('notice', 'Categoría creada correctamente.');

    $category = Category::query()->where('slug', 'pantalones')->sole();

    expect($category->name)->toBe('Pantalones')
        ->and($category->parent_id)->toBeNull();
});

test('creates a subcategory from a root row without selecting the parent', function () {
    $root = Category::factory()->create(['name' => 'Camisetas']);

    Livewire::test(Index::class)
        ->call('createSubcategory', $root->id)
        ->assertSet('parentId', $root->id)
        ->assertSee('Nueva subcategoría')
        ->assertSee('Creando subcategoría dentro de: Camisetas')
        ->assertDontSeeHtml('<select')
        ->set('name', 'Camisetas básicas')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('notice', 'Categoría creada correctamente.');

    $category = Category::query()->where('slug', 'camisetas-basicas')->sole();

    expect($category->name)->toBe('Camisetas básicas')
        ->and($category->parent->is($root))->toBeTrue();
});

test('generates a new slug when editing a category', function () {
    $category = Category::factory()->create(['name' => 'Sudaderas', 'slug' => 'sudaderas']);

    Livewire::test(Index::class)
        ->call('edit', $category->id)
        ->assertSet('editingId', $category->id)
        ->assertSet('name', 'Sudaderas')
        ->call('save')
        ->assertHasNoErrors()
        ->call('edit', $category->id)
        ->set('name', 'Sudaderas con capucha')
        ->call('save')
        ->assertHasNoErrors();

    $category->refresh();

    expect($category->name)->toBe('Sudaderas con capucha')
        ->and($category->slug)->toBe('sudaderas-con-capucha');
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
        ->call('save')
        ->assertHasErrors(['name'])
        ->assertSee('Ya existe una categoría con ese nombre.');

    expect(Category::query()->count())->toBe(1);
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

test('blocks deleting a category that has subcategories', function () {
    $root = Category::factory()->create();
    $child = Category::factory()->for($root, 'parent')->create();

    Livewire::test(Index::class)
        ->call('delete', $root->id)
        ->assertSet('noticeType', 'error')
        ->assertDispatched('toast', function (string $name, array $params) {
            return $name === 'toast'
                && $params['tone'] === 'error'
                && str_contains($params['message'], 'subcategorías');
        });

    $this->assertModelExists($root);
    $this->assertModelExists($child);
});

test('announces a created category in the toast stack', function () {
    Livewire::test(Index::class)
        ->call('create')
        ->set('name', 'Pantalones')
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
        ->assertSee('Nueva categoría');
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

test('rejects a subcategory as the parent', function () {
    $root = Category::factory()->create();
    $child = Category::factory()->for($root, 'parent')->create();

    Livewire::test(Index::class)
        ->call('create')
        ->set('name', 'Categoría nieto')
        ->set('parentId', $child->id)
        ->call('save')
        ->assertHasErrors(['parentId'])
        ->assertSee('Solo puedes elegir una categoría raíz como categoría padre.');

    expect(Category::query()->where('name', 'Categoría nieto')->exists())->toBeFalse();
});

test('marks subcategories as collapsed until their root is expanded', function () {
    $root = Category::factory()->create(['name' => 'Camisetas']);
    Category::factory()->for($root, 'parent')->create(['name' => 'Camisetas básicas']);

    Livewire::test(Index::class)
        ->assertSeeHtml('data-expanded="false"');
});

test('expands and collapses a root to show and hide its subcategories', function () {
    $root = Category::factory()->create(['name' => 'Camisetas']);
    Category::factory()->for($root, 'parent')->create(['name' => 'Camisetas básicas']);

    Livewire::test(Index::class)
        ->assertSeeHtml('data-expanded="false"')
        ->call('toggleExpanded', $root->id)
        ->assertSeeHtml('data-expanded="true"')
        ->call('toggleExpanded', $root->id)
        ->assertSeeHtml('data-expanded="false"');
});

test('auto-expands a root when the search matches one of its subcategories', function () {
    $root = Category::factory()->create(['name' => 'Camisetas']);
    Category::factory()->for($root, 'parent')->create(['name' => 'Camisetas básicas']);
    Category::factory()->create(['name' => 'Pantalones']);

    Livewire::test(Index::class)
        ->set('search', 'básicas')
        ->assertSee('Camisetas')
        ->assertSee('Camisetas básicas')
        ->assertDontSee('Pantalones');
});

test('moves a root category up by swapping order with its previous sibling', function () {
    $first = Category::factory()->create(['name' => 'Camisetas', 'order' => 1]);
    $second = Category::factory()->create(['name' => 'Pantalones', 'order' => 2]);

    Livewire::test(Index::class)
        ->call('moveUp', $second->id);

    expect($first->fresh()->order)->toBe(2)
        ->and($second->fresh()->order)->toBe(1);
});

test('moves a root category down by swapping order with its next sibling', function () {
    $first = Category::factory()->create(['name' => 'Camisetas', 'order' => 1]);
    $second = Category::factory()->create(['name' => 'Pantalones', 'order' => 2]);

    Livewire::test(Index::class)
        ->call('moveDown', $first->id);

    expect($first->fresh()->order)->toBe(2)
        ->and($second->fresh()->order)->toBe(1);
});

test('moves a subcategory up within its own parent', function () {
    $root = Category::factory()->create(['name' => 'Camisetas']);
    $first = Category::factory()->for($root, 'parent')->create(['name' => 'Básicas', 'order' => 1]);
    $second = Category::factory()->for($root, 'parent')->create(['name' => 'Deportivas', 'order' => 2]);

    Livewire::test(Index::class)
        ->call('moveUp', $second->id);

    expect($first->fresh()->order)->toBe(2)
        ->and($second->fresh()->order)->toBe(1)
        ->and($root->fresh()->order)->toBe(0);
});

test('reordering subcategories of a root does not affect another root ordering', function () {
    $rootA = Category::factory()->create(['name' => 'Camisetas']);
    $rootB = Category::factory()->create(['name' => 'Pantalones']);

    $aFirst = Category::factory()->for($rootA, 'parent')->create(['name' => 'Básicas', 'order' => 1]);
    $aSecond = Category::factory()->for($rootA, 'parent')->create(['name' => 'Deportivas', 'order' => 2]);
    $bFirst = Category::factory()->for($rootB, 'parent')->create(['name' => 'Slim', 'order' => 1]);
    $bSecond = Category::factory()->for($rootB, 'parent')->create(['name' => 'Anchos', 'order' => 2]);

    Livewire::test(Index::class)
        ->call('moveUp', $aSecond->id);

    expect($aFirst->fresh()->order)->toBe(2)
        ->and($aSecond->fresh()->order)->toBe(1)
        ->and($rootA->fresh()->order)->toBe(0)
        ->and($rootB->fresh()->order)->toBe(0)
        ->and($bFirst->fresh()->order)->toBe(1)
        ->and($bSecond->fresh()->order)->toBe(2);
});

test('cannot move the first sibling up or the last sibling down', function () {
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
