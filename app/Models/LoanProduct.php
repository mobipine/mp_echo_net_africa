<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoanProduct extends Model
{
    protected $fillable = ['name', 'description', 'is_active'];

    public function LoanProductAttributes()
    {
        return $this->hasMany(LoanProductAttribute::class, 'loan_product_id', 'id');
    }
    
    
}
