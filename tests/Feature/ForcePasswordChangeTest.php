<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('users that must change password are redirected after login', function () {
    $user = User::factory()->mustChangePassword()->create([
        'email' => 'temporal@exemple.com',
    ]);

    $response = $this->post(route('login'), [
        'email' => 'temporal@exemple.com',
        'password' => 'password',
    ]);

    $response->assertRedirect(route('password.force-change'));
    $this->assertAuthenticatedAs($user);
});

test('users that must change password cannot visit other pages', function () {
    $user = User::factory()->mustChangePassword()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertRedirect(route('password.force-change'));
});

test('users can set a new password on first login', function () {
    $user = User::factory()->mustChangePassword()->create();

    $response = $this->actingAs($user)->put(route('password.force-change.update'), [
        'password' => 'new-password',
        'password_confirmation' => 'new-password',
    ]);

    $response->assertRedirect(route('dashboard'));

    $user->refresh();

    expect($user->must_change_password)->toBeFalse()
        ->and(password_verify('new-password', $user->password))->toBeTrue();
});

test('users without forced change cannot use force password endpoint', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->put(route('password.force-change.update'), [
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])
        ->assertForbidden();
});

test('force password change page renders for affected users', function () {
    $user = User::factory()->mustChangePassword()->create();

    $this->actingAs($user)
        ->get(route('password.force-change'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('auth/ForcePasswordChange'));
});
