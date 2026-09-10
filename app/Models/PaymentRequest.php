<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentRequest extends Model
{
    protected $guarded = [];

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
