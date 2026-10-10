<?php

use App\Livewire\Admin\Users\Index;
use App\Models\Address;
use App\Models\Order;
use App\Models\User;
use App\Models\Wishlist;
use App\Services\Storefront\DeleteCustomerAccount;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Mi cuenta: pestaña Eliminar cuenta y baja del cliente
|--------------------------------------------------------------------------
| El cliente borra su cuenta confirmando la contraseña actual en un modal
| (Alpine, sin AJAX). La baja pasa por DeleteCustomerAccount en una sola
| transacción: sin pedidos la fila y sus datos se borran; con pedidos la fila
| se anonimiza y los pedidos conservan su enlace. La contraseña la verifica
| DeleteAccountRequest y los errores van a la bolsa «deleteAccount».
*/

/**
 * Un cliente listo para borrar su cuenta, con los roles sembrados. La fábrica
 * de User deja la contraseña en «password».
 */
function clienteQueElimina(): User
{
    test()->seed(RoleSeeder::class);

    $user = User::factory()->create(['password' => 'password']);
    $user->assignRole('cliente');

    return $user;
}

test('la pestaña Eliminar cuenta aparece cuarta y activa con su texto y el modal de confirmación', function () {
    $user = clienteQueElimina();

    $this->actingAs($user)->get('/cuenta')
        ->assertOk()
        ->assertSeeInOrder(['Perfil', 'Seguridad', 'Direcciones', 'Eliminar cuenta']);

    $this->actingAs($user)->get('/cuenta?tab=eliminar')
        ->assertOk()
        ->assertSeeHtml('href="'.route('account.index', ['tab' => 'eliminar']).'"')
        ->assertSeeHtml('aria-current="page"')
        ->assertSee(config('tienda.eliminar_cuenta_texto'))
        ->assertSee('Eliminar mi cuenta')
        ->assertSeeHtml('role="dialog"')
        ->assertSee('deleteAccountModal')
        ->assertSeeHtml('action="'.route('account.destroy').'"')
        ->assertSeeHtml('name="_method" value="DELETE"')
        ->assertSeeHtml('name="current_password"')
        ->assertSeeHtml('autocomplete="current-password"')
        ->assertDontSeeHtml('name="current_password" value="')
        ->assertDontSee('x-if', escape: false);
});

test('el modal de eliminar cuenta solo está en la pestaña Eliminar cuenta', function () {
    $user = clienteQueElimina();

    foreach (['/cuenta', '/cuenta?tab=perfil', '/cuenta?tab=seguridad', '/tienda'] as $path) {
        $this->actingAs($user)->get($path)
            ->assertOk()
            ->assertDontSee('deleteAccountModal')
            ->assertDontSee('Eliminar mi cuenta')
            ->assertDontSeeHtml('role="dialog"');
    }

    // En Direcciones sí vive el modal de direcciones (con role dialog), pero no
    // el de eliminar cuenta.
    $this->actingAs($user)->get('/cuenta?tab=direcciones')
        ->assertOk()
        ->assertDontSee('deleteAccountModal')
        ->assertDontSee('Eliminar mi cuenta');
});

test('un cliente sin pedidos se borra con sus datos y su correo vuelve a estar libre', function () {
    $user = clienteQueElimina();
    $correo = $user->email;

    Address::factory()->count(2)->for($user)->create();
    Wishlist::factory()->count(2)->for($user)->create();

    $this->actingAs($user)
        ->from('/cuenta?tab=eliminar')
        ->delete(route('account.destroy'), ['current_password' => 'password'])
        ->assertSessionHasNoErrors()
        ->assertRedirect('/')
        ->assertSessionHas('status', 'account-deleted');

    $this->assertGuest();

    expect(User::query()->whereKey($user->id)->exists())->toBeFalse()
        ->and(Address::query()->where('user_id', $user->id)->count())->toBe(0)
        ->and(Wishlist::query()->where('user_id', $user->id)->count())->toBe(0);

    $this->get('/')
        ->assertOk()
        ->assertSee('Tu cuenta fue eliminada.');

    $this->post('/register', [
        'name' => 'Ana',
        'last_name' => 'Gómez',
        'email' => $correo,
        'phone' => '3001234567',
        'password' => 'password',
        'password_confirmation' => 'password',
        'terms' => '1',
    ])->assertRedirect(route('account.index'));
});

