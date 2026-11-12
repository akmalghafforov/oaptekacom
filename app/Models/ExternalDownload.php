<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExternalDownload extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'company' => 'array', 'account_discount' => 'json', 'worker_expires_at' => 'datetime', 'next_retry_at' => 'datetime'];
    }
}
