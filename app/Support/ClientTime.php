<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * Times that come from outside (the TPV sends them in UTC, like 2026-10-18T19:00:00.000Z).
 * They must be moved to the app's time zone before saving: the datetime columns keep only
 * the wall-clock time, so a UTC time would be saved as is and read back as Madrid time,
 * two hours early in summer (one in winter).
 */
final class ClientTime
{
    public static function parse(string $value): CarbonImmutable
    {
        return CarbonImmutable::parse($value)->setTimezone((string) config('app.timezone'));
    }
}
