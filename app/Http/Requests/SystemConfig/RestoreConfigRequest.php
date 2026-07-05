<?php

namespace App\Http\Requests\SystemConfig;

use Illuminate\Foundation\Http\FormRequest;

class RestoreConfigRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('system-config.access') ?? false;
    }

    public function rules(): array
    {
        return [
            'security_password' => ['required', 'string'],
        ];
    }

    public function messages(): array
    {
        return ['security_password.required' => 'Confirm with your admin password to restore.'];
    }
}
