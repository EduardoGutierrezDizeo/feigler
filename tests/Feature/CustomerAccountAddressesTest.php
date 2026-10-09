<?php

use App\Models\Address;
use App\Models\User;
use App\Support\ColombiaLocations;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Mi cuenta: pestaña Direcciones
|--------------------------------------------------------------------------
| La pestaña vive en App\Enums\AccountTab y recibe los datos de ubicación solo
| cuando es la activa. El alta y la edición pasan por un formulario con los
| mensajes en la bolsa «address», que hace que la pestaña reabra el modal en el
| modo correcto cuando falla. Las reglas de negocio (límite, predeterminada)
| viven en App\Services\Storefront\CustomerAddresses y los nombres oficiales de
| departamento y ciudad salen de ColombiaLocations por el código DANE, nunca del
| texto del cliente.
*/

/**
 * Crea la lista de datos válidos para enviar, sobreescribibles por caso.
 */
function datosDireccion(array $overrides = []): array
{
    return array_merge([
        'recipient_name' => 'Ana Gómez',
        'phone' => '+57 300 123 4567',
        'department_code' => '54',
        'city_code' => '54498',
        'label' => 'Casa',
        'line1' => 'Calle 4 #10-20',
        'line2' => 'Apto 302',
        'instructions' => 'Edificio verde',
    ], $overrides);
}

/**
 * Un cliente listo para usar, con los roles sembrados.
 */
function clienteConCuenta(): User
{
    test()->seed(RoleSeeder::class);

    $user = User::factory()->create();
    $user->assignRole('cliente');

    return $user;
}

test('los datos de ubicación son los municipios DANE completos y con códigos válidos', function () {
    $departamentos = ColombiaLocations::departments();
    $todos = collect(ColombiaLocations::citiesByDepartment())->flatten(1)->all();

    expect(count($departamentos))->toBe(33)
        ->and(count($todos))->toBeGreaterThan(1000);

    $codigos = array_column($todos, 'code');

    expect(count($codigos))->toBe(count(array_unique($codigos)));

    foreach ($todos as $ciudad) {
        expect(strlen($ciudad['code']))->toBe(5)
            ->and($ciudad['department'])->toBe(substr($ciudad['code'], 0, 2));
    }

    expect(ColombiaLocations::department('11'))->toMatchArray(['name' => 'BOGOTÁ, D.C.'])
        ->and(ColombiaLocations::city('11001'))->toMatchArray(['name' => 'BOGOTÁ, D.C.', 'department' => '11'])
        ->and(ColombiaLocations::cityBelongsToDepartment('11001', '11'))->toBeTrue()
        ->and(ColombiaLocations::city('54001')['name'])->toBe('SAN JOSÉ DE CÚCUTA')
        ->and(ColombiaLocations::city('05001')['name'])->toBe('MEDELLÍN')
        ->and(ColombiaLocations::city('54498'))->toMatchArray(['name' => 'OCAÑA', 'department' => '54'])
        ->and(ColombiaLocations::cityBelongsToDepartment('54498', '54'))->toBeTrue();
});

test('la pestaña Direcciones aparece tras Perfil y Seguridad, activa y con su estado vacío', function () {
    $user = clienteConCuenta();

    $this->actingAs($user)->get('/cuenta?tab=direcciones')
        ->assertOk()
        ->assertSeeInOrder(['Perfil', 'Seguridad', 'Direcciones'])
        ->assertSeeHtml('href="'.route('account.index', ['tab' => 'direcciones']).'"')
        ->assertSeeHtml('aria-current="page"')
        ->assertSee('Agregar dirección')
        ->assertSeeInOrder(['Agregar dirección', 'Aún no tienes direcciones guardadas.']);
});

