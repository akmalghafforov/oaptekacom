<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductCategoryRule extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['context_requirements' => 'array', 'context_exclusions' => 'array', 'supersedes_categories' => 'array', 'is_enabled' => 'boolean'];
    }
}
