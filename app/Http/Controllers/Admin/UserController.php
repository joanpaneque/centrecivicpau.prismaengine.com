<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Users\CreateUserWithTemporaryPassword;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ResetUserPasswordRequest;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserAdminRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    /**
     * Display the admin user management page.
     */
    public function index(): Response
    {
        $users = User::query()
            ->latest('id')
            ->get(['id', 'name', 'email', 'is_admin', 'must_change_password', 'created_at']);

        return Inertia::render('admin/Users', [
            'users' => $users->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'isAdmin' => $user->is_admin,
                'mustChangePassword' => $user->must_change_password,
                'createdAt' => $user->created_at?->toIso8601String(),
            ]),
        ]);
    }

    /**
     * Store a newly created user with a temporary password.
     */
    public function store(
        StoreUserRequest $request,
        CreateUserWithTemporaryPassword $createUser,
    ): RedirectResponse {
        $createUser->handle($request->validated());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Usuario creado. Deberá cambiar la contraseña al iniciar sesión.',
        ]);

        return to_route('admin.users');
    }

    /**
     * Update the user's name and email.
     */
    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        $user->fill($request->safe()->only(['name', 'email']));

        if ($user->isDirty('email')) {
            $user->email_verified_at = now();
        }

        $user->save();

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => "Se ha actualizado el usuario {$user->name}.",
        ]);

        return back();
    }

    /**
     * Reset the user's password and require a change on next login.
     */
    public function resetPassword(ResetUserPasswordRequest $request, User $user): RedirectResponse
    {
        $user->forceFill([
            'password' => $request->validated('password'),
            'must_change_password' => true,
        ])->save();

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => "Contraseña restablecida para {$user->name}. Deberá elegir una nueva al iniciar sesión.",
        ]);

        return back();
    }

    /**
     * Grant or revoke administrator access for a user.
     */
    public function updateAdmin(UpdateUserAdminRequest $request, User $user): RedirectResponse
    {
        $isAdmin = $request->boolean('is_admin');

        if ($user->is($request->user()) && ! $isAdmin) {
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => 'No puedes quitarte el permiso de administrador a ti mismo.',
            ]);

            return back();
        }

        $user->forceFill([
            'is_admin' => $isAdmin,
        ])->save();

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $isAdmin
                ? "{$user->name} ahora es administrador."
                : "Se ha quitado el permiso de administrador a {$user->name}.",
        ]);

        return back();
    }

    /**
     * Delete the user.
     */
    public function destroy(Request $request, User $user): RedirectResponse
    {
        if ($user->is($request->user())) {
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => 'No puedes eliminar tu propia cuenta desde aquí.',
            ]);

            return back();
        }

        $name = $user->name;
        $user->delete();

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => "Se ha eliminado a {$name}.",
        ]);

        return back();
    }
}
