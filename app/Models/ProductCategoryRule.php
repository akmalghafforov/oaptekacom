<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductCategoryRule extends Model
{
    protected $guarded = [];

    public function productCategory(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class);
    }

    protected function casts(): array
    {
        return ['context_requirements' => 'array', 'context_exclusions' => 'array', 'supersedes_categories' => 'array', 'is_enabled' => 'boolean'];
    }
}
