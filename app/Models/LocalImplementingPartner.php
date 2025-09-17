<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LocalImplementingPartner extends Model
{
    //
    protected $fillable=['name'];

    public function groups(){
        return $this->hasMany(\App\Models\Group::class);
    }

    
}
