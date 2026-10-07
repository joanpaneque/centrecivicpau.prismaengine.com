<?php

namespace App\Services\Devices;

use App\Enums\DeviceType;
use App\Models\Device;
use App\Models\TicketSeries;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DeviceManager
{
    public const COOKIE = 'tpv_device';

    private const COOKIE_MINUTES = 60 * 24 * 365 * 5;

    public function fromRequest(Request $request): ?Device
    {
        if ($request->attributes->has('device')) {
            /** @var Device|null */
            return $request->attributes->get('device');
        }

        $device = null;
        $raw = $request->cookie(self::COOKIE);

        if (is_string($raw) && str_contains($raw, '|')) {
            [$uuid, $token] = explode('|', $raw, 2);
            $device = Device::query()
                ->where('uuid', $uuid)
                ->where('token_hash', hash('sha256', $token))
                ->where('active', true)
                ->first();
        }

        $request->attributes->set('device', $device);

        return $device;
    }

    public function register(string $name, DeviceType $type, ?User $by = null): Device
    {
        $token = Str::random(48);

        $device = DB::transaction(function () use ($name, $type, $by, $token) {
            $device = Device::query()->create([
                'uuid' => (string) Str::uuid(),
                'name' => $name,
                'type' => $type,
                'token_hash' => hash('sha256', $token),
                'registered_by' => $by?->id,
                'last_seen_at' => now(),
            ]);

            if ($type === DeviceType::Cashier) {
                $this->ensureSeries($device);
            }

            return $device;
        });

        Cookie::queue(Cookie::make(self::COOKIE, $device->uuid.'|'.$token, self::COOKIE_MINUTES, httpOnly: true, sameSite: 'lax'));

        return $device;
    }

    public function changeType(Device $device, DeviceType $type): Device
    {
        $device->type = $type;
        $device->save();

        if ($type === DeviceType::Cashier) {
            $this->ensureSeries($device);
        }

        return $device;
    }

    /**
     * Each cashier device owns a series, so numbers stay correlative while it works offline.
     */
    public function ensureSeries(Device $device): TicketSeries
    {
        $existing = TicketSeries::query()->where('device_id', $device->id)->where('kind', 'simplified')->first();

        if ($existing) {
            return $existing;
        }

        $index = TicketSeries::query()->where('kind', 'simplified')->count() + 1;

        do {
            $code = 'T'.$index.'-'.now()->format('Y');
            $index++;
        } while (TicketSeries::query()->where('code', $code)->exists());

        return TicketSeries::query()->create([
            'code' => $code,
            'kind' => 'simplified',
            'device_id' => $device->id,
            'last_number' => 0,
        ]);
    }

    public function touch(Device $device): void
    {
        if ($device->last_seen_at === null || $device->last_seen_at->lt(now()->subMinute())) {
            $device->forceFill(['last_seen_at' => now()])->saveQuietly();
        }
    }
}