test('un cliente guarda una dirección completa, la primera queda predeterminada y redirige con el mensaje', function () {
    $user = clienteConCuenta();

    $this->actingAs($user)->from('/cuenta?tab=direcciones')
        ->post('/cuenta/direcciones', datosDireccion())
        ->assertRedirect('/cuenta?tab=direcciones')
        ->assertSessionHas('status', 'address-stored');

    $address = $user->addresses()->sole();

    expect($address->recipient_name)->toBe('Ana Gómez')
        ->and($address->phone)->toBe('3001234567')
        ->and($address->department_code)->toBe('54')
        ->and($address->department)->toBe('NORTE DE SANTANDER')
        ->and($address->city_code)->toBe('54498')
        ->and($address->city)->toBe('OCAÑA')
        ->and($address->label)->toBe('Casa')
        ->and($address->line1)->toBe('Calle 4 #10-20')
        ->and($address->line2)->toBe('Apto 302')
        ->and($address->instructions)->toBe('Edificio verde')
        ->and($address->is_default)->toBeTrue();

    $this->actingAs($user)->get('/cuenta?tab=direcciones')
        ->assertOk()
        ->assertSee('Dirección guardada.')
        ->assertSee('Quien recibe')
        ->assertSee('OCAÑA · NORTE DE SANTANDER');
});

test('los envíos inválidos rechazan con la bolsa address y no guardan nada', function (array $datos, string $campo) {
    $user = clienteConCuenta();

    $this->actingAs($user)->from('/cuenta?tab=direcciones')
        ->post('/cuenta/direcciones', $datos)
        ->assertSessionHasErrors($campo, null, 'address');

    expect($user->addresses()->count())->toBe(0);
})->with([
    'todo vacío' => [datosDireccion(['recipient_name' => '', 'phone' => '', 'department_code' => '', 'city_code' => '', 'line1' => '']), 'recipient_name'],
    'departamento inexistente' => [datosDireccion(['department_code' => '02']), 'department_code'],
    'ciudad ajena al departamento' => [datosDireccion(['department_code' => '11', 'city_code' => '05001']), 'city_code'],
    'teléfono que no es móvil colombiano' => [datosDireccion(['phone' => '1234']), 'phone'],
    'nombre demasiado largo' => [datosDireccion(['recipient_name' => str_repeat('a', 101)]), 'recipient_name'],
    'dirección demasiado larga' => [datosDireccion(['line1' => str_repeat('l', 151)]), 'line1'],
    'complemento demasiado largo' => [datosDireccion(['line2' => str_repeat('x', 101)]), 'line2'],
    'nombre de la dirección demasiado largo' => [datosDireccion(['label' => str_repeat('x', 31)]), 'label'],
    'indicaciones demasiado largas' => [datosDireccion(['instructions' => str_repeat('x', 201)]), 'instructions'],
]);

test('tras un envío inválido la pestaña conserva lo escrito y reabre el modal en crear', function () {
    $user = clienteConCuenta();

    $this->actingAs($user)->from('/cuenta?tab=direcciones')
        ->post('/cuenta/direcciones', datosDireccion(['recipient_name' => '', 'line1' => '']))
        ->assertSessionHasErrors(['recipient_name', 'line1'], null, 'address')
        ->assertSessionHasInput('phone');

    expect($user->addresses()->count())->toBe(0);

    $this->get('/cuenta?tab=direcciones')
        ->assertOk()
        ->assertSeeHtml("x-data=\"addressModal({ open: true, mode: 'create'")
        ->assertSee('JSON.parse(\'{\u0022recipient_name\u0022:\u0022\u0022,\u0022phone\u0022:\u0022+57 300 123 4567\u0022', escape: false)
        ->assertSee('El nombre de quien recibe es obligatorio.')
        ->assertSee('La dirección es obligatoria.');
});

test('la sexta dirección se rechaza con el mensaje del límite', function () {
    $user = clienteConCuenta();

    Address::factory()->count(5)->for($user)->create();

    $this->actingAs($user)->from('/cuenta?tab=direcciones')
        ->post('/cuenta/direcciones', datosDireccion())
        ->assertSessionHasErrors('direcciones', null, 'address');

    expect($user->addresses()->count())->toBe(5);

    $this->actingAs($user)->get('/cuenta?tab=direcciones')
        ->assertOk()
        ->assertSee('Puedes guardar hasta 5 direcciones.')
        ->assertSee('Ya guardaste el máximo de 5 direcciones.')
        ->assertDontSee('Agregar dirección');
});

