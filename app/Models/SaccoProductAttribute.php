<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SaccoProductAttribute extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'type',
        'options',
        'description',
        'applicable_product_types',
        'is_required',
        'default_value',
    ];

    protected $casts = [
        'applicable_product_types' => 'array',
        'is_required' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get all attribute values using this attribute
     */
    public function attributeValues()
    {
        return $this->hasMany(SaccoProductAttributeValue::class, 'attribute_id');
    }
}
