<?php

namespace App\Models;

use App\Enums\OrganizationType;
use App\Enums\TradeMode;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Organization extends Model
{
    use HasFactory;

    protected $guarded = [];

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function offers()
    {
        return $this->hasMany(Offer::class);
    }

    public function permitsMode(TradeMode $mode): bool
    {
        return $this->type === OrganizationType::Pharmacy || $this->supplier_mode === 'both' || $this->supplier_mode === $mode->value;
    }

    protected function casts(): array
    {
        return ['subscription_until' => 'datetime', 'type' => OrganizationType::class];
    }
}
