<?php

namespace App\Http\Requests\Admin;

use App\Enums\UserRole;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() === true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['nullable', 'string', 'max:120', 'required_without:email'],
            'email' => ['nullable', 'string', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['nullable', 'required_with:email', 'string', Password::default(), 'confirmed'],
            'role' => ['nullable', Rule::enum(UserRole::class)],
            'locale' => ['nullable', Rule::in(['ca', 'es'])],
            'color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'tax_id' => ['nullable', 'string', 'max:20'],
            'pin' => ['nullable', 'digits:4'],
        ];
    }
}
