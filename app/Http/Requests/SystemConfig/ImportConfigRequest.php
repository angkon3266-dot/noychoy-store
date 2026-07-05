<?php

namespace App\Http\Requests\SystemConfig;

use Illuminate\Foundation\Http\FormRequest;

class ImportConfigRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('system-config.access') ?? false;
    }

    public function rules(): array
    {
        return [
            'backup_file' => ['required', 'file', 'max:2048'],
        ];
    }
}
