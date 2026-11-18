<?php

namespace App\Models;

use Database\Factories\SupplierVersionClaimFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SupplierVersionClaim extends Model
{
    /** @use HasFactory<SupplierVersionClaimFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['completed_at' => 'immutable_datetime'];
    }
}
