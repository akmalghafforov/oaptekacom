<?php

namespace App\Models;

use Database\Factories\ExternalListingRunFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ExternalListingRun extends Model
{
    /** @use HasFactory<ExternalListingRunFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['pages' => 'array', 'dispatch_requested' => 'boolean', 'next_retry_at' => 'immutable_datetime', 'scan_hour' => 'immutable_datetime', 'completed_at' => 'immutable_datetime'];
    }
}
