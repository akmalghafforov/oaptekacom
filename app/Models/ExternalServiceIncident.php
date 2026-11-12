<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExternalServiceIncident extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['delivered_to' => 'array', 'resolved_at' => 'datetime', 'notified_at' => 'datetime', 'notification_attempted_at' => 'datetime'];
    }
}
