<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SaccoProductAttributeValue extends Model
{
    protected $fillable = [
        'sacco_product_id',
        'attribute_id',
        'value',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the product this value belongs to
     */
    public function product()
    {
        return $this->belongsTo(SaccoProduct::class, 'sacco_product_id');
    }

    /**
     * Get the attribute definition
     */
    public function attribute()
    {
        return $this->belongsTo(SaccoProductAttribute::class, 'attribute_id');
    }
}
