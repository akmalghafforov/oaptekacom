<?php

namespace App\Models;

use Database\Factories\GmailImportContentClaimFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GmailImportContentClaim extends Model
{
    /** @use HasFactory<GmailImportContentClaimFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [];
    }
}
