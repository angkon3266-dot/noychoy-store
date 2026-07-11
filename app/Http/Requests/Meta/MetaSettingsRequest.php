<?php

namespace App\Http\Requests\Meta;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the Meta integration settings form (Development Mode credentials +
 * behaviour toggles). The access token is optional on update so a blank field
 * leaves the stored token untouched.
 */
class MetaSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('meta.access') ?? false;
    }

    public function rules(): array
    {
        return [
            'enabled' => ['nullable', 'boolean'],
            'mode' => ['required', 'in:development,production'],

            'business_id' => ['nullable', 'string', 'max:64', 'regex:/^\d*$/'],
            'catalog_id' => ['nullable', 'string', 'max:64', 'regex:/^\d*$/'],
            'access_token' => ['nullable', 'string', 'max:1000'],
            'pixel_id' => ['nullable', 'string', 'max:64', 'regex:/^\d*$/'],

            // Conversions API (server-side). Token optional — blank reuses the
            // system-user token above; a blank field also leaves any stored
            // dedicated token untouched.
            'capi_enabled' => ['nullable', 'boolean'],
            'capi_token' => ['nullable', 'string', 'max:1000'],

            // Behaviour toggles.
            'auto_sync' => ['nullable', 'boolean'],
            'sync_draft' => ['nullable', 'boolean'],
            'sync_out_of_stock' => ['nullable', 'boolean'],
            'sync_hidden' => ['nullable', 'boolean'],
            'sync_images' => ['nullable', 'boolean'],
            'sync_variations' => ['nullable', 'boolean'],
            'sync_inventory' => ['nullable', 'boolean'],
            'sync_price' => ['nullable', 'boolean'],
            'sync_categories' => ['nullable', 'boolean'],
        ];
    }

    /** Toggle checkboxes as real booleans. */
    public function booleanFlags(): array
    {
        $flags = [
            'enabled', 'capi_enabled', 'auto_sync', 'sync_draft', 'sync_out_of_stock', 'sync_hidden',
            'sync_images', 'sync_variations', 'sync_inventory', 'sync_price', 'sync_categories',
        ];

        return collect($flags)->mapWithKeys(fn ($f) => [$f => $this->boolean($f)])->all();
    }

    public function messages(): array
    {
        return [
            'business_id.regex' => 'The Business ID must be numeric.',
            'catalog_id.regex' => 'The Catalog ID must be numeric.',
            'pixel_id.regex' => 'The Pixel ID must be numeric.',
        ];
    }
}
