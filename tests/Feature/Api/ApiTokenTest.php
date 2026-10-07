<?php

use App\Models\AuditLog;
use App\Models\Reservation;
use App\Models\User;
use App\Services\TimeTracking\TimeEntryRecorder;
use Carbon\CarbonImmutable;

test('the api requires a token', function () {
    $this->getJson(route('api.reservations.index'))->assertUnauthorized();
});

test('tokens are limited to their abilities', function () {
    $admin = User::factory()->admin()->create();
    $token = $admin->createToken('reserves', ['reservations:read'])->plainTextToken;

    $this->withToken($token)->getJson(route('api.reservations.index'))->assertOk();
    $this->withToken($token)->postJson(route('api.reservations.store'), [])->assertForbidden();
    $this->withToken($token)->getJson(route('api.inspection.workers'))->assertForbidden();
});

test('reservations can be created through the api', function () {
    $admin = User::factory()->admin()->create();
    $token = $admin->createToken('web', ['reservations:read', 'reservations:write'])->plainTextToken;

    $response = $this->withToken($token)->postJson(route('api.reservations.store'), [
        'name' => 'Família Puig',
        'phone' => '600000000',
        'party_size' => 4,
        'reserved_at' => now()->addDay()->setTime(21, 0)->toIso8601String(),
    ])->assertCreated();

    $uuid = $response->json('data.uuid');

    expect(Reservation::query()->where('name', 'Família Puig')->value('source'))->toBe('api');

    $this->withToken($token)->postJson(route('api.reservations.cancel', $uuid))->assertOk();

    expect(Reservation::query()->where('name', 'Família Puig')->value('status'))->toBe('cancelled');
});

test('the inspection api returns time records and audits every access', function () {
    $admin = User::factory()->admin()->create();
    $worker = User::factory()->create(['name' => 'Persona Inspeccionada']);
    app(TimeEntryRecorder::class)->record($worker, 'clock_in', CarbonImmutable::parse('yesterday 09:00'), 'app');
    $token = $admin->createToken('inspeccio', ['inspection'])->plainTextToken;

    $this->withToken($token)->getJson(route('api.inspection.workers'))
        ->assertOk()
        ->assertJsonFragment(['name' => 'Persona Inspeccionada']);

    $this->withToken($token)->getJson(route('api.inspection.records', [
        'from' => CarbonImmutable::parse('yesterday')->toDateString(),
        'to' => CarbonImmutable::parse('today')->toDateString(),
    ]))->assertOk();

    expect(AuditLog::query()->where('action', 'like', 'inspection.%')->count())->toBe(2);
});

test('admins create api tokens from the panel', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->post(route('admin.api-tokens.store'), [
        'name' => 'Inspecció de Treball',
        'abilities' => ['inspection'],
    ])->assertRedirect();

    expect($admin->tokens()->where('name', 'Inspecció de Treball')->exists())->toBeTrue();
});
