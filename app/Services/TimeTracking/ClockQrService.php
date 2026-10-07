<?php

namespace App\Services\TimeTracking;

use App\Support\AppSettings;
use Carbon\CarbonInterface;

/**
 * Rotating QR shown on the clock device. The code is HMAC(secret, time window), so a photo
 * taken earlier stops working after one window. The same algorithm runs offline on the
 * clock device (clockQrCode in resources/js/pos/crypto.ts) using the secret it receives at bootstrap.
 */
class ClockQrService
{
    public function window(CarbonInterface $time): int
    {
        return intdiv($time->getTimestamp(), $this->seconds());
    }

    public function seconds(): int
    {
        return max(30, min(60, (int) AppSettings::get('clock_qr_seconds', 45)));
    }

    public function codeFor(int $window): string
    {
        $mac = hash_hmac('sha256', 'clock:'.$window, AppSettings::clockSecret());

        return $window.'.'.substr($mac, 0, 16);
    }

    /**
     * A code is accepted when it is authentic and the clock-in moment falls within one
     * window of the code (the phone may submit later if it was offline).
     */
    public function verify(string $code, CarbonInterface $occurredAt): bool
    {
        if (! preg_match('/^(\d+)\.([a-f0-9]{16})$/', $code, $m)) {
            return false;
        }

        $window = (int) $m[1];

        if (! hash_equals($this->codeFor($window), $code)) {
            return false;
        }

        return abs($this->window($occurredAt) - $window) <= 1;
    }
}
