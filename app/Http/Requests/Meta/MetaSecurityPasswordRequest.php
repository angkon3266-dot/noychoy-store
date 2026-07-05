<?php

namespace App\Http\Requests\Meta;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * Validates creating or changing the module's secondary security password.
 * When a password already exists, the current one must be supplied.
 */
class MetaSecurityPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('meta.access') ?? false;
    }

    public function rules(): array
    {
        return [
            'current_password' => ['nullable', 'string'],
            'new_password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
        ];
    }
}
