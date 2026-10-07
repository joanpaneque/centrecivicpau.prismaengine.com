<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

#[Signature('register')]
#[Description('Crea un usuario nuevo de forma interactiva con correo y contraseña')]
class RegisterUserCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $email = text(
            label: 'Correo electrónico',
            required: true,
            validate: ['email' => ['required', 'string', 'email', 'max:255', Rule::unique(User::class)]],
        );

        $password = password(
            label: 'Contraseña',
            required: true,
            validate: ['password' => ['required', 'string', Password::defaults()]],
        );

        $name = Str::of(Str::before($email, '@'))
            ->replace(['.', '_', '-'], ' ')
            ->title()
            ->toString();

        $isFirstUser = User::query()->doesntExist();

        $user = User::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'is_admin' => $isFirstUser,
        ]);

        $user->forceFill([
            'email_verified_at' => now(),
        ])->save();

        $this->components->info("Usuario [{$user->email}] creado correctamente.");

        if ($isFirstUser) {
            $this->components->info('Es el primer usuario: se ha marcado como administrador.');
        }

        return self::SUCCESS;
    }
}
