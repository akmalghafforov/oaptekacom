<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExternalAvailability extends Model
{
    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['available' => 'boolean', 'evidence' => 'array', 'published_at' => 'datetime', 'last_checked_at' => 'datetime', 'last_downloaded_at' => 'datetime', 'next_check_at' => 'datetime'];
    }
}
