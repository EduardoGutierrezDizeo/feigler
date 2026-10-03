<?php

use App\Enums\StoreSection;
use App\Models\Category;
use App\Models\Product;
use App\Models\Size;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to
| your project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * A category whose prefix is counted, so a second one in the same test does not
 * trip the unique index on `sku_prefix`.
 *
 * It lives here and not in a test file because the products panel and the variants
 * tab share it: a helper defined in a test file is only loaded when that whole
 * file is, so the other file could not be run on its own.
 */
function numberedCategory(string $prefix = 'PL', string $name = 'Prenda', StoreSection $section = StoreSection::Hombre): Category
{
    return Category::factory()->section($section)->create(['sku_prefix' => $prefix, 'name' => $name]);
}

/**
 * An administrator for the panels that ask for one. The roles are expected to be
 * there already, so the tests that need them seed `RoleSeeder` first.
 */
function adminForPanel(): User
{
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    return $admin;
}

/**
 * A size of the category of this product, created when the category does not carry it
 * yet.
 *
 * It lives here and not in a test file because several of them need one: a size only
 * means something inside a category, so a test cannot make one up out of thin air,
 * and the panel offers the sizes the category has rather than a free list.
 */
function sizeOfProduct(Product $product, string $name = 'M'): Size
{
    return Size::query()->firstOrCreate([
        'category_id' => $product->category_id,
        'name' => $name,
    ]);
}
