<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

test('register command creates a user with the given email and password', function () {
    $this->artisan('register')
        ->expectsQuestion('Correo electrónico', 'joan@example.com')
        ->expectsQuestion('Contraseña', 'password')
        ->expectsOutputToContain('Usuario [joan@example.com] creado correctamente.')
        ->assertSuccessful();

    $user = User::query()->where('email', 'joan@example.com')->first();

    expect($user)->not->toBeNull()
        ->and($user->name)->toBe('Joan')
        ->and($user->is_admin)->toBeTrue()
        ->and(Hash::check('password', $user->password))->toBeTrue()
        ->and($user->email_verified_at)->not->toBeNull();
});

test('register command does not make later users admins', function () {
    User::factory()->create();

    $this->artisan('register')
        ->expectsQuestion('Correo electrónico', 'nou@example.com')
        ->expectsQuestion('Contraseña', 'password')
        ->assertSuccessful();

    $user = User::query()->where('email', 'nou@example.com')->first();

    expect($user)->not->toBeNull()
        ->and($user->is_admin)->toBeFalse();
});

test('register command rejects an email that is already taken', function () {
    $this->artisan('register')
        ->expectsQuestion('Correo electrónico', 'taken@example.com')
        ->expectsQuestion('Contraseña', 'password')
        ->assertSuccessful();

    $this->artisan('register')
        ->expectsQuestion('Correo electrónico', 'taken@example.com')
        ->expectsOutputToContain('registra')
        ->assertFailed();

    expect(User::query()->where('email', 'taken@example.com')->count())->toBe(1);
});
