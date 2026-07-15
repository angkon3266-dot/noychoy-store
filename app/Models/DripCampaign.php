<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DripCampaign extends Model
{
    protected $fillable = ['name', 'trigger', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function steps(): HasMany
    {
        return $this->hasMany(DripStep::class)->orderBy('position');
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(DripEnrollment::class);
    }
}
