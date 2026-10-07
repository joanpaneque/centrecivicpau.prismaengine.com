<?php

namespace App\Http\Controllers\Admin;

use App\Events\TpvChanged;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Services\Images\ImageProcessor;
use App\Support\AppSettings;
use App\Support\Translation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    public function edit(): Response
    {
        $settings = AppSettings::forDevices();

        return Inertia::render('admin/Settings', [
            'settings' => $settings,
        ]);
    }

    public function update(Request $request, ImageProcessor $images): RedirectResponse
    {
        $data = $request->validate([
            'business_name' => ['required', 'string', 'max:120'],
            'issuer_name' => ['required', 'string', 'max:160'],
            'issuer_tax_id' => ['required', 'string', 'max:20'],
            'issuer_address' => ['required', 'string', 'max:160'],
            'issuer_postal_code' => ['required', 'string', 'max:10'],
            'issuer_city' => ['required', 'string', 'max:80'],
            'issuer_province' => ['required', 'string', 'max:80'],
            'issuer_phone' => ['nullable', 'string', 'max:30'],
            'issuer_email' => ['nullable', 'email', 'max:120'],
            'ticket_footer' => ['nullable', 'array'],
            'ticket_footer.ca' => ['nullable', 'string', 'max:200'],
            'ticket_footer.es' => ['nullable', 'string', 'max:200'],
            'terrace_surcharge_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'show_zero_surcharge' => ['boolean'],
            'default_vat_rate' => ['required', 'numeric', Rule::in([0, 4, 5, 10, 21])],
            'default_locale' => ['required', Rule::in(Translation::LOCALES)],
            'reservation_lead_minutes' => ['required', 'integer', 'min:0', 'max:600'],
            'clock_qr_seconds' => ['required', 'integer', 'min:15', 'max:600'],
            'clock_out_reminder_minutes' => ['required', 'integer', 'min:0', 'max:1440'],
            'off_shift_tolerance_minutes' => ['required', 'integer', 'min:0', 'max:600'],
            'invoice_series_code' => ['required', 'alpha', 'max:5'],
            'logo' => ['nullable', 'image', 'max:4096'],
            'remove_logo' => ['boolean'],
        ]);

        $values = Arr::except($data, ['logo', 'remove_logo']);
        $values['issuer_phone'] = (string) ($values['issuer_phone'] ?? '');
        $values['issuer_email'] = (string) ($values['issuer_email'] ?? '');
        $values['ticket_footer'] = Translation::normalize($data['ticket_footer'] ?? null);
        $values['terrace_surcharge_percent'] = (float) $data['terrace_surcharge_percent'];
        $values['default_vat_rate'] = (float) $data['default_vat_rate'];
        $values['show_zero_surcharge'] = (bool) ($data['show_zero_surcharge'] ?? false);
        $values['invoice_series_code'] = strtoupper($data['invoice_series_code']);

        $old = AppSettings::get('logo_path');

        if ($request->hasFile('logo')) {
            $values['logo_path'] = $images->logo($request->file('logo'));
        } elseif ($request->boolean('remove_logo')) {
            $values['logo_path'] = null;
        }

        if (array_key_exists('logo_path', $values) && is_string($old) && $old !== '') {
            Storage::disk('public')->delete($old);
        }

        AppSettings::set($values);
        AuditLog::record('settings.update', null, ['keys' => array_keys($values)], userId: $request->user()?->id);
        TpvChanged::notify(['settings']);
        $this->toast(__('tpv.saved'));

        return back();
    }

    public function regenerateClockSecret(Request $request): RedirectResponse
    {
        AppSettings::set(['clock_secret' => Str::random(64)]);
        AuditLog::record('settings.clock_secret', userId: $request->user()?->id);
        TpvChanged::notify(['settings', 'device']);
        $this->toast(__('tpv.saved'));

        return back();
    }
}
