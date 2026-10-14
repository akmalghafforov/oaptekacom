<?php

namespace App\Models;

use Database\Factories\GmailConnectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['email', 'access_token', 'refresh_token', 'expires_at', 'scopes', 'status', 'connected_at', 'reconnect_required_at'])]
#[Hidden(['access_token', 'refresh_token'])]
class GmailConnection extends Model
{
    /** @use HasFactory<GmailConnectionFactory> */
    use HasFactory;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['access_token' => 'encrypted', 'refresh_token' => 'encrypted', 'expires_at' => 'datetime', 'scopes' => 'array', 'connected_at' => 'datetime', 'reconnect_required_at' => 'datetime'];
    }
}
