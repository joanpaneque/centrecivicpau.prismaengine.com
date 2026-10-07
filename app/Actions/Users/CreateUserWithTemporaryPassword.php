<?php

namespace App\Actions\Users;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Str;

class CreateUserWithTemporaryPassword
{
    /**
     * Staff without e-mail only use PIN / QR access, so they never get a password.
     *
     * @param  array{name?: string|null, email?: string|null, password?: string|null, role?: string|null, locale?: string|null, color?: string|null, tax_id?: string|null, pin?: string|null}  $data
     */
    public function handle(array $data): User
    {
        $email = $data['email'] ?? null;
        $name = $data['name'] ?? null;

        $user = User::query()->create([
            'name' => $name !== null && $name !== '' ? $name : Str::of((string) $email)->before('@')->headline()->toString(),
            'email' => $email,
            'password' => $email ? ($data['password'] ?? null) : null,
            'role' => UserRole::tryFrom((string) ($data['role'] ?? '')) ?? UserRole::Staff,
            'locale' => $data['locale'] ?? 'ca',
            'color' => $data['color'] ?? null,
            'tax_id' => $data['tax_id'] ?? null,
        ]);

        $user->forceFill([
            'must_change_password' => $email !== null,
            'email_verified_at' => now(),
        ]);

        if (! empty($data['pin'])) {
            $user->setPin($data['pin']);
        }

        $user->save();
        $user->regenerateLoginToken();

        return $user;
    }
}
