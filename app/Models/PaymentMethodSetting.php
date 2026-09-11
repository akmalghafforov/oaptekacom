<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['method', 'is_enabled', 'wallet_number', 'instructions'])]
class PaymentMethodSetting extends Model
{
    protected function casts(): array
    {
        return ['method' => PaymentMethod::class, 'is_enabled' => 'boolean'];
    }

    public function isConfigured(): bool
    {
        return $this->is_enabled && filled($this->wallet_number) && filled($this->instructions);
    }
}
