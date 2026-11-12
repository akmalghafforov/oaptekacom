<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExternalServiceState extends Model
{
    protected $guarded = [];

    protected $primaryKey = 'source';

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return ['directory_progress' => 'array', 'directory_lease_expires_at' => 'datetime', 'directory' => 'array', 'cooldown_until' => 'datetime', 'next_request_at' => 'datetime', 'last_checked_at' => 'datetime', 'last_success_at' => 'datetime', 'directory_checked_at' => 'datetime', 'next_check_at' => 'datetime'];
    }
}
