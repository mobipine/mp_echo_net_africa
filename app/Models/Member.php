<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Member extends Model
{
    protected $fillable = [
        'group_id', 'name', 'email', 'phone', 'national_id',
        'gender', 'dob', 'marital_status', 'profile_picture'
    ];

    protected $casts = [
        'dob' => 'date',
    ];

    public function group()
    {
        return $this->belongsTo(\App\Models\Group::class);
    }

    public function dependants()
    {
        return $this->hasMany(Dependant::class);
    }

    public function kycDocuments()
    {
        return $this->hasMany(KycDocument::class);
    }
}
