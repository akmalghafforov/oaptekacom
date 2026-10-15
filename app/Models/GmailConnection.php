<?php

namespace App\Models;

use Database\Factories\GmailConnectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['email', 'access_token', 'refresh_token', 'expires_at', 'scopes', 'status', 'connected_at', 'reconnect_required_at', 'history_cursor', 'scan_baseline', 'scan_page', 'history_page', 'scan_completed_at', 'last_synced_at', 'last_sync_error', 'next_sync_at'])]
#[Hidden(['access_token', 'refresh_token'])]
class GmailConnection extends Model
{
    /** @use HasFactory<GmailConnectionFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::updating(function (self $connection): void {
            if ($connection->isDirty('email') && strcasecmp($connection->getOriginal('email'), $connection->email) !== 0) {
                foreach (['history_cursor', 'scan_baseline', 'scan_page', 'history_page', 'scan_completed_at', 'last_synced_at', 'last_sync_error', 'next_sync_at'] as $column) {
                    $connection->{$column} = null;
                }
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['access_token' => 'encrypted', 'refresh_token' => 'encrypted', 'expires_at' => 'datetime', 'scopes' => 'array', 'connected_at' => 'datetime', 'reconnect_required_at' => 'datetime', 'next_sync_at' => 'immutable_datetime'];
    }
}
