<?php

namespace App\Http\Requests\SystemConfig;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a configuration save. Field-level validation is schema-driven and
 * performed in SystemConfigService::validate(); here we guard the envelope.
 *
 * Reaching this request at all means passing the system-config.access gate,
 * which is the check that decides who may edit configuration. Re-typing the
 * same account's password on every save proved nothing on top of that — it
 * just made routine edits cost an extra step.
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
        ];
    }
}
