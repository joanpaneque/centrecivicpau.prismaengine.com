<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AppSettings
{
    private const CACHE_KEY = 'app-settings';

    /**
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'business_name' => 'Bar Centre Cívic de Pau',
            'issuer_name' => 'Juan Paneque Domingo',
            'issuer_tax_id' => '40457683Q',
            'issuer_address' => 'Carrer Sant Pere, 12',
            'issuer_postal_code' => '17494',
            'issuer_city' => 'Pau',
            'issuer_province' => 'Girona',
            'issuer_phone' => '',
            'issuer_email' => '',
            'logo_path' => null,
            'ticket_footer' => ['ca' => 'Gràcies per la vostra visita!', 'es' => '¡Gracias por su visita!'],
            'terrace_surcharge_percent' => 0,
            'show_zero_surcharge' => true,
            'default_vat_rate' => 10,
            'default_locale' => 'ca',
            'reservation_lead_minutes' => 60,
            'clock_qr_seconds' => 45,
            'clock_secret' => null,
            'invoice_series_code' => 'F',
            'clock_out_reminder_minutes' => 120,
            'off_shift_tolerance_minutes' => 30,
        ];
    }

    /**
     * Keys that may be sent to devices. Secrets are excluded.
     *
     * @return list<string>
     */
    public static function publicKeys(): array
    {
        return array_values(array_diff(array_keys(self::defaults()), ['clock_secret']));
    }

    /**
     * @return array<string, mixed>
     */
    public static function all(): array
    {
        /** @var array<string, mixed> $stored */
        $stored = Cache::rememberForever(self::CACHE_KEY, fn () => Setting::query()->pluck('value', 'key')->all());

        return array_replace(self::defaults(), $stored);
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return self::all()[$key] ?? $default;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public static function set(array $values): void
    {
        foreach ($values as $key => $value) {
            Setting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
        }

        Cache::forget(self::CACHE_KEY);
    }

    /**
     * @return array<string, mixed>
     */
    public static function forDevices(): array
    {
        $all = self::all();
        $public = array_intersect_key($all, array_flip(self::publicKeys()));
        $public['logo_url'] = self::logoUrl();

        return $public;
    }

    public static function logoUrl(): ?string
    {
        $path = self::get('logo_path');

        return is_string($path) && $path !== '' ? Storage::disk('public')->url($path) : '/images/logo-centre-civic.png';
    }

    public static function clockSecret(): string
    {
        $secret = self::get('clock_secret');

        if (! is_string($secret) || $secret === '') {
            $secret = Str::random(64);
            self::set(['clock_secret' => $secret]);
        }

        return $secret;
    }

    public static function lastChangedAt(): ?string
    {
        $value = Setting::query()->max('updated_at');

        return is_string($value) ? $value : null;
    }

    /**
     * @return array<string, string>
     */
    public static function issuer(): array
    {
        $s = self::all();

        return [
            'business_name' => (string) $s['business_name'],
            'name' => (string) $s['issuer_name'],
            'tax_id' => (string) $s['issuer_tax_id'],
            'address' => (string) $s['issuer_address'],
            'postal_code' => (string) $s['issuer_postal_code'],
            'city' => (string) $s['issuer_city'],
            'province' => (string) $s['issuer_province'],
            'phone' => (string) $s['issuer_phone'],
            'email' => (string) $s['issuer_email'],
        ];
    }
}
