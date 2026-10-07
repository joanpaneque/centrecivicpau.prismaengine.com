<?php

namespace App\Actions\Users;

use App\Models\User;
use Illuminate\Support\Str;

class CreateUserWithTemporaryPassword
{
    /**
     * @param  array{email: string, password: string}  $data
     */
    public function handle(array $data): User
    {
        $user = User::query()->create([
            'name' => Str::of($data['email'])->before('@')->headline()->toString(),
            'email' => $data['email'],
            'password' => $data['password'],
        ]);

        $user->forceFill([
            'must_change_password' => true,
            'email_verified_at' => now(),
        ])->save();

        return $user;
    }
}
