<?php

namespace App\Models;

use Database\Factories\OneTimePasswordFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OneTimePassword extends Model
{
    /** @use HasFactory<OneTimePasswordFactory> */
    use HasFactory;

    protected $fillable = ['purpose', 'phone', 'code_hash', 'transaction_id', 'provider_transaction_id', 'provider_message_id', 'status', 'sent_at', 'expires_at', 'consumed_at', 'attempts'];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime', 'expires_at' => 'datetime', 'consumed_at' => 'datetime'];
    }
}
