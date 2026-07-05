<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContactMessage extends Model
{
    protected $fillable = [
        'name', 'phone', 'email', 'subject', 'message', 'is_read', 'ip',
    ];

    protected $casts = ['is_read' => 'boolean'];
}
