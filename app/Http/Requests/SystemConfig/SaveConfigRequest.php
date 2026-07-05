<?php

namespace App\Http\Requests\SystemConfig;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a configuration save. Field-level validation is schema-driven and
 * performed in SystemConfigService::validate(); here we guard the envelope +
 * the mandatory password confirmation.
 */
class SaveConfigRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('system-config.access') ?? false;
    }

    public function rules(): array
    {
        return [
            'values' => ['nullable', 'array'],
            'notes' => ['nullable', 'string', 'max:255'],
            'security_password' => ['required', 'string'],
        ];
    }

    public function messages(): array
    {
        return ['security_password.required' => 'Confirm with your admin password to save changes.'];
    }
}
