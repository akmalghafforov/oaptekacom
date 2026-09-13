<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductCategoryRuleSet extends Model
{
    protected $guarded = [];

    public function rules(): HasMany
    {
        return $this->hasMany(ProductCategoryRule::class);
    }

    protected function casts(): array
    {
        return ['published_at' => 'datetime'];
    }
}
