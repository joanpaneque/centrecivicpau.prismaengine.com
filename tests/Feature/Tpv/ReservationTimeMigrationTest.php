<?php

use App\Models\Reservation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

test('existing tpv reservations saved in utc are moved to their real time', function () {
    $make = fn (string $at, string $source) => DB::table('reservations')->insertGetId([
        'uuid' => (string) Str::uuid(), 'name' => 'X', 'party_size' => 2, 'reserved_at' => $at, 'duration_minutes' => 90,
        'status' => 'confirmed', 'source' => $source, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $summer = $make('2026-07-15 19:00:00', 'staff');
    $winter = $make('2026-12-15 20:00:00', 'staff');
    $web = $make('2026-07-15 21:00:00', 'web');

    $migration = require database_path('migrations/2026_10_18_120000_fix_utc_times_of_tpv_reservations.php');
    $migration->up();

    expect(Reservation::query()->find($summer)->reserved_at->format('Y-m-d H:i'))->toBe('2026-07-15 21:00')
        ->and(Reservation::query()->find($winter)->reserved_at->format('Y-m-d H:i'))->toBe('2026-12-15 21:00')
        ->and(Reservation::query()->find($web)->reserved_at->format('Y-m-d H:i'))->toBe('2026-07-15 21:00');

    $migration->down();
    expect(Reservation::query()->find($summer)->reserved_at->format('H:i'))->toBe('19:00');
});
