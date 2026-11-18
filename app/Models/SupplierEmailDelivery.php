<?php

namespace App\Models;

use Database\Factories\SupplierEmailDeliveryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SupplierEmailDelivery extends Model
{
    /** @use HasFactory<SupplierEmailDeliveryFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['received_at' => 'immutable_datetime', 'manifest' => 'array'];
    }
}