test('con la contraseña incorrecta o vacía no se borra nada y el modal arranca abierto con el error', function (array $payload, string $message) {
    $user = clienteQueElimina();

    $this->actingAs($user)
        ->from('/cuenta?tab=eliminar')
        ->delete(route('account.destroy'), $payload)
        ->assertRedirect('/cuenta?tab=eliminar')
        ->assertSessionHasErrorsIn('deleteAccount', 'current_password');

    $this->assertAuthenticatedAs($user);

    expect(User::query()->whereKey($user->id)->exists())->toBeTrue();

    $this->get('/cuenta?tab=eliminar')
        ->assertOk()
        ->assertSeeHtml('x-data="deleteAccountModal({ open: true })"')
        ->assertSee($message);
})->with([
    'incorrecta' => [['current_password' => 'incorrecta'], 'La contraseña es incorrecta.'],
    'vacía' => [['current_password' => ''], 'La contraseña es obligatoria.'],
]);

test('un cliente con pedidos se anonimiza y sus pedidos y su id se conservan', function () {
    $user = clienteQueElimina();
    $correo = $user->email;
    $hashAnterior = $user->password;

    Order::factory()->count(2)->for($user)->create();
    Address::factory()->count(2)->for($user)->create();
    Wishlist::factory()->count(2)->for($user)->create();

    $user->forceFill(['terms_accepted_at' => now()->subDays(3)])->save();

    $this->actingAs($user)
        ->from('/cuenta?tab=eliminar')
        ->delete(route('account.destroy'), ['current_password' => 'password'])
        ->assertSessionHasNoErrors()
        ->assertRedirect('/')
        ->assertSessionHas('status', 'account-deleted');

    $this->assertGuest();

    $fila = User::query()->whereKey($user->id)->first();

    expect($fila)->not->toBeNull()
        ->and($fila->name)->toBe('Cliente eliminado')
        ->and($fila->last_name)->toBeNull()
        ->and($fila->phone)->toBeNull()
        ->and($fila->email)->toEndWith('@eliminado.invalid')
        ->and($fila->email)->not->toBe($correo)
        ->and($fila->password)->not->toBe($hashAnterior)
        ->and($fila->is_active)->toBeFalse()
        ->and($fila->email_verified_at)->toBeNull()
        ->and($fila->terms_accepted_at)->not->toBeNull();

    expect(Address::query()->where('user_id', $user->id)->count())->toBe(0)
        ->and(Wishlist::query()->where('user_id', $user->id)->count())->toBe(0);

    $orders = Order::query()->where('user_id', $user->id)->get();

    expect($orders)->toHaveCount(2)
        ->and($orders->pluck('user_id')->unique()->values()->all())->toBe([$user->id]);

    $this->post('/login', [
        'email' => $correo,
        'password' => 'password',
    ])->assertSessionHasErrors('email');

    $this->assertGuest();
});

test('dos clientes anonimizados conservan correos únicos', function () {
    $cliente = clienteQueElimina();
    $cliente2 = clienteQueElimina();

    Order::factory()->for($cliente)->create();
    Order::factory()->for($cliente2)->create();

    foreach ([$cliente, $cliente2] as $cuenta) {
        $this->actingAs($cuenta)
            ->from('/cuenta?tab=eliminar')
            ->delete(route('account.destroy'), ['current_password' => 'password'])
            ->assertRedirect('/');
    }

    expect(User::query()->whereKey($cliente->id)->value('email'))
        ->not->toBe(User::query()->whereKey($cliente2->id)->value('email'));
});

test('el cliente anonimizado no aparece en el panel de Usuarios', function () {
    $user = clienteQueElimina();
    $admin = adminForPanel();

    Order::factory()->for($user)->create();

    $this->actingAs($user)
        ->delete(route('account.destroy'), ['current_password' => 'password'])
        ->assertRedirect('/');

    Livewire::actingAs($admin)->test(Index::class)
        ->assertDontSee('Cliente eliminado')
        ->assertDontSee('@eliminado.invalid');
});

test('un vendedor y un administrador reciben 403 y un invitado va al login en DELETE /cuenta', function () {
    $this->seed(RoleSeeder::class);

    foreach (['admin', 'vendedor'] as $rol) {
        $staff = User::factory()->create(['password' => 'password']);
        $staff->assignRole($rol);

        $this->actingAs($staff)
            ->delete(route('account.destroy'), ['current_password' => 'password'])
            ->assertForbidden();
    }

    $this->app['auth']->forgetGuards();

    $this->delete(route('account.destroy'), ['current_password' => 'password'])
        ->assertRedirect(route('login'));
});

