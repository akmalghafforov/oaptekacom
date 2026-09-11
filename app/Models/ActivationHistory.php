<?php

namespace App\Models;

use Database\Factories\ActivationHistoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ActivationHistory extends Model
{
    /** @use HasFactory<ActivationHistoryFactory> */
    use HasFactory;

    protected $fillable = ['phone', 'organization_id', 'demo_used_at'];

    protected function casts(): array
    {
        return ['demo_used_at' => 'datetime'];
    }
}
