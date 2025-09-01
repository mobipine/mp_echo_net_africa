<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Member extends Model
{
    protected $fillable = [
        'group_id', 'name', 'email', 'phone', 'national_id',
        'gender', 'dob', 'marital_status', 'profile_picture','is_active'
    ];

    protected $casts = [
        'dob' => 'date',
        'is_active' => 'boolean',
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

    public function emailInboxes()
    {
        return $this->hasMany(EmailInbox::class);
    }

    public function smsInboxes()
    {
        return $this->hasMany(SmsInbox::class);
    }
}
