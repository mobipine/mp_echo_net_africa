<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoanAttribute extends Model
{

    protected $fillable = [
        'name',
        'slug',
        'type',
        'options',
        'is_required',
    ];

    

    public function loanProductAttributes()
    {
        return $this->hasMany(LoanProductAttribute::class);
    }

    public function getAttributeLabelAttribute()
    {
        return $this->name;
    }
}
