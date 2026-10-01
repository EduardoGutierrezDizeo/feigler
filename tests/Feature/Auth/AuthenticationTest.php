<?php

use App\Models\User;
use Illuminate\Support\Facades\Blade;

test('login screen can be rendered', function () {
    $response = $this->get('/login');

    $response->assertStatus(200);
});

test('the primary button component submits forms by default', function () {
    $html = Blade::render('<x-primary-button>Save</x-primary-button>');

    expect($html)->toContain('type="submit"');
});

test('the login form has a control that actually submits it', function () {
    $response = $this->get('/login');

    $response->assertStatus(200);

    $dom = new DOMDocument;
    $dom->loadHTML('<?xml encoding="utf-8" ?>'.$response->getContent(), LIBXML_NOERROR);

    $forms = $dom->getElementsByTagName('form');

    expect($forms->length)->toBe(1);

    // A button without a type attribute submits the form, per HTML semantics.
    $types = array_map(
        fn (DOMElement $button) => $button->getAttribute('type') ?: 'submit',
        iterator_to_array($forms->item(0)->getElementsByTagName('button')),
    );

    expect($types)->toContain('submit');
});

test('users can authenticate using the login screen', function () {
    $user = User::factory()->create();

    $response = $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('dashboard', absolute: false));
});

test('users can not authenticate with invalid password', function () {
    $user = User::factory()->create();

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    $this->assertGuest();
});

test('users can logout', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post('/logout');

    $this->assertGuest();
    $response->assertRedirect('/');
});
