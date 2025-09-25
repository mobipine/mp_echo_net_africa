<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MemberEditRequest extends Model
{
    protected $fillable=["national_id","group","gender","year_of_birth","name","phone_number","status"];
}
