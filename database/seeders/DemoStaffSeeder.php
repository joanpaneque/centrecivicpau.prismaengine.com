<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Optional demo staff for testing the TPV (not run by default). PINs: 1111, 2222, 3333.
 */
class DemoStaffSeeder extends Seeder
{
    public function run(): void
    {
        $staff = [
            ['Marta', 'marta@example.com', UserRole::Staff, '1111', '#db2777'],
            ['Pere', 'pere@example.com', UserRole::Staff, '2222', '#0891b2'],
            ['Cuina', null, UserRole::Kitchen, '3333', '#ea580c'],
        ];

        foreach ($staff as [$name, $email, $role, $pin, $color]) {
            $user = User::query()->firstOrCreate(['name' => $name], [
                'email' => $email,
                'password' => $email ? 'password' : null,
                'role' => $role,
                'color' => $color,
                'locale' => 'ca',
                'email_verified_at' => now(),
                'must_change_password' => false,
            ]);
            $user->setPin($pin);
            $user->save();
        }
    }
}