test('un cliente edita su dirección y el modal reabre en editar cuando falla', function () {
    $user = clienteConCuenta();

    $address = Address::factory()->for($user)->create();

    $this->actingAs($user)->from('/cuenta?tab=direcciones')
        ->put(route('account.addresses.update', $address), datosDireccion([
            'recipient_name' => 'Luis Rojas',
            'label' => 'Trabajo',
            'address_mode' => $address->id,
        ]))
        ->assertRedirect('/cuenta?tab=direcciones')
        ->assertSessionHas('status', 'address-updated');

    expect($address->fresh()->recipient_name)->toBe('Luis Rojas')
        ->and($address->fresh()->label)->toBe('Trabajo');

    $this->actingAs($user)->get('/cuenta?tab=direcciones')
        ->assertOk()
        ->assertSee('Dirección actualizada.')
        ->assertSee('Luis Rojas');

    $this->actingAs($user)->from('/cuenta?tab=direcciones')
        ->put(route('account.addresses.update', $address), datosDireccion(['city_code' => '99999', 'address_mode' => $address->id]))
        ->assertSessionHasErrors('city_code', null, 'address');

    $this->actingAs($user)->get('/cuenta?tab=direcciones')
        ->assertOk()
        ->assertSeeHtml("x-data=\"addressModal({ open: true, mode: 'edit', addressId: ".$address->id)
        ->assertSee('La ciudad elegida no pertenece al departamento.');
});

test('editar, eliminar o cambiar la predeterminada de una dirección ajena responde 404 y no cambia nada', function () {
    $user = clienteConCuenta();
    $otro = clienteConCuenta();

    $ajena = Address::factory()->for($otro)->create();

    $this->actingAs($user)->put(route('account.addresses.update', $ajena), datosDireccion(['recipient_name' => 'Intruso']))
        ->assertNotFound();
    $this->actingAs($user)->patch(route('account.addresses.default', $ajena))->assertNotFound();
    $this->actingAs($user)->delete(route('account.addresses.destroy', $ajena))->assertNotFound();

    expect($ajena->fresh()->recipient_name)->not->toBe('Intruso')
        ->and($otro->addresses()->count())->toBe(1);
});

test('los campos ajenos de la dirección se ignoran y los nombres salen de los códigos DANE', function () {
    $user = clienteConCuenta();
    $otro = clienteConCuenta();

    $this->actingAs($user)->post('/cuenta/direcciones', datosDireccion([
        'user_id' => $otro->id,
        'is_default' => false,
        'department' => 'BURUNDI',
        'city' => 'ISLA INVENTADA',
        'shipping_zone_id' => 99,
    ]))->assertRedirect('/cuenta?tab=direcciones');

    $direccion = $user->addresses()->sole();

    expect($direccion->user_id)->toBe($user->id)
        ->and($direccion->is_default)->toBeTrue()
        ->and($direccion->department)->toBe('NORTE DE SANTANDER')
        ->and($direccion->city)->toBe('OCAÑA')
        ->and($direccion->shipping_zone_id)->toBeNull();
});

test('cambiar la predeterminada deja exactamente una', function () {
    $user = clienteConCuenta();

    $primera = Address::factory()->for($user)->create();
    $segunda = Address::factory()->for($user)->create();
    $tercera = Address::factory()->for($user)->create();

    $this->actingAs($user)->patch(route('account.addresses.default', $primera))
        ->assertRedirect('/cuenta?tab=direcciones')
        ->assertSessionHas('status', 'address-default');

    expect($user->addresses()->where('is_default', true)->count())->toBe(1)
        ->and($primera->fresh()->is_default)->toBeTrue();

    $this->actingAs($user)->patch(route('account.addresses.default', $tercera))
        ->assertRedirect('/cuenta?tab=direcciones');

    expect($user->addresses()->where('is_default', true)->count())->toBe(1)
        ->and($tercera->fresh()->is_default)->toBeTrue()
        ->and($primera->fresh()->is_default)->toBeFalse()
        ->and($segunda->fresh()->is_default)->toBeFalse();
});

