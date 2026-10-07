<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Sanctum\PersonalAccessToken;

class ApiTokenController extends Controller
{
    public const ABILITIES = ['reservations:read', 'reservations:write', 'inspection'];

    public function index(): Response
    {
        return Inertia::render('admin/ApiTokens', [
            'tokens' => PersonalAccessToken::query()->latest('id')->get()->map(fn (PersonalAccessToken $t) => [
                'id' => $t->id,
                'name' => $t->name,
                'abilities' => $t->abilities,
                'lastUsedAt' => $t->last_used_at?->toIso8601String(),
                'expiresAt' => $t->expires_at?->toIso8601String(),
                'createdAt' => $t->created_at?->toIso8601String(),
            ]),
            'abilities' => self::ABILITIES,
            'baseUrl' => url('/api'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'abilities' => ['required', 'array', 'min:1'],
            'abilities.*' => [Rule::in(self::ABILITIES)],
            'expiresAt' => ['nullable', 'date', 'after:today'],
        ]);

        $token = $request->user()?->createToken($data['name'], $data['abilities'], isset($data['expiresAt']) ? now()->parse($data['expiresAt']) : null);
        AuditLog::record('api_token.create', null, ['name' => $data['name'], 'abilities' => $data['abilities']]);

        Inertia::flash('newToken', $token?->plainTextToken);

        return back();
    }

    public function destroy(int $token): RedirectResponse
    {
        PersonalAccessToken::query()->whereKey($token)->delete();
        AuditLog::record('api_token.revoke', null, ['id' => $token]);
        $this->toast(__('tpv.deleted'));

        return back();
    }
}
