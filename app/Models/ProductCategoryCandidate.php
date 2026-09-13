<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductCategoryCandidate extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['examples' => 'array'];
    }
}
