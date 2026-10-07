<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('guests cannot visit the admin panel', function () {
    $this->get(route('admin.users'))->assertRedirect(route('login'));
});

test('non admins cannot visit the admin panel', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('admin.users'))
        ->assertForbidden();
});

test('admins can visit the admin users page', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->get(route('admin.users'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/Users')
            ->has('users'));
});

test('admin index redirects to users', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->get(route('admin.index'))
        ->assertRedirect('/admin/usuarios');
});

test('admins can create users that must change password', function () {
    $admin = User::factory()->admin()->create();

    $response = $this->actingAs($admin)->post(route('admin.users.store'), [
        'email' => 'nou@exemple.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $response->assertRedirect(route('admin.users'));

    $user = User::query()->where('email', 'nou@exemple.com')->first();

    expect($user)->not->toBeNull()
        ->and($user->must_change_password)->toBeTrue()
        ->and($user->is_admin)->toBeFalse()
        ->and($user->email_verified_at)->not->toBeNull()
        ->and(password_verify('password', $user->password))->toBeTrue();
});

test('non admins cannot create users', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('admin.users.store'), [
            'email' => 'nou@exemple.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])
        ->assertForbidden();

    $this->assertDatabaseMissing('users', [
        'email' => 'nou@exemple.com',
    ]);
});
