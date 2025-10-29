<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SaccoProductType extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'description',
        'category',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get all products of this type
     */
    public function products()
    {
        return $this->hasMany(SaccoProduct::class, 'product_type_id');
    }
}
