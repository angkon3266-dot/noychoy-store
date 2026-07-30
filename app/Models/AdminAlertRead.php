<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One admin's record that they've seen a particular alert.
 *
 * Alerts are computed from live data (see AdminAlerts), so this table holds
 * only the decision to stop being told — never the alert itself.
 */
class AdminAlertRead extends Model
{
    public $timestamps = false;

    protected $fillable = ['user_id', 'alert_key', 'created_at'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }
}
