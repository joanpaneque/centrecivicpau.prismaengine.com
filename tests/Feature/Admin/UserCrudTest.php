<?php

use App\Models\User;

test('admins can update a users name and email', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create([
        'name' => 'Nombre antiguo',
        'email' => 'antic@exemple.com',
    ]);

    $this->actingAs($admin)
        ->from(route('admin.users'))
        ->patch(route('admin.users.update', $user), [
            'name' => 'Nombre nuevo',
            'email' => 'nou@exemple.com',
        ])
        ->assertRedirect(route('admin.users'));

    $user->refresh();

    expect($user->name)->toBe('Nombre nuevo')
        ->and($user->email)->toBe('nou@exemple.com');
});

test('admins cannot update a user with a duplicate email', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create(['email' => 'usuario@ejemplo.com']);
    User::factory()->create(['email' => 'ocupat@exemple.com']);

    $this->actingAs($admin)
        ->from(route('admin.users'))
        ->patch(route('admin.users.update', $user), [
            'name' => $user->name,
            'email' => 'ocupat@exemple.com',
        ])
        ->assertRedirect(route('admin.users'))
        ->assertSessionHasErrors('email');

    expect($user->fresh()->email)->toBe('usuario@ejemplo.com');
});

test('admins can reset a users password and require change', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create([
        'must_change_password' => false,
        'password' => 'old-password',
    ]);

    $this->actingAs($admin)
        ->from(route('admin.users'))
        ->patch(route('admin.users.password', $user), [
            'password' => 'temporary-password',
            'password_confirmation' => 'temporary-password',
        ])
        ->assertRedirect(route('admin.users'));

    $user->refresh();

    expect($user->must_change_password)->toBeTrue()
        ->and(password_verify('temporary-password', $user->password))->toBeTrue();
});

test('admins can delete other users', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create();

    $this->actingAs($admin)
        ->from(route('admin.users'))
        ->delete(route('admin.users.destroy', $user))
        ->assertRedirect(route('admin.users'));

    $this->assertDatabaseMissing('users', [
        'id' => $user->id,
    ]);
});

test('admins cannot delete themselves', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->from(route('admin.users'))
        ->delete(route('admin.users.destroy', $admin))
        ->assertRedirect(route('admin.users'));

    $this->assertDatabaseHas('users', [
        'id' => $admin->id,
    ]);
});

test('non admins cannot manage users', function () {
    $actor = User::factory()->create();
    $user = User::factory()->create();

    $this->actingAs($actor)
        ->patch(route('admin.users.update', $user), [
            'name' => 'Hack',
            'email' => 'hack@exemple.com',
        ])
        ->assertForbidden();

    $this->actingAs($actor)
        ->patch(route('admin.users.password', $user), [
            'password' => 'temporary-password',
            'password_confirmation' => 'temporary-password',
        ])
        ->assertForbidden();

    $this->actingAs($actor)
        ->delete(route('admin.users.destroy', $user))
        ->assertForbidden();

    $this->assertDatabaseHas('users', [
        'id' => $user->id,
        'email' => $user->email,
    ]);
});
