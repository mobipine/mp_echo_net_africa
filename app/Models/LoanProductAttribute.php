<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LoanProductAttribute extends Model
{
    use HasFactory;

    protected $fillable = [
        'loan_product_id',
        'loan_attribute_id',
        'value',
        'order',
    ];

    public function loanProduct()
    {
        return $this->belongsTo(LoanProduct::class, 'loan_product_id', 'id');
    }

    public function loanAttribute()
    {
        return $this->belongsTo(LoanAttribute::class, 'loan_attribute_id', 'id');
    }

    public function getAttributeLabelAttribute()
    {
        return $this->loanAttribute->name;
    }
    
}
