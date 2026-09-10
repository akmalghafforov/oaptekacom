<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class AuditEvent extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['before' => 'array', 'after' => 'array', 'meta' => 'array'];
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
