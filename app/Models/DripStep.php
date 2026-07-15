<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DripStep extends Model
{
    protected $fillable = ['drip_campaign_id', 'position', 'delay_hours', 'title', 'body', 'url', 'image'];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(DripCampaign::class, 'drip_campaign_id');
    }
}
