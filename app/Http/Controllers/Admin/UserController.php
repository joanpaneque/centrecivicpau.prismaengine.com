<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Users\CreateUserWithTemporaryPassword;
use App\Enums\UserRole;
use App\Events\TpvChanged;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ResetUserPasswordRequest;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserAdminRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Models\AuditLog;
use App\Models\Ticket;
use App\Models\TimeEntry;
use App\Models\TimeEntryCorrection;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function index(): Response
    {
        $users = User::query()->orderByDesc('active')->orderBy('name')->get();

        return Inertia::render('admin/Users', [
            'users' => $users->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role->value,
                'locale' => $user->locale,
                'color' => $user->color,
                'taxId' => $user->tax_id,
                'active' => $user->active,
                'isAdmin' => $user->isAdmin(),
                'hasPin' => $user->pin_hash !== null,
                'mustChangePassword' => $user->must_change_password,
                'createdAt' => $user->created_at?->toIso8601String(),
            ]),
        ]);
    }

    public function store(StoreUserRequest $request, CreateUserWithTemporaryPassword $createUser): RedirectResponse
    {
        /** @var array{name?: string|null, email?: string|null, password?: string|null, role?: string|null, locale?: string|null, color?: string|null, tax_id?: string|null, pin?: string|null} $data */
        $data = $request->validated();
        $user = $createUser->handle($data);

        AuditLog::record('user.create', $user, ['role' => $user->role->value], userId: $request->user()?->id);
        TpvChanged::notify(['staff']);
        $this->toast(__('tpv.saved'));

        return to_route('admin.users');
    }

    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        $data = $request->validated();

        if ($user->is($request->user()) && isset($data['role']) && $data['role'] !== UserRole::Admin->value) {
            unset($data['role']);
        }

        $user->fill($data);

        if ($user->isDirty('email')) {
            $user->forceFill(['email_verified_at' => $user->email ? now() : null]);
        }

        $user->save();
        TpvChanged::notify(['staff']);
        $this->toast(__('tpv.saved'));

        return back();
    }

    public function resetPassword(ResetUserPasswordRequest $request, User $user): RedirectResponse
    {
        $user->forceFill([
            'password' => $request->validated('password'),
            'must_change_password' => true,
        ])->save();

        $this->toast(__('tpv.saved'));

        return back();
    }

    public function updateAdmin(UpdateUserAdminRequest $request, User $user): RedirectResponse
    {
        $isAdmin = $request->boolean('is_admin');

        if ($user->is($request->user()) && ! $isAdmin) {
            $this->toast('No puedes quitarte el permiso de administrador a ti mismo.', 'error');

            return back();
        }

        $user->forceFill(['is_admin' => $isAdmin])->save();
        TpvChanged::notify(['staff']);
        $this->toast(__('tpv.saved'));

        return back();
    }

    public function pin(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate(['pin' => ['nullable', 'digits:4']]);

        $user->setPin($data['pin'] ?? null);
        $user->save();

        AuditLog::record('user.pin', $user, ['cleared' => empty($data['pin'])], userId: $request->user()?->id);
        TpvChanged::notify(['staff']);
        $this->toast(__('tpv.saved'));

        return back();
    }

    /**
     * Returns the QR login URL; regenerating it invalidates the previous card.
     */
    public function qr(Request $request, User $user): JsonResponse
    {
        $token = $request->boolean('regenerate') || ! is_string($user->login_token) || $user->login_token === ''
            ? $user->regenerateLoginToken()
            : $user->login_token;

        if ($request->boolean('regenerate')) {
            AuditLog::record('user.qr_regenerated', $user, userId: $request->user()?->id);
        }

        return response()->json(['url' => route('qr-login', ['token' => $token])]);
    }

    public function toggleActive(Request $request, User $user): RedirectResponse
    {
        if ($user->is($request->user())) {
            $this->toast('No puedes desactivar tu propia cuenta.', 'error');

            return back();
        }

        $user->forceFill(['active' => ! $user->active])->save();

        if (! $user->active) {
            $user->forceFill(['login_token' => null, 'login_token_hash' => null, 'remember_token' => null])->save();
        }

        AuditLog::record($user->active ? 'user.activate' : 'user.deactivate', $user, userId: $request->user()?->id);
        TpvChanged::notify(['staff']);
        $this->toast(__('tpv.saved'));

        return back();
    }

    /**
     * Users with fiscal or working-time records cannot be erased (legal retention):
     * they are deactivated instead.
     */
    public function destroy(Request $request, User $user): RedirectResponse
    {
        if ($user->is($request->user())) {
            $this->toast('No puedes eliminar tu propia cuenta desde aquí.', 'error');

            return back();
        }

        $hasRecords = TimeEntry::query()->where('user_id', $user->id)->exists()
            || TimeEntryCorrection::query()->where('user_id', $user->id)->orWhere('corrected_by', $user->id)->exists()
            || Ticket::query()->where('waiter_id', $user->id)->orWhere('cashier_id', $user->id)->exists();

        if ($hasRecords) {
            $user->forceFill(['active' => false, 'login_token' => null, 'login_token_hash' => null, 'remember_token' => null])->save();
            AuditLog::record('user.deactivate', $user, userId: $request->user()?->id);
            $this->toast(__('tpv.user_has_records'), 'warning');
        } else {
            AuditLog::record('user.delete', null, ['id' => $user->id, 'name' => $user->name], userId: $request->user()?->id);
            $user->delete();
            $this->toast(__('tpv.deleted'));
        }

        TpvChanged::notify(['staff']);

        return back();
    }
}
