<?php

use App\Models\User;

test('guests cannot toggle admin status', function () {
    $user = User::factory()->create();

    $this->patch(route('admin.users.admin', $user), [
        'is_admin' => true,
    ])->assertRedirect(route('login'));

    expect($user->fresh()->is_admin)->toBeFalse();
});

test('non admins cannot toggle admin status', function () {
    $actor = User::factory()->create();
    $user = User::factory()->create();

    $this->actingAs($actor)
        ->patch(route('admin.users.admin', $user), [
            'is_admin' => true,
        ])
        ->assertForbidden();

    expect($user->fresh()->is_admin)->toBeFalse();
});

test('admins can grant admin status', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create();

    $this->actingAs($admin)
        ->from(route('admin.users'))
        ->patch(route('admin.users.admin', $user), [
            'is_admin' => 1,
        ])
        ->assertRedirect(route('admin.users'));

    expect($user->fresh()->is_admin)->toBeTrue();
});

test('admins can revoke admin status', function () {
    $admin = User::factory()->admin()->create();
    $otherAdmin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->from(route('admin.users'))
        ->patch(route('admin.users.admin', $otherAdmin), [
            'is_admin' => 0,
        ])
        ->assertRedirect(route('admin.users'));

    expect($otherAdmin->fresh()->is_admin)->toBeFalse();
});

test('admins can toggle admin status with string booleans', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create();

    $this->actingAs($admin)
        ->from(route('admin.users'))
        ->patch(route('admin.users.admin', $user), [
            'is_admin' => 'true',
        ])
        ->assertRedirect(route('admin.users'));

    expect($user->fresh()->is_admin)->toBeTrue();

    $this->actingAs($admin)
        ->from(route('admin.users'))
        ->patch(route('admin.users.admin', $user), [
            'is_admin' => 'false',
        ])
        ->assertRedirect(route('admin.users'));

    expect($user->fresh()->is_admin)->toBeFalse();
});

test('admins cannot revoke their own admin status', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->from(route('admin.users'))
        ->patch(route('admin.users.admin', $admin), [
            'is_admin' => false,
        ])
        ->assertRedirect(route('admin.users'));

    expect($admin->fresh()->is_admin)->toBeTrue();
});
