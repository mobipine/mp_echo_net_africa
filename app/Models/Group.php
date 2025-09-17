<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Group extends Model
{
    protected $fillable = ['name', 'email', 'phone_number', 'county', 'sub_county', 'address', 'township','group_certificate','ward','local_implementing_partner_id','county_ENA_staff_id'];

    public function members()
    {
        return $this->hasMany(\App\Models\Member::class);
    }
    public function localImplementingPartner()
    {
        return $this->belongsTo(\App\Models\LocalImplementingPartner::class,'local_implementing_partner_id');
    }
    public function CountyENAStaff(){
        return $this->belongsTo(\App\Models\CountyENAStaff::class,'county_ENA_staff_id');   
    }
    
    public function surveys()
    {
        return $this->belongsToMany(Survey::class, 'group_survey');
    }

    public function officials(): HasMany
    {
        return $this->hasMany(Official::class);
    }
}
