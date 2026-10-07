<?php

namespace App\Actions\Users;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class CreateInitialAdmin
{
    /**
     * Creates the first administrator atomically: an advisory lock serialises concurrent
     * attempts and the emptiness check is repeated inside the transaction.
     *
     * @param  array{name: string, email: string, password: string, locale?: string}  $data
     */
    public function handle(array $data): User
    {
        return DB::transaction(function () use ($data): User {
            if (DB::getDriverName() === 'pgsql') {
                DB::select('SELECT pg_advisory_xact_lock(?)', [7_311_001]);
                DB::statement('LOCK TABLE users IN SHARE ROW EXCLUSIVE MODE');
            }

            if (User::query()->exists()) {
                throw new RuntimeException('initial-setup-already-done');
            }

            $user = new User;
            $user->forceFill([
                'name' => $data['name'],
                'email' => mb_strtolower($data['email']),
                'password' => $data['password'],
                'role' => UserRole::Admin,
                'locale' => $data['locale'] ?? 'ca',
                'email_verified_at' => now(),
                'must_change_password' => false,
            ])->save();

            return $user;
        });
    }
}
