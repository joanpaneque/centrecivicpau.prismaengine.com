<?php

use App\Models\Reservation;
use App\Models\User;
use App\Services\Devices\DeviceManager;
use Illuminate\Support\Str;

test('a reservation made at 21:00 on the tpv stays at 21:00 (the tpv sends it in utc)', function () {
    $user = User::factory()->create();
    $cookie = registerTpvDevice($this, $user);
    $uuid = (string) Str::uuid();

    // 21:00 in Madrid in summer (UTC+2) and in winter (UTC+1), as the browser's toISOString() sends them.
    $this->withCredentials()->withCookie(DeviceManager::COOKIE, $cookie)->postJson(route('tpv.push'), ['operations' => [
        tpvOperation('reservation.save', ['uuid' => $uuid, 'name' => 'Garcia', 'partySize' => 4, 'reservedAt' => now()->addDays(3)->setTime(21, 0)->utc()->toIso8601ZuluString('millisecond')], $user->id),
    ]])->assertOk()->assertJsonPath('results.0.status', 'ok');

    $reservation = Reservation::query()->where('uuid', $uuid)->firstOrFail();

    expect($reservation->reserved_at->format('H:i'))->toBe('21:00')
        ->and($reservation->getRawOriginal('reserved_at'))->toEndWith('21:00:00');

    $this->withCredentials()->withCookie(DeviceManager::COOKIE, $cookie)->getJson(route('tpv.bootstrap'))
        ->assertJsonPath('reservations.0.reservedAt', $reservation->reserved_at->toIso8601String());

    expect(Str::of($reservation->reserved_at->toIso8601String())->contains('T21:00:00'))->toBeTrue();
});
