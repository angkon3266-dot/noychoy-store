<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class CustomerSegment extends Model
{
    protected $fillable = ['name', 'type', 'rules'];

    protected $casts = ['rules' => 'array'];

    /** Manually-picked members (for type=manual). */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(Customer::class, 'customer_segment_members');
    }
}
