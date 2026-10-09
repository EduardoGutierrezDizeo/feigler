<?php

use App\Models\User;

/*
|--------------------------------------------------------------------------
| Caracterización del teléfono en el registro
|--------------------------------------------------------------------------
| Fija lo que el registro devuelve hoy para una lista de entradas: el valor
| guardado cuando el celular es válido y un error de validación cuando no.
| Al extraer la normalización a una pieza compartida, esta prueba no debe
| cambiar.
*/

test('el registro trata cada teléfono igual que hoy', function (array $payload, bool $saved) {
    $response = $this->post('/register', array_merge([
        'name' => 'Ana',
        'last_name' => 'Gómez',
        'email' => 'ana@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'terms' => '1',
    ], $payload));

    if ($saved) {
        $response->assertRedirect(route('account.index'));
        expect(User::query()->where('email', 'ana@example.com')->value('phone'))->toBe('3001234567');
    } else {
        $response->assertSessionHasErrors('phone');
        expect(User::query()->where('email', 'ana@example.com')->exists())->toBeFalse();
    }
})->with([
    'con espacios' => [['phone' => '300 123 4567'], true],
    'con prefijo conversacional' => [['phone' => '+57 300 123 4567'], true],
    'con prefijo 57 pegado' => [['phone' => '57 300 123 4567'], true],
    'con paréntesis' => [['phone' => '(300) 123-4567'], true],
    'sin formato' => [['phone' => '3001234567'], true],
    'no empieza por 3' => [['phone' => '1234567890'], false],
    'nueve dígitos' => [['phone' => '300123456'], false],
    'fijo de Bogotá' => [['phone' => '6011234567'], false],
    'letras' => [['phone' => 'abc'], false],
    'vacío' => [['phone' => ''], false],
    'solo espacios' => [['phone' => '   '], false],
    'campo ausente' => [[], false],
]);
