<?php

namespace App\Models;

use Database\Factories\GmailImportReceiptFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Log;

class GmailImportReceipt extends Model
{
    /** @use HasFactory<GmailImportReceiptFactory> */
    use HasFactory;

    protected $guarded = [];

    protected $attributes = ['status' => 'pending', 'attempts' => 0, 'receipt_scope' => 'message'];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_receipt_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_receipt_id');
    }

    protected static function booted(): void
    {
        static::updated(function (self $receipt): void {
            if ($receipt->wasChanged(['status', 'reason'])) {
                Log::info('Gmail import outcome', ['receipt_id' => $receipt->id, 'supplier_id' => $receipt->supplier_organization_id, 'profile_id' => $receipt->supplier_import_profile_id, 'import_id' => $receipt->price_list_import_id, 'stage' => 'intake', 'status' => $receipt->status, 'reason' => $receipt->reason, 'attempts' => $receipt->attempts]);
            }
        });
    }

    protected function casts(): array
    {
        return ['attachment_manifest' => 'array', 'selected_part' => 'array', 'received_at' => 'immutable_datetime', 'next_attempt_at' => 'immutable_datetime'];
    }
}