test('eliminar la predeterminada promueve la más reciente de las que quedan', function () {
    $user = clienteConCuenta();

    $primera = Address::factory()->for($user)->create();
    $segunda = Address::factory()->for($user)->create();
    $tercera = Address::factory()->for($user)->create();

    $this->actingAs($user)->patch(route('account.addresses.default', $primera));

    $this->actingAs($user)->delete(route('account.addresses.destroy', $primera))
        ->assertRedirect('/cuenta?tab=direcciones')
        ->assertSessionHas('status', 'address-deleted');

    expect($user->addresses()->count())->toBe(2)
        ->and($user->addresses()->where('is_default', true)->count())->toBe(1)
        ->and($tercera->fresh()->is_default)->toBeTrue()
        ->and($segunda->fresh()->is_default)->toBeFalse();
});

test('eliminar la única dirección deja al cliente sin ninguna', function () {
    $user = clienteConCuenta();

    $unica = Address::factory()->for($user)->create(['is_default' => true]);

    $this->actingAs($user)->delete(route('account.addresses.destroy', $unica))
        ->assertRedirect('/cuenta?tab=direcciones');

    expect($user->addresses()->count())->toBe(0);
});

test('los vendedores no tocan las direcciones', function () {
    $this->seed(RoleSeeder::class);

    $vendedor = User::factory()->create();
    $vendedor->assignRole('vendedor');

    $this->actingAs($vendedor)->post(route('account.addresses.store'))->assertForbidden();
    $this->actingAs($vendedor)->put(route('account.addresses.update', 1))->assertForbidden();
    $this->actingAs($vendedor)->patch(route('account.addresses.default', 1))->assertForbidden();
    $this->actingAs($vendedor)->delete(route('account.addresses.destroy', 1))->assertForbidden();

    expect($vendedor->addresses()->count())->toBe(0);
});

test('los visitantes van a iniciar sesión', function () {
    $this->post(route('account.addresses.store'))->assertRedirect(route('login'));
    $this->put(route('account.addresses.update', 1))->assertRedirect(route('login'));
    $this->patch(route('account.addresses.default', 1))->assertRedirect(route('login'));
    $this->delete(route('account.addresses.destroy', 1))->assertRedirect(route('login'));
});

test('los datos de ubicación y el modal solo están en la pestaña Direcciones', function () {
    $user = clienteConCuenta();

    $this->actingAs($user)->get('/cuenta?tab=direcciones')
        ->assertOk()
        ->assertSeeHtml('role="dialog"')
        ->assertSee('addressModal')
        ->assertSee('05001')
        ->assertSee('11001');

    foreach (['/cuenta', '/cuenta?tab=seguridad', '/tienda'] as $path) {
        $this->actingAs($user)->get($path)
            ->assertOk()
            ->assertDontSee('addressModal')
            ->assertDontSee('05001')
            ->assertDontSee('11001')
            ->assertDontSeeHtml('role="dialog"');
    }
});

test('la pestaña Direcciones usa las mismas consultas con una y con cinco direcciones', function () {
    $user = clienteConCuenta();

    $medir = function () use ($user): int {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->actingAs($user)->get(route('account.index', ['tab' => 'direcciones']))->assertOk();

        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    // La primera visita calienta el caché de roles de spatie; las dos medidas se
    // toman en caliente para comparar solo la petición de la pestaña.
    $medir();

    Address::factory()->for($user)->create();

    $conUna = $medir();

    Address::factory()->count(4)->for($user)->create();

    $conCinco = $medir();

    expect($conCinco)->toBe($conUna);
});

test('ni la vista ni las respuestas de la pestaña usan x-if', function () {
    $user = clienteConCuenta();

    $this->actingAs($user)->get('/cuenta?tab=direcciones')
        ->assertOk()
        ->assertDontSee('x-if', escape: false);
});
