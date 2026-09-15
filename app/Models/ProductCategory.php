<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductCategory extends Model
{
    protected $guarded = [];

    public function medicines(): BelongsToMany
    {
        return $this->belongsToMany(Medicine::class)->withPivot(['source', 'confidence', 'rule_set_id', 'rule_set_checksum', 'evidence', 'assigned_by'])->withTimestamps();
    }

    public function rules(): HasMany
    {
        return $this->hasMany(ProductCategoryRule::class);
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
