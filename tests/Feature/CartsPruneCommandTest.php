<?php

use App\Models\Cart;
use App\Models\User;
use Illuminate\Support\Str;

/*
 * La limpieza de carritos olvidados (`carts:prune`): borra los carritos de
 * visitante sin actividad desde hace 30 días y los de cuenta desde hace 90 por
 * defecto, con sus edades configurables. Para nada importa lo que lleven
 * dentro: un carrito no reserva stock ni guarda precio, así que el barrido no
 * le debe nada a nadie. La actividad es `last_activity_at` y, cuando la fila
 * fue creada sin actividad, cae al `updated_at` y luego al `created_at`.
 */

test('por defecto borra los carritos de visitante con más de 30 días', function (array $atributos, bool $borrado) {
    $carrito = Cart::factory()->create($atributos);

    $this->artisan('carts:prune')
        ->expectsOutputToContain(sprintf('Carritos de visitante borrados: %d.', $borrado ? 1 : 0))
        ->expectsOutputToContain('Carritos de cuenta borrados: 0.')
        ->assertExitCode(0);

    expect(Cart::query()->whereKey($carrito->getKey())->exists())->toBe(! $borrado);
})->with([
    'actividad de 45 días' => [['last_activity_at' => now()->subDays(45)], true],
    'actividad de 29 días' => [['last_activity_at' => now()->subDays(29)], false],
    'sin actividad, actualizado hace 40 días' => [
        ['last_activity_at' => null, 'updated_at' => now()->subDays(40)],
        true,
    ],
]);

test('un carrito que nunca registró actividad se juzga por su updated_at', function () {
    $viejo = Cart::factory()->create([
        'last_activity_at' => null,
        'updated_at' => now()->subDays(40),
        'created_at' => now()->subDays(60),
    ]);
    $reciente = Cart::factory()->create([
        'last_activity_at' => null,
        'updated_at' => now()->subDays(10),
        'created_at' => now()->subDays(10),
    ]);

    $this->artisan('carts:prune')->assertExitCode(0);

    expect(Cart::query()->whereKey($viejo->getKey())->exists())->toBeFalse()
        ->and(Cart::query()->whereKey($reciente->getKey())->exists())->toBeTrue();
});

test('los carritos de cuenta se conservan 90 días por defecto', function () {
    $conservado = Cart::factory()->account(User::factory()->create())->create([
        'last_activity_at' => now()->subDays(80),
    ]);
    $borrado = Cart::factory()->account(User::factory()->create())->create([
        'last_activity_at' => now()->subDays(100),
    ]);

    $this->artisan('carts:prune')
        ->expectsOutputToContain('Carritos de visitante borrados: 0.')
        ->expectsOutputToContain('Carritos de cuenta borrados: 1.')
        ->assertExitCode(0);

    expect(Cart::query()->whereKey($conservado->getKey())->exists())->toBeTrue()
        ->and(Cart::query()->whereKey($borrado->getKey())->exists())->toBeFalse();
});

test('las edades se pueden ajustar por opción', function () {
    $cuenta = User::factory()->create();

    // 61 días: dentro de los 90 por defecto, fuera de los 60 pedidos.
    $borde = Cart::factory()->account($cuenta)->create([
        'last_activity_at' => now()->subDays(61),
    ]);

    $this->artisan('carts:prune', ['--user-days' => 60])
        ->expectsOutputToContain('Carritos de cuenta borrados: 1.')
        ->assertExitCode(0);

    expect(Cart::query()->whereKey($borde->getKey())->exists())->toBeFalse();
});

test('bajar la edad del visitante borra lo que antes se conservaba', function () {
    $viejo = Cart::factory()->create(['last_activity_at' => now()->subDays(2)]);
    $fresco = Cart::factory()->create(['last_activity_at' => now()->subHours(2)]);

    $this->artisan('carts:prune', ['--guest-days' => 1])
        ->expectsOutputToContain('Carritos de visitante borrados: 1.')
        ->assertExitCode(0);

    expect(Cart::query()->whereKey($viejo->getKey())->exists())->toBeFalse()
        ->and(Cart::query()->whereKey($fresco->getKey())->exists())->toBeTrue();
});

test('unas edades inválidas cortan el barrido sin borrar nada', function () {
    $carrito = Cart::factory()->create(['last_activity_at' => now()->subDays(60)]);

    $this->artisan('carts:prune', ['--guest-days' => 'muchos'])
        ->expectsOutputToContain('--guest-days debe ser un entero mayor o igual que cero.')
        ->assertExitCode(2);

    $this->artisan('carts:prune', ['--user-days' => -3])
        ->expectsOutputToContain('--user-days debe ser un entero mayor o igual que cero.')
        ->assertExitCode(2);

    expect(Cart::query()->whereKey($carrito->getKey())->exists())->toBeTrue();
});

test('el barrido conoce ambos tipos a la vez y reporta sus totales', function () {
    Cart::factory()->create(['last_activity_at' => now()->subDays(45)]);
    Cart::factory()->create(['last_activity_at' => now()->subDays(45)]);
    Cart::factory()->account(User::factory()->create())->create(['last_activity_at' => now()->subDays(100)]);
    Cart::factory()->create(['last_activity_at' => now()->subDays(3)]);
    Cart::factory()->account(User::factory()->create())->create(['last_activity_at' => now()->subDays(10)]);

    $this->artisan('carts:prune')
        ->expectsOutputToContain('Carritos de visitante borrados: 2.')
        ->expectsOutputToContain('Carritos de cuenta borrados: 1.')
        ->assertExitCode(0);

    expect(Cart::count())->toBe(2);
});

test('el barrido alcanza a un carrito de cuenta que arrastra una cookie vieja', function () {
    $cuenta = User::factory()->create();

    // Un carrito que ya es de cuenta pero todavía tiene el token, como el que
    // quedó si la fusión no llegó a olvidar la cookie del visitante.
    $fantasma = Cart::factory()->account($cuenta)->create([
        'token' => (string) Str::uuid(),
        'last_activity_at' => now()->subDays(100),
        'updated_at' => now()->subDays(100),
    ]);

    $this->artisan('carts:prune')
        ->expectsOutputToContain('Carritos de cuenta borrados: 1.')
        ->assertExitCode(0);

    expect(Cart::query()->whereKey($fantasma->getKey())->exists())->toBeFalse();
});
