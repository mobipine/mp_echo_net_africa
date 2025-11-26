<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class County extends Model
{
    protected $table = 'counties';
    protected $fillable = [
        'name'
    ];
}
