<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Dependant extends Model
{
    protected $fillable = [
        'member_id',
        'name',
        'relationship',
        'dob',
        'gender',
    ];
}
