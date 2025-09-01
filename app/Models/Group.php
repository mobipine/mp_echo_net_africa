<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Group extends Model
{
    protected $fillable = ['name', 'email', 'phone_number', 'county', 'sub_county', 'address', 'township'];

    public function members()
    {
        return $this->hasMany(\App\Models\Member::class);
    }
    
    public function surveys()
    {
        return $this->belongsToMany(Survey::class, 'group_survey');
    }
}