test('los campos extra se ignoran y nunca se borra a otro usuario', function () {
    $cliente = clienteQueElimina();
    $otro = User::factory()->create(['password' => 'password']);

    $this->actingAs($cliente)
        ->from('/cuenta?tab=eliminar')
        ->delete(route('account.destroy'), [
            'current_password' => 'password',
            'user_id' => $otro->id,
            'id' => $otro->id,
            'email' => $otro->email,
        ])
        ->assertRedirect('/');

    expect(User::query()->whereKey($otro->id)->exists())->toBeTrue()
        ->and(User::query()->whereKey($cliente->id)->exists())->toBeFalse();
});

test('el sexto intento de eliminación en un minuto recibe 429', function () {
    $user = clienteQueElimina();

    $this->actingAs($user);

    foreach (range(1, 5) as $attempt) {
        $this->from('/cuenta?tab=eliminar')
            ->delete(route('account.destroy'), ['current_password' => 'incorrecta'])
            ->assertStatus(302);
    }

    $this->from('/cuenta?tab=eliminar')
        ->delete(route('account.destroy'), ['current_password' => 'incorrecta'])
        ->assertStatus(429);
});

test('las sesiones activas y los tokens de restablecimiento del correo se limpian', function () {
    $user = clienteQueElimina();

    DB::table('sessions')->insert([
        'id' => Str::random(40),
        'user_id' => $user->id,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'pest',
        'payload' => 'dummy',
        'last_activity' => now()->timestamp,
    ]);

    DB::table('password_reset_tokens')->insert([
        'email' => $user->email,
        'token' => Str::random(60),
        'created_at' => now(),
    ]);

    $this->actingAs($user)
        ->from('/cuenta?tab=eliminar')
        ->delete(route('account.destroy'), ['current_password' => 'password'])
        ->assertRedirect('/');

    expect(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(0)
        ->and(DB::table('password_reset_tokens')->where('email', $user->email)->count())->toBe(0);
});

test('el borrado es atómico: un fallo al final deja todo como estaba', function () {
    $user = clienteQueElimina();

    Address::factory()->for($user)->create();

    DB::table('sessions')->insert([
        'id' => Str::random(40),
        'user_id' => $user->id,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'pest',
        'payload' => 'dummy',
        'last_activity' => now()->timestamp,
    ]);

    Log::shouldReceive('info')
        ->once()
        ->withArgs(function (string $event, array $context): bool {
            return $event === 'account.deleted';
        })
        ->andThrow(new RuntimeException('fallo forzado'));

    expect(fn () => app(DeleteCustomerAccount::class)->delete($user))
        ->toThrow(RuntimeException::class, 'fallo forzado');

    expect(User::query()->whereKey($user->id)->exists())->toBeTrue()
        ->and(Address::query()->where('user_id', $user->id)->count())->toBe(1)
        ->and(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(1);
});

test('la anonimización es atómica: un fallo al final deja al cliente intacto', function () {
    $user = clienteQueElimina();

    Order::factory()->for($user)->create();
    Address::factory()->for($user)->create();

    Log::shouldReceive('info')
        ->once()
        ->andThrow(new RuntimeException('fallo forzado'));

    expect(fn () => app(DeleteCustomerAccount::class)->delete($user))
        ->toThrow(RuntimeException::class, 'fallo forzado');

    $user->refresh();

    expect($user->name)->not->toBe('Cliente eliminado')
        ->and($user->is_active)->toBeTrue()
        ->and($user->email)->not->toEndWith('@eliminado.invalid')
        ->and(Address::query()->where('user_id', $user->id)->count())->toBe(1)
        ->and(Order::query()->where('user_id', $user->id)->count())->toBe(1);
});

test('ni la vista de la pestaña ni la respuesta usan x-if', function () {
    $user = clienteQueElimina();

    $this->actingAs($user)->get('/cuenta?tab=eliminar')
        ->assertOk()
        ->assertDontSee('x-if', escape: false);
});

test('la pestaña Eliminar cuenta hace un número constante de consultas', function () {
    $user = clienteQueElimina();

    $medir = function (string $url) use ($user): int {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->actingAs($user)->get($url)->assertOk();

        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    // La primera visita calienta el caché de roles de spatie; se miden en caliente.
    $medir('/cuenta?tab=perfil');

    $perfil = $medir('/cuenta?tab=perfil');
    $eliminar = $medir('/cuenta?tab=eliminar');

    $this->actingAs($user)
        ->from('/cuenta?tab=eliminar')
        ->delete(route('account.destroy'), ['current_password' => 'incorrecta']);

    $conError = $medir('/cuenta?tab=eliminar');

    expect($eliminar)->toBe($perfil)
        ->and($conError)->toBe($perfil);
});
