<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One editable configuration field (section + key → value). Sensitive values
 * are stored encrypted; encryption/decryption is handled by the repository so
 * this model stays a plain data record.
 */
class SystemConfig extends Model
{
    protected $fillable = ['section', 'key', 'value', 'is_encrypted'];

    protected $casts = ['is_encrypted' => 'boolean'];
}
