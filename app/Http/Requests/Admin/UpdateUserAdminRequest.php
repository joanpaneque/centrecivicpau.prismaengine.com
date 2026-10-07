<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateUserAdminRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->is_admin === true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'is_admin' => ['required', 'boolean'],
        ];
    }

    /**
     * Normalize FormData boolean-like strings before validation.
     */
    protected function prepareForValidation(): void
    {
        if (! $this->has('is_admin')) {
            return;
        }

        $this->merge([
            'is_admin' => filter_var($this->input('is_admin'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
        ]);
    }
}
